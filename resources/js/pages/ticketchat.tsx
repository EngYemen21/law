import axios from 'axios';
import { Link, router } from '@inertiajs/react';
import React, { useEffect, useRef, useState } from 'react';
import DetailShell from '@/components/babylon/DetailShell';
import ChatThread from '@/components/babylon/ChatThread';
import FlowLine from '@/components/babylon/FlowLine';
import Badge from '@/components/babylon/Badge';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { TKT_LIFE, tktStage, type Message } from '@/lib/chat';
import { echo } from '@/lib/echo';
import { useServerAction } from '@/lib/use-server-action';
// بطاقة العميل من النوع المشترك (`Ticket::toCard`) — كانت مُعرَّفةً هنا وفي الصفحة الأخرى
import type { ClientTicketCard as TicketCard } from '@/types';

// يطابق clientTicketView + خطوات حجز الاستشارة (tfChooseConsult→tfInvoice→tfPaid→tfChooseSlot→tfConfirm)
// دورة الحجز مقودة من الخادم عبر حالة الاستشارة المرتبطة (consult): تسعير الإدارة → فاتورة → دفع محاكى → موعد.
interface ConsultLink {
  id: number; ref: string; status: string; channel: string; statusCode?: string;
  price?: number; vat?: number; total?: number; priced?: boolean; paid?: boolean; invoiceNo?: string | null;
  /** النسبة المطبَّقة على هذه الاستشارة (`Consult::vatRate`) — لا «15%» منقوشة تخالف مبلغ الضريبة. */
  vatRate?: number | null;
}

const TYPES: { key: string; label: string; ico: string; sub: string }[] = [
  { key: 'office', label: 'حضورية', ico: 'office', sub: 'في مقرّ المكتب' },
  { key: 'video', label: 'مرئية', ico: 'video', sub: 'عبر الفيديو' },
  { key: 'phone', label: 'هاتفية', ico: 'phone', sub: 'اتصال مباشر' },
];

// لوحة حجز الاستشارة داخل التذكرة — مقودة من الخادم بحسب حالة الاستشارة المرتبطة.
const BookConsult: React.FC<{ no: string; consult?: ConsultLink | null }> = ({ no, consult }) => {
  const toast = useToast();
  const [type, setType] = useState('');
  const [requesting, setBusy] = useState(false);
  // قفلٌ موحّد للدفع — نقرتان لا تفتحان جلستَي دفع
  const payment = useServerAction();
  const busy = requesting || payment.busy;
  const cardRef = useRef<HTMLDivElement>(null);

  // بثّ لحظي لتقدّم الاستشارة (تسعير الإدارة/السداد) — يعيد تحميل الحقول من الخادم
  useEffect(() => {
    if (!consult?.id) return;
    echo.private(`consult.${consult.id}`).listen('.status', () => router.reload({ only: ['consult', 'messages', 'ticket'] }));
    return () => echo.leave(`consult.${consult.id}`);
  }, [consult?.id]);

  const status = consult?.status ?? null;

  // عند وصول خطوة اختيار الموعد (بعد الدفع) — التمرير لأعلى ليظهر قسم حجز الموعد بدل بقاء الشاشة أسفل الدردشة
  useEffect(() => {
    if (status === 'بانتظار تحديد الموعد') {
      cardRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }, [status]);

  // الخطوة 1: طلب الاستشارة (النوع فقط) → يُرسل للتسعير
  const requestConsult = () => {
    if (!type) { toast('اختر نوع الاستشارة'); return; }
    setBusy(true);
    axios.post(`/tickets/${encodeURIComponent(no)}/book`, { type })
      .then(() => { toast('تم إرسال طلبك للتسعير'); router.reload({ only: ['consult', 'messages', 'ticket'] }); })
      .catch(() => { setBusy(false); toast('تعذّر إرسال الطلب، حاول مجدداً'); });
  };

  const pay = () => {
    if (!consult) return;
    // النجاح = تحويل المتصفّح لصفحة ميسّر (Inertia::location) — لا توست «تم السداد» هنا،
    // فـ back()->with('error') عند تعذّر بدء الدفع استجابة ناجحة أيضاً وكانت تُظهر نجاحاً كاذباً.
    void payment.run(`/consults/${consult.id}/pay`, { fallback: 'تعذّر بدء الدفع، حاول بعد قليل' });
  };

  const badge = !status ? 'اختر النوع'
    : status === 'بانتظار التسعير' ? 'بانتظار التسعير'
      : status === 'بانتظار السداد' ? 'بانتظار السداد' : 'قيد تحديد الموعد';

  // المعرّف book-consult هو هدف تمرير زرّ «حجز موعد الاستشارة» في بطاقة قرار الإدارة أعلى الصفحة
  return (
    <div className="card" id="book-consult" ref={cardRef} style={{ marginBottom: 16 }}>
      <div className="card-h">
        <h3>حجز موعد الاستشارة</h3>
        <Badge text={badge} tone="b-amber" />
      </div>
      <div className="card-b" style={{ padding: 18 }}>

        {/* الخطوة 1: اختيار النوع وإرسال الطلب للتسعير */}
        {!status && (
          <>
            <div className="choices" style={{ marginBottom: 14 }}>
              {TYPES.map((t) => (
                <div key={t.key} className={`choice ${type === t.key ? 'sel' : ''}`} onClick={() => setType(t.key)} role="button">
                  <div className="cico"><Icon name={t.ico} /></div>
                  <b>{t.label}</b>
                  <span>{t.sub}</span>
                </div>
              ))}
            </div>
            <button className="btn block" type="button" disabled={!type || busy} style={{ opacity: type && !busy ? 1 : 0.5 }} onClick={requestConsult}>
              إرسال الطلب لتحديد السعر
            </button>
          </>
        )}

        {/* بانتظار تسعير المكتب */}
        {status === 'بانتظار التسعير' && (
          <div className="action-hint" style={{ textAlign: 'center', padding: 14 }}>
            <Icon name="clock" /> طلبك ({consult?.ref}) قيد المراجعة لدى المكتب لتحديد سعر الاستشارة. ستصلك الفاتورة فور تحديده.
          </div>
        )}

        {/* الفاتورة + الدفع المحاكى */}
        {status === 'بانتظار السداد' && (
          <>
            <div className="invoice">
              <div className="inv-head"><b>فاتورة استشارة قانونية</b><span>{consult?.invoiceNo ?? consult?.ref}</span></div>
              <div className="inv-body">
                <div className="inv-row"><span className="lbl">استشارة {consult?.channel}</span><span>{consult?.price} ر.س</span></div>
                <div className="inv-row"><span className="lbl">ضريبة القيمة المضافة ({consult?.vatRate}%)</span><span>{consult?.vat} ر.س</span></div>
                <div className="inv-row total"><span>الإجمالي</span><span>{consult?.total} ر.س</span></div>
              </div>
            </div>
            <button className="btn block" type="button" disabled={busy} style={{ marginTop: 14, opacity: busy ? 0.5 : 1 }} onClick={pay}>
              <Icon name="card" /> الدفع الآن عبر ميسّر — {consult?.total} ر.س
            </button>
          </>
        )}

        {/* بعد السداد: المكتب يحدّد الموعد (قرار المالك 2026-09-14) — لا جدول حجز للعميل */}
        {status === 'بانتظار تحديد الموعد' && (
          <div className="action-hint" style={{ textAlign: 'center', padding: 14 }}>
            <Icon name="clock" /> سوف يتم تحديد موعد جلسة استشارية مع المستشار المختص وثمّ تزويدك بالموعد المحدد
          </div>
        )}

      </div>
    </div>
  );
};

const TicketChat: React.FC<{ ticket: TicketCard; channel: string; messages: Message[]; consult?: ConsultLink | null }> = ({ ticket, channel, messages, consult }) => {
  // الحالة لحظية: تتحدّث عبر بثّ القناة فيتقدّم المسار دون إعادة تحميل
  const [status, setStatus] = useState({ status: ticket.status, tone: ticket.tone, isTerminal: Boolean(ticket.isTerminal) });

  // عند بثّ حالة التذكرة (تقدّم المسار خادميّاً) نعيد جلب الاستشارة المرتبطة أيضاً — فتصل حقول
  // الفاتورة/السداد لحظياً ويُفعَّل زر «الدفع عبر ميسّر» دون إعادة تحميل يدوي للصفحة.
  // القناة مشتركة مع الطاقم: `status` داخليّ، والعميل يقرأ `clientStatus` (قيد إعداد الرأي القانوني…)
  const onStatus = (s: { status: string; tone: string; clientStatus?: string; isTerminal?: boolean }) => {
    setStatus({ status: s.clientStatus ?? s.status, tone: s.tone, isTerminal: Boolean(s.isTerminal) });
    router.reload({ only: ['consult'] });
  };

  // تُعرض لوحة الحجز بعد نشر الرأي القانونيّ المبدئيّ (يُطلب منها)، أو ما دامت هناك استشارة قيد
  // التسعير/السداد/تحديد الموعد — «المرحلة التالية» لم تعد تنقل التذكرة إلى «بانتظار حجز الاستشارة».
  const bookingActive = consult && ['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد'].includes(consult.status);
  const canRequest = ticket.actions?.can_request_consult ?? (
    ['الرأي القانوني', 'بانتظار حجز الاستشارة'].includes(status.status)
    && (!consult || ['منتهية', 'ملغاة', 'لم يحضر'].includes(consult.status))
  );
  const showBooking = canRequest || !!bookingActive;

  // حكم الخادم (`TicketStatus::isTerminal`) — من الصفحة ثمّ من البثّ. كانت قائمةٌ داخليّة تُقارَن بتسمية
  // العميل (`clientStatus`) فلا تصدق أبداً
  const isTerminal = Boolean(status.isTerminal || ticket.isFrozen);

  const topExtra = (
    <>
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>التذكرة {ticket.no}</h3>
          <Badge text={status.status} tone={status.tone} />
        </div>
        <div className="card-b" style={{ padding: '16px 18px' }}>
          <FlowLine steps={TKT_LIFE} cur={tktStage(status.status)} />
        </div>
      </div>

      {/* ── بطاقات توجيه وقرارات الإدارة العليا المعتمدة للعميل مع بيان السبب الحقيقي ── */}
      {(ticket.trackGovernance?.approvedTrack === 'execution' || status.status === 'محولة إلى تنفيذ' || ticket.hasExecution) && (
        <div className="card" style={{ marginBottom: 16, borderInlineStart: '4px solid var(--amber, #d97706)', background: '#fffbeb' }}>
          <div className="card-b" style={{ padding: '14px 18px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10, minWidth: 0, flex: '1 1 240px' }}>
              <div style={{ flexShrink: 0, marginTop: 2 }}><Icon name="card" /></div>
              <div style={{ minWidth: 0 }}>
                <b style={{ fontSize: 13.5, display: 'block', color: '#92400e', wordBreak: 'break-word' }}>قرار الإدارة العليا: تحويل الطلب إلى ملف تنفيذ قضائي</b>
                <span style={{ fontSize: 12.5, color: '#78350f', display: 'block', marginTop: 2, wordBreak: 'break-word', overflowWrap: 'break-word' }}>
                  <strong>السبب والمبرر النظامي:</strong> {ticket.trackGovernance?.approvedTrackReason || 'تبيّن حيازة سند تنفيذي مكتمل الأركان لمباشرة التنفيذ عبر منصة ناجز.'}
                </span>
              </div>
            </div>
            {/* رابط عميق لملف التنفيذ بمكون Link التابع لـ Inertia */}
            {ticket.executionNumber ? (
              <Link
                href={`/execs/${encodeURIComponent(ticket.executionNumber)}`}
                className="btn sm"
                style={{ whiteSpace: 'nowrap', background: '#d97706', color: '#fff', border: 'none', flexShrink: 0 }}
              >
                الانتقال لملف التنفيذ ←
              </Link>
            ) : (
              <Link
                href="/execs"
                className="btn sm"
                style={{ whiteSpace: 'nowrap', background: '#d97706', color: '#fff', border: 'none', flexShrink: 0 }}
              >
                الانتقال لملف التنفيذ ←
              </Link>
            )}
          </div>
        </div>
      )}

      {(status.status === 'محولة إلى قضية' || ticket.trackGovernance?.approvedTrack === 'case') && !ticket.hasExecution && status.status !== 'محولة إلى تنفيذ' && (
        <div className="card" style={{ marginBottom: 16, borderInlineStart: '4px solid var(--primary, #0e5c9c)' }}>
          <div className="card-b" style={{ padding: '14px 18px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10, minWidth: 0, flex: '1 1 240px' }}>
              <div style={{ flexShrink: 0, marginTop: 2 }}><Icon name="scale" /></div>
              <div style={{ minWidth: 0 }}>
                <b style={{ fontSize: 13.5, display: 'block', wordBreak: 'break-word' }}>قرار الإدارة العليا: تحويل الطلب إلى قضية رسمية</b>
                {ticket.trackGovernance?.approvedTrackReason ? (
                  <span style={{ fontSize: 12.5, color: 'var(--text-soft, #475569)', display: 'block', marginTop: 2, wordBreak: 'break-word', overflowWrap: 'break-word' }}>
                    <strong>السبب والمبرر النظامي:</strong> {ticket.trackGovernance.approvedTrackReason}
                  </span>
                ) : (
                  <span style={{ fontSize: 12, color: 'var(--muted)', wordBreak: 'break-word' }}>تم فتح ملف قضية لمتابعة الإجراءات القضائية، يمكنك متابعة المستجدات في قائمة القضايا.</span>
                )}
              </div>
            </div>
            {/* رابط عميق لملف القضية بمكون Link التابع لـ Inertia */}
            <Link
              href={ticket.caseNumber ? `/cases/${encodeURIComponent(ticket.caseNumber)}` : '/cases'}
              className="btn soft sm"
              style={{ whiteSpace: 'nowrap', flexShrink: 0 }}
            >
              الانتقال للقضية ←
            </Link>
          </div>
        </div>
      )}

      {ticket.trackGovernance?.approvedTrack === 'consultation' && ticket.trackGovernance?.approvedTrackReason && (
        <div className="card" style={{ marginBottom: 16, borderInlineStart: '4px solid #2563eb', background: '#eff6ff' }}>
          <div className="card-b" style={{ padding: '14px 18px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10, minWidth: 0, flex: '1 1 240px' }}>
              <div style={{ flexShrink: 0, marginTop: 2 }}><Icon name="chat" /></div>
              <div style={{ minWidth: 0 }}>
                <b style={{ fontSize: 13.5, display: 'block', color: '#1d4ed8', wordBreak: 'break-word' }}>قرار الإدارة العليا: توجيه بطلب استشارة قانونية</b>
                <span style={{ fontSize: 12.5, color: '#1e3a8a', display: 'block', marginTop: 2, wordBreak: 'break-word', overflowWrap: 'break-word' }}>
                  <strong>السبب والمبرر النظامي:</strong> {ticket.trackGovernance.approvedTrackReason}
                </span>
              </div>
            </div>
            <button
              type="button"
              className="btn sm"
              style={{ whiteSpace: 'nowrap', background: '#2563eb', color: '#fff', border: 'none', flexShrink: 0 }}
              onClick={() => {
                // المحدّد القديم (صنف book-consult) كان ميّتاً — لا عنصر يحمله فلا يفعل الزرّ شيئاً؛
                // الهدف الصحيح بطاقة الحجز ذات المعرّف book-consult في هذه الصفحة، وإن لم تكن
                // معروضة ننتقل لصفحة الحجز /book كما تفعل صفحة «طلباتي».
                const card = document.getElementById('book-consult');

                if (card) {
                  card.scrollIntoView({ behavior: 'smooth' });
                } else {
                  router.visit('/book');
                }
              }}
            >
              حجز موعد الاستشارة
            </button>
          </div>
        </div>
      )}

      {(status.status === 'مغلقة' || ticket.trackGovernance?.approvedTrack === 'close') && (
        <div className="card" style={{ marginBottom: 16, borderInlineStart: '4px solid var(--muted, #64748b)' }}>
          <div className="card-b" style={{ padding: '14px 18px', display: 'flex', alignItems: 'flex-start', gap: 10, minWidth: 0 }}>
            <div style={{ flexShrink: 0, marginTop: 2 }}><Icon name="check" /></div>
            <div style={{ minWidth: 0 }}>
              <b style={{ fontSize: 13.5, display: 'block', wordBreak: 'break-word' }}>اكتملت المعالجة وحُفظ الطلب بقرار مسبّب</b>
              <span style={{ fontSize: 12.5, color: 'var(--muted)', display: 'block', marginTop: 2, wordBreak: 'break-word', overflowWrap: 'break-word' }}>
                {ticket.trackGovernance?.approvedTrackReason ? (
                  <><strong>المبرر النظامي:</strong> {ticket.trackGovernance.approvedTrackReason}</>
                ) : (
                  'تم تقديم المشورة القانونية وإغلاق التذكرة بنجاح. السجل متاح للاطلاع في أي وقت.'
                )}
              </span>
            </div>
          </div>
        </div>
      )}

      {showBooking && <BookConsult no={ticket.no} consult={consult} />}
    </>
  );

  const send = (text: string) => axios.post(`/tickets/${encodeURIComponent(ticket.no)}/messages`, { body: text });

  // رفع مستند فعلي — يظهر للطرفين لحظياً
  const attach = (file?: File) => {
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    return axios.post(`/tickets/${encodeURIComponent(ticket.no)}/attach`, fd);
  };

  return (
    <DetailShell
      backHref="/tickets"
      backLabel="رجوع لكل التذاكر"
      title={`محادثة التذكرة ${ticket.no}`}
      no={ticket.no}
      noLabel="التذكرة"
      status={status.status}
      tone={status.tone}
      info={[['الحالة', status.status], ['النوع', ticket.type]]}
      topExtra={topExtra}
    >
      <ChatThread
        initial={messages}
        channel={channel}
        onSend={send}
        onAttach={attach}
        onStatus={onStatus}
        readOnly={isTerminal}
        placeholder={isTerminal ? 'التذكرة مكتملة وأرشيفها للقراءة فقط' : 'اكتب رسالتك للفريق القانوني…'}
        composerLabel="اكتب في التذكرة:"
      />
    </DetailShell>
  );
};

export default TicketChat;
