import { Link, router, usePage } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { usePrompt } from '@/components/babylon/ConfirmDialog';
import { useBodyScrollLock, useEscapeLayer } from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { isStaffRoom, ROOM_TEXT, roomKey, roomNoun } from '@/lib/room';
import type { Room } from '@/lib/room';
import {
  closeView,
  dismissResume,
  isActivePhase,
  leaveRoom,
  markRoomEnded,
  openRoom,
  rejoinRoom,
  resetRoom,
  syncRoom,
  useRoomSession,
} from '@/lib/room-session';
import { useSettings } from '@/lib/settings';

// ============================================================
// غرفة الجلسة المرئيّة — تصميمٌ واحد للجميع (قرار المالك ٢٠٢٦-٠٩-٢٦)
//
// كانت للغرفة صورتان: عمودٌ بسيط للعميل في الاستشارة، وغرفةٌ كاملة للباقين — ولكلّ دورٍ بطاقةُ
// إنهاءٍ تحت الغرفة بشرطٍ مختلف. فصارت:
//   • `RoomPage`: الغرفة ملءَ التبويب لكلّ الأدوار والنوعين، تقرأ عقد الخادم `room` وحده.
//   • `RoomDock`: شريطٌ عائم في كلّ صفحةٍ أخرى — التنقّل لا يُسقط المكالمة (انظر `room-session.ts`).
// و«تسجيل» للطاقم وحده، والإنهاء داخل الغرفة بشرطٍ واحد `endAction.enabled`، والمدّة من Zoom بعد
// الانتهاء لا صفَّ «مدّة» مُعلَنة سلفاً.
// ============================================================

/** مؤقّت الحضور التصاعديّ (س:د:ث) — منذ انضمامك أنت، لا مدّة الجلسة الرسميّة. */
function useElapsed(since: number | null): string {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (since === null) {
      return undefined;
    }

    const t = window.setInterval(() => setNow(Date.now()), 1000);

    return () => window.clearInterval(t);
  }, [since]);

  if (since === null) {
    return '';
  }

  const total = Math.max(0, Math.floor((now - since) / 1000));
  const h = Math.floor(total / 3600);
  const mm = String(Math.floor((total % 3600) / 60)).padStart(2, '0');
  const ss = String(total % 60).padStart(2, '0');

  // كان يعرض الدقائق وحدها فتصير الساعة «75:00»
  return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
}

/** شارة «تسجيل»: للطاقم وحده، ومن الخادم وحده — لا تُستنتج من أحداث Zoom. */
const RecordingBadge: React.FC<{ room: Room }> = ({ room }) =>
  isStaffRoom(room) && room.recording ? (
    <span className="vr-rec"><span className="dot" /> {ROOM_TEXT.recording}</span>
  ) : null;

/** صفوف التفاصيل — من الخادم كما هي، ومعها الحاضرون الآن والمدّة المقيسة بعد الانتهاء. */
const RoomRows: React.FC<{ room: Room; participants: number | null }> = ({ room, participants }) => (
  <>
    {room.rows.map((r, i) => (
      <div key={`${i}-${r.label}`} className="mroom-row"><span className="k">{r.label}</span><span className="v">{r.value}</span></div>
    ))}
    {participants !== null && !room.ended && (
      <div className="mroom-row"><span className="k">{ROOM_TEXT.participants}</span><span className="v">{participants}</span></div>
    )}
    {room.ended && (
      <div className="mroom-row">
        <span className="k">{ROOM_TEXT.measuredDuration}</span>
        <span className="v">{room.measuredDuration ?? ROOM_TEXT.durationPending}</span>
      </div>
    )}
  </>
);

// ——————————————————————— صفحة الغرفة ———————————————————————

export const RoomPage: React.FC<{ room: Room }> = ({ room: serverRoom }) => {
  const { office_name: officeName } = useSettings();
  const { url } = usePage();
  const s = useRoomSession();
  const toast = useToast();
  const askFor = usePrompt();
  const [drawer, setDrawer] = useState(false);
  const key = roomKey(serverRoom);

  // فتحُ الجلسة عند دخول الصفحة، وتصغيرُها (لا إنهاؤها) عند الخروج
  useEffect(() => {
    openRoom(serverRoom, url);

    return () => closeView(serverRoom);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- الجلسة تُفتح لكلّ مفتاح مرّةً؛ تجدّد الخصائص يمرّ بـsyncRoom
  }, [key]);

  useEffect(() => {
    syncRoom(serverRoom);
  }, [serverRoom]);

  useEscapeLayer(drawer, () => setDrawer(false));
  // الغرفة تغطّي التبويب — لا تمريرَ للصفحة تحتها (العدّاد مشتركٌ مع النوافذ)
  useBodyScrollLock(true);

  const mine = s.room !== null && roomKey(s.room) === key;
  // جلسةٌ أخرى قائمة في الشريط — لا تُهدم بصمت، يختار المستخدم
  const busy = s.room !== null && !mine && isActivePhase(s.phase);
  const room = mine && s.room ? s.room : serverRoom;
  const phase = mine ? s.phase : 'idle';
  const elapsed = useElapsed(mine && phase === 'joined' ? s.joinedAt : null);
  const noun = roomNoun(room.kind);
  const end = room.endAction;

  const endSession = async () => {
    if (!end?.enabled) {
      return;
    }

    const notes = await askFor({
      title: end.label,
      message: ROOM_TEXT.endHint,
      label: ROOM_TEXT.endNotesLabel,
      placeholder: end.placeholder,
      multiline: true,
      required: false,
      confirmLabel: end.label,
    });

    if (notes === null) {
      return;
    }

    router.post(end.url, { notes: notes.trim() }, {
      preserveScroll: true,
      onSuccess: () => {
        markRoomEnded();

        // الخادم يعيد من الغرفة إلى الوجهة نفسها غالباً (`RoomDetails::afterEnd`) — فلا زيارةَ ثانية
        const here = `${window.location.pathname}${window.location.search}`;

        if (here !== end.redirect && window.location.href !== end.redirect) {
          router.visit(end.redirect);
        }
      },
      onError: (errors) => toast(String(Object.values(errors)[0] ?? ROOM_TEXT.endFailed), 'error'),
    });
  };

  const overlay = (() => {
    if (busy && s.room) {
      return (
        <div className="mroom-overlay"><div>
          <div className="oi"><Icon name="video" /></div>
          <b>{ROOM_TEXT.otherActive}</b>
          <div className="os">«{s.room.title}» — {ROOM_TEXT.otherActiveHint}</div>
          <div className="mroom-actions">
            {s.href && <Link className="btn sm" href={s.href}><Icon name="reply" /> {ROOM_TEXT.returnToRoom}</Link>}
            <button className="btn soft sm" onClick={() => {
              resetRoom();
              openRoom(serverRoom, url);
            }} type="button">{ROOM_TEXT.leaveAndJoin}</button>
          </div>
        </div></div>
      );
    }

    if (phase === 'idle' || phase === 'loading' || phase === 'joining') {
      return (
        <div className="mroom-overlay"><div>
          <div className="mroom-spin" />
          <b>{phase === 'joining' ? ROOM_TEXT.joining : ROOM_TEXT.preparing}</b>
          <div className="os">{room.title}</div>
        </div></div>
      );
    }

    if (phase === 'ended') {
      return (
        <div className="mroom-overlay"><div>
          <div className="oi"><Icon name="check" /></div>
          <b>{ROOM_TEXT.ended}</b>
          <div className="os">
            {ROOM_TEXT.measuredDuration}: {room.measuredDuration ?? ROOM_TEXT.durationPending}
          </div>
          <div className="os">{ROOM_TEXT.endedThanks}</div>
          <div className="mroom-actions">
            {room.summaryHref && <Link className="btn sm" href={room.summaryHref}><Icon name="doc" /> صفحة {noun}</Link>}
            <Link className={room.summaryHref ? 'btn soft sm' : 'btn sm'} href={room.back}><Icon name="reply" /> {ROOM_TEXT.back}</Link>
          </div>
        </div></div>
      );
    }

    if (phase === 'left') {
      return (
        <div className="mroom-overlay"><div>
          <div className="oi"><Icon name="out" /></div>
          <b>{ROOM_TEXT.left}</b>
          <div className="os">{ROOM_TEXT.leftHint}</div>
          <div className="mroom-actions">
            <button className="btn sm" onClick={rejoinRoom} type="button"><Icon name="video" /> {ROOM_TEXT.rejoin}</button>
            <Link className="btn soft sm" href={room.back}><Icon name="reply" /> {ROOM_TEXT.back}</Link>
          </div>
        </div></div>
      );
    }

    if (phase === 'error') {
      return (
        <div className="mroom-overlay"><div>
          <div className="oi"><Icon name="alert" /></div>
          <b>{ROOM_TEXT.failedTitle}</b>
          {/* سببُ الرفض كما علّله الخادم (403/422) — لا رسالةٌ عامّة */}
          <div className="os">{s.message}</div>
          <div className="mroom-actions">
            <button className="btn sm" onClick={rejoinRoom} type="button"><Icon name="video" /> {ROOM_TEXT.retry}</button>
            <Link className="btn soft sm" href={room.back}><Icon name="reply" /> {ROOM_TEXT.back}</Link>
          </div>
        </div></div>
      );
    }

    return null;
  })();

  if (typeof document === 'undefined') {
    return null;
  }

  /*
   * الغرفة تُرسم على `body` لا داخل `.view`: حركة `.view` (animation) تجعلها حاويةً للعناصر
   * الثابتة فيُحبس «ملءُ التبويب» داخل عمود المحتوى. والطبقات إخوةٌ بلا غلافٍ ذي `z-index` كي
   * تعلو الترويسةُ والشاشاتُ حاويةَ Zoom (`.mroom-zoom`) التي تعيش هي أيضاً على `body`.
   */
  return createPortal(
    <div className="mroom-layer" data-kind={room.kind}>
      <div className="mroom-bg" />
      <header className="mroom-head">
        <span className="brand"><Icon name="video" /> <span className="lbl">{officeName}</span></span>
        <span className="ttl">
          {room.title}
          {room.statusLabel && <span className="st">{room.statusLabel}</span>}
        </span>
        <span className="tools">
          {phase === 'joined' && elapsed && <span className="vr-timer">{elapsed}</span>}
          <RecordingBadge room={room} />
          <button className="mroom-btn" onClick={() => setDrawer((o) => !o)} type="button" aria-expanded={drawer} title={ROOM_TEXT.detailsTitle}>
            <Icon name="info" /> <span className="lbl">{ROOM_TEXT.details}</span>
          </button>
          {end && !room.ended && (
            <button
              className="mroom-btn end"
              onClick={() => void endSession()}
              disabled={!end.enabled}
              title={end.enabled ? end.label : ROOM_TEXT.endDisabled}
              type="button"
            >
              <Icon name="check" /> <span className="lbl">{end.label}</span>
            </button>
          )}
          {(phase === 'joined' || phase === 'joining') && (
            <button className="mroom-btn leave" onClick={leaveRoom} title={ROOM_TEXT.leaveHint} type="button">
              <Icon name="out" /> <span className="lbl">{ROOM_TEXT.leave}</span>
            </button>
          )}
          <Link className="mroom-btn" href={room.back} title={isActivePhase(phase) ? ROOM_TEXT.backHint : ROOM_TEXT.back}>
            <Icon name="reply" /> <span className="lbl">{ROOM_TEXT.back}</span>
          </Link>
        </span>
      </header>

      <div className="mroom-stage">{overlay}</div>
      {phase === 'joined' && s.userName && <div className="mroom-wm">{s.userName} · {room.ref}</div>}

      {drawer && <div className="mroom-scrim" onClick={() => setDrawer(false)} />}
      <aside className={`mroom-drawer${drawer ? ' open' : ''}`} aria-hidden={!drawer}>
        <div className="mroom-drawer-h">
          <h3>{ROOM_TEXT.detailsTitle}</h3>
          {room.statusLabel && <span className="sub">{room.statusLabel}</span>}
          <button className="x" onClick={() => setDrawer(false)} type="button" aria-label={ROOM_TEXT.close}><Icon name="close" /></button>
        </div>
        <div className="mroom-drawer-b">
          <RoomRows room={room} participants={mine ? s.participants : null} />
          {end && !room.ended && (
            <button
              className="btn sm mroom-drawer-end"
              onClick={() => void endSession()}
              disabled={!end.enabled}
              title={end.enabled ? end.label : ROOM_TEXT.endDisabled}
              type="button"
            >
              <Icon name="check" /> {end.label}
            </button>
          )}
          {end && !room.ended && !end.enabled && <p className="action-hint"><Icon name="info" /> {ROOM_TEXT.endDisabled}</p>}
        </div>
      </aside>
    </div>,
    document.body,
  );
};

/** صفحة غرفةٍ وصلت بلا `room` (رابطٌ قديم أو جلسةٌ غير محدّدة). */
export const RoomMissing: React.FC = () => (
  <div className="card"><div className="card-b">
    <div className="empty"><Icon name="video" /><b>{ROOM_TEXT.missing}</b></div>
  </div></div>
);

/** غلاف صفحات الغرف الثماني — كلّها تمرّر `room` كما أرسله الخادم ولا شيء غيره. */
export const RoomRoute: React.FC<{ room?: Room | null }> = ({ room }) =>
  room ? <RoomPage room={room} /> : <RoomMissing />;

// ——————————————————————— الشريط العائم ———————————————————————

/**
 * يُركَّب مرّةً في جذر التطبيق (`app.tsx`) خارج الصفحات — فيبقى مع كلّ تنقّل. يظهر حين تكون الجلسة
 * مصغّرة (صفحةٌ غير صفحة الغرفة)، أو انتهت وأنت خارجها، أو قطعتها إعادة التحميل.
 * أزراره تنقّلٌ بـ`router.visit`: الشريط خارج شجرة صفحات Inertia.
 */
export const RoomDock: React.FC = () => {
  const s = useRoomSession();
  const elapsed = useElapsed(s.view === 'dock' && s.phase === 'joined' ? s.joinedAt : null);

  if (!s.room && s.resume) {
    const resume = s.resume;

    return (
      <div className="mroom-dock" role="status">
        <div className="mroom-dock-bar">
          <Icon name="video" />
          <span className="t">{ROOM_TEXT.resumeTitle} — «{resume.title}»</span>
        </div>
        <div className="mroom-dock-actions">
          <button className="btn sm" onClick={() => router.visit(resume.href)} type="button">{ROOM_TEXT.returnToRoom}</button>
          <button className="btn soft sm" onClick={dismissResume} type="button">{ROOM_TEXT.dismiss}</button>
        </div>
      </div>
    );
  }

  const room = s.room;

  if (!room || s.view !== 'dock') {
    return null;
  }

  const href = s.href;

  if (isActivePhase(s.phase)) {
    // فوق حاوية Zoom المصغّرة مباشرةً (`.mroom-zoom[data-view=dock]`)
    return (
      <div className="mroom-dock live" role="region" aria-label={room.title}>
        <div className="mroom-dock-bar">
          <Icon name="video" />
          <span className="t">{room.title}</span>
          {elapsed && <span className="vr-timer">{elapsed}</span>}
          <RecordingBadge room={room} />
        </div>
        <div className="mroom-dock-actions">
          {href && <button className="btn sm" onClick={() => router.visit(href)} type="button">{ROOM_TEXT.returnToRoom}</button>}
          <button className="btn sm mroom-dock-leave" onClick={leaveRoom} title={ROOM_TEXT.leaveHint} type="button">{ROOM_TEXT.leave}</button>
        </div>
      </div>
    );
  }

  return (
    <div className="mroom-dock" role="status">
      <div className="mroom-dock-bar">
        <Icon name={s.phase === 'ended' ? 'check' : 'video'} />
        <span className="t">{s.phase === 'ended' ? ROOM_TEXT.ended : ROOM_TEXT.left} — «{room.title}»</span>
      </div>
      {s.phase === 'ended' && (
        <div className="mroom-dock-note">{ROOM_TEXT.measuredDuration}: {room.measuredDuration ?? ROOM_TEXT.durationPending}</div>
      )}
      <div className="mroom-dock-actions">
        {s.phase === 'ended' && room.summaryHref && (
          <button className="btn sm" onClick={() => {
            const to = room.summaryHref as string;
            resetRoom();
            router.visit(to);
          }} type="button">صفحة {roomNoun(room.kind)}</button>
        )}
        {s.phase !== 'ended' && href && (
          <button className="btn sm" onClick={() => router.visit(href)} type="button">{ROOM_TEXT.returnToRoom}</button>
        )}
        <button className="btn soft sm" onClick={resetRoom} type="button">{ROOM_TEXT.dismiss}</button>
      </div>
    </div>
  );
};

export default RoomPage;
