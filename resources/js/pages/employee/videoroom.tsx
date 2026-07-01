import { router, usePage } from '@inertiajs/react';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { OFFICE_IP } from '@/lib/chat';
import { CONSULTS, MEET_REQUESTS, maskClient, maskLawyer } from '@/lib/employee-data';

// يطابق videoRoomView + vrTick/vrToggleMic/vrToggleCam/vrEnd/vrMinimize في index (82).html
// الموظف هو الطرف المحلي (selfName/selfAv = منيرة الحربي / م ح)

const SELF_NAME = 'منيرة الحربي';
const SELF_AV = 'م ح';

// يطابق vrInitials
function vrInitials(n: string): string {
  if (!n) return '؟';
  const p = String(n).trim().split(/\s+/);
  return (p[0] ? p[0][0] : '') + (p[1] ? p[1][0] : '');
}

// يطابق fmtDur
function fmtDur(s: number): string {
  const m = Math.floor(s / 60);
  const ss = s % 60;
  return `${m < 10 ? '0' : ''}${m}:${ss < 10 ? '0' : ''}${ss}`;
}

interface Ctx { kind: string; ref: string; remoteName: string; remoteSub: string; label: string; back: string; }

function resolveCtx(params: URLSearchParams): Ctx {
  const kind = params.get('kind') || 'consult';
  if (kind === 'req') {
    const id = params.get('id') || '';
    const r = MEET_REQUESTS.find((x) => x.id === id);
    return {
      kind: 'req', ref: id,
      remoteName: r ? r.client : 'العميل',
      remoteSub: r ? r.service : '',
      label: (r && r.meetId) ? r.meetId : id,
      back: '/employee/meetreqs',
    };
  }
  const ref = params.get('ref') || '';
  const c = CONSULTS.find((x) => x.ref === ref);
  return {
    kind: 'consult', ref,
    remoteName: c ? maskClient(c.client) : 'العميل',
    remoteSub: c ? `${maskLawyer(c.lawyer)} · ${c.subject}` : '',
    label: ref,
    back: '/employee/consultrecv',
  };
}

const EmployeeVideoRoom: React.FC = () => {
  const toast = useToast();
  const { url } = usePage() as unknown as { url: string };
  const ctx = resolveCtx(new URLSearchParams(url.split('?')[1] || ''));

  const [seconds, setSeconds] = useState(0);
  const [mic, setMic] = useState(true);
  const [cam, setCam] = useState(true);
  const [notes, setNotes] = useState('');
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    timer.current = setInterval(() => setSeconds((s) => s + 1), 1000);
    return () => { if (timer.current) clearInterval(timer.current); };
  }, []);

  const stopTimer = () => { if (timer.current) { clearInterval(timer.current); timer.current = null; } };

  // يطابق vrMinimize
  const minimize = () => { stopTimer(); router.visit(ctx.back); };

  // يطابق vrEnd (دور الموظف)
  const end = () => {
    stopTimer();
    if (ctx.kind === 'consult') {
      toast(`انتهت الجلسة (${fmtDur(seconds)}) — ولّد الفريق القانوني ملخص الاستشارة`);
      router.visit(`/employee/consult?ref=${encodeURIComponent(ctx.ref)}`);
      return;
    }
    toast(`انتهت الجلسة المرئية — المدة ${fmtDur(seconds)}`);
    router.visit(ctx.back);
  };

  const rAv = vrInitials(ctx.remoteName);
  const hint = (!mic ? '🔇 الميكروفون مكتوم  ' : '') + (!cam ? '📷 الكاميرا متوقفة' : '')
    || 'الجلسة مُسجّلة ومحميّة — لا يسمح بالنسخ أو التحميل';

  return (
    <div style={{ maxWidth: 880, margin: '0 auto' }}>
      <div style={{ marginBottom: 14, display: 'flex', gap: 9, flexWrap: 'wrap' }}>
        <button className="btn soft sm" onClick={minimize} type="button">
          <Icon name="reply" /> تصغير (إبقاء الجلسة)
        </button>
      </div>

      <div className="vroom">
        <div className="vr-stage">
          <div className="vr-top">
            <Icon name="video" />
            <b>{ctx.remoteName}</b>
            <span style={{ opacity: 0.8, fontSize: 12 }}>{ctx.label} · استشارة مرئية</span>
            <span className="vr-rec"><span className="dot" /> تسجيل</span>
            <span className="vr-timer">{fmtDur(seconds)}</span>
          </div>

          <div className="vr-main">
            <div className="vr-ava">{rAv}</div>
            <b>{ctx.remoteName}</b>
            {ctx.remoteSub && <span className="st">{ctx.remoteSub}</span>}
            <span className="st">متصل · الكاميرا مفعّلة</span>
          </div>

          <div className={`vr-self${cam ? '' : ' camoff'}`}>
            <div className="sa">{SELF_AV}</div>
            <span className="sl">أنت ({SELF_NAME}){cam ? '' : ' · الكاميرا متوقفة'}</span>
          </div>

          <div className="vr-wm">سري · {OFFICE_IP}</div>
        </div>

        <div className="vr-controls">
          <button className={`vr-btn${mic ? '' : ' off'}`} onClick={() => setMic((v) => !v)} title="ميكروفون" type="button">
            <Icon name="mic" />
          </button>
          <button className={`vr-btn${cam ? '' : ' off'}`} onClick={() => setCam((v) => !v)} title="كاميرا" type="button">
            <Icon name="video" />
          </button>
          <button className="vr-btn" onClick={() => toast('تمت مشاركة الشاشة')} title="مشاركة الشاشة" type="button">
            <Icon name="upload" />
          </button>
          <button className="vr-btn end" onClick={end} title="إنهاء الجلسة" type="button">
            <Icon name="phone" />
          </button>
        </div>

        <div className="vr-hint">{hint}</div>
      </div>

      <div className="card" style={{ marginTop: 16 }}>
        <div className="card-h">
          <h3>ملاحظات الجلسة</h3>
          <button className="btn sm" onClick={end} type="button">
            <Icon name="doc" /> إنهاء وكتابة الملخص
          </button>
        </div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          <textarea
            className="input"
            rows={3}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="دوّن أبرز نقاط الجلسة المرئية (تُحفظ وتُنقل لصفحة الملخص)…"
          />
        </div>
      </div>
    </div>
  );
};

export default EmployeeVideoRoom;
