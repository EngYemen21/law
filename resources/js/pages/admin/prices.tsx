import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';

// أسعار الاستشارات — تُحفظ فعلياً في الإعدادات وتنعكس على كل حجز جديد

interface Props { prices: { office: number; video: number; phone: number; vat: number }; }

const ROWS: [('office' | 'video' | 'phone'), string, string][] = [
  ['video', 'video', 'استشارة مرئية (فيديو)'],
  ['office', 'office', 'استشارة حضورية'],
  ['phone', 'phone', 'استشارة هاتفية'],
];

const AdminPrices: React.FC<Props> = ({ prices }) => {
  const toast = useToast();
  const [office, setOffice] = useState(String(prices.office));
  const [video, setVideo] = useState(String(prices.video));
  const [phone, setPhone] = useState(String(prices.phone));
  const [vat, setVat] = useState(String(prices.vat));
  const [busy, setBusy] = useState(false);

  const save = () => {
    setBusy(true);
    router.post('/admin/prices', {
      office: parseInt(office, 10) || 0,
      video: parseInt(video, 10) || 0,
      phone: parseInt(phone, 10) || 0,
      vat: parseInt(vat, 10) || 0,
    }, { preserveScroll: true, onFinish: () => setBusy(false), onSuccess: () => toast('تم حفظ الأسعار — مطبّقة على الحجوزات الجديدة') });
  };

  const cur = { office: parseInt(office, 10) || 0, video: parseInt(video, 10) || 0, phone: parseInt(phone, 10) || 0 };
  const vatN = parseInt(vat, 10) || 0;

  return (
    <>
      <div className="greet">
        <h2>أسعار الاستشارات</h2>
        <p>تحدّد الإدارة العليا أسعار الاستشارات؛ وتُطبَّق فوراً على الحجز والفواتير.</p>
      </div>

      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><h3>تسعير أنواع الاستشارات</h3></div>
        <div className="card-b" style={{ padding: '16px 18px' }}>
          <div className="field"><label>سعر استشارة مرئية (فيديو) (ر.س)</label><input type="number" min={0} value={video} onChange={(e) => setVideo(e.target.value)} /></div>
          <div className="field"><label>سعر استشارة حضورية (ر.س)</label><input type="number" min={0} value={office} onChange={(e) => setOffice(e.target.value)} /></div>
          <div className="field"><label>سعر استشارة هاتفية (ر.س)</label><input type="number" min={0} value={phone} onChange={(e) => setPhone(e.target.value)} /></div>
          <div className="field"><label>نسبة ضريبة القيمة المضافة (%)</label><input type="number" min={0} value={vat} onChange={(e) => setVat(e.target.value)} /></div>
          <button className="btn" onClick={save} type="button" disabled={busy}><Icon name="check" /> {busy ? 'جارٍ الحفظ…' : 'حفظ الأسعار'}</button>
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>الأسعار الحالية (شاملة الضريبة)</h3></div>
        <div className="card-b">
          {ROWS.map(([key, ico, label]) => {
            const p = cur[key];
            const v = Math.round((p * vatN) / 100);
            return (
              <div key={key} className="item">
                <div className="iico"><Icon name={ico} /></div>
                <div className="imeta"><b>{label}</b><span>الأساسي {p} ر.س · الضريبة {v} ر.س</span></div>
                <div className="iact"><Badge text={`${p + v} ر.س الإجمالي`} tone="b-blue" /></div>
              </div>
            );
          })}
        </div>
      </div>
    </>
  );
};

export default AdminPrices;
