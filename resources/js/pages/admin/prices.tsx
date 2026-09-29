import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';

interface Props {
  prices: {
    office: number;
    video: number;
    phone: number;
    vat: number;
  };
}

export const AdminPrices: React.FC<Props> = ({ prices }) => {
  const toast = useToast();

  // القيم من `Setting::consultPrices()` وحده — كانت هنا افتراضاتٌ منقوشة (600/450/350/15) نسخةً
  // ثانية من افتراضات الخادم، تتباعد عنها عند أوّل تعديل ويحفظها المدير دون أن يدري.
  const [office, setOffice] = useState<string>(String(prices.office));
  const [video, setVideo] = useState<string>(String(prices.video));
  const [phone, setPhone] = useState<string>(String(prices.phone));
  const [vat, setVat] = useState<string>(String(prices.vat));
  const [busy, setBusy] = useState(false);

  // Live computations
  const officeNum = Math.max(0, parseInt(office, 10) || 0);
  const videoNum = Math.max(0, parseInt(video, 10) || 0);
  const phoneNum = Math.max(0, parseInt(phone, 10) || 0);
  const vatRate = Math.max(0, Math.min(100, parseInt(vat, 10) || 0));

  const officeVat = Math.round((officeNum * vatRate) / 100);
  const videoVat = Math.round((videoNum * vatRate) / 100);
  const phoneVat = Math.round((phoneNum * vatRate) / 100);

  const officeTotal = officeNum + officeVat;
  const videoTotal = videoNum + videoVat;
  const phoneTotal = phoneNum + phoneVat;

  const handleSave = (e?: React.FormEvent) => {
    e?.preventDefault();
    setBusy(true);

    router.post(
      '/admin/prices',
      {
        office: officeNum,
        video: videoNum,
        phone: phoneNum,
        vat: vatRate,
      },
      {
        preserveScroll: true,
        // النجاح يعلنه الخادم برسالته (`flash`) ويعرضها `AppLayout` — تنبيهٌ محلّيّ فوقه كان يكرّره
        onSuccess: () => setBusy(false),
        // رسالة الحقل من الخادم نفسها — العامّة كانت تُخفي أيّ القيم رُدّت ولماذا
        onError: (e) => {
          setBusy(false);
          toast(String(Object.values(e)[0] ?? 'تعذّر حفظ الأسعار — راجع القيم المدخلة'), 'error');
        },
      }
    );
  };

  const channelCards = [
    {
      key: 'video',
      label: 'استشارة مرئية (Zoom)',
      icon: 'video',
      color: '#0E5C9C',
      base: videoNum,
      vatVal: videoVat,
      total: videoTotal,
      val: video,
      setVal: setVideo,
      desc: 'جلسات استشارية مرئية عن بُعد عبر Zoom مع تسجيل موثق',
    },
    {
      key: 'office',
      label: 'استشارة حضورية (بالمقر)',
      icon: 'office',
      color: '#11A0C8',
      base: officeNum,
      vatVal: officeVat,
      total: officeTotal,
      val: office,
      setVal: setOffice,
      desc: 'استقبال العميل بمقر المكتب وجلسة استشارية مباشرة',
    },
    {
      key: 'phone',
      label: 'استشارة هاتفية',
      icon: 'phone',
      color: '#C0832B',
      base: phoneNum,
      vatVal: phoneVat,
      total: phoneTotal,
      val: phone,
      setVal: setPhone,
      desc: 'مكالمة استشارية هاتفية سريعة مع المستشار القانوني',
    },
  ];

  return (
    <div className="admin-prices-360-root" style={{ paddingBottom: 60, width: '100%' }}>
      {/* ── 1. الهيدر والترحيب ── */}
      <div className="greet" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 14 }}>
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0 }}>
            <Icon name="card" cls="ic" />
            إدارة وتسعير باقات الاستشارات القانونية — الإدارة العليا
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--muted)', fontSize: 13 }}>
            تحديد الأسعار المعيارية الأساسية وضريبة القيمة المضافة؛ وتُطبّق تلقائياً على كل حجز وفاتورة جديدة تصدر للعميل.
          </p>
        </div>

        <button
          className="btn primary"
          type="button"
          disabled={busy}
          onClick={handleSave}
          style={{ padding: '10px 24px', fontSize: 13.5, fontWeight: 700, borderRadius: 10 }}
        >
          <Icon name="check" /> {busy ? 'جاري حفظ الأسعار…' : 'اعتماد وحفظ الأسعار'}
        </button>
      </div>

      {/* ── 2. محاكاة الأسعار والفاتورة الحية (Live Price Matrix Preview) ── */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 300px), 1fr))', gap: 16, margin: '20px 0' }}>
        {channelCards.map((card) => (
          <div
            key={card.key}
            className="card"
            style={{
              margin: 0,
              padding: 20,
              display: 'flex',
              flexDirection: 'column',
              justifyContent: 'space-between',
              gap: 16,
              borderTop: `4px solid ${card.color}`,
              boxShadow: '0 4px 15px rgba(0,0,0,0.04)',
            }}
          >
            <div>
              {/* رأس الباقة */}
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <div
                    style={{
                      width: 36,
                      height: 36,
                      borderRadius: 10,
                      background: 'rgba(0,0,0,0.04)',
                      color: card.color,
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                    }}
                  >
                    <Icon name={card.icon} />
                  </div>
                  <div>
                    <b style={{ fontSize: 15, color: '#13314F' }}>{card.label}</b>
                    <div style={{ fontSize: 11.5, color: 'var(--muted)', marginTop: 2 }}>{card.desc}</div>
                  </div>
                </div>
              </div>

              {/* حقل تعديل السعر الأساسي */}
              <div style={{ marginTop: 16 }}>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--muted)', marginBottom: 6 }}>
                  السعر الأساسي (غير شامل الضريبة):
                </label>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <div style={{ position: 'relative', flex: 1 }}>
                    <input
                      type="number"
                      min={0}
                      value={card.val}
                      onChange={(e) => card.setVal(e.target.value)}
                      style={{
                        width: '100%',
                        padding: '10px 14px',
                        fontSize: 16,
                        fontWeight: 800,
                        borderRadius: 8,
                        border: '1.5px solid rgba(0,0,0,0.15)',
                        boxSizing: 'border-box',
                        color: 'var(--primary)',
                      }}
                    />
                    <span style={{ position: 'absolute', left: 12, top: 11, fontSize: 12, fontWeight: 700, color: 'var(--muted)' }}>
                      ر.س
                    </span>
                  </div>

                  {/* أزرار تعديل سريع */}
                  <div style={{ display: 'flex', gap: 4 }}>
                    <button
                      type="button"
                      className="btn soft sm"
                      style={{ padding: '6px 10px', fontSize: 11 }}
                      onClick={() => card.setVal(String(Math.max(0, card.base - 50)))}
                    >
                      -50
                    </button>
                    <button
                      type="button"
                      className="btn soft sm"
                      style={{ padding: '6px 10px', fontSize: 11 }}
                      onClick={() => card.setVal(String(card.base + 50))}
                    >
                      +50
                    </button>
                  </div>
                </div>
              </div>
            </div>

            {/* بطاقة تفكيك الضريبة والإجمالي النهائي */}
            <div
              style={{
                background: 'rgba(0,0,0,0.02)',
                border: '1px solid rgba(0,0,0,0.06)',
                borderRadius: 10,
                padding: '12px 14px',
                fontSize: 12.5,
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                <span style={{ color: 'var(--muted)' }}>السعر الأساسي:</span>
                <b>{card.base.toLocaleString()} ر.س</b>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                <span style={{ color: 'var(--muted)' }}>ضريبة القيمة المضافة ({vatRate}%):</span>
                <span style={{ color: '#C0832B', fontWeight: 600 }}>+{card.vatVal.toLocaleString()} ر.س</span>
              </div>
              <div
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  paddingTop: 8,
                  borderTop: '1px solid rgba(0,0,0,0.08)',
                  fontSize: 14,
                }}
              >
                <span style={{ fontWeight: 700, color: '#13314F' }}>المبلغ الإجمالي بالفاتورة:</span>
                <Badge text={`${card.total.toLocaleString()} ر.س`} tone="b-blue" />
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* ── 3. إعدادات ضريبة القيمة المضافة والمعلومات النظامية ── */}
      <div className="card" style={{ padding: 20, marginTop: 10 }}>
        <div className="card-h" style={{ marginBottom: 16 }}>
          <h3>إعدادات الضريبة والقواعد النظامية</h3>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 280px), 1fr))', gap: 20, alignItems: 'center' }}>
          <div>
            <label style={{ display: 'block', fontSize: 13, fontWeight: 700, color: 'var(--muted)', marginBottom: 8 }}>
              نسبة ضريبة القيمة المضافة VAT (%):
            </label>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <div style={{ position: 'relative', width: 140 }}>
                <input
                  type="number"
                  min={0}
                  max={100}
                  value={vat}
                  onChange={(e) => setVat(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    fontSize: 16,
                    fontWeight: 800,
                    borderRadius: 8,
                    border: '1.5px solid rgba(0,0,0,0.15)',
                    boxSizing: 'border-box',
                    textAlign: 'center',
                  }}
                />
                <span style={{ position: 'absolute', left: 12, top: 11, fontSize: 14, fontWeight: 700, color: 'var(--muted)' }}>
                  %
                </span>
              </div>

              <div style={{ display: 'flex', gap: 6 }}>
                {[0, 5, 15].map((rate) => (
                  <button
                    key={rate}
                    type="button"
                    className={`btn sm ${vatRate === rate ? 'primary' : 'soft'}`}
                    onClick={() => setVat(String(rate))}
                    style={{ padding: '7px 12px', fontSize: 12 }}
                  >
                    {rate}%
                  </button>
                ))}
              </div>
            </div>
            <span style={{ fontSize: 11.5, color: 'var(--muted)', marginTop: 6, display: 'block' }}>
              النسبة النظامية المعتمدة في المملكة العربية السعودية هي 15%.
              {' '}
              <b>والنسبة عامّة</b>: تُطبَّق على كلّ فاتورةٍ جديدة في النظام — الاستشارات وأتعاب القضايا والتنفيذ — لا على الاستشارات وحدها؛ والفاتورة الصادرة تحتفظ بنسبتها.
            </span>
          </div>

          {/* صندوق معلومات الحجوزات */}
          <div
            style={{
              background: 'rgba(14, 92, 156, 0.04)',
              borderRight: '4px solid var(--primary)',
              borderRadius: 8,
              padding: '14px 16px',
              fontSize: 12.5,
              lineHeight: 1.7,
            }}
          >
            <b style={{ color: 'var(--primary)', display: 'block', marginBottom: 4 }}>
              كيف تُطبّق الأسعار على المنصة؟
            </b>
            <span style={{ color: '#333' }}>
              عند حجز العميل لاستشارة مباشرة أو تسعير طلب جديد من لوحة الإدارة، يتم جلب هذه الأسعار تلقائياً واحتساب الضريبة وإصدار فاتورة إلكترونية معتمدة بالرقم المرجعي للاستشارة.
            </span>
          </div>
        </div>
      </div>
    </div>
  );
};

export default AdminPrices;
