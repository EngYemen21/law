import { Link, router, usePage } from '@inertiajs/react';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import { OFFICE_IP } from '@/lib/chat';
import FlowLine from '@/components/babylon/FlowLine';
import {
  type AuditEntry,
  CONSULT_CHANNELS, CONSULT_FLOW, cStage, cTone,
  crChannelIcon, crChannelTone, maskClient, MEET_REQUESTS,
} from '@/lib/employee-data';

// ============================================================
// واجهة الاستشارات المشتركة (سجلّ Consult الحقيقي من الخادم)
// يطابق consultRecvView + videoRoomView + vrEnd في index (82).html
// ============================================================

// بطاقة الاستشارة كما يعيدها الخادم (Consult::toCard)
export interface ConsultCard {
  id: number;
  ref: string;
  client: string;
  subject: string;
  channel: string; // مرئية / حضورية / هاتفية
  lawyer: string;
  when: string;
  branch: string;
  phone: string;
  slink: string;
  hostLink: string | null; // رابط مضيف Zoom (للمكتب)
  session: string; // بانتظار الجلسة / جلسة جارية / منتهية
  status: string;
  summary: string | null;
  duration: string | null;
  total: number;
  // رحلة المعالجة (CONSULT_FLOW)
  type: string;
  priority: string;
  received: string;
  employee: string;
  mins: number;
  aiDone: boolean;
  aiClass: string;
  aiSummary: string;
  aiLawyer: string;
  missing: string[];
  audit: AuditEntry[];
  decisions: string[];
  tasksCreated: boolean;
}

// فتح جلسة Zoom في تبويب جديد (المكالمة والتسجيل على Zoom)
export function openMeeting(url: string): void {
  if (url) window.open(url, '_blank', 'noopener');
}

// «أ. سارة القحطاني» → «أ. سارة» (لدور العميل)
export function lawyerFirst(name: string): string {
  const parts = name.trim().split(/\s+/);
  if (/^(أ|د|م|الأستاذ|الأستاذة|المحامي|المحامية)\.?$/.test(parts[0]) && parts.length > 1) {
    return `${parts[0]} ${parts[1]}`;
  }
  return parts[0] || name;
}

// يطابق fmtDur
export function fmtDur(s: number): string {
  const m = Math.floor(s / 60);
  const ss = s % 60;
  return `${m < 10 ? '0' : ''}${m}:${ss < 10 ? '0' : ''}${ss}`;
}

// يطابق vrInitials
export function vrInitials(n: string): string {
  if (!n) return '؟';
  const p = String(n).trim().split(/\s+/);
  return (p[0] ? p[0][0] : '') + (p[1] ? ` ${p[1][0]}` : '');
}

export const sessTone = (s: string) =>
  s === 'جلسة جارية' ? 'b-amber' : s === 'منتهية' ? 'b-green' : 'b-grey';

// نافذة ملخص الاستشارة (تُستخدم لدى العميل والمكتب)
export const SummaryModal: React.FC<{ consult: ConsultCard | null; onClose: () => void }> = ({ consult, onClose }) => (
  <Modal title={`ملخص الاستشارة — ${consult?.ref ?? ''}`} open={!!consult} onClose={onClose}>
    {consult && (
      <div style={{ whiteSpace: 'pre-wrap', lineHeight: 1.9, fontSize: '13.5px' }}>
        {consult.summary || 'انتهت الجلسة — يُعدّ الملخص حالياً وسيصلك إشعار فور جاهزيته.'}
      </div>
    )}
  </Modal>
);

// ============================================================
// استقبال الاستشارات — صفحة مشتركة للموظف/المحامي/الإدارة
// يطابق consultRecvView + crStart/crEnd/crSetFilter
// ============================================================

export const ConsultRecvPage: React.FC<{ consults: ConsultCard[]; base: string }> = ({ consults, base }) => {
  const toast = useToast();
  const [filter, setFilter] = useState('all');
  const [summaryOf, setSummaryOf] = useState<ConsultCard | null>(null);

  const counts: Record<string, number> = { 'مرئية': 0, 'حضورية': 0, 'هاتفية': 0 };
  consults.forEach((c) => { if (counts[c.channel] != null) counts[c.channel]++; });
  const ended = consults.filter((c) => c.session === 'منتهية').length;

  const stats: StatItem[] = [
    ['t-blue', 'video', counts['مرئية'], 'مرئية (فيديو)'],
    ['t-green', 'office', counts['حضورية'], 'حضورية'],
    ['t-amber', 'phone', counts['هاتفية'], 'هاتفية'],
    ['t-cyan', 'check', ended, 'منتهية'],
  ];

  // يطابق crStart — بدء الجلسة (يبثّ للعميل لحظياً)
  const start = (c: ConsultCard) => {
    const msg = c.channel === 'مرئية' ? 'تم بدء الجلسة المرئية مع العميل'
      : c.channel === 'هاتفية' ? 'تم بدء المكالمة الهاتفية مع العميل'
      : 'تم تسجيل وصول العميل وبدء الجلسة الحضورية';
    router.post(`${base}/consults/${c.id}/start`, {}, {
      preserveScroll: true,
      onSuccess: () => toast(msg),
    });
  };

  // يطابق crEnd — إنهاء وتوليد الملخص
  const end = (c: ConsultCard) => {
    router.post(`${base}/consults/${c.id}/end`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('انتهت الجلسة — ولّد الفريق القانوني ملخص الاستشارة'),
    });
  };

  // دخول جلسة Zoom كمضيف — وبدء الجلسة إن لم تكن قد بدأت (يبثّ «جارية الآن» للعميل)
  const enterRoom = (c: ConsultCard) => {
    openMeeting(c.hostLink || c.slink);
    if (c.session === 'بانتظار الجلسة') start(c);
  };

  const copyLink = (c: ConsultCard) => {
    if (navigator.clipboard) void navigator.clipboard.writeText(c.slink);
    toast('تم نسخ رابط الاجتماع');
  };

  const list = consults.filter((c) => filter === 'all' || c.channel === filter);

  return (
    <>
      <div className="greet">
        <h2>استقبال الاستشارات</h2>
        <p>تكملة رحلة الاستشارة: استقبال الجلسات حسب القناة — مرئية (فيديو) / حضورية / هاتفية — حتى كتابة الملخص.</p>
      </div>

      <StatRow items={stats} />

      <div className="mtabs">
        {CONSULT_CHANNELS.map((t) => (
          <button
            key={t[0]}
            className={`mtab${filter === t[0] ? ' on' : ''}`}
            onClick={() => setFilter(t[0])}
            type="button"
          >
            {t[1]}
          </button>
        ))}
      </div>

      <div className="card">
        <div className="card-h">
          <h3>جلسات الاستشارات</h3>
          <span className="sub">{list.length} استشارة</span>
        </div>
        <div className="card-b">
          {list.length ? list.map((c) => {
            const extra = c.channel === 'حضورية' ? (
              <span style={{ display: 'block', marginTop: 3, fontSize: '11.5px', color: 'var(--muted)' }}>
                <Icon name="pin" /> {c.branch}
              </span>
            ) : c.channel === 'هاتفية' ? (
              <span style={{ display: 'block', marginTop: 3, fontSize: '11.5px', color: 'var(--muted)' }}>
                <Icon name="phone" /> {c.phone || '—'}
              </span>
            ) : (
              <span style={{ display: 'block', marginTop: 3, direction: 'ltr', textAlign: 'right', fontSize: 11, color: 'var(--primary)', fontWeight: 700 }}>
                🔗 {c.slink}
              </span>
            );

            return (
              <div key={c.ref} className="item">
                <div className="iico"><Icon name={crChannelIcon(c.channel)} /></div>
                <div className="imeta">
                  <b>{c.ref} — {maskClient(c.client)}</b>
                  <span style={{ display: 'block', margin: '2px 0' }}>
                    {c.subject} · {c.lawyer} · {c.when}
                  </span>
                  {extra}
                </div>
                <div className="iact">
                  <Badge text={c.channel} tone={crChannelTone(c.channel)} />
                  {c.session === 'بانتظار الجلسة' ? (
                    c.channel === 'مرئية' ? (
                      <>
                        <button className="btn sm" onClick={() => enterRoom(c)} type="button">
                          <Icon name="video" /> بدء ودخول جلسة Zoom
                        </button>
                        <button className="btn soft sm" onClick={() => copyLink(c)} type="button">
                          <Icon name="link" /> نسخ الرابط
                        </button>
                      </>
                    ) : c.channel === 'هاتفية' ? (
                      <button className="btn sm" onClick={() => start(c)} type="button">
                        <Icon name="phone" /> بدء المكالمة
                      </button>
                    ) : (
                      <button className="btn sm" onClick={() => start(c)} type="button">
                        <Icon name="check" /> تسجيل وصول العميل
                      </button>
                    )
                  ) : c.session === 'جلسة جارية' ? (
                    <>
                      <Badge text="جلسة جارية" tone="b-amber" />
                      {c.channel === 'مرئية' && (
                        <button className="btn soft sm" onClick={() => openMeeting(c.hostLink || c.slink)} type="button">
                          <Icon name="video" /> دخول جلسة Zoom
                        </button>
                      )}
                      <button className="btn sm" onClick={() => end(c)} type="button">
                        <Icon name="doc" /> إنهاء وكتابة الملخص
                      </button>
                    </>
                  ) : (
                    <>
                      <Badge text="منتهية" tone="b-green" />
                      <button className="btn soft sm" onClick={() => setSummaryOf(c)} type="button">
                        <Icon name="out" /> الملخص
                      </button>
                    </>
                  )}
                </div>
              </div>
            );
          }) : (
            <div className="empty"><Icon name="video" /><b>لا استشارات في هذه القناة</b></div>
          )}
        </div>
      </div>

      <SummaryModal consult={summaryOf} onClose={() => setSummaryOf(null)} />
    </>
  );
};

// ============================================================
// غرفة الجلسة المرئية — مكوّن مشترك للأدوار الأربعة
// يطابق videoRoomView + vrTick/vrToggleMic/vrToggleCam/vrEnd/vrMinimize
// ============================================================

export interface VideoRoomProps {
  remoteName: string;
  remoteSub: string;
  label: string; // «CN-2026-1042 · استشارة مرئية»
  selfName: string;
  selfAv: string;
  viewer: 'client' | 'staff';
  back: string;
  onEnd: (notes: string, duration: string, stop: () => void) => void;
}

export const VideoRoom: React.FC<VideoRoomProps> = ({ remoteName, remoteSub, label, selfName, selfAv, viewer, back, onEnd }) => {
  const toast = useToast();
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
  const minimize = () => { stopTimer(); router.visit(back); };

  const end = () => onEnd(notes, fmtDur(seconds), stopTimer);

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
            <b>{remoteName}</b>
            <span style={{ opacity: 0.8, fontSize: 12 }}>{label}</span>
            <span className="vr-rec"><span className="dot" /> تسجيل</span>
            <span className="vr-timer">{fmtDur(seconds)}</span>
          </div>

          <div className="vr-main">
            <div className="vr-ava">{vrInitials(remoteName)}</div>
            <b>{remoteName}</b>
            {remoteSub && <span className="st">{remoteSub}</span>}
            <span className="st">متصل · الكاميرا مفعّلة</span>
          </div>

          <div className={`vr-self${cam ? '' : ' camoff'}`}>
            <div className="sa">{selfAv || vrInitials(selfName)}</div>
            <span className="sl">أنت ({selfName}){cam ? '' : ' · الكاميرا متوقفة'}</span>
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

      {viewer === 'staff' && (
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
      )}
    </div>
  );
};

// ============================================================
// غرفة الجلسة للمكتب (موظف/محامٍ/إدارة) — استشارة حقيقية أو طلب اجتماع (kind=req)
// ============================================================

export interface StaffRoomProps {
  consult?: ConsultCard | null;
  selfName?: string;
  selfAv?: string;
  base: string; // '/employee' | '/lawyer' | '/admin'
}

export const StaffVideoRoomPage: React.FC<StaffRoomProps> = ({ consult, selfName = '', selfAv = '', base }) => {
  const toast = useToast();
  const { url } = usePage() as unknown as { url: string };
  const params = new URLSearchParams(url.split('?')[1] || '');

  // طلبات الاجتماعات (kind=req) — ما تزال على البيانات التمهيدية
  if (!consult && params.get('kind') === 'req') {
    const id = params.get('id') || '';
    const r = MEET_REQUESTS.find((x) => x.id === id);
    return (
      <VideoRoom
        remoteName={r ? r.client : 'العميل'}
        remoteSub={r ? r.service : ''}
        label={`${(r && r.meetId) ? r.meetId : id} · جلسة مرئية`}
        selfName={selfName}
        selfAv={selfAv}
        viewer="staff"
        back={`${base}/meetreqs`}
        onEnd={(_notes, dur, stop) => {
          stop();
          toast(`انتهت الجلسة المرئية — المدة ${dur}`);
          router.visit(`${base}/meetreqs`);
        }}
      />
    );
  }

  if (!consult) {
    return (
      <div className="card"><div className="card-b">
        <div className="empty"><Icon name="video" /><b>لا توجد جلسة محددة</b></div>
      </div></div>
    );
  }

  // يطابق vrEnd (دور المكتب): إنهاء → حفظ الملاحظات → توليد الملخص → العودة للاستقبال
  const end = (notes: string, dur: string, stop: () => void) => {
    stop();
    router.post(`${base}/consults/${consult.id}/end`, { notes, duration: dur }, {
      onSuccess: () => {
        toast(`انتهت الجلسة (${dur}) — ولّد الفريق القانوني ملخص الاستشارة`);
        router.visit(`${base}/consultrecv`);
      },
    });
  };

  return (
    <VideoRoom
      remoteName={maskClient(consult.client)}
      remoteSub={`${consult.lawyer} · ${consult.subject}`}
      label={`${consult.ref} · استشارة مرئية`}
      selfName={selfName}
      selfAv={selfAv}
      viewer="staff"
      back={`${base}/consultrecv`}
      onEnd={end}
    />
  );
};

// ============================================================
// إدارة الاستشارات — قائمة الرحلة (يطابق emConsultsView + cKPIs)
// ============================================================

export const ConsultsListPage: React.FC<{ consults: ConsultCard[]; base: string }> = ({ consults, base }) => {
  const by = (s: string) => consults.filter((c) => c.status === s).length;
  const done = consults.filter((c) =>
    c.status === 'منتهية' || c.status === 'محولة إلى قضية' || c.status === 'محالة للمحامي'
  ).length;
  const avg = consults.length ? Math.round(consults.reduce((a, c) => a + (c.mins || 0), 0) / consults.length) : 0;

  const stats: StatItem[] = [
    ['t-blue', 'folder', by('جديدة'), 'جديدة'],
    ['t-cyan', 'user', by('قيد مراجعة الموظف'), 'قيد المراجعة'],
    ['t-amber', 'clock', by('بانتظار استكمال البيانات'), 'بانتظار البيانات'],
    ['t-cyan', 'info', by('قيد معالجة الفريق القانوني'), 'قيد الفريق القانوني'],
    ['t-amber', 'check', by('بانتظار اعتماد الموظف'), 'بانتظار الاعتماد'],
    ['t-green', 'scale', by('جاهزة للمحامي'), 'جاهزة للمحامي'],
    ['t-blue', 'exec', done, 'منجزة'],
    ['t-grey', 'clock', `${avg} د`, 'متوسط الزمن'],
  ];

  return (
    <>
      <div className="greet">
        <h2>إدارة الاستشارات</h2>
        <p>لوحة استقبال ومعالجة الاستشارات: المراجعة، الفريق القانوني، الاعتماد، والإحالة للمحامي.</p>
      </div>

      <StatRow items={stats} />

      <div className="card">
        <div className="card-h">
          <h3>الاستشارات</h3>
          <span className="sub">{consults.length} استشارة</span>
        </div>
        <div className="card-b">
          {consults.length ? consults.map((c) => (
            <div key={c.ref} className="item">
              <div className="iico"><Icon name="folder" /></div>
              <div className="imeta">
                <b>{c.ref} — {maskClient(c.client)}</b>
                <span style={{ display: 'block', margin: '3px 0' }}>
                  {c.subject} · {c.type} · {c.received}
                </span>
                <span><FlowLine steps={CONSULT_FLOW} cur={cStage(c.status)} /></span>
              </div>
              <div className="iact">
                <span className={`mq-priority ${c.priority}`}>{c.priority}</span>
                <Badge text={c.status} tone={cTone(c.status)} />
                <button
                  className="btn soft sm"
                  onClick={() => router.visit(`${base}/consult?ref=${encodeURIComponent(c.ref)}`)}
                  type="button"
                >
                  <Icon name="out" /> فتح
                </button>
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="folder" /><b>لا استشارات بعد</b></div>
          )}
        </div>
      </div>
    </>
  );
};

// ============================================================
// رحلة الاستشارة (يطابق consultView + cTake/cRequestDocs/cRunAI/cSaveAI/cApproveAI/cRerun/cRefer)
// ============================================================

const LAWYER_OPTS = ['أ. سارة القحطاني', 'أ. خالد المالكي', 'أ. ريم الزهراني', 'أ. ماجد العتيبي'];

export const ConsultJourneyPage: React.FC<{ consult: ConsultCard; base: string; isAdmin?: boolean }> = ({ consult: c, base, isAdmin }) => {
  const toast = useToast();
  const [busy, setBusy] = useState(false);

  // الحقول القابلة للتعديل لتحليل الفريق القانوني (تُزامَن مع الخادم بعد كل إجراء)
  const [aiClass, setAiClass] = useState(c.aiClass);
  const [aiSummary, setAiSummary] = useState(c.aiSummary);
  const [aiLawyer, setAiLawyer] = useState(c.aiLawyer || LAWYER_OPTS[0]);
  const [priority, setPriority] = useState(c.priority);
  useEffect(() => {
    setAiClass(c.aiClass);
    setAiSummary(c.aiSummary);
    setAiLawyer(c.aiLawyer || LAWYER_OPTS[0]);
    setPriority(c.priority);
  }, [c.aiClass, c.aiSummary, c.aiLawyer, c.priority]);

  const post = (action: string, data: Record<string, string>, msg: string) => {
    setBusy(true);
    router.post(`${base}/consults/${c.id}/${action}`, data, {
      preserveScroll: true,
      onSuccess: () => toast(msg),
      onFinish: () => setBusy(false),
    });
  };

  const take = () => post('take', {}, 'تم استلام الاستشارة لدى الموظف');
  const requestDocs = () => post('reqdocs', {}, 'تم طلب استكمال البيانات وإشعار العميل');
  const runAI = () => post('analyze', {}, 'اكتمل تحليل الفريق القانوني');
  const saveAI = () => post('analysis', { aiClass, aiSummary, aiLawyer }, 'تم حفظ التعديلات في سجل التدقيق');
  const approveAI = () => post('approve', {}, 'تم اعتماد التحليل — الاستشارة جاهزة للمحامي');
  const rerun = () => post('analyze', {}, 'تمت إعادة التحليل');
  const refer = () => post('refer', { lawyer: aiLawyer }, `تمت إحالة الاستشارة إلى المحامي: ${aiLawyer}`);

  // تحويل قرارات الاستشارة إلى مهام حقيقية (تُستخرج عند إنهاء الجلسة) — لمرة واحدة
  const [tasksDone, setTasksDone] = useState(c.tasksCreated);
  const makeTasks = () => {
    if (tasksDone || c.decisions.length === 0) {
      return;
    }
    setBusy(true);
    router.post(`${base}/consults/${c.id}/tasks`, {}, {
      preserveScroll: true,
      onSuccess: () => { setTasksDone(true); toast(`تم تحويل ${c.decisions.length} قرار إلى مهام`); },
      onFinish: () => setBusy(false),
    });
  };
  const savePriority = () => post('priority', { priority }, 'تم تحديث الأولوية');

  const showEmpActions = c.status === 'جديدة'
    || c.status === 'قيد مراجعة الموظف'
    || c.status === 'بانتظار استكمال البيانات';
  const showRefer = c.status === 'جاهزة للمحامي';
  const showAiCard = c.aiDone;
  const showApprove = c.status === 'بانتظار اعتماد الموظف';

  return (
    <div className="detail-wrap" style={{ maxWidth: 920 }}>
      <div style={{ marginBottom: 14 }}>
        <Link href={`${base}/consults`} className="btn soft sm">
          <Icon name="reply" /> رجوع للاستشارات
        </Link>
      </div>

      {/* info */}
      <div className="card">
        <div className="card-h">
          <h3>{c.ref}</h3>
          <Badge text={c.status} tone={cTone(c.status)} />
        </div>
        <div className="card-b" style={{ padding: '14px 18px' }}>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <span className="chip muted">{maskClient(c.client)}</span>
            <span className="chip muted">{c.subject}</span>
            <span className="chip muted">{c.type}</span>
            <span className={`mq-priority ${c.priority}`}>{c.priority}</span>
            <span className="chip muted">استُلمت: {c.received}</span>
            <span className="chip muted">الموظف: {c.employee}</span>
            <span className="chip muted">المحامي: {c.lawyer}</span>
          </div>
        </div>
      </div>

      {/* stage */}
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-b" style={{ padding: '16px 18px' }}>
          <FlowLine steps={CONSULT_FLOW} cur={cStage(c.status)} />
        </div>
      </div>

      {/* action bar */}
      {(showEmpActions || showRefer) && (
        <div style={{ display: 'flex', gap: 9, margin: '0 0 16px', flexWrap: 'wrap' }}>
          {c.status === 'جديدة' && (
            <button className="btn" onClick={take} disabled={busy} type="button">
              <Icon name="check" /> استلام الاستشارة
            </button>
          )}
          {(c.status === 'قيد مراجعة الموظف' || c.status === 'بانتظار استكمال البيانات') && (
            <>
              <button className="btn soft" onClick={requestDocs} disabled={busy} type="button">
                <Icon name="upload" /> طلب استكمال مستندات
              </button>
              <button className="btn" onClick={runAI} disabled={busy} type="button">
                <Icon name="info" /> {busy ? 'جارٍ التحليل…' : 'بدء معالجة الفريق القانوني'}
              </button>
            </>
          )}
          {showRefer && (
            <button className="btn" onClick={refer} disabled={busy} type="button">
              <Icon name="scale" /> إحالة للمحامي ({aiLawyer})
            </button>
          )}
        </div>
      )}

      {/* AI card */}
      {showAiCard && (
        <>
          <div className="ai-banner">
            <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
            <p>نتائج تحليل الفريق القانوني — يمكن للموظف المخوّل أو الإدارة تعديلها واعتمادها. تُحفظ كل التعديلات في سجل التدقيق.</p>
          </div>
          <div className="card" style={{ marginBottom: 14 }}>
            <div className="card-h"><h3>تحليل الفريق القانوني</h3></div>
            <div className="card-b" style={{ padding: '16px 18px' }}>
              <div className="field">
                <label>تصنيف الاستشارة</label>
                <input className="input" value={aiClass} onChange={(e) => setAiClass(e.target.value)} />
              </div>
              <div className="field">
                <label>الملخص القانوني</label>
                <textarea className="input" rows={5} value={aiSummary} onChange={(e) => setAiSummary(e.target.value)} />
              </div>
              <div className="field">
                <label>المحامي المقترح</label>
                <select value={aiLawyer} onChange={(e) => setAiLawyer(e.target.value)}>
                  {LAWYER_OPTS.map((l) => <option key={l}>{l}</option>)}
                </select>
              </div>
              {c.missing.length > 0 && (
                <div className="action-hint">
                  <Icon name="upload" /> مستندات ناقصة: {c.missing.join('، ')}
                </div>
              )}
              <div style={{ display: 'flex', gap: 9, marginTop: 6, flexWrap: 'wrap' }}>
                <button className="btn soft sm" onClick={saveAI} disabled={busy} type="button">
                  <Icon name="check" /> حفظ التعديلات
                </button>
                {showApprove && (
                  <button className="btn sm" onClick={approveAI} disabled={busy} type="button">
                    <Icon name="check" /> اعتماد التحليل (جاهزة للمحامي)
                  </button>
                )}
                <button className="btn soft sm" onClick={() => toast('طباعة الملخص (PDF)')} type="button">
                  <Icon name="download" /> طباعة الملخص (PDF)
                </button>
                <button className="btn soft sm" onClick={rerun} disabled={busy} type="button">
                  <Icon name="info" /> إعادة التحليل
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* admin panel */}
      {isAdmin && (
        <div className="card" style={{ marginBottom: 14 }}>
          <div className="card-h"><h3>تدخّل الإدارة العليا</h3></div>
          <div className="card-b" style={{ padding: '16px 18px' }}>
            <div style={{ display: 'flex', gap: 9, flexWrap: 'wrap', alignItems: 'flex-end' }}>
              <div className="field" style={{ minWidth: 160, margin: 0 }}>
                <label>الأولوية</label>
                <select value={priority} onChange={(e) => setPriority(e.target.value)}>
                  {['عالية', 'متوسطة', 'عادية'].map((p) => <option key={p}>{p}</option>)}
                </select>
              </div>
              <button className="btn soft sm" onClick={savePriority} disabled={busy} type="button">
                <Icon name="check" /> تحديث الأولوية
              </button>
              <div className="field" style={{ minWidth: 200, margin: 0 }}>
                <label>المحامي المختص</label>
                <select value={aiLawyer} onChange={(e) => setAiLawyer(e.target.value)}>
                  {LAWYER_OPTS.map((l) => <option key={l}>{l}</option>)}
                </select>
              </div>
              <button className="btn sm" onClick={refer} disabled={busy} type="button">
                <Icon name="scale" /> تعيين المحامي واعتماد الإحالة
              </button>
            </div>
          </div>
        </div>
      )}

      {/* القرارات والمهام — تُستخرج من ملخص الجلسة وتُحوّل لمهام حقيقية */}
      {c.decisions.length > 0 && (
        <div className="card">
          <div className="card-h">
            <h3>القرارات والمهام</h3>
            <button className="btn soft sm" onClick={makeTasks} disabled={busy || tasksDone} type="button">
              <Icon name="check" /> {tasksDone ? 'حُوّلت إلى مهام' : 'تحويل القرارات إلى مهام'}
            </button>
          </div>
          <div className="card-b">
            <ul style={{ margin: 0, paddingInlineStart: 18, lineHeight: 2 }}>
              {c.decisions.map((d, i) => <li key={i}>{d}</li>)}
            </ul>
          </div>
        </div>
      )}

      {/* audit log */}
      <div className="card">
        <div className="card-h">
          <h3>سجل التدقيق (Audit Log)</h3>
          <span className="sub">{c.audit.length}</span>
        </div>
        <div className="card-b">
          {c.audit.length ? c.audit.map((a, i) => (
            <div key={i} className="item">
              <div className="iico"><Icon name="info" /></div>
              <div className="imeta">
                <b>{a.field}</b>
                <span style={{ display: 'block', marginTop: 2 }}>{a.user} · {a.before} ← {a.after}</span>
                <span style={{ color: 'var(--muted)', fontSize: 11 }}>{a.time}</span>
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="info" /><b>لا تعديلات بعد</b></div>
          )}
        </div>
      </div>
    </div>
  );
};
