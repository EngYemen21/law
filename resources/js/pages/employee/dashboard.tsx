import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';

// لوحة الموظف — تذاكر تحتاج إجراءً وعدّادات حقيقية من الخادم

interface EmpTicket { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; }
interface Props {
  tickets: EmpTicket[];
  counts: { needAction: number; missingDocs: number; todayAppts: number; referred: number };
}

const openTicket = (no: string) => router.visit(`/employee/tickets/${encodeURIComponent(no)}`);

const EmployeeDashboard: React.FC<Props> = ({ tickets, counts }) => {
  const stats: StatItem[] = [
    ['t-blue', 'folder', counts.needAction, 'تذاكر بانتظار إجراء'],
    ['t-amber', 'upload', counts.missingDocs, 'نواقص مطلوبة'],
    ['t-cyan', 'cal', counts.todayAppts, 'مواعيد قادمة'],
    ['t-green', 'reply', counts.referred, 'محوّلة للقسم'],
  ];

  return (
    <>
      <div className="greet">
        <h2>لوحة الموظف</h2>
        <p>متابعة التذاكر، طلب النواقص، جدولة المواعيد، وتحويل التذاكر.</p>
      </div>

      <StatRow items={stats} />

      <div className="card">
        <div className="card-h"><h3>تذاكر تحتاج إجراءً</h3></div>
        <div className="card-b t-wrap">
          {tickets.length ? (
            <table className="tbl">
              <thead>
                <tr><th>التذكرة</th><th>العميل</th><th>النوع</th><th>الحالة</th><th></th></tr>
              </thead>
              <tbody>
                {tickets.map((t) => (
                  <tr key={t.no} className="click" onClick={() => openTicket(t.no)}>
                    <td className="mono">{t.no}</td>
                    <td>{t.client}</td>
                    <td className="muted">{t.type}</td>
                    <td><Badge text={t.status} tone={t.tone} /></td>
                    <td>
                      <button className="btn sm" onClick={(e) => { e.stopPropagation(); openTicket(t.no); }} type="button">
                        <Icon name="reply" /> فتح المحادثة
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="empty"><Icon name="folder" /><b>لا تذاكر تحتاج إجراءً حالياً</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default EmployeeDashboard;
