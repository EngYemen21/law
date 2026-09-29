import { router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import BookingActions from '@/components/babylon/BookingActions';
import { useToast } from '@/components/babylon/Toast';
import type { ConsultCard } from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import { crChannelIcon, crChannelTone } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { arabicCount, NOUN } from '@/lib/arabic-count';

// ============================================================
// بوابة حجز الاستشارات القانونية 360 درجة (360° Consultations Booking Command Center)
// منصة سلاسل بابل لإدارة مكاتب المحاماة
// ملاحظة هامة: لا تُعرض أسعار مسبقة؛ التسعير يتم حصراً من الإدارة العليا بعد دراسة الطلب
// ============================================================

const CHANNELS: { key: string; label: string; icon: string; desc: string }[] = [
  {
    key: 'video',
    label: 'استشارة مرئية عن بُعد',
    icon: 'video',
    desc: 'جلسة افتراضية عبر Zoom، مشاركة المستندات، ومحضر رسمي معتمد.',
  },
  {
    key: 'office',
    label: 'استشارة حضورية بالمكتب',
    icon: 'office',
    desc: 'لقاء وجاهي في مقر مكتب المحاماة، دراسة الأوراق والمستندات الأصلية.',
  },
  {
    key: 'phone',
    label: 'استشارة هاتفية مباشرة',
    icon: 'phone',
    desc: 'اتصال هاتفي مباشر من المستشار بالموعد، إجابات سريعة ومركزة.',
  },
];

const OTHER = '__other__';

export interface BookingStats {
  pendingCount: number;
  activeCount: number;
  completedCount: number;
  totalRequested: number;
}

interface Props {
  pending: ConsultCard[];
  specialties?: string[];
  stats?: BookingStats;
}

const Book: React.FC<Props> = ({
  pending = [],
  specialties = [],
}) => {
  const toast = useToast();
  const [channel, setChannel] = useState('video');
  const [caseType, setCaseType] = useState('');
  const [otherType, setOtherType] = useState('');
  const [subject, setSubject] = useState('');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [items, setItems] = useState<ConsultCard[]>(pending);

  // تزامن لحظي: تسعير الإدارة/تأكيد الدفع يصلان فوراً
  useEffect(() => {
    setItems(pending);
    pending.forEach((c) => {
      echo.private(`consult.${c.id}`).listen(
        '.status',
        (e: {
          status?: string;
          price?: number;
          vat?: number;
          total?: number;
          priced?: boolean;
          paid?: boolean;
          invoiceNo?: string | null;
        }) => {
          setItems((prev) =>
            prev.map((x) =>
              x.id === c.id
                ? {
                    ...x,
                    status: e.status ?? x.status,
                    price: e.price ?? x.price,
                    vat: e.vat ?? x.vat,
                    total: e.total ?? x.total,
                    priced: e.priced ?? x.priced,
                    paid: e.paid ?? x.paid,
                    invoiceNo: e.invoiceNo ?? x.invoiceNo,
                  }
                : x
            )
          );
        }
      );
    });
    return () => {
      pending.forEach((c) => echo.leave(`consult.${c.id}`));
    };
  }, [pending]);

  const submit = () => {
    if (!channel) {
      toast('اختر نوع وقناة الاستشارة');
      return;
    }
    if (!caseType) {
      toast('اختر المجال أو نوع القضية');
      return;
    }
    if (caseType === OTHER && !otherType.trim()) {
      toast('اكتب نوع القضية أو التخصص المطلوب');
      return;
    }
    if (!subject.trim()) {
      toast('اكتب عنواناً أو موضوعاً موجزاً للاستشارة');
      return;
    }

    // المجال: قسمٌ من الكتالوج أو نصٌّ حرّ في «مجال آخر» — الخادم يطابقه بالكتالوج في الحالتين
    const specialty = caseType !== OTHER ? caseType : otherType.trim();
    const caseLabel = specialty;
    // حدّ الخادم subject: max:120 — بلا قصّ كان أي حجز بملاحظات حقيقية يسقط بـ422
    const composedSubject = (notes.trim()
      ? `${subject.trim()} (${caseLabel}) — ${notes.trim()}`
      : `${subject.trim()} (${caseLabel})`).slice(0, 120);

    setBusy(true);
    router.post(
      '/book',
      { type: channel, subject: composedSubject, specialty },
      {
        onFinish: () => setBusy(false),
        onSuccess: () => toast('أُرسل طلبك بنجاح — سيتم دراسته وتسعيره من الإدارة العليا فوراً'),
        onError: (e) => toast(e.type || e.specialty || e.subject || e.message || 'تعذّر إرسال الطلب'),
      }
    );
  };

  return (
    <>
      {/* 1. الهيدر التنفيذي 360° وشريط الإجراءات */}
      <div className="hero" style={{ padding: '24px 26px', marginBottom: 20 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 16 }}>
          <div style={{ maxWidth: 660 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
              <span className="chip" style={{ background: 'rgba(255,255,255,0.18)', color: '#fff', borderColor: 'rgba(255,255,255,0.3)', fontWeight: 700 }}>
                <Icon name="sparkles" cls="ic" /> بوابة حجز الاستشارات المعتمدة 360°
              </span>
              <span style={{ fontSize: 12, color: '#e0f2fe' }}>
                تسعير واعتماد مباشر من الإدارة العليا
              </span>
            </div>
            <h2>حجز استشارة قانونية متخصصة 🏛️</h2>
            <p>
              احصل على رأي قانوني رصين من نخبة المحامين والمستشارين المعتمدين. اختر القناة والمجال المناسبين ليتم تسعير الطلب واعتماده من الإدارة العليا قبل السداد وحجز الموعد.
            </p>
          </div>

          <div className="hero-cta" style={{ margin: 0 }}>
            <button className="hero-b" onClick={() => router.visit('/myconsults')} type="button">
              <Icon name="scale" /> استشاراتي الحالية
            </button>
            <button className="hero-b ghost" onClick={() => router.visit('/calendar')} type="button">
              <Icon name="cal" /> المواعيد والتقويم
            </button>
            <button className="hero-b ghost" onClick={() => router.visit('/tickets')} type="button">
              <Icon name="ticket" /> طلباتي وتذاكري
            </button>
          </div>
        </div>
      </div>

      {/* 2. مسار رحلة الاستشارة الشفاف (4-Step Flow Stepper) */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
          gap: 12,
          marginBottom: 22,
        }}
      >
        <div
          style={{
            padding: '14px 16px',
            borderRadius: 12,
            backgroundColor: '#ffffff',
            border: '1.5px solid var(--primary)',
            boxShadow: '0 4px 14px -4px rgba(14, 92, 156, 0.15)',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--primary)', fontWeight: 800, fontSize: 13, marginBottom: 4 }}>
            <span style={{ width: 22, height: 22, borderRadius: '50%', backgroundColor: 'var(--primary)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11 }}>
              1
            </span>
            تقديم الطلب والمجال
          </div>
          <p style={{ margin: 0, fontSize: 12, color: 'var(--muted)' }}>تحديد القناة وتفاصيل الموضوع والوقائع.</p>
        </div>

        <div
          style={{
            padding: '14px 16px',
            borderRadius: 12,
            backgroundColor: '#f8fafc',
            border: '1px solid var(--line-soft)',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--deep)', fontWeight: 800, fontSize: 13, marginBottom: 4 }}>
            <span style={{ width: 22, height: 22, borderRadius: '50%', backgroundColor: '#e2e8f0', color: 'var(--ink)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11 }}>
              2
            </span>
            تسعير الإدارة العليا
          </div>
          <p style={{ margin: 0, fontSize: 12, color: 'var(--muted)' }}>دراسة الموضوع وتحديد الأتعاب وإصدار الفاتورة.</p>
        </div>

        <div
          style={{
            padding: '14px 16px',
            borderRadius: 12,
            backgroundColor: '#f8fafc',
            border: '1px solid var(--line-soft)',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--deep)', fontWeight: 800, fontSize: 13, marginBottom: 4 }}>
            <span style={{ width: 22, height: 22, borderRadius: '50%', backgroundColor: '#e2e8f0', color: 'var(--ink)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11 }}>
              3
            </span>
            السداد عبر ميسّر
          </div>
          <p style={{ margin: 0, fontSize: 12, color: 'var(--muted)' }}>دفع إلكتروني آمن وفوري بكافة البطاقات.</p>
        </div>

        <div
          style={{
            padding: '14px 16px',
            borderRadius: 12,
            backgroundColor: '#f8fafc',
            border: '1px solid var(--line-soft)',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--deep)', fontWeight: 800, fontSize: 13, marginBottom: 4 }}>
            <span style={{ width: 22, height: 22, borderRadius: '50%', backgroundColor: '#e2e8f0', color: 'var(--ink)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11 }}>
              4
            </span>
            حجز الموعد وانعقاد الجلسة
          </div>
          <p style={{ margin: 0, fontSize: 12, color: 'var(--muted)' }}>اختيار الفترة وتلقي الرأي والمحضر المعتمد.</p>
        </div>
      </div>

      {/* 3. طلبات الاستشارة الجارية بدورة الحجز (إن وجدت)
          شعار المنصّة في الترويسة (طلب المالك 2026-09-26) — كانت أيقونة ساعةٍ بصنفِ لونٍ وحده (`cls="text-amber"`)
          يُسقط صنفها الأساسيّ `ic`، فتُرسم بلا حجمٍ ولا حدٍّ وتُملأ سوداء: «دائرة سوداء» بجانب العنوان. */}
      {items.length > 0 && (
        <div className="card book-pending" style={{ marginBottom: 22 }}>
          <div className="card-h book-pending-h">
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, minWidth: 0 }}>
              <span className="book-pending-mark">
                <img src="/images/sb-mark.png" alt="" width={320} height={303} />
              </span>
              <div style={{ minWidth: 0 }}>
                <h3>طلبات استشاراتك قيد المتابعة والإجراء</h3>
                <span className="sub">نتابع كلّ طلبٍ حتى انعقاد جلسته — والخطوة التالية مكتوبةٌ بجانبه</span>
              </div>
            </div>
            <span className="badge-s b-amber">{arabicCount(items.length, NOUN.request)}</span>
          </div>
          <div className="card-b" style={{ padding: '6px 18px 14px' }}>
            {items.map((c) => (
              <div key={c.ref} className="item book-pending-item">
                <div className="item-top">
                  <div className={`iico ${crChannelTone(c.channel)}`}>
                    <Icon name={crChannelIcon(c.channel)} />
                  </div>
                  <div className="imeta">
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 4 }}>
                      <b>{c.subject || 'طلب استشارة'}</b>
                      <span className="chip" style={{ fontSize: 11, direction: 'ltr' }}>{c.ref}</span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', fontSize: 12.5, color: 'var(--muted)' }}>
                      <Badge text={c.channel} tone={crChannelTone(c.channel)} />
                      {c.priced ? (
                        <span className={`badge-s ${c.paid ? 'b-green' : 'b-amber'}`}>
                          {c.total} ر.س · {c.paid ? 'مسدَّد' : 'بانتظار السداد'}
                        </span>
                      ) : (
                        <span className="badge-s b-amber">بانتظار تسعير الإدارة العليا</span>
                      )}
                    </div>
                  </div>
                </div>
                <div className="iact">
                  <BookingActions c={c} toast={toast} />
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* 4. النموذج الذكي لحجز استشارة جديدة */}
      <div className="card" style={{ marginBottom: 24 }}>
        <div className="card-h">
          <div>
            <h3>بيانات طلب الاستشارة</h3>
            <span className="sub">اختر القناة والتخصص المناسب لموضوعك</span>
          </div>
          <span className="badge-s b-blue">
            تسعير معتمد من الإدارة
          </span>
        </div>

        <div className="card-b" style={{ padding: '20px 22px' }}>
          {/* أ. اختيار قناة الاستشارة (Visual Channel Cards) */}
          <div style={{ marginBottom: 22 }}>
            <label style={{ display: 'block', fontWeight: 800, fontSize: 14, color: 'var(--ink)', marginBottom: 10 }}>
              1. اختر نوع وقناة الاستشارة:
            </label>

            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
                gap: 12,
              }}
            >
              {CHANNELS.map((ch) => {
                const isSelected = channel === ch.key;

                return (
                  <div
                    key={ch.key}
                    onClick={() => setChannel(ch.key)}
                    style={{
                      padding: '16px',
                      borderRadius: 12,
                      cursor: 'pointer',
                      border: isSelected ? '2px solid var(--primary)' : '1px solid var(--line)',
                      backgroundColor: isSelected ? 'rgba(14, 92, 156, 0.04)' : '#ffffff',
                      boxShadow: isSelected ? '0 6px 20px -6px rgba(14, 92, 156, 0.25)' : 'none',
                      transition: 'all 0.2s ease',
                      position: 'relative',
                    }}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 8 }}>
                      <div
                        style={{
                          width: 36,
                          height: 36,
                          borderRadius: 8,
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          backgroundColor: isSelected ? 'var(--primary)' : 'var(--paper-2)',
                          color: isSelected ? '#ffffff' : 'var(--ink)',
                        }}
                      >
                        <Icon name={ch.icon} />
                      </div>
                      <span
                        className="chip"
                        style={{
                          fontWeight: 700,
                          color: isSelected ? 'var(--primary)' : 'var(--muted)',
                          backgroundColor: isSelected ? '#e0f2fe' : 'var(--paper-2)',
                          borderColor: isSelected ? '#bae6fd' : 'var(--line)',
                          fontSize: 11,
                        }}
                      >
                        تسعير الإدارة العليا
                      </span>
                    </div>

                    <b style={{ display: 'block', fontSize: 14.5, color: isSelected ? 'var(--primary)' : 'var(--ink)', marginBottom: 4 }}>
                      {ch.label}
                    </b>
                    <p style={{ margin: 0, fontSize: 12, color: 'var(--muted)', lineHeight: 1.6 }}>
                      {ch.desc}
                    </p>
                  </div>
                );
              })}
            </div>
          </div>

          {/* ب. اختيار المجال والتخصص القضائي */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 280px), 1fr))', gap: 16, marginBottom: 18 }}>
            <div className="field" style={{ margin: 0 }}>
              <label style={{ fontWeight: 700, color: 'var(--ink)', marginBottom: 6 }}>
                2. المجال القانوني / نوع القضية:
              </label>
              <select
                className="input"
                value={caseType}
                onChange={(e) => setCaseType(e.target.value)}
                style={{ padding: '10px 14px', fontSize: 13.5 }}
              >
                <option value="">— اختر المجال القضائي —</option>
                {/* الأقسام الفعّالة من كتالوج الأقسام — يمرّرها الخادم */}
                {specialties.map((s) => (
                  <option key={s} value={s}>{s}</option>
                ))}
                <option value={OTHER}>مجال آخر / تخصص إضافي</option>
              </select>
            </div>

            {caseType === OTHER ? (
              <div className="field" style={{ margin: 0 }}>
                <label style={{ fontWeight: 700, color: 'var(--ink)', marginBottom: 6 }}>
                  اكتب اسم التخصص أو نوع القضية:
                </label>
                <input
                  className="input"
                  list="book-specialties"
                  value={otherType}
                  onChange={(e) => setOtherType(e.target.value)}
                  placeholder="مثال: نزاع ملكية فكرية، تأمين طبي…"
                  style={{ padding: '10px 14px', fontSize: 13.5 }}
                />
                <datalist id="book-specialties">
                  {specialties.map((s) => (
                    <option key={s} value={s} />
                  ))}
                </datalist>
              </div>
            ) : (
              <div className="field" style={{ margin: 0 }}>
                <label style={{ fontWeight: 700, color: 'var(--ink)', marginBottom: 6 }}>
                  3. موضوع الاستشارة الرئيسي:
                </label>
                <input
                  className="input"
                  type="text"
                  placeholder="مثال: مراجعة بنود عقد استثمار، فسخ عقد عمل…"
                  maxLength={60}
                  value={subject}
                  onChange={(e) => setSubject(e.target.value)}
                  style={{ padding: '10px 14px', fontSize: 13.5 }}
                />
              </div>
            )}
          </div>

          {caseType === OTHER && (
            <div className="field" style={{ marginBottom: 18 }}>
              <label style={{ fontWeight: 700, color: 'var(--ink)', marginBottom: 6 }}>
                3. موضوع الاستشارة الرئيسي:
              </label>
              <input
                className="input"
                type="text"
                placeholder="مثال: مراجعة بنود عقد استثمار، فسخ عقد عمل…"
                maxLength={60}
                  value={subject}
                onChange={(e) => setSubject(e.target.value)}
                style={{ padding: '10px 14px', fontSize: 13.5 }}
              />
            </div>
          )}

          {/* ج. تفاصيل وشرح موضوع الاستشارة */}
          <div className="field" style={{ marginBottom: 20 }}>
            <label style={{ fontWeight: 700, color: 'var(--ink)', marginBottom: 6 }}>
              4. شرح موجز لوقائع الموضوع والأسئلة القانونية:
            </label>
            <textarea
              className="input"
              rows={4}
              maxLength={300}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="اكتب نبذة عن النزاع أو التساؤلات المطلوب الإجابة عليها ليتمكن المستشار المختص من تحضير الرأي القانوني المناسب مسبقاً…"
              style={{ padding: '12px 14px', fontSize: 13.5, lineHeight: 1.7 }}
            />
          </div>

          {/* د. لافتة توضيح سياسة التسعير والاعتماد الرسمي */}
          <div
            style={{
              padding: '16px 18px',
              borderRadius: 12,
              backgroundColor: '#f8fafc',
              border: '1px solid var(--line)',
              marginBottom: 20,
              display: 'flex',
              alignItems: 'center',
              gap: 14,
            }}
          >
            <div
              style={{
                width: 40,
                height: 40,
                borderRadius: 10,
                backgroundColor: 'rgba(14, 92, 156, 0.08)',
                color: 'var(--primary)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                flexShrink: 0,
              }}
            >
              <Icon name="scale" />
            </div>
            <div>
              <b style={{ fontSize: 13.5, color: 'var(--deep)', display: 'block', marginBottom: 2 }}>
                آلية التسعير والاعتماد من الإدارة العليا:
              </b>
              <p style={{ margin: 0, fontSize: 12.5, color: 'var(--muted)', lineHeight: 1.6 }}>
                يتم تحديد المقابل المالي وإصدار الفاتورة الرسمية للاستشارة من قِبل الإدارة العليا بعد دراسة وقائع الطلب والتخصص المطلوب، لتتمكن بعدها من سداد الفاتورة إلكترونياً واختيار موعد الجلسة مباشرة.
              </p>
            </div>
          </div>

          {/* هـ. زر الإرسال */}
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 14 }}>
            <button
              className="btn"
              onClick={submit}
              type="button"
              disabled={busy}
              style={{
                padding: '12px 28px',
                fontSize: 14.5,
                fontWeight: 800,
                minWidth: 220,
              }}
            >
              <Icon name="send" /> {busy ? 'جارٍ إرسال الطلب…' : 'إرسال طلب الاستشارة للإدارة'}
            </button>

            <div style={{ fontSize: 12.5, color: 'var(--muted)', display: 'flex', alignItems: 'center', gap: 6 }}>
              <Icon name="lock" />
              <span>بياناتك ومستنداتك مشفرة ومحمية بموجب نظام المحاماة السعودي.</span>
            </div>
          </div>
        </div>
      </div>

      {/* 5. ميثاق الجودة والضمانات القانونية */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 260px), 1fr))',
          gap: 16,
          marginBottom: 24,
        }}
      >
        <div className="card" style={{ padding: '16px 18px', margin: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8, color: 'var(--primary)' }}>
            <Icon name="lock" />
            <b style={{ fontSize: 14, color: 'var(--ink)' }}>السرية المهنية المطلقة</b>
          </div>
          <p style={{ margin: 0, fontSize: 12.5, color: 'var(--muted)', lineHeight: 1.6 }}>
            نلتزم التزاماً صارماً بسرية كافة المعلومات والوثائق وفق المادة 23 من نظام المحاماة.
          </p>
        </div>

        <div className="card" style={{ padding: '16px 18px', margin: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8, color: 'var(--primary)' }}>
            <Icon name="scale" />
            <b style={{ fontSize: 14, color: 'var(--ink)' }}>مستشارون مرخصون</b>
          </div>
          <p style={{ margin: 0, fontSize: 12.5, color: 'var(--muted)', lineHeight: 1.6 }}>
            تُقدم الاستشارات بواسطة نخبة من المحامين المعتمدين والمقيدين لدى وزارة العدل.
          </p>
        </div>

        <div className="card" style={{ padding: '16px 18px', margin: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8, color: 'var(--primary)' }}>
            <Icon name="doc" />
            <b style={{ fontSize: 14, color: 'var(--ink)' }}>محضر وتوصيات معتمدة</b>
          </div>
          <p style={{ margin: 0, fontSize: 12.5, color: 'var(--muted)', lineHeight: 1.6 }}>
            تحصل على تقرير ومحضر رسمي بنتائج الجلسة والتوصيات التنفيذية فور انتهاء الاستشارة.
          </p>
        </div>
      </div>
    </>
  );
};

export default Book;
