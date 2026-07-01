import React from 'react';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { BarChart } from '@/components/babylon/admin-charts';
import { FULL_MEETINGS } from '@/lib/admin-data';

// يطابق meetReportsView في index (82).html

const AdminMeetReports: React.FC = () => {
  const byType: Record<string, number> = {};
  FULL_MEETINGS.forEach((m) => { byType[m.type] = (byType[m.type] || 0) + 1; });
  const typeData: [string, number][] = Object.keys(byType).map((k) => [k, byType[k]]);

  const lawyerData: [string, number][] = [
    ['أ. سارة القحطاني', 5], ['أ. خالد المالكي', 3], ['أ. ماجد العتيبي', 2], ['أ. ريم الحربي', 2],
  ];
  const branchData: [string, number][] = [['الرئيسي — جدة', 7], ['الرياض', 4], ['الدمام', 1]];

  const done = FULL_MEETINGS.filter((m) => m.status === 'منتهٍ');
  const att = done.length ? Math.round(done.reduce((a, m) => a + (m.attend || 0), 0) / done.length) : 0;
  const cancelled = FULL_MEETINGS.filter((m) => m.status === 'ملغى').length;
  const postponed = FULL_MEETINGS.filter((m) => m.status === 'مؤجل').length;

  const stats: StatItem[] = [
    ['t-blue', 'video', '3', 'اجتماعات اليوم'],
    ['t-cyan', 'video', '38', 'هذا الشهر'],
    ['t-green', 'user', `${att}%`, 'نسبة الحضور'],
    ['t-amber', 'exec', '82%', 'إنجاز المهام'],
    ['t-grey', 'clock', '52 د', 'متوسط المدة'],
    ['t-blue', 'check', '1.8 يوم', 'متوسط إغلاق المهام'],
    ['t-red', 'out', cancelled, 'ملغاة'],
    ['t-amber', 'clock', postponed, 'مؤجلة'],
  ];

  return (
    <>
      <div className="greet">
        <h2>تقارير الاجتماعات</h2>
        <p>إحصاءات تفاعلية: حسب النوع والمحامي والفرع، نسب الحضور وإنجاز المهام.</p>
      </div>
      <StatRow items={stats} />
      <BarChart title="الاجتماعات حسب النوع" data={typeData} />
      <BarChart title="الاجتماعات لكل محامٍ" data={lawyerData} />
      <BarChart title="الاجتماعات لكل فرع" data={branchData} />
    </>
  );
};

export default AdminMeetReports;
