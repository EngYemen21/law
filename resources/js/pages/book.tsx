import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

// طلب استشارة ذكي — نوع + تخصّص + موضوع → يُرسل للمكتب لتحديد السعر، ثم تُكمل الرحلة في «استشاراتي»
// (فاتورة → دفع محاكى → اختيار المستشار والموعد). مطابق لتصميم رحلة الحجز.

const TYPES: [string, string, string][] = [
  ['office', 'حضورية', 'زيارة المكتب والاجتماع مع المستشار'],
  ['video', 'مرئية', 'اجتماع إلكتروني عبر الفيديو'],
  ['phone', 'هاتفية', 'مكالمة هاتفية مباشرة'],
];

interface Props {
  prices: { office: number; video: number; phone: number; vat: number };
  specialties: string[];
}

const Book: React.FC<Props> = ({ prices, specialties }) => {
  const toast = useToast();
  const [type, setType] = useState<string | null>(null);
  const [subject, setSubject] = useState('');
  const [specialty, setSpecialty] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = () => {
    if (!type) { toast('اختر نوع الاستشارة'); return; }
    if (!specialty) { toast('اختر التخصّص'); return; }
    setBusy(true);
    router.post('/book', { type, subject: subject.trim(), specialty }, {
      onFinish: () => setBusy(false),
      onSuccess: () => toast('تم إرسال طلبك — بانتظار تسعير المكتب'),
      onError: (e) => toast(e.type || e.specialty || 'تعذّر إرسال الطلب'),
    });
  };

  return (
    <>
      {/* 1) نوع الاستشارة (السعر إرشادي — يعتمده المكتب) */}
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
                  <span className="chip" style={{ margin: '6px 0' }}>{(p + Math.round(p * prices.vat / 100)).toLocaleString('en-US')} ر.س تقريباً</span>
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
          <div className="card-h"><h3>تفاصيل الطلب</h3></div>
          <div className="card-b" style={{ padding: 18 }}>
            <div className="field"><label>موضوع الاستشارة</label><input value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="مثال: نزاع تجاري مع مورّد" /></div>
            <div className="field">
              <label>التخصّص</label>
              <select className="input" value={specialty} onChange={(e) => setSpecialty(e.target.value)}>
                <option value="">— اختر التخصّص —</option>
                {specialties.map((s) => <option key={s} value={s}>{s}</option>)}
              </select>
            </div>
            <div className="action-hint" style={{ margin: '4px 0 12px' }}>
              <Icon name="clock" /> يحدّد المكتب سعر الاستشارة ويُصدر الفاتورة، ثم تختار المستشار والموعد بعد السداد من «استشاراتي».
            </div>
            <button className="btn block" onClick={submit} type="button" disabled={busy || !specialty}>
              <Icon name="calplus" /> {busy ? 'جارٍ الإرسال…' : 'إرسال الطلب لتحديد السعر'}
            </button>
          </div>
        </div>
      )}
    </>
  );
};

export default Book;
