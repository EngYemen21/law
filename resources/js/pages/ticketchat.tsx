import axios from 'axios';
import { router } from '@inertiajs/react';
import React, { useEffect, useRef, useState } from 'react';
import DetailShell from '@/components/babylon/DetailShell';
import ChatThread from '@/components/babylon/ChatThread';
import FlowLine from '@/components/babylon/FlowLine';
import Badge from '@/components/babylon/Badge';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { TKT_LIFE, tktStage, type Message } from '@/lib/chat';
import { echo } from '@/lib/echo';
import SpecialistPicker, { todayISO } from '@/components/SpecialistPicker';

// يطابق clientTicketView + خطوات حجز الاستشارة (tfChooseConsult→tfInvoice→tfPaid→tfChooseSlot→tfConfirm)
// دورة الحجز مقودة من الخادم عبر حالة الاستشارة المرتبطة (consult): تسعير الإدارة → فاتورة → دفع محاكى → موعد.

interface TicketCard { no: string; type: string; status: string; tone: string; }
interface ConsultLink {
  id: number; ref: string; status: string; channel: string;
  price?: number; vat?: number; total?: number; priced?: boolean; paid?: boolean; invoiceNo?: string | null;
}

const TYPES: { key: string; label: string; ico: string; sub: string }[] = [
  { key: 'office', label: 'حضورية', ico: 'office', sub: 'في الفرع' },
  { key: 'video', label: 'مرئية', ico: 'video', sub: 'عبر الفيديو' },
  { key: 'phone', label: 'هاتفية', ico: 'phone', sub: 'اتصال مباشر' },
];

// لوحة حجز الاستشارة داخل التذكرة — مقودة من الخادم بحسب حالة الاستشارة المرتبطة.
const BookConsult: React.FC<{ no: string; consult?: ConsultLink | null }> = ({ no, consult }) => {
  const toast = useToast();
  const [type, setType] = useState('');
  const [date, setDate] = useState(todayISO());
  const [lawyerId, setLawyerId] = useState<number | null>(null);
  const [time, setTime] = useState('');
  const [busy, setBusy] = useState(false);
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
    setBusy(true);
    // النجاح = تحويل المتصفّح لصفحة ميسّر (Inertia::location) — لا توست «تم السداد» هنا،
    // فـ back()->with('error') عند تعذّر بدء الدفع استجابة ناجحة أيضاً وكانت تُظهر نجاحاً كاذباً.
    router.post(`/consults/${consult.id}/pay`, {}, {
      preserveScroll: true,
      onError: (errors) => toast(Object.values(errors)[0] ?? 'تعذّر بدء الدفع، حاول بعد قليل'),
      onFinish: () => setBusy(false),
    });
  };

  const confirmSlot = () => {
    if (!consult || !time) { toast('اختر موعداً متاحاً'); return; }
    setBusy(true);
    // الإسناد خادميّ (LawyerAvailability::assignLawyer) — لا يُرسل lawyer_id لأنه كان يُهمَل بالكامل
    router.post(`/consults/${consult.id}/schedule`, { date, time }, {
      preserveScroll: true,
      onSuccess: () => toast('تم تأكيد موعد الاستشارة'),
      onError: () => toast('تعذّر تأكيد الموعد، جرّب فترة أخرى'),
      onFinish: () => setBusy(false),
    });
  };

  const badge = !status ? 'اختر النوع'
    : status === 'بانتظار التسعير' ? 'بانتظار التسعير'
      : status === 'بانتظار السداد' ? 'بانتظار السداد' : 'اختر الموعد';

  return (
    <div className="card" ref={cardRef} style={{ marginBottom: 16 }}>
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
                <div className="inv-row"><span className="lbl">ضريبة القيمة المضافة (15%)</span><span>{consult?.vat} ر.س</span></div>
                <div className="inv-row total"><span>الإجمالي</span><span>{consult?.total} ر.س</span></div>
              </div>
            </div>
            <button className="btn block" type="button" disabled={busy} style={{ marginTop: 14, opacity: busy ? 0.5 : 1 }} onClick={pay}>
              <Icon name="card" /> الدفع الآن عبر ميسّر — {consult?.total} ر.س
            </button>
          </>
        )}

        {/* اختيار الموعد بعد السداد */}
        {status === 'بانتظار تحديد الموعد' && (
          <>
            <div className="field" style={{ marginBottom: 8 }}>
              <label>تاريخ الموعد</label>
              <input className="input" type="date" min={todayISO()} value={date}
                onChange={(e) => { setDate(e.target.value); setLawyerId(null); setTime(''); }} />
            </div>
            <SpecialistPicker
              fetchUrl={`/tickets/${encodeURIComponent(no)}/availability`}
              enabled autoAssign date={date} onDateSnap={setDate}
              lawyerId={lawyerId} onLawyerChange={setLawyerId}
              time={time} onTimeChange={setTime}
            />
            <button className="btn block" type="button" style={{ marginTop: 15, opacity: lawyerId && time && !busy ? 1 : 0.5 }}
              disabled={!lawyerId || !time || busy} onClick={confirmSlot}>
              <Icon name="cal" /> تأكيد الموعد
            </button>
          </>
        )}

      </div>
    </div>
  );
};

const TicketChat: React.FC<{ ticket: TicketCard; channel: string; messages: Message[]; consult?: ConsultLink | null }> = ({ ticket, channel, messages, consult }) => {
  // الحالة لحظية: تتحدّث عبر بثّ القناة فيتقدّم المسار دون إعادة تحميل
  const [status, setStatus] = useState({ status: ticket.status, tone: ticket.tone });

  // عند بثّ حالة التذكرة (تقدّم المسار خادميّاً) نعيد جلب الاستشارة المرتبطة أيضاً — فتصل حقول
  // الفاتورة/السداد لحظياً ويُفعَّل زر «الدفع عبر ميسّر» دون إعادة تحميل يدوي للصفحة.
  const onStatus = (s: { status: string; tone: string }) => {
    setStatus(s);
    router.reload({ only: ['consult'] });
  };

  // تُعرض لوحة الحجز عند مرحلة الحجز، أو ما دامت هناك استشارة قيد الحجز/الدفع/الجدولة
  const bookingActive = consult && ['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد'].includes(consult.status);
  const showBooking = status.status === 'بانتظار حجز الاستشارة' || !!bookingActive;

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
      {showBooking && <BookConsult no={ticket.no} consult={consult} />}
    </>
  );

  const send = (text: string) => {
    axios.post(`/tickets/${encodeURIComponent(ticket.no)}/messages`, { body: text });
  };

  // رفع مستند فعلي — يظهر للطرفين لحظياً
  const attach = (file?: File) => {
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    axios.post(`/tickets/${encodeURIComponent(ticket.no)}/attach`, fd);
  };

  return (
    <DetailShell
      backHref="/tickets"
      backLabel="رجوع لكل التذاكر"
      title={`محادثة التذكرة ${ticket.no}`}
      no={ticket.no}
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
        readOnly={['مكتملة', 'مغلقة'].includes(status.status)}
        placeholder="اكتب رسالتك للفريق القانوني…"
      />
    </DetailShell>
  );
};

export default TicketChat;
