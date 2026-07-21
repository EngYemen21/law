import { Link, router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { nowClock, todayDate } from '@/lib/chat';
import { openMeeting } from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import { MR_FLOW, maskClient } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import ZoomEmbedRoom from '@/lib/zoom-room';

// ============================================================
// واجهة الاجتماعات المشتركة (Meeting/MeetRequest الحقيقيان من الخادم)
// يطابق meetReqsView + meetingView في index (82).html
// ============================================================

/**
 * نغمة شارة حالة الاجتماع — مصدر وحيد لكل اللوحات.
 * حالة الاجتماع مشتقّة لا مخزّنة (لا عمود tone في الجدول)، فمكانها الصحيح هنا لا على الخادم.
 * كانت خريطتان متناقضتان: قائمة الإدارة تلوّن «جارٍ» عنبرياً وصفحة التفاصيل أزرق،
 * و«قادم» أزرق في القائمة ورمادي في التفاصيل — لنفس الاجتماع.
 */
export function meetStatusTone(status: string): string {
  const m: Record<string, string> = {
    'قادم': 'b-blue', 'جارٍ': 'b-amber', 'منتهٍ': 'b-green', 'مؤجل': 'b-grey', 'ملغى': 'b-red',
  };

  return m[status] ?? 'b-grey';
}

// بطاقة الاجتماع الكامل (Meeting::toFullCard) — تطابق FullMeeting
export interface FullMeetingCard {
  id: string;      // M-26101
  dbId: number;
  title: string;
  type: string;
  client: string;
  when: string;
  approve: string;
  before: string[];
  during: string[];
  after: string[];
  status: string;  // قادم/جارٍ/منتهٍ/مؤجل/ملغى
  priority: string;
  conf: string;
  attend: number;
  link: string;
  meetId: string;
  meetLink: string;
  hostLink: string | null;
  dur: string;
  summary: string | null;
  sumApproved: boolean;
  minutes: string | null;
  participants: string | null;
  caseRef: string | null;
  decisions: string[];
  tasksCreated: boolean;
}

// بطاقة دعوة الاجتماع (MeetRequest::toCard) — تطابق MeetRequest
export interface MeetReqCard {
  id: string;      // MR-1042
  dbId: number;
  client: string;
  service: string;
  type: string;
  caseRef: string | null;
  day: string;
  time: string;
  by: string;
  stage: number;   // 0..3
  meetId: string | null;
  meetLink: string | null;
  hostLink: string | null;
  meetingRef: string | null; // مرجع الاجتماع المرتبط (M-…) للغرفة المضمّنة
}

export interface ClientDirEntry { id: number; name: string; items: string[] }

// غرفة الاجتماع المضمّنة لدور المكتب — فيديو Zoom داخل الموقع (المحضر/الملخص في صفحة الاجتماع)
export const StaffMeetingRoom: React.FC<{ meeting: FullMeetingCard; base: string }> = ({ meeting, base }) => (
  <ZoomEmbedRoom
    cref={meeting.id}
    kind="meeting"
    label={`${meeting.id} · ${meeting.title}`}
    back={`${base}/meeting?id=${encodeURIComponent(meeting.id)}`}
    fallbackUrl={meeting.hostLink || meeting.meetLink}
    viewer="staff"
  />
);

// ============================================================
// طلبات الاجتماعات — صفحة مشتركة للموظف/المحامي/الإدارة
// يطابق meetReqsView + sendMeetInvite/mrCancel
// ============================================================

export const MeetReqsPage: React.FC<{ requests: MeetReqCard[]; clients: ClientDirEntry[]; base: string }> = ({ requests, clients, base }) => {
  const toast = useToast();
  const [open, setOpen] = useState(false);

  // حقول مودال إرسال الدعوة
  const [miClient, setMiClient] = useState<number>(clients[0]?.id ?? 0);
  const [miCase, setMiCase] = useState('');
  const [miService, setMiService] = useState('');
  const [miType, setMiType] = useState('استشارة مرئية');
  const [miDay, setMiDay] = useState('');
  const [miTime, setMiTime] = useState('');
  useEffect(() => {
 setMiCase(''); 
}, [miClient]);

  const caseOptions = clients.find((c) => c.id === miClient)?.items ?? [];

  const submitInvite = () => {
    const name = clients.find((c) => c.id === miClient)?.name ?? '';
    router.post(`${base}/meetreqs`, {
      client_id: miClient, service: miService, type: miType, case_ref: miCase, day: miDay, time: miTime,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setOpen(false);
        setMiService(''); setMiDay(''); setMiTime('');
        toast(`تم إرسال الدعوة وإشعارها إلى العميل: ${name}`);
      },
    });
  };

  const cancel = (r: MeetReqCard) =>
    router.post(`${base}/meetreqs/${r.dbId}/cancel`, {}, { preserveScroll: true, onSuccess: () => toast('تم إلغاء الدعوة') });

  // دخول الغرفة المضمّنة كمضيف ويعلّم «تنفيذ الجلسة»
  const enterRoom = (r: MeetReqCard) => {
    if (r.stage === 1) {
router.post(`${base}/meetreqs/${r.dbId}/start`, {}, { preserveScroll: true });
}

    if (r.meetingRef) {
router.visit(`${base}/meetingroom?ref=${encodeURIComponent(r.meetingRef)}`);
} else if (r.type.indexOf('مرئية') >= 0) {
openMeeting(r.hostLink || r.meetLink || '');
} // احتياط
    else {
toast('سيتم فتح رابط الاجتماع في موعده');
}
  };

  const copyLink = (r: MeetReqCard) => {
    if (navigator.clipboard && r.meetLink) {
void navigator.clipboard.writeText(r.meetLink);
}

    toast('تم نسخ رابط الاجتماع');
  };

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>
          يرسل المكتب دعوة الاجتماع للعميل، فيستقبلها ويؤكّد حضوره. <b>المسار:</b> إرسال الدعوة للعميل ← تأكيد حضور العميل ← تنفيذ الجلسة ← اعتماد الإدارة.
        </p>
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 14 }}>
        <button className="btn" onClick={() => setOpen(true)} type="button">
          <Icon name="send" /> إرسال دعوة اجتماع للعميل
        </button>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>طلبات الاجتماعات</h3>
          <span className="sub">{requests.length} دعوة</span>
        </div>
        <div className="card-b">
          {requests.length ? requests.map((r) => (
            <div key={r.id} className="item">
              <div className="iico"><Icon name="video" /></div>
              <div className="imeta">
                <b>{r.id} — {maskClient(r.client)}</b>
                <span style={{ display: 'block', margin: '3px 0' }}>
                  {r.type} · {r.service} · {r.day} {r.time} · أرسلها: {r.by || 'المكتب'}
                </span>
                {r.stage >= 1 && r.meetLink && (
                  <span style={{ display: 'block', margin: '4px 0', fontSize: '11.5px', color: 'var(--primary)', fontWeight: 700, direction: 'ltr', textAlign: 'right' }}>
                    🔗 {r.meetLink}
                  </span>
                )}
                <span><FlowLine steps={MR_FLOW} cur={r.stage} /></span>
              </div>
              <div className="iact">
                {r.stage >= 3 ? (
                  <Badge text="معتمد" tone="b-green" />
                ) : r.stage === 0 ? (
                  <>
                    <span className="chip muted">بانتظار تأكيد العميل</span>
                    <button className="btn soft sm" onClick={() => cancel(r)} type="button">
                      <Icon name="out" /> إلغاء
                    </button>
                  </>
                ) : (
                  <span className="chip muted">{MR_FLOW[r.stage]}</span>
                )}
                {r.stage >= 1 && r.meetLink && (
                  <>
                    <button className="btn soft sm" onClick={() => copyLink(r)} type="button">
                      <Icon name="link" /> نسخ الرابط
                    </button>
                    <button className="btn sm" onClick={() => enterRoom(r)} type="button">
                      <Icon name="video" /> {r.type.indexOf('مرئية') >= 0 ? 'دخول جلسة Zoom' : 'دخول'}
                    </button>
                  </>
                )}
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="video" /><b>لا دعوات اجتماعات حالياً</b></div>
          )}
        </div>
      </div>

      <Modal title="إرسال دعوة اجتماع للعميل" open={open} onClose={() => setOpen(false)}>
        <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>
          يرسل المكتب الدعوة للعميل ليؤكّد حضوره — تصل لإشعاراته و«دعوات الاجتماعات».
        </p>
        <div className="field">
          <label>العميل (من المسجّلين)</label>
          <select value={miClient} onChange={(e) => setMiClient(Number(e.target.value))}>
            {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
        <div className="field">
          <label>قضية / استشارة العميل</label>
          <select value={miCase} onChange={(e) => setMiCase(e.target.value)}>
            <option value="">— اختر قضية/استشارة —</option>
            {caseOptions.map((i) => <option key={i} value={i}>{i}</option>)}
          </select>
        </div>
        <div className="field">
          <label>الموضوع/الخدمة</label>
          <input className="input" value={miService} onChange={(e) => setMiService(e.target.value)} placeholder="مثال: نزاع تجاري" />
        </div>
        <div className="field">
          <label>نوع الاجتماع</label>
          <select value={miType} onChange={(e) => setMiType(e.target.value)}>
            <option>استشارة مرئية</option>
            <option>استشارة حضورية</option>
            <option>استشارة هاتفية</option>
          </select>
        </div>
        <div className="picker-grid">
          <div className="field">
            <label>اليوم</label>
            <input className="input" type="date" value={miDay} onChange={(e) => setMiDay(e.target.value)} />
          </div>
          <div className="field">
            <label>الوقت</label>
            <input className="input" type="time" value={miTime} onChange={(e) => setMiTime(e.target.value)} />
          </div>
        </div>
        <button className="btn block" onClick={submitInvite} type="button">
          <Icon name="send" /> إرسال الدعوة للعميل
        </button>
      </Modal>
    </>
  );
};

// ============================================================
// تفاصيل الاجتماع — مشتركة للمحامي/الإدارة (يطابق meetingView)
// ============================================================

function defaultMinutes(m: FullMeetingCard): string {
  return `محضر اجتماع: ${m.title}\nالنوع: ${m.type}\nالتاريخ: ${m.when}\n\n` +
    `أبرز ما دار:\n- ${m.during.join('\n- ')}\n\n` +
    `القرارات والمهام:\n- ${m.after.join('\n- ')}`;
}

function defaultSummary(m: FullMeetingCard): string {
  return `ملخص اجتماع: ${m.title} — ${m.type}. أبرز ما دار: ${m.during.join(' ، ')}. ` +
    `الخلاصة والقرارات: ${m.after.join(' ، ')}.`;
}

export const MeetingDetailPage: React.FC<{ meeting: FullMeetingCard; base: string }> = ({ meeting: m, base }) => {
  const toast = useToast();

  // حالة لحظية: تتحدّث فور بثّ الخادم (إنهاء/اعتماد + الملخص/المحضر)
  const [status, setStatus] = useState(m.status);
  const [approve, setApprove] = useState(m.approve);
  const approved = approve === 'معتمد';

  const [summary, setSummary] = useState(m.summary || defaultSummary(m));
  const [minutes, setMinutes] = useState(m.minutes || defaultMinutes(m));
  const [decisions, setDecisions] = useState<string[]>(m.decisions ?? []);
  const [tasksDone, setTasksDone] = useState(m.tasksCreated);
  useEffect(() => {
    setSummary(m.summary || defaultSummary(m)); setMinutes(m.minutes || defaultMinutes(m));
    setDecisions(m.decisions ?? []); setTasksDone(m.tasksCreated); setStatus(m.status); setApprove(m.approve);
  }, [m.summary, m.minutes, m.decisions, m.tasksCreated, m.status, m.approve]);

  // بثّ لحظي لحالة الاجتماع (جارٍ→منتهٍ→معتمد + المخرجات بعد الاعتماد)
  useEffect(() => {
    const ch = echo.private(`meeting.${m.dbId}`).listen('.status', (e: { status: string; approve: string; summary: string | null; minutes: string | null }) => {
      setStatus(e.status); setApprove(e.approve);

      if (e.summary) {
setSummary(e.summary);
}

      if (e.minutes) {
setMinutes(e.minutes);
}
    });

    return () => {
 void ch; echo.leave(`meeting.${m.dbId}`); 
};
  }, [m.dbId]);

  const saveSummary = () =>
    router.post(`${base}/meetings/${m.dbId}/summary`, { summary }, { preserveScroll: true, onSuccess: () => toast('تم حفظ الملخص') });
  const saveMinutes = () =>
    router.post(`${base}/meetings/${m.dbId}/minutes`, { minutes }, { preserveScroll: true, onSuccess: () => toast('تم حفظ المحضر') });

  const copyLink = () => {
    if (navigator.clipboard) {
void navigator.clipboard.writeText(m.meetLink);
}

    toast('تم نسخ رابط الاجتماع');
  };

  // تحويل قرارات الاجتماع إلى مهام حقيقية (موديل Task) — لمرة واحدة
  const decisionsToTasks = () => {
    if (tasksDone || decisions.length === 0) {
return;
}

    router.post(`${base}/meetings/${m.dbId}/tasks`, {}, {
      preserveScroll: true,
      onSuccess: () => {
 setTasksDone(true); toast(`تم تحويل ${decisions.length} قرار إلى مهام`); 
},
    });
  };

  return (
    <div className="detail-wrap">
      <div style={{ marginBottom: 14 }}>
        <Link href={`${base}/meetings`} className="btn soft sm">
          <Icon name="reply" /> رجوع للاجتماعات
        </Link>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>{m.title}</h3>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <Badge text={status} tone={meetStatusTone(status)} />
            <Badge text={approve} tone={approved ? 'b-green' : 'b-amber'} />
          </div>
        </div>
        <div className="card-b" style={{ padding: '14px 18px' }}>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <span className="chip muted">{m.type}</span>
            <span className="chip muted">{m.client}</span>
            <span className="chip muted">{m.when}</span>
            <span className="chip muted">{m.dur}</span>
            {m.caseRef && <span className="chip muted">{m.caseRef}</span>}
          </div>
          {m.participants && (
            <div style={{ marginTop: 11, fontSize: '12.5px', color: 'var(--ink)' }}>
              <b>المشاركون:</b> <span style={{ color: 'var(--muted)' }}>{m.participants}</span>
            </div>
          )}
          {m.meetLink && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--line-soft)' }}>
              <span style={{ direction: 'ltr', color: 'var(--primary)', fontWeight: 700, fontSize: '12.5px' }}>🔗 {m.meetLink}</span>
              <button className="btn soft sm" onClick={copyLink} type="button">
                <Icon name="link" /> نسخ الرابط
              </button>
              <button className="btn sm" onClick={() => router.visit(`${base}/meetingroom?ref=${encodeURIComponent(m.id)}`)} type="button">
                <Icon name="video" /> دخول اجتماع Zoom
              </button>
            </div>
          )}
        </div>
      </div>

      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>مخرجات الفريق القانوني للاجتماع (قبل/أثناء/بعد)، مع إمكانية تعديل المحضر واعتماده.</p>
      </div>

      <div className="mpanel" style={{ marginBottom: 16 }}>
        <div className="mbox">
          <div className="h">قبل الاجتماع</div>
          <ul>{m.before.map((x, i) => <li key={i}>{x}</li>)}</ul>
        </div>
        <div className="mbox">
          <div className="h">أثناء الاجتماع</div>
          <ul>{m.during.map((x, i) => <li key={i}>{x}</li>)}</ul>
        </div>
        <div className="mbox">
          <div className="h">بعد الاجتماع</div>
          <ul>{m.after.map((x, i) => <li key={i}>{x}</li>)}</ul>
        </div>
      </div>

      <div className="doc-edit" style={{ marginBottom: 8 }}>
        <div className="doc-head">
          <span className="di"><Icon name="doc" /></span>
          <b>ملخص الاجتماع</b>
          <span className="tag">{m.sumApproved ? 'معتمد' : 'مسودة'}</span>
        </div>
        <textarea value={summary} onChange={(e) => setSummary(e.target.value)} />
      </div>
      <div style={{ display: 'flex', gap: 9, margin: '10px 0 18px', flexWrap: 'wrap' }}>
        <button className="btn soft" onClick={saveSummary} type="button">حفظ الملخص</button>
        {m.sumApproved && <Badge text="الملخص معتمد ومُرسل للعميل" tone="b-green" />}
      </div>

      <div className="doc-edit">
        <div className="doc-head">
          <span className="di"><Icon name="doc" /></span>
          <b>محضر الاجتماع</b>
          <span className="tag">{m.id}</span>
        </div>
        <textarea value={minutes} onChange={(e) => setMinutes(e.target.value)} />
      </div>

      <div className="card" style={{ marginTop: 16 }}>
        <div className="card-h">
          <h3>القرارات والمهام</h3>
          <button className="btn soft sm" onClick={decisionsToTasks} type="button" disabled={tasksDone || decisions.length === 0}>
            <Icon name="check" /> {tasksDone ? 'حُوّلت إلى مهام' : 'تحويل القرارات إلى مهام'}
          </button>
        </div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          {decisions.length ? (
            <ul style={{ margin: 0, paddingInlineStart: 18, lineHeight: 2 }}>
              {decisions.map((x, i) => <li key={i}>{x}</li>)}
            </ul>
          ) : (
            <div className="empty" style={{ padding: '8px 0' }}>
              <Icon name="check" /><b>تُستخرج القرارات تلقائياً بعد إنهاء الاجتماع</b>
            </div>
          )}
        </div>
      </div>

      <div className="prot-box">
        <div className="ph"><Icon name="lock" /> حماية الاجتماع</div>
        <div className="prot-list">
          <span className="chip">منع التحميل</span>
          <span className="chip">منع النسخ</span>
          <span className="chip">منع الطباعة</span>
          <span className="chip">منع المشاركة</span>
          <span className="chip">علامة مائية ديناميكية</span>
        </div>
        <div className="audit">Audit Log · {m.client} · {m.id} · {todayDate()} {nowClock()}</div>
      </div>

      <div style={{ display: 'flex', gap: 9, marginTop: 16, flexWrap: 'wrap' }}>
        <button className="btn soft" onClick={saveMinutes} type="button">حفظ المحضر</button>
      </div>
    </div>
  );
};
