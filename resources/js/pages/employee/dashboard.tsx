import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useTicketActions } from '@/components/babylon/TicketActions';
import { SYS_TICKETS, maskClient } from '@/lib/employee-data';

// يطابق emHome في index (82).html

const openTicket = (no: string) =>
  router.visit(`/employee/tickets/chat?no=${encodeURIComponent(no)}`);

const EmployeeDashboard: React.FC = () => {
  const { openReqDocs, openSchedule, node } = useTicketActions();
  const needAction = SYS_TICKETS.filter((t) => t.status !== 'مغلقة');

  const stats: StatItem[] = [
    ['t-blue', 'folder', needAction.length, 'تذاكر بانتظار إجراء'],
    ['t-amber', 'upload', SYS_TICKETS.filter((t) => t.status === 'بانتظار مرفقات').length, 'نواقص مطلوبة'],
    ['t-cyan', 'cal', 3, 'مواعيد اليوم'],
    ['t-green', 'reply', 2, 'محوّلة للقسم'],
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
          <table className="tbl">
            <thead>
              <tr>
                <th>التذكرة</th>
                <th>العميل</th>
                <th>النوع</th>
                <th>الحالة</th>
                <th>إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {needAction.map((t) => (
                <tr key={t.no} className="click" onClick={() => openTicket(t.no)}>
                  <td className="mono">{t.no}</td>
                  <td>{maskClient(t.client)}</td>
                  <td className="muted">{t.type}</td>
                  <td><Badge text={t.status} tone={t.tone} /></td>
                  <td>
                    <div
                      className="row-acts"
                      style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}
                      onClick={(e) => e.stopPropagation()}
                    >
                      <button className="btn sm" onClick={() => openTicket(t.no)} type="button">
                        <Icon name="reply" /> فتح المحادثة
                      </button>
                      <button className="btn soft sm" onClick={() => openReqDocs(t.no)} type="button">
                        <Icon name="upload" /> نواقص
                      </button>
                      <button className="btn soft sm" onClick={() => openSchedule(t.no)} type="button">
                        <Icon name="cal" /> جدولة
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {node}
    </>
  );
};

export default EmployeeDashboard;
