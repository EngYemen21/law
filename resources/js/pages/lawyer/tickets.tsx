import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import QuickTicketModal, { type TicketPreviewData } from '@/components/babylon/QuickTicketModal';
import { useCan } from '@/lib/permissions';

// تذاكر المستشار المحالة — بيانات حقيقية من الخادم (بملخص أو بانتظار تحليله)

interface EmpTicket { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; converted?: boolean; awaitingSummary?: boolean; }
interface Props { tickets: EmpTicket[]; }

const openTicket = (no: string) => router.visit(`/lawyer/tickets/${encodeURIComponent(no)}`);

const LawyerTickets: React.FC<Props> = ({ tickets }) => {
  const toast = useToast();
  const canManageCases = useCan()('إدارة القضايا والأتعاب');
  const [previewTicket, setPreviewTicket] = useState<TicketPreviewData | null>(null);

  const convert = (no: string) =>
    router.post(`/lawyer/tickets/${encodeURIComponent(no)}/convert`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('تم تحويل التذكرة إلى قضية'),
      onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر تحويل التذكرة لقضية'}`),
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
                  <td>
                    <Badge text={t.status} tone={t.tone} />
                    {t.awaitingSummary && <Badge text="بانتظار التحليل" tone="b-grey" />}
                  </td>
                  <td>
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }} onClick={(e) => e.stopPropagation()}>
                      <button className="btn soft sm" onClick={() => setPreviewTicket(t)} type="button">
                        <Icon name="doc" /> معاينة
                      </button>
                      <button className="btn sm" onClick={() => openTicket(t.no)} type="button">
                        <Icon name="scale" /> دراسة الملف
                      </button>
                      {t.status === 'مكتملة' && (
                        t.converted
                          ? <Badge text="محوّلة لقضية" tone="b-cyan" />
                          : canManageCases && (
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
      <QuickTicketModal
        ticket={previewTicket}
        open={Boolean(previewTicket)}
        role="lawyer"
        onClose={() => setPreviewTicket(null)}
      />
    </div>
  );
};

export default LawyerTickets;
