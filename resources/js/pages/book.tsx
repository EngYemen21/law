import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import BookingActions from '@/components/babylon/BookingActions';
import { useToast } from '@/components/babylon/Toast';
import type { ConsultCard } from '@/lib/consult-ui';
import { crChannelIcon, crChannelTone } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { SVC, SVC_GROUPS } from '@/lib/newticket-data';

// حجز استشارة — يطابق viewBook/bkSubmit في التصميم المرجعي حرفياً (نموذج واحد: نوع القضية +
// نوع الاستشارة + وصف موجز، فوق قائمة طلبات الحجز الحالية)، بتنفيذ حقيقي بالكامل خلف الكواليس:
// المكتب يُسعّر → فاتورة حقيقية → دفع فعلي عبر ميسّر → اختيار موعد حقيقي بمنع تعارض حقيقي.

const CHANNELS: [string, string][] = [
  ['office', 'حضورية'],
  ['video', 'مرئية'],
  ['phone', 'هاتفية'],
];

const OTHER = '__other__';

interface Props {
  pending: ConsultCard[];
}

const Book: React.FC<Props> = ({ pending }) => {
  const toast = useToast();
  const [channel, setChannel] = useState('');
  const [caseType, setCaseType] = useState('');
  const [otherType, setOtherType] = useState('');
  const [subject, setSubject] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = () => {
    if (!caseType) { toast('اختر نوع القضية'); return; }
    if (caseType === OTHER && !otherType.trim()) { toast('اكتب نوع القضية'); return; }
    if (!channel) { toast('اختر نوع الاستشارة'); return; }

    const svc = caseType !== OTHER ? SVC[caseType] : null;
    const caseLabel = svc ? svc.label : otherType.trim();
    const specialty = svc ? svc.dept : otherType.trim();
    const composedSubject = subject.trim() ? `${caseLabel} — ${subject.trim()}` : caseLabel;

    setBusy(true);
    router.post('/book', { type: channel, subject: composedSubject, specialty }, {
      onFinish: () => setBusy(false),
      onSuccess: () => toast('أُرسل طلبك — بانتظار تحديد السعر من الإدارة'),
      onError: (e) => toast(e.type || e.specialty || 'تعذّر إرسال الطلب'),
    });
  };

  return (
    <>
      <div className="greet">
        <h2>حجز استشارة</h2>
        <p>اختر نوع القضية ونوع الاستشارة، وستحدّد الإدارة السعر المناسب قبل السداد واختيار الموعد.</p>
      </div>

      {pending.length > 0 && (
        <div className="card" style={{ marginBottom: 14 }}>
          <div className="card-h">
            <h3>طلبات الاستشارة</h3>
            <span className="sub">{pending.length}</span>
          </div>
          <div className="card-b">
            {pending.map((c) => (
              <div key={c.ref} className="item">
                <div className="iico"><Icon name={crChannelIcon(c.channel)} /></div>
                <div className="imeta">
                  <b>{c.ref} — {c.subject}</b>
                  <span style={{ display: 'block', margin: '3px 0' }}>
                    <Badge text={c.channel} tone={crChannelTone(c.channel)} />
                    {c.priced ? ` · ${c.total} ر.س شامل الضريبة` : ' · بانتظار تحديد السعر من الإدارة'}
                  </span>
                </div>
                <div className="iact">
                  <BookingActions c={c} toast={toast} />
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="card">
        <div className="card-h"><h3>طلب استشارة جديدة</h3></div>
        <div className="card-b" style={{ padding: 18 }}>
          <div className="field">
            <label>نوع القضية</label>
            <select className="input" value={caseType} onChange={(e) => setCaseType(e.target.value)}>
              <option value="">— اختر نوع القضية —</option>
              {SVC_GROUPS.map(([group, keys]) => (
                <optgroup key={group} label={group}>
                  {keys.map((k) => <option key={k} value={k}>{SVC[k].label}</option>)}
                </optgroup>
              ))}
              <option value={OTHER}>أخرى</option>
            </select>
          </div>
          {caseType === OTHER && (
            <div className="field"><label>اكتب نوع القضية</label><input className="input" value={otherType} onChange={(e) => setOtherType(e.target.value)} placeholder="مثال: نزاع تأمين طبي" /></div>
          )}
          <div className="field">
            <label>نوع الاستشارة</label>
            <select className="input" value={channel} onChange={(e) => setChannel(e.target.value)}>
              <option value="">— اختر نوع الاستشارة —</option>
              {CHANNELS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div className="field">
            <label>وصف موجز للطلب</label>
            <textarea className="input" rows={3} value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="اشرح موضوع استشارتك" />
          </div>
          <button className="btn" onClick={submit} type="button" disabled={busy}>
            <Icon name="send" /> {busy ? 'جارٍ الإرسال…' : 'إرسال الطلب للإدارة'}
          </button>
          <div className="action-hint" style={{ marginTop: 10 }}>
            <Icon name="info" /> ترسل طلبك ← تحدّد الإدارة سعر الاستشارة ← تسدّد فعليًا عبر ميسّر ← تختار الموعد من الفترات المتاحة (يُسند لك النظام أفضل مستشار مختص متاح تلقائياً).
          </div>
        </div>
      </div>
    </>
  );
};

export default Book;
