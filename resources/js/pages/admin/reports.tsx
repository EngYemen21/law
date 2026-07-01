import React from 'react';
import Icon from '@/lib/icons';
import { Bars } from '@/components/babylon/admin-charts';
import type { BarDatum } from '@/lib/admin-data';

// يطابق adReports في index (82).html

const TICKET_BY_DEPT: BarDatum[] = [
  { m: 'تجاري', v: 42 }, { m: 'عمالي', v: 28 }, { m: 'عقاري', v: 21 },
  { m: 'أحوال', v: 19 }, { m: 'تنفيذ', v: 16 }, { m: 'أخرى', v: 16 },
];

const STATS: [string, string, string, string][] = [
  ['t-blue', 'folder', '142', 'إجمالي التذاكر'],
  ['t-green', 'check', '86%', 'نسبة الإغلاق'],
  ['t-cyan', 'video', '38', 'اجتماعات منعقدة'],
];

const AdminReports: React.FC = () => (
  <>
    <div className="stats">
      {STATS.map((s, i) => (
        <div key={i} className={`stat ${s[0]}`}>
          <div className="si"><Icon name={s[1]} /></div>
          <div className="num">{s[2]}</div>
          <div className="lbl">{s[3]}</div>
        </div>
      ))}
    </div>
    <div className="card">
      <div className="card-h"><h3>التذاكر حسب القسم</h3><span className="sub">عدد</span></div>
      <div className="card-b" style={{ padding: 18 }}><Bars data={TICKET_BY_DEPT} /></div>
    </div>
  </>
);

export default AdminReports;
