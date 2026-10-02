import { Link, router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import { useConfirm, usePrompt } from '@/components/babylon/ConfirmDialog';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { isStaffRoom, ROOM_TEXT, roomKey, roomNoun } from '@/lib/room';
import type { Room } from '@/lib/room';
import {
  isActivePhase,
  leaveRoom,
  markRoomEnded,
  mountZoom,
  openRoom,
  rejoinRoom,
  syncRoom,
  unmountRoom,
  useRoomSession,
} from '@/lib/room-session';
import { firstError } from '@/lib/server-message';
import { useSettings } from '@/lib/settings';

// ============================================================
// غرفة الجلسة المرئيّة — تصميمٌ واحد للجميع (قرار المالك ٢٠٢٦-٠٩-٢٦)
//
// كانت للغرفة صورتان: عمودٌ بسيط للعميل في الاستشارة، وغرفةٌ كاملة للباقين — ولكلّ دورٍ بطاقةُ
// إنهاءٍ تحت الغرفة بشرطٍ مختلف. فصارت:
//   • `RoomPage`: الغرفة صفحةٌ مستقلّة بتبويبها لكلّ الأدوار والنوعين، تقرأ عقد الخادم `room` وحده
//     (قرار المالك 2026-10-03: لا نافذة مصغّرة — انظر `room-session.ts`).
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

/** تنبيه الطاقم: دخل أحدٌ الجلسة من خارج المنصّة (بلا مفتاحها) — من الخادم وحده، والعميل لا يراه. */
const OutsidersNotice: React.FC<{ room: Room }> = ({ room }) =>
  isStaffRoom(room) && room.outsiders > 0 ? (
    <div className="mroom-warn" role="alert">{ROOM_TEXT.outsiders(room.outsiders)}</div>
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

/**
 * **صفحةٌ مستقلّة بلا طبقةٍ فوق Zoom** (قرار المالك 2026-10-03). Zoom يُلحق نوافذه وقوائمه بـ`body` بـ`z-index`
 * تلقائيّ أو `2` (توثيق import-sdk، وثبت في حزمة 6.2.0)، ويمنع فريقه تغيير طبقاته. فالترويسة واللوحة الجانبيّة
 * وشاشات الانتظار والانتهاء في تدفّق الصفحة العاديّ بلا `z-index`، ومساحة Zoom تأتي بعد شاشة الغرفة في
 * ترتيب العناصر — فما يرسمه Zoom يعلو كلّ ما لنا. والتفاصيل لوحةٌ جانبيّة تقلّص مساحة Zoom (`updateVideoOptions`).
 */
export const RoomPage: React.FC<{ room: Room }> = ({ room: serverRoom }) => {
  const { office_name: officeName } = useSettings();
  const s = useRoomSession();
  const toast = useToast();
  const askFor = usePrompt();
  const ask = useConfirm();
  const [details, setDetails] = useState(false);
  const key = roomKey(serverRoom);

  // الجلسة تبدأ مع الصفحة وتنتهي بمغادرتها — لا مكالمة خارج صفحتها
  useEffect(() => {
    openRoom(serverRoom);

    return () => unmountRoom();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- الجلسة تُفتح لكلّ مفتاح مرّةً؛ تجدّد الخصائص يمرّ بـsyncRoom
  }, [key]);

  useEffect(() => {
    syncRoom(serverRoom);
  }, [serverRoom]);

  const room = s.room && roomKey(s.room) === key ? s.room : serverRoom;
  const phase = s.room && roomKey(s.room) === key ? s.phase : 'idle';
  const active = isActivePhase(phase);
  const elapsed = useElapsed(phase === 'joined' ? s.joinedAt : null);
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
      onError: (errors) => toast(firstError(errors, ROOM_TEXT.endFailed), 'error'),
    });
  };

  // «رجوع»: يغادر الاجتماع بتأكيد، ثمّ يغلق تبويب الجلسة إن فُتح منه، وإلّا يعود إلى الصفحة السابقة
  const goBack = async () => {
    if (active && !(await ask({ title: ROOM_TEXT.backConfirmTitle, message: ROOM_TEXT.backConfirm, confirmLabel: ROOM_TEXT.leave }))) {
      return;
    }

    leaveRoom();

    if (window.opener) {
      window.close();
    }

    router.visit(room.back);
  };

  const screen = (() => {
    if (phase === 'idle' || phase === 'loading' || phase === 'joining') {
      return (
        <div><div className="mroom-spin" />
          <b>{phase === 'joining' ? ROOM_TEXT.joining : ROOM_TEXT.preparing}</b>
          <div className="os">{room.title}</div>
        </div>
      );
    }

    if (phase === 'ended') {
      return (
        <div>
          <div className="oi"><Icon name="check" /></div>
          <b>{ROOM_TEXT.ended}</b>
          <div className="os">{ROOM_TEXT.measuredDuration}: {room.measuredDuration ?? ROOM_TEXT.durationPending}</div>
          <div className="os">{ROOM_TEXT.endedThanks}</div>
          <div className="mroom-actions">
            {room.summaryHref && <Link className="btn sm" href={room.summaryHref}><Icon name="doc" /> صفحة {noun}</Link>}
            <button className={room.summaryHref ? 'btn soft sm' : 'btn sm'} onClick={() => void goBack()} type="button"><Icon name="reply" /> {ROOM_TEXT.back}</button>
          </div>
        </div>
      );
    }

    if (phase === 'left') {
      return (
        <div>
          <div className="oi"><Icon name="out" /></div>
          <b>{ROOM_TEXT.left}</b>
          <div className="os">{ROOM_TEXT.leftHint}</div>
          <div className="mroom-actions">
            <button className="btn sm" onClick={rejoinRoom} type="button"><Icon name="video" /> {ROOM_TEXT.rejoin}</button>
            <button className="btn soft sm" onClick={() => void goBack()} type="button"><Icon name="reply" /> {ROOM_TEXT.back}</button>
          </div>
        </div>
      );
    }

    return (
      <div>
        <div className="oi"><Icon name="alert" /></div>
        <b>{ROOM_TEXT.failedTitle}</b>
        {/* سببُ الرفض كما علّله الخادم (403/422) — لا رسالةٌ عامّة */}
        <div className="os">{s.message}</div>
        <div className="mroom-actions">
          <button className="btn sm" onClick={rejoinRoom} type="button"><Icon name="video" /> {ROOM_TEXT.retry}</button>
          <button className="btn soft sm" onClick={() => void goBack()} type="button"><Icon name="reply" /> {ROOM_TEXT.back}</button>
        </div>
      </div>
    );
  })();

  return (
    <div className="mroom-page" data-kind={room.kind}>
      <header className="mroom-head">
        <span className="brand"><Icon name="video" /> <span className="lbl">{officeName}</span></span>
        <span className="ttl">
          {room.title}
          {room.statusLabel && <span className="st">{room.statusLabel}</span>}
        </span>
        <span className="tools">
          {phase === 'joined' && elapsed && <span className="vr-timer">{elapsed}</span>}
          <RecordingBadge room={room} />
          <button className="mroom-btn" onClick={() => setDetails((o) => !o)} type="button" aria-expanded={details} title={ROOM_TEXT.detailsTitle}>
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
          {active && (
            <button className="mroom-btn leave" onClick={leaveRoom} title={ROOM_TEXT.leaveHint} type="button">
              <Icon name="out" /> <span className="lbl">{ROOM_TEXT.leave}</span>
            </button>
          )}
          <button className="mroom-btn" onClick={() => void goBack()} title={active ? ROOM_TEXT.backHint : ROOM_TEXT.back} type="button">
            <Icon name="reply" /> <span className="lbl">{ROOM_TEXT.back}</span>
          </button>
        </span>
      </header>
      <OutsidersNotice room={room} />

      <div className="mroom-body">
        <main className="mroom-stage">
          {/* شاشة الغرفة أوّلاً ثمّ مساحة Zoom فوقها بترتيب العناصر — بلا z-index */}
          <div className="mroom-screen">{screen}</div>
          <div className="mroom-zoom" ref={mountZoom} />
          {phase === 'joined' && s.userName && <div className="mroom-wm">{s.userName} · {room.ref}</div>}
        </main>

        {details && (
          <aside className="mroom-side" aria-label={ROOM_TEXT.detailsTitle}>
            <div className="mroom-side-h">
              <h3>{ROOM_TEXT.detailsTitle}</h3>
              {room.statusLabel && <span className="sub">{room.statusLabel}</span>}
              <button className="x" onClick={() => setDetails(false)} type="button" aria-label={ROOM_TEXT.close}><Icon name="close" /></button>
            </div>
            <div className="mroom-side-b">
              <RoomRows room={room} participants={active ? s.participants : null} />
              {end && !room.ended && (
                <button
                  className="btn sm mroom-side-end"
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
        )}
      </div>
    </div>
  );
};

/** صفحة غرفةٍ وصلت بلا `room` (رابطٌ قديم أو جلسةٌ غير محدّدة). */
export const RoomMissing: React.FC = () => (
  <div className="card"><div className="card-b">
    <div className="empty"><Icon name="video" /><b>{ROOM_TEXT.missing}</b></div>
  </div></div>
);

type RoomRouteComponent = React.FC<{ room?: Room | null }> & { layout: (page: React.ReactNode) => React.ReactNode };

/**
 * غلاف صفحات الغرف الثماني — كلّها تمرّر `room` كما أرسله الخادم. و**بلا تخطيط اللوحة**: الغرفة صفحةٌ مستقلّة
 * لا يشاركها الشريط الجانبيّ ولا ترويسة اللوحة (`app.tsx` يحترم `layout` المعرَّف).
 */
export const RoomRoute = (({ room }) => (room ? <RoomPage room={room} /> : <RoomMissing />)) as RoomRouteComponent;
RoomRoute.layout = (page) => page;

export default RoomPage;
