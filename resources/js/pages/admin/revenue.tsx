import React from 'react';
import Icon from '@/lib/icons';
import { Bars } from '@/components/babylon/admin-charts';
import type { BarDatum } from '@/lib/admin-data';
import { PAY_METHODS } from '@/lib/admin-data';

// إيرادات الإدارة — أرقام حقيقية من الاستشارات والفواتير والرواتب

const fmt = (n: number) => n.toLocaleString('en-US');

interface Props {
  bookings: number;
  bookingRevenue: number;
  issued: number;
  collected: number;
  due: number;
  byService: BarDatum[];
  salaries: { name: string; salary: number }[];
  salaryTotal: number;
}

const AdminRevenue: React.FC<Props> = ({ bookings, bookingRevenue, issued, collected, due, byService, salaries, salaryTotal }) => (
  <>
    <div className="stats" style={{ marginBottom: 14 }}>
      <div className="stat t-blue"><div className="si"><Icon name="card" /></div><div className="num">{fmt(issued)} ر.س</div><div className="lbl">إجمالي الفواتير المُصدَرة</div></div>
      <div className="stat t-green"><div className="si"><Icon name="check" /></div><div className="num">{fmt(collected)} ر.س</div><div className="lbl">المحصّل</div></div>
      <div className="stat t-amber"><div className="si"><Icon name="folder" /></div><div className="num">{fmt(due)} ر.س</div><div className="lbl">المستحق (الذمم)</div></div>
      <div className="stat t-cyan"><div className="si"><Icon name="video" /></div><div className="num">{bookings}</div><div className="lbl">استشارات محجوزة</div></div>
    </div>

    <div className="card" style={{ marginBottom: 14 }}>
      <div className="card-h">
        <h3>إيرادات الاستشارات المحجوزة (مدفوعة)</h3>
        <span className="sub">{bookings} حجز · {fmt(bookingRevenue)} ر.س</span>
      </div>
      <div className="card-b">
        {bookings
          ? <div className="kpi-row"><span className="t">إجمالي إيراد الاستشارات (شامل الضريبة)</span><span className="v">{fmt(bookingRevenue)} ر.س</span></div>
          : <div className="empty"><Icon name="card" /><b>لا حجوزات مدفوعة بعد</b></div>}
      </div>
    </div>

    <div className="card" style={{ marginBottom: 14 }}>
      <div className="card-h"><h3>الإيراد حسب نوع الاستشارة</h3><span className="sub">ر.س</span></div>
      <div className="card-b" style={{ padding: 18 }}>
        {byService.length ? <Bars data={byService} /> : <div className="empty"><Icon name="card" /><b>لا بيانات إيراد بعد</b></div>}
      </div>
    </div>

    <div className="card">
      <div className="card-h"><h3>رواتب الموظفين الشهرية</h3><span className="sub">النشطون على راتب ثابت</span></div>
      <div className="card-b" style={{ padding: 16 }}>
        <div className="kpi-row"><span className="t">إجمالي الرواتب الثابتة الشهرية</span><span className="v">{fmt(salaryTotal)} ر.س</span></div>
        <div className="kpi-row"><span className="t">عدد الموظفين على راتب ثابت</span><span className="v">{salaries.length}</span></div>
        {salaries.map((s) => (
          <div key={s.name} className="kpi-row"><span className="t">{s.name}</span><span className="v">{fmt(s.salary)} ر.س</span></div>
        ))}
        <div className="action-hint" style={{ marginTop: 10 }}>أجور النِّسَب والجلسات متغيّرة وتُحتسب عند الاستحقاق.</div>
      </div>
    </div>

    <div className="card">
      <div className="card-h"><h3>وسائل الدفع المتاحة</h3></div>
      <div className="card-b" style={{ padding: 16 }}>
        <div className="chips">{PAY_METHODS.map((p) => <span key={p} className="chip">{p}</span>)}</div>
      </div>
    </div>
  </>
);

export default AdminRevenue;
