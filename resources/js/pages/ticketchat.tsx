import axios from 'axios';
import React, { useState } from 'react';
import DetailShell from '@/components/babylon/DetailShell';
import ChatThread from '@/components/babylon/ChatThread';
import FlowLine from '@/components/babylon/FlowLine';
import Badge from '@/components/babylon/Badge';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { TKT_LIFE, tktStage, type Message } from '@/lib/chat';
import { CONSULT_PRICES, VAT_RATE } from '@/lib/newticket-data';
import SpecialistPicker, { todayISO } from '@/components/SpecialistPicker';

// يطابق clientTicketView + خطوات حجز الاستشارة (tfChooseConsult→tfInvoice→tfPaid→tfChooseSlot→tfConfirm)

interface TicketCard { no: string; type: string; status: string; tone: string; }

const TYPES: { key: string; label: string; ico: string; sub: string }[] = [
  { key: 'office', label: 'حضورية', ico: 'office', sub: 'في الفرع' },
  { key: 'video', label: 'مرئية', ico: 'video', sub: 'عبر الفيديو' },
  { key: 'phone', label: 'هاتفية', ico: 'phone', sub: 'اتصال مباشر' },
];

// لوحة حجز الاستشارة داخل التذكرة — تظهر عند مرحلة «بانتظار حجز الاستشارة»
// المستشارون المتخصّصون بقسم التذكرة (مرتّبون بالذكاء الاصطناعي + سجلّ النجاح) وفتراتهم المتاحة الحقيقية.
const BookConsult: React.FC<{ no: string }> = ({ no }) => {
  const toast = useToast();
  const [step, setStep] = useState<'type' | 'invoice' | 'slot'>('type');
  const [type, setType] = useState('');
  const [date, setDate] = useState(todayISO());
  const [lawyerId, setLawyerId] = useState<number | null>(null);
  const [time, setTime] = useState('');
  const [busy, setBusy] = useState(false);

  const label = TYPES.find((t) => t.key === type)?.label || '';
  const price = CONSULT_PRICES[label] || 450;
  const vat = Math.round(price * VAT_RATE);
  const total = price + vat;

  const confirm = () => {
    if (!lawyerId || !time) { toast('اختر المستشار وموعداً متاحاً'); return; }
    setBusy(true);
    axios.post(`/tickets/${encodeURIComponent(no)}/book`, { type, lawyer_id: lawyerId, date, time })
      .then(() => toast('تم تأكيد موعد الاستشارة'))
      .catch(() => { setBusy(false); toast('تعذّر الحجز، حاول مجدداً'); });
  };

  return (
    <div className="card" style={{ marginBottom: 16 }}>
      <div className="card-h">
        <h3>حجز موعد الاستشارة</h3>
        <Badge text={step === 'type' ? 'اختر النوع' : step === 'invoice' ? 'بانتظار السداد' : 'اختر الموعد'} tone="b-amber" />
      </div>
      <div className="card-b" style={{ padding: 18 }}>

        {step === 'type' && (
          <>
            <div className="choices" style={{ marginBottom: 14 }}>
              {TYPES.map((t) => (
                <div
                  key={t.key}
                  className={`choice ${type === t.key ? 'sel' : ''}`}
                  onClick={() => setType(t.key)}
                  role="button"
                >
                  <div className="cico"><Icon name={t.ico} /></div>
                  <b>{t.label}</b>
                  <span>{t.sub}</span>
                </div>
              ))}
            </div>
            <button
              className="btn block"
              type="button"
              disabled={!type}
              style={{ opacity: type ? 1 : 0.5 }}
              onClick={() => setStep('invoice')}
            >
              متابعة لإصدار الفاتورة
            </button>
          </>
        )}

        {step === 'invoice' && (
          <>
            <div className="invoice">
              <div className="inv-head"><b>فاتورة استشارة قانونية</b><span>{no}</span></div>
              <div className="inv-body">
                <div className="inv-row"><span className="lbl">استشارة {label}</span><span>{price} ر.س</span></div>
                <div className="inv-row"><span className="lbl">ضريبة القيمة المضافة ({Math.round(VAT_RATE * 100)}%)</span><span>{vat} ر.س</span></div>
                <div className="inv-row total"><span>الإجمالي</span><span>{total} ر.س</span></div>
              </div>
            </div>
            <button className="btn block" type="button" style={{ marginTop: 14 }} onClick={() => { toast(`تم سداد ${total} ر.س`); setStep('slot'); }}>
              <Icon name="card" /> ادفع الآن عبر الرابط الآمن — {total} ر.س
            </button>
            <div className="action-hint">دفع إلكتروني محاكى — لن يتم خصم أي مبلغ.</div>
          </>
        )}

        {step === 'slot' && (
          <>
            <div className="field" style={{ marginBottom: 8 }}>
              <label>تاريخ الموعد</label>
              <input
                className="input"
                type="date"
                min={todayISO()}
                value={date}
                onChange={(e) => { setDate(e.target.value); setLawyerId(null); setTime(''); }}
              />
            </div>
            <SpecialistPicker
              fetchUrl={`/tickets/${encodeURIComponent(no)}/availability`}
              enabled
              date={date}
              onDateSnap={setDate}
              lawyerId={lawyerId}
              onLawyerChange={setLawyerId}
              time={time}
              onTimeChange={setTime}
            />
            <button
              className="btn block"
              type="button"
              style={{ marginTop: 15, opacity: lawyerId && time && !busy ? 1 : 0.5 }}
              disabled={!lawyerId || !time || busy}
              onClick={confirm}
            >
              <Icon name="cal" /> تأكيد الموعد
            </button>
          </>
        )}

      </div>
    </div>
  );
};

const TicketChat: React.FC<{ ticket: TicketCard; channel: string; messages: Message[] }> = ({ ticket, channel, messages }) => {
  // الحالة لحظية: تتحدّث عبر بثّ القناة فيتقدّم المسار دون إعادة تحميل
  const [status, setStatus] = useState({ status: ticket.status, tone: ticket.tone });

  const showBooking = status.status === 'بانتظار حجز الاستشارة';

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
      {showBooking && <BookConsult no={ticket.no} />}
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
        onStatus={setStatus}
        placeholder="اكتب رسالتك للفريق القانوني…"
      />
    </DetailShell>
  );
};

export default TicketChat;
