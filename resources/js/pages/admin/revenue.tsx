import React from 'react';
import Icon from '@/lib/icons';
import { Bars } from '@/components/babylon/admin-charts';
import { STAFF } from '@/lib/employee-data';
import { REVENUE, REV_BY_SVC, PAY_METHODS } from '@/lib/admin-data';

// يطابق adRevenue في index (82).html
// BOOKING_REVENUE يبدأ فارغاً في الأصل (var BOOKING_REVENUE=[];) — يُملأ عند الحجز فقط

const AdminRevenue: React.FC = () => {
  const act = STAFF.filter((s) => (s.status || 'نشط') !== 'موقوف');
  const tot = act.reduce((a, s) => a + (s.salary || 0), 0);
  const fixed = act.filter((s) => (s.salary || 0) > 0);

  return (
    <>
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h">
          <h3>إيرادات الاستشارات المحجوزة (مدفوعة)</h3>
          <span className="sub">0 حجز · 0 ر.س</span>
        </div>
        <div className="card-b">
          <div className="empty"><Icon name="card" /><b>لا حجوزات مدفوعة بعد</b></div>
        </div>
      </div>

      <div className="grid-2">
        <div className="card">
          <div className="card-h"><h3>الإيراد الشهري</h3><span className="sub">ألف ر.س</span></div>
          <div className="card-b" style={{ padding: 18 }}><Bars data={REVENUE} /></div>
        </div>
        <div className="card">
          <div className="card-h"><h3>الإيراد حسب الخدمة</h3><span className="sub">ألف ر.س</span></div>
          <div className="card-b" style={{ padding: 18 }}><Bars data={REV_BY_SVC} /></div>
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>رواتب الموظفين الشهرية</h3><span className="sub">النشطون فقط</span></div>
        <div className="card-b" style={{ padding: 16 }}>
          <div className="kpi-row"><span className="t">إجمالي الرواتب الثابتة الشهرية</span><span className="v">{tot.toLocaleString()} ر.س</span></div>
          <div className="kpi-row"><span className="t">عدد الموظفين النشطين</span><span className="v">{act.length}</span></div>
          <div className="kpi-row"><span className="t">على راتب ثابت</span><span className="v">{fixed.length}</span></div>
          {fixed.map((s) => (
            <div key={s.name} className="kpi-row"><span className="t">{s.name}</span><span className="v">{(s.salary || 0).toLocaleString()} ر.س</span></div>
          ))}
          <div className="action-hint" style={{ marginTop: 10 }}>أجور النِّسَب والجلسات متغيّرة وتُحتسب عند الاستحقاق.</div>
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>وسائل الدفع المتاحة</h3></div>
        <div className="card-b" style={{ padding: 16 }}>
          <div className="chips">
            {PAY_METHODS.map((p) => <span key={p} className="chip">{p}</span>)}
          </div>
        </div>
      </div>
    </>
  );
};

export default AdminRevenue;
