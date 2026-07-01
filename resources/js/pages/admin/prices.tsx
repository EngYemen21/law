import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import {
  type PriceLogEntry, CONSULT_PRICES, DEFAULT_VAT_RATE, crChannelIcon, crChannelTone, relTime,
} from '@/lib/admin-data';

// يطابق adPricesView + savePrices في index (82).html

const ROWS: [string, string][] = [
  ['مرئية', 'استشارة مرئية (فيديو)'],
  ['حضورية', 'استشارة حضورية'],
  ['هاتفية', 'استشارة هاتفية'],
];

const AdminPrices: React.FC = () => {
  const toast = useToast();
  const [prices, setPrices] = useState<Record<string, number>>(() => ({ ...CONSULT_PRICES }));
  const [vat, setVat] = useState(DEFAULT_VAT_RATE);
  const [log, setLog] = useState<PriceLogEntry[]>([]);

  // قيم الإدخال
  const [inVisual, setInVisual] = useState(String(CONSULT_PRICES['مرئية']));
  const [inOffice, setInOffice] = useState(String(CONSULT_PRICES['حضورية']));
  const [inPhone, setInPhone] = useState(String(CONSULT_PRICES['هاتفية']));
  const [inVat, setInVat] = useState(String(Math.round(DEFAULT_VAT_RATE * 100)));

  const save = () => {
    const ch: string[] = [];
    const next = { ...prices };
    const apply = (k: string, raw: string) => {
      const v = parseFloat(raw);
      if (!isNaN(v) && v !== prices[k]) { ch.push(`سعر ${k}: ${prices[k]} ← ${v} ر.س`); next[k] = v; }
    };
    apply('مرئية', inVisual);
    apply('حضورية', inOffice);
    apply('هاتفية', inPhone);
    const vt = parseFloat(inVat);
    let nextVat = vat;
    if (!isNaN(vt) && vt / 100 !== vat) { ch.push(`الضريبة: ${Math.round(vat * 100)}% ← ${vt}%`); nextVat = vt / 100; }
    setPrices(next);
    setVat(nextVat);
    if (ch.length) setLog((p) => [{ who: 'الإدارة العليا', ts: Date.now(), changes: ch }, ...p]);
    toast(ch.length ? 'تم حفظ أسعار الاستشارات — مطبّقة على الحجز والفواتير' : 'لا تغييرات على الأسعار');
  };

  return (
    <>
      <div className="greet">
        <h2>أسعار الاستشارات</h2>
        <p>تحدّد الإدارة العليا أسعار جميع أنواع الاستشارات؛ وتُطبَّق فوراً على الحجز والفواتير (التذاكر و«حجز استشارة»).</p>
      </div>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>صلاحية الإدارة العليا: يمكنك تعديل أسعار الاستشارات وضريبة القيمة المضافة في أي وقت.</p>
      </div>

      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><h3>تسعير أنواع الاستشارات</h3></div>
        <div className="card-b" style={{ padding: '16px 18px' }}>
          <div className="field"><label>سعر استشارة مرئية (فيديو) (ر.س)</label><input className="input" type="number" min={0} value={inVisual} onChange={(e) => setInVisual(e.target.value)} /></div>
          <div className="field"><label>سعر استشارة حضورية (ر.س)</label><input className="input" type="number" min={0} value={inOffice} onChange={(e) => setInOffice(e.target.value)} /></div>
          <div className="field"><label>سعر استشارة هاتفية (ر.س)</label><input className="input" type="number" min={0} value={inPhone} onChange={(e) => setInPhone(e.target.value)} /></div>
          <div className="field"><label>نسبة ضريبة القيمة المضافة (%)</label><input className="input" type="number" min={0} value={inVat} onChange={(e) => setInVat(e.target.value)} /></div>
          <button className="btn" onClick={save} type="button"><Icon name="check" /> حفظ الأسعار</button>
        </div>
      </div>

      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><h3>الأسعار الحالية (شاملة الضريبة)</h3></div>
        <div className="card-b">
          {ROWS.map((r) => {
            const p = prices[r[0]] || 0;
            const v = Math.round(p * vat);
            return (
              <div key={r[0]} className="item">
                <div className="iico"><Icon name={crChannelIcon(r[0])} /></div>
                <div className="imeta"><b>{r[1]}</b><span>الأساسي {p} ر.س · الضريبة {v} ر.س</span></div>
                <div className="iact"><Badge text={`${p + v} ر.س الإجمالي`} tone={crChannelTone(r[0])} /></div>
              </div>
            );
          })}
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>سجل تغييرات الأسعار</h3><span className="sub">{log.length}</span></div>
        <div className="card-b">
          {log.length ? log.map((l, i) => (
            <div key={i} className="item">
              <div className="iico"><Icon name="card" /></div>
              <div className="imeta"><b>{l.who}</b><span>{l.changes.join(' · ')}</span></div>
              <div className="iact"><span className="chip muted">{relTime(l.ts)}</span></div>
            </div>
          )) : (
            <div className="empty"><Icon name="card" /><b>لا تغييرات بعد</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default AdminPrices;
