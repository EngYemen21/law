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

// يطابق clientTicketView + خطوات حجز الاستشارة (tfChooseConsult→tfInvoice→tfPaid→tfChooseSlot→tfConfirm)

interface TicketCard { no: string; type: string; status: string; tone: string; }

const AR_DAYS = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
const AR_MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
const TIMES = ['10:00 ص', '11:30 ص', '01:00 م', '02:30 م', '04:00 م', '05:30 م'];
const BRANCHES = ['الرياض — حي العليا', 'جدة — حي الروضة', 'مكة — العزيزية'];
const LAWYERS = ['أ. سارة القحطاني', 'أ. خالد المالكي', 'أ. ريم الزهراني'];
const TYPES: { key: string; label: string; ico: string; sub: string }[] = [
  { key: 'office', label: 'حضورية', ico: 'office', sub: 'في الفرع' },
  { key: 'video', label: 'مرئية', ico: 'video', sub: 'عبر الفيديو' },
  { key: 'phone', label: 'هاتفية', ico: 'phone', sub: 'اتصال مباشر' },
];

// عرض الاسم الأول فقط للمستشار (مع الإبقاء على القيمة الكاملة للخادم)
// «أ. سارة القحطاني» → «أ. سارة»
function lawyerFirst(name: string): string {
  const parts = name.trim().split(/\s+/);
  if (/^(أ|د|م|الأستاذ|الأستاذة|المحامي|المحامية)\.?$/.test(parts[0]) && parts.length > 1) {
    return `${parts[0]} ${parts[1]}`;
  }
  return parts[0] || name;
}

function upcomingDays(n: number): string[] {
  const out: string[] = [];
  const now = new Date();
  for (let i = 1; out.length < n; i++) {
    const x = new Date(now);
    x.setDate(now.getDate() + i);
    if (x.getDay() === 5 || x.getDay() === 6) continue; // تخطّي الجمعة/السبت
    out.push(`${AR_DAYS[x.getDay()]} ${x.getDate()} ${AR_MONTHS[x.getMonth()]}`);
  }
  return out;
}

// لوحة حجز الاستشارة داخل التذكرة — تظهر عند مرحلة «بانتظار حجز الاستشارة»
const BookConsult: React.FC<{ no: string }> = ({ no }) => {
  const toast = useToast();
  const days = upcomingDays(4);
  const [step, setStep] = useState<'type' | 'invoice' | 'slot'>('type');
  const [type, setType] = useState('');
  const [branch, setBranch] = useState(BRANCHES[0]);
  const [lawyer, setLawyer] = useState(LAWYERS[0]);
  const [day, setDay] = useState(days[0]);
  const [time, setTime] = useState('');
  const [busy, setBusy] = useState(false);

  const label = TYPES.find((t) => t.key === type)?.label || '';
  const price = CONSULT_PRICES[label] || 450;
  const vat = Math.round(price * VAT_RATE);
  const total = price + vat;

  const confirm = () => {
    setBusy(true);
    axios.post(`/tickets/${encodeURIComponent(no)}/book`, { type, day, time, branch, lawyer })
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
            <div className="picker-grid">
              {type === 'office' && (
                <div className="field">
                  <label>الفرع</label>
                  <select value={branch} onChange={(e) => setBranch(e.target.value)}>
                    {BRANCHES.map((b) => <option key={b} value={b}>{b}</option>)}
                  </select>
                </div>
              )}
              <div className="field">
                <label>المستشار المتاح</label>
                <select value={lawyer} onChange={(e) => setLawyer(e.target.value)}>
                  {LAWYERS.map((l) => <option key={l} value={l}>{lawyerFirst(l)}</option>)}
                </select>
              </div>
            </div>
            <div className="field" style={{ marginBottom: 6 }}>
              <label>اليوم</label>
              <select value={day} onChange={(e) => setDay(e.target.value)}>
                {days.map((d) => <option key={d} value={d}>{d}</option>)}
              </select>
            </div>
            <label style={{ display: 'block', fontSize: 13, fontWeight: 700, color: 'var(--ink)', margin: '13px 0 0' }}>الوقت المتاح</label>
            <div className="slots">
              {TIMES.map((t) => (
                <button key={t} type="button" className={`slot ${time === t ? 'sel' : ''}`} onClick={() => setTime(t)}>{t}</button>
              ))}
            </div>
            <div className="cal-note"><Icon name="check" /> تم التحقق من التوافر عبر Google Calendar</div>
            <button
              className="btn block"
              type="button"
              style={{ marginTop: 15, opacity: time && !busy ? 1 : 0.5 }}
              disabled={!time || busy}
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

  const showBooking = status.status === 'بانتظار حجز الاستشارة' || status.status === 'بانتظار حجز استشارة';

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
