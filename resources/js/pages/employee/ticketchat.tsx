import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import Modal from '@/components/babylon/Modal';
import MsgMeta from '@/components/babylon/MsgMeta';
import TicketTalkingNotice from '@/components/babylon/TicketTalkingNotice';
import TicketActionsPanel from '@/components/babylon/TicketActionsPanel';
import TicketOpsModals, { type TicketOpsKind } from '@/components/babylon/TicketOpsModals';
import TicketDetailsCard from '@/components/babylon/TicketDetailsCard';
import TimeSlotPicker from '@/components/babylon/TimeSlotPicker';
import { useToast } from '@/components/babylon/Toast';
import { echo } from '@/lib/echo';
import { ALLOWED_DOC_ACCEPT, TKT_LIFE, nowClock, tktStage, type Message } from '@/lib/chat';
import { useCan } from '@/lib/permissions';
import { isPastSlot, todayISO } from '@/components/SpecialistPicker';

// حالتان نهائيّتان — الخادم يمنع الخروج منهما لأي حالة أخرى (Employee\TicketController::status)
const FINAL = ['مكتملة', 'مغلقة'];

// محادثة التذكرة (لوحة الموظف) — مزامنة لحظية مع العميل (Reverb) بلا إعادة تحميل

interface EmpTicket {
  no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string;
  clientId?: number; lawyerId?: number; caseRef?: string | null; summaryApproved?: boolean;
  subject?: string | null; priority?: string | null; mobile?: string | null; openedAt?: string | null;
}
interface StateOption { status: string; tone: string; }
interface LawyerOption { id: number; name: string; }
interface ClientStats {
  totalTickets: number;
  activeTickets: number;
  totalCases: number;
  memberSince: string;
}

const MsgRow: React.FC<{ m: Message }> = ({ m }) => {
  if (m.who === 'note') {
    return (
      <div className="msg" style={{ justifyContent: 'center' }}>
        <div style={{ background: '#FBF1E0', border: '1px solid #F0DDB0', color: '#8a6d2f', borderRadius: 11, padding: '9px 13px', fontSize: 12.5, maxWidth: '85%' }}>
          <b>🔒 ملاحظة داخلية — {m.name}</b>
          <div style={{ marginTop: 4 }} dangerouslySetInnerHTML={{ __html: m.text }} />
          <time style={{ display: 'block', marginTop: 4, color: '#b08d4a', fontSize: 11 }}>{m.time}</time>
        </div>
      </div>
    );
  }
  const isClient = m.who === 'client' || m.who === 'me';
  const actor = isClient ? 'me' : 'ai';
  return (
    <div className={`msg ${actor}`}>
      <div className={`av ${actor}`}>{isClient ? 'ع' : <img src="/images/mono.jpg" alt="" />}</div>
      <div className="bubble-wrap">
        <div className="who">
          <b>{isClient ? 'العميل' : m.name}</b>
          {m.role && <span className={`role ${actor}`}>{m.role}</span>}
          <time>{m.time}</time>
        </div>
        <div className="bubble" dangerouslySetInnerHTML={{ __html: m.text }} />
        <MsgMeta m={m} />
      </div>
    </div>
  );
};

const EmployeeTicketChat: React.FC<{
  ticket: EmpTicket;
  channel: string;
  messages: Message[];
  states: StateOption[];
  lawyers: LawyerOption[];
  clientStats?: ClientStats | null;
}> = ({ ticket, channel, messages, states, lawyers, clientStats }) => {
  const toast = useToast();
  const can = useCan();
  // الأزرار تُخفى بحسب الصلاحية التفصيلية — كانت تُعرض للجميع ثم يُبتلع رفض الخادم
  const canReply = can('الرد على العملاء');
  const canSchedule = can('جدولة المواعيد');
  const canTransfer = can('تحويل التذاكر');
  const [msgs, setMsgs] = useState<Message[]>(messages);
  const [status, setStatus] = useState({ status: ticket.status, tone: ticket.tone });
  // مؤلّف بمبدّل وضع (يطابق التصميم): ردّ للعميل ⇄ ملاحظة داخلية — صندوق واحد
  const [mode, setMode] = useState<'reply' | 'note'>(canReply ? 'reply' : 'note');
  const [body, setBody] = useState('');
  // يتغيّر مع كل ضغطة في وضع الردّ → يبثّ إشارة «يكتب» لزملائه (منع الردّ المزدوج)
  const [typingSignal, setTypingSignal] = useState(0);
  const endRef = useRef<HTMLDivElement>(null);
  const seen = useRef<Set<number>>(new Set(messages.map((m) => m.id).filter(Boolean) as number[]));

  // ── مودال جدولة الموعد المضمّن (يطابق التصميم المرجعي) ──
  const [schedOpen, setSchedOpen] = useState(false);
  const [schedLawyerId, setSchedLawyerId] = useState<string>(ticket.lawyerId ? String(ticket.lawyerId) : (lawyers[0]?.id ? String(lawyers[0].id) : ''));
  const [schedType, setSchedType] = useState<'office' | 'video' | 'phone'>('office');
  const [schedDate, setSchedDate] = useState('');
  const [schedTime, setSchedTime] = useState('');
  const [schedBusy, setSchedBusy] = useState(false);
  // الفترات المتاحة: تُجلب من API عند تغيير المحامي أو التاريخ
  const [slots, setSlots] = useState<{ time: string; taken: boolean }[]>([]);
  const [slotsLoading, setSlotsLoading] = useState(false);

  const fetchSlots = (lawyerId: string, date: string) => {
    if (!lawyerId || !date) { setSlots([]); return; }
    setSlotsLoading(true);
    axios.get('/employee/schedule/slots', { params: { lawyer_id: lawyerId, date } })
      .then((r) => { setSlots(r.data.slots ?? []); setSchedTime(''); })
      .catch(() => setSlots([]))
      .finally(() => setSlotsLoading(false));
  };

  const openSchedule = () => {
    setSchedDate('');
    setSchedTime('');
    setSlots([]);
    setSchedBusy(false);
    setSchedOpen(true);
  };

  // ── مودالا التحويل وطلب النواقص — نسخة مشتركة واحدة (TicketOpsModals) ──
  const [opsKind, setOpsKind] = useState<TicketOpsKind>(null);
  const openTransfer = () => setOpsKind('transfer');
  const openReqDocs = () => setOpsKind('reqdocs');

  const submitSchedule = () => {
    if (!schedDate || !schedTime) { toast('يرجى اختيار التاريخ والوقت'); return; }
    // حجب الأوقات الماضية على تاريخ اليوم (الحارس الخادمي isPast() يبقى شبكة أمان)
    if (schedDate === todayISO() && schedTime <= new Date().toTimeString().slice(0, 5)) {
      toast('لا يمكن اختيار وقت ماضٍ، فضلاً اختر وقتاً لاحقاً');
      return;
    }
    setSchedBusy(true);
    axios.post('/employee/schedule', {
      client_id: ticket.clientId,
      lawyer_id: schedLawyerId || null,
      type: schedType,
      subject: ticket.type,
      date: schedDate,
      time: schedTime,
      // بدونه تبقى التذكرة عالقة في «بانتظار حجز الاستشارة» بلا مخرج رغم رسالة النجاح
      ticket_no: ticket.no,
    }).then(() => {
      toast('✅ تم إنشاء موعد الاستشارة بنجاح');
      setSchedOpen(false);
    }).catch((err) => {
      const msg = err.response?.data?.message || 'تعذّر إنشاء الموعد، تحقق من البيانات';
      toast(`⚠️ ${msg}`);
    }).finally(() => setSchedBusy(false));
  };

  // الاشتراك في قناة التذكرة + قناة الملاحظات الداخلية + تحديثات الحالة
  useEffect(() => {
    const append = (e: { message: Message }) => {
      const m = e.message;
      if (m.id && seen.current.has(m.id)) return;
      if (m.id) seen.current.add(m.id);
      setMsgs((prev) => [...prev, m]);
    };
    const ch = echo.private(channel);
    ch.listen('.message', append);
    ch.listen('.status', (e: { status: string; tone: string }) => setStatus(e));
    echo.private(`${channel}.staff`).listen('.message', append);
    return () => { echo.leave(channel); echo.leave(`${channel}.staff`); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel]);

  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, [msgs]);

  // إرسال موحّد: يوجّه للنقطة القائمة بحسب الوضع (ردّ أو ملاحظة)
  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const v = body.trim(); if (!v) return;
    const endpoint = mode === 'reply' ? 'reply' : 'note';
    // لا يُمسح النص إلا بعد نجاح الإرسال فعلاً — كان يضيع عند أي فشل (صلاحية/تحقّق/خادم)
    axios.post(`/employee/tickets/${encodeURIComponent(ticket.no)}/${endpoint}`, { body: v })
      .then(() => setBody(''))
      .catch((err) => {
        const msg = err.response?.data?.message || 'تعذّر الإرسال';
        toast(`⚠️ ${msg}`);
      });
  };
  // إرفاق مستند من الموظف — نفس قيود رفع العميل (الصيغ + 10MB)؛ الرسالة تصل عبر البثّ
  const fileRef = useRef<HTMLInputElement>(null);
  const [attachBusy, setAttachBusy] = useState(false);
  const attachFile = (f: File) => {
    setAttachBusy(true);
    const fd = new FormData();
    fd.append('file', f);
    axios.post(`/employee/tickets/${encodeURIComponent(ticket.no)}/attach`, fd)
      .then(() => toast('✅ تم إرفاق المستند بالتذكرة'))
      .catch((err) => {
        const msg = err.response?.data?.message || 'تعذّر إرفاق المستند (الصيغ المسموحة: PDF/JPG/PNG/DOC — حتى 10MB)';
        toast(`⚠️ ${msg}`);
      })
      .finally(() => setAttachBusy(false));
  };

  const rerunAi = () => {
    axios.post(`/employee/tickets/${encodeURIComponent(ticket.no)}/rerun`)
      .then(() => toast('تمت إعادة تشغيل التحليل الذكي للملخّص'))
      .catch((err) => {
        const msg = err.response?.data?.message || 'تعذّر إعادة تشغيل التحليل حالياً';
        toast(`⚠️ ${msg}`);
      });
  };
  const changeStatus = (s: string) => {
    // النغمة يشتقّها الخادم من TicketJourney — لا تُرسل من هنا كي لا تُلوَّن الحالة نفسها لونين
    axios.post(`/employee/tickets/${encodeURIComponent(ticket.no)}/status`, { status: s })
      .catch((err) => {
        const msg = err.response?.data?.message || 'تعذّر تغيير الحالة';
        toast(`⚠️ ${msg}`);
      });
  };
  const advance = () => {
    axios.post(`/employee/tickets/${encodeURIComponent(ticket.no)}/advance`)
      .catch((err) => {
        const msg = err.response?.data?.message || 'تعذّر تنفيذ المرحلة التالية';
        toast(`⚠️ ${msg}`);
      });
  };
  const cur = tktStage(status.status);
  const isLast = cur >= TKT_LIFE.length - 1;
  // مراحل بيد المحامي/الإدارة/العميل — لا يتقدّم الموظف فيها
  // يطابق TicketJourney::AWAITING_OTHERS على الخادم
  const WAITING: Record<string, string> = {
    'بانتظار اعتماد المستشار': 'بانتظار اعتماد المستشار',
    'بانتظار حجز الاستشارة': 'بانتظار حجز العميل',
    'بانتظار الدفع': 'بانتظار سداد العميل',
    'بانتظار اعتماد النتيجة': 'بانتظار اعتماد المستشار',
    'بانتظار اعتماد الإدارة': 'بانتظار اعتماد الإدارة',
  };
  const waiting = WAITING[status.status];
  // يطابق TicketJourney::SESSION_READY — الإجراء هنا عقد الجلسة، لا القفز إلى «النتيجة».
  const SESSION_ACTION: Record<string, string> = {
    'موعد مؤكد': 'عقد الجلسة وتوثيق المحضر',
    'قيد التنفيذ': 'عقد الجلسة وتوثيق المحضر',
  };
  const sessionAction = SESSION_ACTION[status.status];

  return (
    <div className="tflow">
      {/* ── مودال جدولة الموعد (يطابق التصميم المرجعي: فوق المحادثة بلا مغادرة الصفحة) ── */}
      <Modal title={`جدولة موعد — ${ticket.no}`} open={schedOpen} onClose={() => setSchedOpen(false)}>
        <div className="field">
          <label>المستشار القانوني</label>
          <select
            value={schedLawyerId}
            onChange={(e) => { setSchedLawyerId(e.target.value); fetchSlots(e.target.value, schedDate); }}
          >
            <option value="">توزيع تلقائي</option>
            {lawyers.map((l) => (
              <option key={l.id} value={String(l.id)}>{l.name}</option>
            ))}
          </select>
        </div>
        <div className="field">
          <label>نوع الاستشارة</label>
          <select value={schedType} onChange={(e) => setSchedType(e.target.value as 'office' | 'video' | 'phone')}>
            <option value="office">حضورية</option>
            <option value="video">مرئية</option>
            <option value="phone">هاتفية</option>
          </select>
        </div>
        <div className="field">
          <label>تاريخ الموعد</label>
          <input
            type="date"
            value={schedDate}
            min={new Date().toISOString().split('T')[0]}
            onChange={(e) => { setSchedDate(e.target.value); fetchSlots(schedLawyerId, e.target.value); }}
          />
        </div>
        {/* شبكة الفترات المتاحة للموعد */}
        <TimeSlotPicker
          value={schedTime}
          onChange={setSchedTime}
          date={schedDate}
          slots={schedLawyerId && slots.length > 0 ? slots : undefined}
          label={schedLawyerId ? 'الوقت المتاح للمستشار' : 'وقت الموعد المقترح'}
          helperText={slotsLoading ? 'جاري التحقق من أوقات المستشار المتاحة...' : undefined}
          required
        />
        <button className="btn block" type="button" onClick={submitSchedule} disabled={schedBusy || !schedTime}>
          <Icon name="calplus" /> {schedBusy ? 'جاري الحجز…' : 'تأكيد الجدولة'}
        </button>
      </Modal>

      {/* ── مودالا التحويل وطلب النواقص (نسخة مشتركة مع قائمة التذاكر) ── */}
      <TicketOpsModals
        kind={opsKind}
        ticketNo={ticket.no}
        dept={ticket.dept}
        lawyerId={ticket.lawyerId ?? null}
        lawyers={lawyers}
        onClose={() => setOpsKind(null)}
        onDone={() => router.reload({ only: ['ticket', 'messages'] })}
      />

      <div style={{ marginBottom: 14 }}>
        <Link href="/employee/tickets" className="btn soft sm"><Icon name="reply" /> رجوع لكل التذاكر</Link>
      </div>

      {/* مسار المعالجة + تنفيذ المرحلة التالية */}
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>مسار المعالجة</h3>
          {/* الانتظار يُفحص أولاً: «بانتظار اعتماد الإدارة» فهرسها 6 فكانت تُعرض «مكتملة» خضراء خطأً */}
          {waiting
            ? <Badge text={waiting} tone="b-amber" />
            : isLast
              ? <Badge text={status.status} tone={status.tone} />
              : <button className="btn sm" type="button" onClick={advance}><Icon name="check" /> {sessionAction ?? `تنفيذ المرحلة التالية: ${TKT_LIFE[cur + 1]}`}</button>}
        </div>
        <div className="card-b" style={{ padding: '16px 18px' }}>
          <FlowLine steps={TKT_LIFE} cur={cur} />
        </div>
      </div>

      <div className="tf-grid">
        <div>
          <div className="card">
            <div className="card-h">
              <h3>محادثة التذكرة {ticket.no}</h3>
              <Badge text={status.status} tone={status.tone} />
            </div>
            {/* تنبيه فور الدخول: زميل داخل المحادثة الآن — يمنع الردّ المزدوج */}
            <TicketTalkingNotice channel={channel} canReply typingSignal={typingSignal} />

            <div className="thread">
              {msgs.map((m, i) => <MsgRow key={m.id ?? i} m={m} />)}
              <div ref={endRef} />
            </div>

            <div className="composer">
              {/* مبدّل الوضع (يطابق التصميم .cmode): ردّ للعميل ⇄ ملاحظة داخلية */}
              <div className="cmode">
                {canReply && (
                  <button type="button" className={mode === 'reply' ? 'on' : ''} onClick={() => setMode('reply')}>
                    <Icon name="reply" /> رد على العميل
                  </button>
                )}
                <button type="button" className={mode === 'note' ? 'on note-on' : ''} onClick={() => setMode('note')}>
                  <Icon name="lock" /> ملاحظة داخلية
                </button>
              </div>

              <form onSubmit={submit}>
                <textarea
                  value={body}
                  onChange={(e) => {
                    setBody(e.target.value);
                    if (mode === 'reply') setTypingSignal((n) => n + 1); // الهمس في وضع الردّ فقط
                  }}
                  placeholder={mode === 'reply' ? 'اكتب ردك للعميل…' : 'اكتب ملاحظة داخلية لا تظهر للعميل…'}
                />
                <div className="crow">
                  <button className={mode === 'reply' ? 'btn' : 'btn soft'} type="submit">
                    <Icon name={mode === 'reply' ? 'send' : 'doc'} /> {mode === 'reply' ? 'إرسال الرد' : 'حفظ الملاحظة'}
                  </button>
                  {/* إرفاق مستند من المكتب (يطابق زر «إرفاق» المرجعي) — بصلاحية الرد على العملاء */}
                  {canReply && (
                    <>
                      <button className="btn soft" type="button" onClick={() => fileRef.current?.click()} disabled={attachBusy}>
                        <Icon name="upload" /> {attachBusy ? 'جاري الرفع…' : 'إرفاق'}
                      </button>
                      <input
                        ref={fileRef}
                        type="file"
                        hidden
                        accept={ALLOWED_DOC_ACCEPT}
                        onChange={(e) => {
                          const f = e.target.files?.[0];
                          if (f) attachFile(f);
                          e.target.value = '';
                        }}
                      />
                    </>
                  )}
                </div>
              </form>
            </div>
          </div>
        </div>

        <aside className="tf-aside">
          <div className="card">
            <div className="tc-top"><div className="lbl">التذكرة</div><div className="num">{ticket.no}</div></div>
            <div className="tc-body">
              <div className="tc-row"><span className="k">العميل</span><span className="v">{ticket.client}</span></div>
              <div className="tc-row"><span className="k">النوع</span><span className="v">{ticket.type}</span></div>
              <div className="tc-row"><span className="k">القسم</span><span className="v">{ticket.dept}</span></div>
              <div className="tc-row"><span className="k">المحامي</span><span className="v">{ticket.lawyer}</span></div>
              {ticket.openedAt && <div className="tc-row"><span className="k">تاريخ الفتح</span><span className="v">{ticket.openedAt}</span></div>}
              <div className="tc-row"><span className="k">وقت العميل</span><span className="v">{nowClock()}</span></div>
            </div>
          </div>

          {/* تفاصيل الطلب (يطابق tkDetailsCard المرجعي): موضوع/قسم/خدمة/جوال/أهمية — بجانب المحادثة */}
          <TicketDetailsCard
            subject={ticket.subject}
            dept={ticket.dept}
            service={ticket.type}
            mobile={ticket.mobile}
            priority={ticket.priority}
          />

          {/* ملف العميل وسياقه 360 درجة */}
          {clientStats && (
            <div className="card">
              <div className="card-h">
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <Icon name="user" />
                  <h3>سياق العميل</h3>
                </div>
              </div>
              <div className="card-b" style={{ padding: '12px 16px' }}>
                <div className="tc-row" style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid var(--line-soft)', fontSize: 13 }}>
                  <span style={{ color: 'var(--muted)' }}>التذاكر النشطة:</span>
                  <b style={{ color: 'var(--primary)' }}>{clientStats.activeTickets} تذكرة</b>
                </div>
                <div className="tc-row" style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid var(--line-soft)', fontSize: 13 }}>
                  <span style={{ color: 'var(--muted)' }}>إجمالي القضايا:</span>
                  <b>{clientStats.totalCases} قضية</b>
                </div>
                <div className="tc-row" style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', fontSize: 13 }}>
                  <span style={{ color: 'var(--muted)' }}>عضو منذ:</span>
                  <span className="muted">{clientStats.memberSince}</span>
                </div>
                <div style={{ marginTop: 8, padding: '6px 10px', background: 'var(--paper-2)', borderRadius: 8, fontSize: 11, color: 'var(--muted)', display: 'flex', alignItems: 'center', gap: 6 }}>
                  <Icon name="lock" cls="ic sm" />
                  <span>المستندات والمرفقات سرية ومخصصة للمستشار والإدارة</span>
                </div>
              </div>
            </div>
          )}

          {/* لوحة إجراءات وتحويلات التذكرة الموحدة (المطابقة للتصميم المرجعي) */}
          <TicketActionsPanel
            ticketNo={ticket.no}
            status={status.status}
            caseRef={ticket.caseRef ?? null}
            role="employee"
            canConvert={ticket.summaryApproved ?? false}
            onRequestDocs={canReply ? openReqDocs : undefined}
            onSchedule={canSchedule ? openSchedule : undefined}
            onTransfer={canTransfer ? openTransfer : undefined}
          />

          <div className="card">
            <div className="card-h"><h3>الحالة والتحكم الإداري</h3></div>
            <div className="card-b" style={{ padding: 14 }}>
              {/* القائمة للتصحيح بنفس المرحلة فقط. الخيارات من مراحل أخرى معطّلة 🔒 */}
              <div className="field" style={{ marginBottom: 11 }}>
                <label>تغيير الحالة</label>
                <select value={status.status} onChange={(e) => changeStatus(e.target.value)}>
                  {states.map((o) => {
                    // نفس حرّاس الخادم: نفس المرحلة، وإن كانت التذكرة نهائية فلا خروج عن النهائيّتين
                    const locked = tktStage(o.status) !== cur
                      || (FINAL.includes(status.status) && !FINAL.includes(o.status));
                    return (
                      <option key={o.status} value={o.status} disabled={locked}>
                        {o.status}{locked ? ' 🔒' : ''}
                      </option>
                    );
                  })}
                </select>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                <button className="btn soft sm" type="button" onClick={rerunAi}><Icon name="sparkles" /> إعادة التحليل الذكي للملخص</button>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
};

export default EmployeeTicketChat;
