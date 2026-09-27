import React from 'react';
import { Bars } from '@/components/babylon/admin-charts';
import type { BarDatum } from '@/lib/admin-data';
import Icon from '@/lib/icons';

// إيرادات الإدارة — تجميعات ومؤشرات مالية متكاملة للاستشارات والقضايا والتنفيذ القضائي والرواتب
//
// **الدخل هنا هو الفاتورة المدفوعة وحدها** (App\Support\Finance\RevenueSnapshot): لا تجمع هذه
// الشاشة مصدرين ولا تحسب رقماً بنفسها. كانت تجمع إيراد الاستشارات + كلّ فاتورة مدفوعة + أتعاب
// التنفيذ، وهي متقاطعة، فيُعَدّ المال مرّتين وثلاثاً.

const fmt = (n?: number) => (n ?? 0).toLocaleString('en-US');

interface Props {
  totalIncome: number;
  consultIncome: number;
  caseIncome: number;
  execIncome: number;
  otherIncome: number;
  issued: number;
  due: number;
  bookings: number;
  unbilledPaidConsults: number;
  execFixedFees?: number;
  execPercentFees?: number;
  totalDebtEnforced?: number;
  totalCollectedDebts?: number;
  collectionRate?: number;
  /** المحصَّل ÷ الصادر من الخادم (`RevenueSnapshot`) — null = لا فواتير صادرة */
  invoiceCollectionRate: number | null;
  byService: BarDatum[];
  salaries: { name: string; salary: number }[];
  salaryTotal: number;
}

const AdminRevenue: React.FC<Props> = ({
  totalIncome,
  consultIncome,
  caseIncome,
  execIncome,
  otherIncome,
  issued,
  due,
  bookings,
  unbilledPaidConsults,
  execFixedFees = 0,
  execPercentFees = 0,
  totalDebtEnforced = 0,
  totalCollectedDebts = 0,
  collectionRate = 0,
  invoiceCollectionRate,
  byService,
  salaries,
  salaryTotal,
}) => {
  return (
    <>
      {/* الرأس المالي مع زر التصدير */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 10 }}>
        <div>
          <h2 style={{ fontSize: 20, fontWeight: 800, margin: 0, color: 'var(--ink, #0a2a55)' }}>
            مؤشرات الإيرادات والتدفق المالي (Financial BI & Cash Flow)
          </h2>
          <span style={{ fontSize: 13, color: 'var(--muted, #7a8aa3)' }}>
            السيولة المحققة، أتعاب القضايا والتنفيذ، ومعدلات تحصيل الديون
          </span>
        </div>
        <a className="btn soft sm" href="/admin/revenue.pdf" download style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
          <Icon name="download" /> تصدير تقرير الإيرادات والسيولة PDF
        </a>
      </div>

      {/* ── لوحة المؤشرات المالية الكبرى للمكتب ── */}
      {/* حُذفت بطاقة «صافي التدفق بعد الرواتب»: كانت تطرح رواتب شهرٍ واحد من إيراد العمر كلّه
          (لا تصفية بالفترة في النظام حتى يحمل الفواتيرَ تاريخُ سداد). تعود بعد م١. */}
      <div className="stats" style={{ marginBottom: 16 }}>
        <div className="stat t-blue">
          <div className="si"><Icon name="card" /></div>
          <div className="num">{fmt(totalIncome)} ر.س</div>
          <div className="lbl">إجمالي الدخل المحصل للمكتب</div>
          <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>مجموع الفواتير المدفوعة</div>
        </div>

        <div className="stat t-amber">
          <div className="si"><Icon name="exec" /></div>
          <div className="num">{fmt(execIncome)} ر.س</div>
          <div className="lbl">أتعاب التنفيذ القضائي</div>
          <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>من فواتير التنفيذ المدفوعة</div>
        </div>

        <div className="stat t-cyan">
          <div className="si"><Icon name="folder" /></div>
          <div className="num">{fmt(due)} ر.س</div>
          <div className="lbl">الذمم المستحقة (فواتير)</div>
          <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>من إجمالي {fmt(issued)} ر.س مُصدَر (بلا الملغاة)</div>
        </div>
      </div>

      {/* تقسيم الدخل — مجموع الأقسام يساوي الإجمالي أعلاه دائماً (قسمة الفواتير المدفوعة نفسها) */}
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>تقسيم الدخل المحصل</h3>
          <span className="sub">مجموع الفواتير المدفوعة موزّعاً على ملفّاتها</span>
        </div>
        <div className="card-b" style={{ padding: 16 }}>
          <div className="kpi-row">
            <span className="t">دخل الاستشارات المحصَّل</span>
            <span className="v">{fmt(consultIncome)} ر.س</span>
          </div>
          <div className="kpi-row">
            <span className="t">دخل أتعاب القضايا المحصَّل</span>
            <span className="v">{fmt(caseIncome)} ر.س</span>
          </div>
          <div className="kpi-row">
            <span className="t">دخل التنفيذ المحصَّل</span>
            <span className="v">{fmt(execIncome)} ر.س</span>
          </div>
          {otherIncome !== 0 && (
            <div className="kpi-row">
              <span className="t">فواتير غير مرتبطة بملفّ</span>
              <span className="v">{fmt(otherIncome)} ر.س</span>
            </div>
          )}
          <div className="kpi-row">
            <span className="t">الإجمالي</span>
            <span className="v" style={{ fontWeight: 800, color: 'var(--c-green, #10b981)' }}>{fmt(totalIncome)} ر.س</span>
          </div>
        </div>
      </div>

      {/* ── قطاعات الدخل الثلاثة ── */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 320px), 1fr))', gap: 16, marginBottom: 16 }}>
        {/* قطاع 1: الاستشارات القانونية */}
        <div className="card">
          <div className="card-h">
            <h3>إيرادات الاستشارات المحجوزة</h3>
            <span className="sub">{bookings} استشارة مدفوعة · {fmt(consultIncome)} ر.س</span>
          </div>
          <div className="card-b" style={{ padding: 16 }}>
            <div className="kpi-row">
              <span className="t">إجمالي إيراد الاستشارات (شامل الضريبة)</span>
              <span className="v" style={{ fontWeight: 800, color: 'var(--c-green, #10b981)' }}>{fmt(consultIncome)} ر.س</span>
            </div>
            <div className="kpi-row">
              <span className="t">عدد الاستشارات المدفوعة</span>
              <span className="v">{bookings} حجز</span>
            </div>
            {/* لا يُخفى النقص ولا يُحسب مالاً: استشارة عليها سداد بلا فاتورة مدفوعة خللُ بيانات */}
            {unbilledPaidConsults > 0 && (
              <div className="action-hint" style={{ marginTop: 10 }}>
                {unbilledPaidConsults} استشارة مسجَّلة مسدَّدة بلا فاتورة مدفوعة — خارج الدخل أعلاه وتحتاج مراجعة.
              </div>
            )}
            <div style={{ marginTop: 14 }}>
              <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', marginBottom: 8 }}>الإيراد حسب نوع القناة:</div>
              {byService.length ? <Bars data={byService} /> : <div className="empty"><Icon name="card" /><b>لا بيانات بعد</b></div>}
            </div>
          </div>
        </div>

        {/* قطاع 2: أتعاب القضايا والفواتير */}
        <div className="card">
          <div className="card-h">
            <h3>أتعاب القضايا والذمم المالية</h3>
            <span className="sub">الفواتير وسندات القبض</span>
          </div>
          <div className="card-b" style={{ padding: 16 }}>
            {/* «للقضايا» و«من القضايا» كانتا كاذبتين: الرقم كان كلّ الفواتير لا فواتير القضايا */}
            <div className="kpi-row">
              <span className="t">إجمالي الفواتير الصادرة (بلا الملغاة)</span>
              <span className="v">{fmt(issued)} ر.س</span>
            </div>
            <div className="kpi-row">
              <span className="t">المبالغ المحصلة فعلياً من فواتير القضايا</span>
              <span className="v" style={{ color: 'var(--c-green, #10b981)', fontWeight: 800 }}>{fmt(caseIncome)} ر.س</span>
            </div>
            <div className="kpi-row">
              <span className="t">الذمم والمستحقات المتبقية بالسوق</span>
              <span className="v" style={{ color: 'var(--c-amber, #f59e0b)', fontWeight: 800 }}>{fmt(due)} ر.س</span>
            </div>
            <div className="kpi-row">
              <span className="t">نسبة تحصيل الفواتير الصادرة</span>
              <span className="v">{invoiceCollectionRate !== null ? `${invoiceCollectionRate}%` : '—'}</span>
            </div>
          </div>
        </div>

        {/* قطاع 3: عوائد وتحصيل التنفيذ القضائي */}
        <div className="card">
          <div className="card-h">
            <h3>عوائد التنفيذ القضائي وتحصيل الديون</h3>
            <span className="sub">مبالغ السندات والتحصيل للعملاء</span>
          </div>
          <div className="card-b" style={{ padding: 16 }}>
            <div className="kpi-row">
              <span className="t">إجمالي ديون السندات المنفذ بها</span>
              <span className="v">{fmt(totalDebtEnforced)} ر.س</span>
            </div>
            <div className="kpi-row">
              <span className="t">المبالغ المستردة فعلياً لصالح العملاء</span>
              <span className="v" style={{ color: 'var(--c-green, #10b981)', fontWeight: 800 }}>{fmt(totalCollectedDebts)} ر.س</span>
            </div>
            <div className="kpi-row">
              <span className="t">نسبة نجاح استرداد أموال التنفيذ</span>
              <span className="v" style={{ color: 'var(--c-green, #10b981)', fontWeight: 800 }}>{collectionRate}%</span>
            </div>
            <div className="kpi-row">
              <span className="t">أتعاب التنفيذ الثابتة المسددة</span>
              <span className="v">{fmt(execFixedFees)} ر.س</span>
            </div>
            <div className="kpi-row">
              <span className="t">عوائد أتعاب نسبة التحصيل</span>
              <span className="v" style={{ color: 'var(--c-blue, #2563eb)', fontWeight: 800 }}>{fmt(execPercentFees)} ر.س</span>
            </div>
          </div>
        </div>
      </div>

      {/* ── كتلة الرواتب والموظفين ── */}
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>رواتب الموظفين الشهرية الثابتة</h3>
          <span className="sub">النشطون على راتب ثابت ({salaries.length} موظف)</span>
        </div>
        <div className="card-b" style={{ padding: 16 }}>
          <div className="kpi-row">
            <span className="t">إجمالي الرواتب الثابتة الشهرية</span>
            <span className="v" style={{ fontWeight: 800 }}>{fmt(salaryTotal)} ر.س</span>
          </div>
          {/* حُذفت «تغطية الرواتب من الدخل المحصل»: نسبةُ إيرادِ العمر كلّه إلى رواتب شهرٍ واحد —
              هي عين عطل «صافي التدفق» بصيغة مئويّة. تعود مع التصفية بالفترة (م١). */}
          <div style={{ marginTop: 12 }}>
            {salaries.map((s) => (
              <div key={s.name} className="kpi-row">
                <span className="t">{s.name}</span>
                <span className="v">{fmt(s.salary)} ر.س</span>
              </div>
            ))}
          </div>
          <div className="action-hint" style={{ marginTop: 10 }}>أجور النِّسَب والجلسات متغيّرة وتُحتسب عند الاستحقاق.</div>
        </div>
      </div>

      {/* حُذفت بطاقة «وسائل وقنوات الدفع المعتمدة»: قائمةٌ تجريبيّة ثابتة من `admin-data` تُعرض كأنّها
          إعدادُ المكتب، والفاتورة لا تحمل وسيلة دفعٍ يُشتقّ منها رقمٌ حقيقيّ. */}
    </>
  );
};

export default AdminRevenue;

