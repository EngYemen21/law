import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { useTicketActions } from '@/components/babylon/TicketActions';

// يطابق emTickets — التذاكر من قاعدة البيانات (مشتركة مع العميل)

interface EmpTicket { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; converted?: boolean; }

const openTicket = (no: string) => router.visit(`/employee/tickets/${encodeURIComponent(no)}`);

const EmployeeTickets: React.FC<{ tickets: EmpTicket[] }> = ({ tickets }) => {
  const toast = useToast();
  const { openTransfer, node } = useTicketActions();

  const convert = (no: string) =>
    router.post(`/employee/tickets/${encodeURIComponent(no)}/convert`, {}, {
      preserveScroll: true, onSuccess: () => toast('تم تحويل التذكرة إلى قضية'),
    });

  return (
    <div className="card">
      <div className="card-h">
        <h3>كل التذاكر</h3>
        <span className="sub">{tickets.length} تذكرة</span>
      </div>
      <div className="card-b t-wrap">
        <table className="tbl">
          <thead>
            <tr>
              <th>التذكرة</th>
              <th>العميل</th>
              <th>النوع</th>
              <th>القسم</th>
              <th>الحالة</th>
              <th>إجراءات</th>
            </tr>
          </thead>
          <tbody>
            {tickets.map((t) => (
              <tr key={t.no} className="click" onClick={() => openTicket(t.no)}>
                <td className="mono">{t.no}</td>
                <td>{t.client}</td>
                <td className="muted">{t.type}</td>
                <td className="muted">{t.dept}</td>
                <td><Badge text={t.status} tone={t.tone} /></td>
                <td>
                  <div
                    style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}
                    onClick={(e) => e.stopPropagation()}
                  >
                    <button className="btn sm" onClick={() => openTicket(t.no)} type="button">
                      <Icon name="reply" /> فتح المحادثة
                    </button>
                    {t.status === 'مكتملة' && (
                      t.converted
                        ? <Badge text="محوّلة لقضية" tone="b-cyan" />
                        : (
                          <button className="btn soft sm" onClick={() => convert(t.no)} type="button">
                            <Icon name="scale" /> تحويل لقضية
                          </button>
                        )
                    )}
                    <button className="btn soft sm" onClick={() => openTransfer(t.no)} type="button">
                      <Icon name="reply" /> تحويل
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {node}
    </div>
  );
};

export default EmployeeTickets;
