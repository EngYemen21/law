import React from 'react';
import Icon from '@/lib/icons';
import { Bars } from '@/components/babylon/admin-charts';
import type { BarDatum } from '@/lib/admin-data';

// تقارير الإدارة — تجميعات حقيقية من قاعدة البيانات

interface Props {
  stats: { totalTickets: number; closureRate: number; meetingsHeld: number; activeCases: number };
  byDept: BarDatum[];
}

const AdminReports: React.FC<Props> = ({ stats, byDept }) => {
  const cards: [string, string, string, string][] = [
    ['t-blue', 'folder', String(stats.totalTickets), 'إجمالي التذاكر'],
    ['t-green', 'check', `${stats.closureRate}%`, 'نسبة الإغلاق'],
    ['t-cyan', 'video', String(stats.meetingsHeld), 'اجتماعات منعقدة'],
    ['t-amber', 'scale', String(stats.activeCases), 'قضايا نشطة'],
  ];

  return (
    <>
      <div className="stats">
        {cards.map((s, i) => (
          <div key={i} className={`stat ${s[0]}`}>
            <div className="si"><Icon name={s[1]} /></div>
            <div className="num">{s[2]}</div>
            <div className="lbl">{s[3]}</div>
          </div>
        ))}
      </div>
      <div className="card">
        <div className="card-h"><h3>التذاكر حسب القسم</h3><span className="sub">عدد</span></div>
        <div className="card-b" style={{ padding: 18 }}>
          {byDept.length ? <Bars data={byDept} /> : <div className="empty"><Icon name="folder" /><b>لا تذاكر بعد</b></div>}
        </div>
      </div>
    </>
  );
};

export default AdminReports;
