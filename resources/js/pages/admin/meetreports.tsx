import React from 'react';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { BarChart } from '@/components/babylon/admin-charts';
import { type FullMeetingCard } from '@/lib/meeting-ui';

// يطابق meetReportsView في index (82).html — الإحصاءات حقيقية من الخادم

const AdminMeetReports: React.FC<{ meetings: FullMeetingCard[] }> = ({ meetings }) => {
  const byType: Record<string, number> = {};
  meetings.forEach((m) => { byType[m.type] = (byType[m.type] || 0) + 1; });
  const typeData: [string, number][] = Object.keys(byType).length
    ? Object.keys(byType).map((k) => [k, byType[k]])
    : [['—', 0]];

  // توزيع الاجتماعات على العملاء/الجهات المرتبطة
  const byClient: Record<string, number> = {};
  meetings.forEach((m) => { byClient[m.client] = (byClient[m.client] || 0) + 1; });
  const clientData: [string, number][] = Object.keys(byClient).length
    ? Object.keys(byClient).map((k) => [k, byClient[k]])
    : [['—', 0]];

  const done = meetings.filter((m) => m.status === 'منتهٍ');
  const att = done.length ? Math.round(done.reduce((a, m) => a + (m.attend || 0), 0) / done.length) : 0;
  const cancelled = meetings.filter((m) => m.status === 'ملغى').length;
  const postponed = meetings.filter((m) => m.status === 'مؤجل').length;
  const up = meetings.filter((m) => m.status === 'قادم').length;
  const approved = meetings.filter((m) => m.approve === 'معتمد').length;

  const stats: StatItem[] = [
    ['t-blue', 'video', meetings.length, 'إجمالي الاجتماعات'],
    ['t-cyan', 'video', up, 'قادمة'],
    ['t-green', 'user', `${att}%`, 'نسبة الحضور'],
    ['t-green', 'check', approved, 'معتمدة'],
    ['t-grey', 'clock', done.length, 'منتهية'],
    ['t-blue', 'doc', done.filter((m) => m.minutes).length, 'بمحضر موثّق'],
    ['t-red', 'out', cancelled, 'ملغاة'],
    ['t-amber', 'clock', postponed, 'مؤجلة'],
  ];

  return (
    <>
      <div className="greet">
        <h2>تقارير الاجتماعات</h2>
        <p>إحصاءات تفاعلية: حسب النوع والجهة، نسب الحضور والاعتماد والتوثيق.</p>
      </div>
      <StatRow items={stats} />
      <BarChart title="الاجتماعات حسب النوع" data={typeData} />
      <BarChart title="الاجتماعات حسب العميل/الجهة" data={clientData} />
    </>
  );
};

export default AdminMeetReports;
