import { router } from '@inertiajs/react';
import React from 'react';
import Badge from '@/components/babylon/Badge';

// إشراف الإدارة — كل التذاكر (بيانات حقيقية من الخادم)

interface EmpTicket { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; }
interface Props { tickets: EmpTicket[]; }

const openTicket = (no: string) => router.visit(`/admin/tickets/${encodeURIComponent(no)}`);

const AdminTickets: React.FC<Props> = ({ tickets }) => (
  <div className="card">
    <div className="card-h">
      <h3>كل التذاكر</h3>
      <span className="sub">{tickets.length} تذكرة</span>
    </div>
    <div className="card-b t-wrap">
      {tickets.length ? (
        <table className="tbl">
          <thead>
            <tr>
              <th>التذكرة</th>
              <th>العميل</th>
              <th>النوع</th>
              <th>القسم</th>
              <th>المحامي</th>
              <th>الحالة</th>
            </tr>
          </thead>
          <tbody>
            {tickets.map((t) => (
              <tr key={t.no} className="click" onClick={() => openTicket(t.no)}>
                <td className="mono">{t.no}</td>
                <td>{t.client}</td>
                <td className="muted">{t.type}</td>
                <td className="muted">{t.dept}</td>
                <td className="muted">{t.lawyer}</td>
                <td><Badge text={t.status} tone={t.tone} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <div className="empty"><b>لا تذاكر</b></div>
      )}
    </div>
  </div>
);

export default AdminTickets;
