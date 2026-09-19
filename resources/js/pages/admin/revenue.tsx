import React from 'react';
import { Bars } from '@/components/babylon/admin-charts';
import type { BarDatum } from '@/lib/admin-data';
import { PAY_METHODS } from '@/lib/admin-data';
import Icon from '@/lib/icons';

// إيرادات الإدارة — تجميعات ومؤشرات مالية متكاملة للاستشارات والقضايا والتنفيذ القضائي والرواتب

const fmt = (n?: number) => (n ?? 0).toLocaleString('en-US');

interface Props {
  bookings: number;
  bookingRevenue: number;
  issued: number;
  collected: number;
  due: number;
  execFixedFees?: number;
  execPercentFees?: number;
  totalExecFees?: number;
  totalDebtEnforced?: number;
  totalCollectedDebts?: number;
  collectionRate?: number;
  totalFirmGross?: number;
  netCashFlow?: number;
  byService: BarDatum[];
  salaries: { name: string; salary: number }[];
  salaryTotal: number;
}

const AdminRevenue: React.FC<Props> = ({
  bookings,
  bookingRevenue,
  issued,
  collected,
  due,
  execFixedFees = 0,
  execPercentFees = 0,
  totalExecFees = 0,
  totalDebtEnforced = 0,
  totalCollectedDebts = 0,
  collectionRate = 0,
  totalFirmGross,
  netCashFlow,
  byService,
  salaries,
  salaryTotal,
}) => {
  const firmGross = totalFirmGross ?? (bookingRevenue + collected + totalExecFees);
  const netFlow = netCashFlow ?? (firmGross - salaryTotal);

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
      <div className="stats" style={{ marginBottom: 16 }}>
        <div className="stat t-blue">
          <div className="si"><Icon name="card" /></div>
          <div className="num">{fmt(firmGross)} ر.س</div>
          <div className="lbl">إجمالي الدخل المحصل للمكتب</div>
          <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>استشارات + قضايا + تنفيذ</div>
        </div>

        <div className="stat t-green">
          <div className="si"><Icon name="check" /></div>
          <div className="num" style={{ color: netFlow >= 0 ? undefined : 'var(--c-red, #ef4444)' }}>
            {fmt(netFlow)} ر.س
          </div>
          <div className="lbl">صافي التدفق بعد الرواتب</div>
          <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>الدخل المحصل مطروحاً منه الرواتب</div>
        </div>

        <div className="stat t-amber">
          <div className="si"><Icon name="exec" /></div>
          <div className="num">{fmt(totalExecFees)} ر.س</div>
          <div className="lbl">أتعاب التنفيذ القضائي</div>
          <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>ثابتة + نسب التحصيل</div>
        </div>

        <div className="stat t-cyan">
          <div className="si"><Icon name="folder" /></div>
          <div className="num">{fmt(due)} ر.س</div>
          <div className="lbl">الذمم المستحقة (فواتير)</div>
          <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>من إجمالي {fmt(issued)} ر.س مُصدَر</div>
        </div>
      </div>

      {/* ── قطاعات الدخل الثلاثة ── */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 16, marginBottom: 16 }}>
        {/* قطاع 1: الاستشارات القانونية */}
        <div className="card">
          <div className="card-h">
            <h3>إيرادات الاستشارات المحجوزة</h3>
            <span className="sub">{bookings} استشارة مدفوعة · {fmt(bookingRevenue)} ر.س</span>
          </div>
          <div className="card-b" style={{ padding: 16 }}>
            <div className="kpi-row">
              <span className="t">إجمالي إيراد الاستشارات (شامل الضريبة)</span>
              <span className="v" style={{ fontWeight: 800, color: 'var(--c-green, #10b981)' }}>{fmt(bookingRevenue)} ر.س</span>
            </div>
            <div className="kpi-row">
              <span className="t">عدد الاستشارات المدفوعة</span>
              <span className="v">{bookings} حجز</span>
            </div>
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
            <div className="kpi-row">
              <span className="t">إجمالي الفواتير الصادرة للقضايا</span>
              <span className="v">{fmt(issued)} ر.س</span>
            </div>
            <div className="kpi-row">
              <span className="t">المبالغ المحصلة فعلياً من القضايا</span>
              <span className="v" style={{ color: 'var(--c-green, #10b981)', fontWeight: 800 }}>{fmt(collected)} ر.س</span>
            </div>
            <div className="kpi-row">
              <span className="t">الذمم والمستحقات المتبقية بالسوق</span>
              <span className="v" style={{ color: 'var(--c-amber, #f59e0b)', fontWeight: 800 }}>{fmt(due)} ر.س</span>
            </div>
            <div className="kpi-row">
              <span className="t">نسبة تحصيل الفواتير الصادرة</span>
              <span className="v">{issued > 0 ? Math.round((collected / issued) * 100) : 0}%</span>
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
          <div className="kpi-row">
            <span className="t">تغطية الرواتب من الدخل المحصل</span>
            <span className="v" style={{ color: salaryTotal > 0 && firmGross >= salaryTotal ? 'var(--c-green, #10b981)' : 'var(--c-amber, #f59e0b)', fontWeight: 800 }}>
              {salaryTotal > 0 ? Math.round((firmGross / salaryTotal) * 100) : 0}%
            </span>
          </div>
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

      {/* ── وسائل الدفع المتاحة ── */}
      <div className="card">
        <div className="card-h"><h3>وسائل وقنوات الدفع المعتمدة</h3></div>
        <div className="card-b" style={{ padding: 16 }}>
          <div className="chips">{PAY_METHODS.map((p) => <span key={p} className="chip">{p}</span>)}</div>
        </div>
      </div>
    </>
  );
};

export default AdminRevenue;

