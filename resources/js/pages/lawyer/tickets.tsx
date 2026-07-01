import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';

// تذاكر المستشار المحالة — بيانات حقيقية من الخادم (لها ملخص ملف)

interface EmpTicket { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; converted?: boolean; }
interface Props { tickets: EmpTicket[]; }

const openTicket = (no: string) => router.visit(`/lawyer/tickets/${encodeURIComponent(no)}`);

const LawyerTickets: React.FC<Props> = ({ tickets }) => {
  const toast = useToast();

  const convert = (no: string) =>
    router.post(`/lawyer/tickets/${encodeURIComponent(no)}/convert`, {}, {
      preserveScroll: true, onSuccess: () => toast('تم تحويل التذكرة إلى قضية'),
    });

  return (
    <div className="card">
      <div className="card-h">
        <h3>تذاكري</h3>
        <span className="sub">المحالة إلى المستشار · {tickets.length}</span>
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
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }} onClick={(e) => e.stopPropagation()}>
                      <button className="btn soft sm" onClick={() => openTicket(t.no)} type="button">
                        <Icon name="scale" /> فتح
                      </button>
                      {t.status === 'مكتملة' && (
                        t.converted
                          ? <Badge text="محوّلة لقضية" tone="b-cyan" />
                          : (
                            <button className="btn sm" onClick={() => convert(t.no)} type="button">
                              <Icon name="scale" /> تحويل لقضية
                            </button>
                          )
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div className="empty"><Icon name="folder" /><b>لا تذاكر محالة إليك بعد</b></div>
        )}
      </div>
    </div>
  );
};

export default LawyerTickets;
