import React from 'react';
import Icon from '@/lib/icons';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { Bars } from '@/components/babylon/admin-charts';
import { SYS_TICKETS } from '@/lib/employee-data';
import { CLIENTS, FULL_MEETINGS, REVENUE, AD_ACTIVITY } from '@/lib/admin-data';

// يطابق adHome في index (82).html

const AdminDashboard: React.FC = () => {
  const stats: StatItem[] = [
    ['t-blue', 'user', CLIENTS.length, 'العملاء'],
    ['t-cyan', 'folder', SYS_TICKETS.filter((t) => t.status !== 'مغلقة').length, 'تذاكر مفتوحة'],
    ['t-green', 'card', '312K', 'إيراد يونيو (ر.س)'],
    ['t-amber', 'video', FULL_MEETINGS.filter((m) => m.approve !== 'معتمد').length, 'اجتماعات بانتظار الاعتماد'],
  ];

  return (
    <>
      <div className="greet">
        <h2>لوحة الإدارة</h2>
        <p>نظرة شاملة على العملاء والتذاكر والمحامين والإيرادات والاعتمادات.</p>
      </div>

      <StatRow items={stats} />

      <div className="grid-2">
        <div className="card">
          <div className="card-h"><h3>الإيرادات الشهرية</h3><span className="sub">ألف ر.س</span></div>
          <div className="card-b" style={{ padding: 18 }}><Bars data={REVENUE} /></div>
        </div>
        <div className="card">
          <div className="card-h"><h3>أحدث النشاط</h3></div>
          <div className="card-b">
            {AD_ACTIVITY.map((a, i) => (
              <div key={i} className="item">
                <div className="iico"><Icon name={a[0]} /></div>
                <div className="imeta"><b>{a[1]}</b><span>{a[2]}</span></div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </>
  );
};

export default AdminDashboard;
