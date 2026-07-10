import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

// حجز استشارة مباشر — يحفظ حجزاً حقيقياً (Consult + Appointment)

const TYPES: [string, string, string][] = [
  ['office', 'حضورية', 'زيارة المكتب والاجتماع مع المستشار'],
  ['video', 'مرئية', 'اجتماع إلكتروني عبر الفيديو'],
  ['phone', 'هاتفية', 'مكالمة هاتفية مباشرة'],
];

interface Props { prices: { office: number; video: number; phone: number; vat: number }; }

const Book: React.FC<Props> = ({ prices }) => {
  const toast = useToast();
  const [type, setType] = useState<string | null>(null);
  const [subject, setSubject] = useState('');
  const [day, setDay] = useState('');
  const [time, setTime] = useState('');
  const [busy, setBusy] = useState(false);

  const price = type ? (prices as Record<string, number>)[type] : 0;
  const vat = Math.round((price * prices.vat) / 100);

  const submit = () => {
    if (!type || !day.trim() || !time.trim()) { toast('اختر النوع واليوم والوقت'); return; }
    setBusy(true);
    router.post('/book', { type, day: day.trim(), time: time.trim(), subject: subject.trim() }, {
      onFinish: () => setBusy(false),
      onSuccess: () => toast('تم تأكيد حجز الاستشارة'),
    });
  };

  return (
    <>
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><h3>اختر نوع الاستشارة</h3></div>
        <div className="card-b" style={{ padding: 18 }}>
          <div className="consults">
            {TYPES.map(([ico, title, sub]) => {
              const p = (prices as Record<string, number>)[ico];
              return (
                <div key={ico} className={`consult${type === ico ? ' sel' : ''}`} style={type === ico ? { borderColor: 'var(--brand)' } : undefined}>
                  <div className="ci"><Icon name={ico} /></div>
                  <b>{title}</b>
                  <span>{sub}</span>
                  <span className="chip" style={{ margin: '6px 0' }}>{(p + Math.round(p * prices.vat / 100)).toLocaleString('en-US')} ر.س شامل الضريبة</span>
                  <button className="btn block" type="button" onClick={() => setType(ico)}>
                    {type === ico ? '✓ مختارة' : 'اختر'}
                  </button>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {type && (
        <div className="card">
          <div className="card-h"><h3>تفاصيل الموعد</h3><span className="sub">{price.toLocaleString('en-US')} + ضريبة {vat.toLocaleString('en-US')} ر.س</span></div>
          <div className="card-b" style={{ padding: 18 }}>
            <div className="field"><label>موضوع الاستشارة</label><input value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="مثال: نزاع تجاري مع مورّد" /></div>
            <div className="picker-grid">
              <div className="field"><label>اليوم</label><input value={day} onChange={(e) => setDay(e.target.value)} placeholder="مثال: الأحد 12 يوليو" /></div>
              <div className="field"><label>الوقت</label><input value={time} onChange={(e) => setTime(e.target.value)} placeholder="مثال: 11:00 ص" /></div>
            </div>
            <button className="btn block" style={{ marginTop: 8 }} onClick={submit} type="button" disabled={busy}>
              <Icon name="calplus" /> {busy ? 'جارٍ الحجز…' : 'تأكيد الحجز'}
            </button>
          </div>
        </div>
      )}
    </>
  );
};

export default Book;
