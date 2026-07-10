import React from 'react';
import Icon from '@/lib/icons';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';

// لوحة الإدارة — نظرة شاملة حقيقية من الخادم

const fmt = (n: number) => n.toLocaleString('en-US');

interface Props {
  stats: { clients: number; openTickets: number; revenue: number; pendingMeetings: number };
  activity: { ico: string; title: string; sub: string }[];
}

const AdminDashboard: React.FC<Props> = ({ stats, activity }) => {
  const items: StatItem[] = [
    ['t-blue', 'user', stats.clients, 'العملاء'],
    ['t-cyan', 'folder', stats.openTickets, 'تذاكر مفتوحة'],
    ['t-green', 'card', fmt(stats.revenue), 'الإيراد المحصّل (ر.س)'],
    ['t-amber', 'video', stats.pendingMeetings, 'اجتماعات بانتظار الاعتماد'],
  ];

  return (
    <>
      <div className="greet">
        <h2>لوحة الإدارة</h2>
        <p>نظرة شاملة على العملاء والتذاكر والإيرادات والاعتمادات.</p>
      </div>

      <StatRow items={items} />

      <div className="card">
        <div className="card-h"><h3>أحدث النشاط</h3></div>
        <div className="card-b">
          {activity.length ? activity.map((a, i) => (
            <div key={i} className="item">
              <div className="iico"><Icon name={a.ico} /></div>
              <div className="imeta"><b>{a.title}</b><span>{a.sub}</span></div>
            </div>
          )) : (
            <div className="empty"><Icon name="folder" /><b>لا نشاط بعد</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default AdminDashboard;
