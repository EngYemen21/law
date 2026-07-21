import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import SpecialistPicker, { todayISO } from '@/components/SpecialistPicker';

// حجز استشارة ذكي — تخصّص → محامون مرتّبون بالذكاء الاصطناعي (بنفس التخصّص + سجلّ النجاح)
// → تاريخ وفترات متاحة (منع الحجز المزدوج) → حجز حقيقي (Consult + Appointment).

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
  const [date, setDate] = useState(todayISO());
  const [lawyerId, setLawyerId] = useState<number | null>(null);
  const [time, setTime] = useState('');
  const [busy, setBusy] = useState(false);

  const price = type ? (prices as Record<string, number>)[type] : 0;
  const vat = Math.round((price * prices.vat) / 100);

  const submit = () => {
    if (!type) { toast('اختر نوع الاستشارة'); return; }
    if (!specialty) { toast('اختر التخصّص'); return; }
    if (!lawyerId) { toast('اختر المستشار'); return; }
    if (!time) { toast('اختر موعداً متاحاً'); return; }
    setBusy(true);
    router.post('/book', {
      type, subject: subject.trim(), specialty, lawyer_id: lawyerId, date, time,
    }, {
      onFinish: () => setBusy(false),
      onSuccess: () => toast('تم تأكيد حجز الاستشارة'),
      onError: (e) => toast(e.starts_at || e.time || e.lawyer_id || 'تعذّر إتمام الحجز'),
    });
  };

  return (
    <>
      {/* 1) نوع الاستشارة */}
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
        <div className="card" style={{ marginBottom: 14 }}>
          <div className="card-h"><h3>تفاصيل الاستشارة</h3><span className="sub">{price.toLocaleString('en-US')} + ضريبة {vat.toLocaleString('en-US')} ر.س</span></div>
          <div className="card-b" style={{ padding: 18 }}>
            <div className="field"><label>موضوع الاستشارة</label><input value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="مثال: نزاع تجاري مع مورّد" /></div>
            <div className="picker-grid">
              <div className="field">
                <label>التخصّص</label>
                <select className="input" value={specialty} onChange={(e) => { setSpecialty(e.target.value); setLawyerId(null); setTime(''); }}>
                  <option value="">— اختر التخصّص —</option>
                  {specialties.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
              </div>
              <div className="field">
                <label>تاريخ الموعد</label>
                <input className="input" type="date" min={todayISO()} value={date} onChange={(e) => { setDate(e.target.value); setLawyerId(null); setTime(''); }} />
              </div>
            </div>
          </div>
        </div>
      )}

      {/* 2) المستشارون المتخصّصون (مرتّبون بالذكاء الاصطناعي + سجلّ النجاح) والفترات المتاحة */}
      {type && specialty && (
        <div className="card">
          <div className="card-h">
            <h3>المستشارون المتخصّصون</h3>
            <span className="sub">مرتّبون بالأنسب — {new Date(date).toLocaleDateString('ar')}</span>
          </div>
          <div className="card-b" style={{ padding: 18 }}>
            <SpecialistPicker
              fetchUrl="/book/availability"
              fetchParams={{ specialty, subject }}
              enabled={!!type && !!specialty}
              date={date}
              onDateSnap={setDate}
              lawyerId={lawyerId}
              onLawyerChange={setLawyerId}
              time={time}
              onTimeChange={setTime}
            />
            <button className="btn block" style={{ marginTop: 14 }} onClick={submit} type="button" disabled={busy || !lawyerId || !time}>
              <Icon name="calplus" /> {busy ? 'جارٍ الحجز…' : 'تأكيد الحجز'}
            </button>
          </div>
        </div>
      )}
    </>
  );
};

export default Book;
