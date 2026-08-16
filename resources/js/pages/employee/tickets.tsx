import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import TicketOpsModals, { type LawyerOption, type TicketOpsKind } from '@/components/babylon/TicketOpsModals';
import QuickTicketModal, { type TicketPreviewData } from '@/components/babylon/QuickTicketModal';
import { useCan } from '@/lib/permissions';

// يطابق emTickets — التذاكر من قاعدة البيانات (مشتركة مع العميل)

interface EmpTicket { no: string; client: string; type: string; dept: string; lawyer: string; lawyerId?: number | null; status: string; tone: string; converted?: boolean; }

const openTicket = (no: string) => router.visit(`/employee/tickets/${encodeURIComponent(no)}`);

const EmployeeTickets: React.FC<{ tickets: EmpTicket[]; lawyers: LawyerOption[] }> = ({ tickets, lawyers }) => {
  const toast = useToast();
  const can = useCan();
  const canTransfer = can('تحويل التذاكر');
  const canReqDocs = can('الرد على العملاء');
  const [previewTicket, setPreviewTicket] = useState<TicketPreviewData | null>(null);
  // مودالا التحويل والنواقص يعملان على تذكرة الصف المختار (نسخة واحدة مشتركة)
  const [opsKind, setOpsKind] = useState<TicketOpsKind>(null);
  const [opsTicket, setOpsTicket] = useState<EmpTicket | null>(null);
  const openTransfer = (t: EmpTicket) => { setOpsTicket(t); setOpsKind('transfer'); };
  const openReqDocs = (t: EmpTicket) => { setOpsTicket(t); setOpsKind('reqdocs'); };

  const convert = (no: string) =>
    router.post(`/employee/tickets/${encodeURIComponent(no)}/convert`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('تم تحويل التذكرة إلى قضية'),
      onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر تحويل التذكرة لقضية'}`),
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
                    <button className="btn soft sm" onClick={() => setPreviewTicket(t)} type="button">
                      <Icon name="doc" /> معاينة سريعة
                    </button>
                    <button className="btn sm" onClick={() => openTicket(t.no)} type="button">
                      <Icon name="reply" /> فتح المحادثة
                    </button>
                    {/* طلب نواقص مباشرة من القائمة (يطابق زر «نواقص» المرجعي) — بصلاحية الرد على العملاء */}
                    {canReqDocs && (
                      <button className="btn soft sm" onClick={() => openReqDocs(t)} type="button">
                        <Icon name="upload" /> نواقص
                      </button>
                    )}
                    {t.status === 'مكتملة' && (
                      t.converted
                        ? <Badge text="محوّلة لقضية" tone="b-cyan" />
                        : (
                          <button className="btn soft sm" onClick={() => convert(t.no)} type="button">
                            <Icon name="scale" /> تحويل لقضية
                          </button>
                        )
                    )}
                    {canTransfer && (
                      <button className="btn soft sm" onClick={() => openTransfer(t)} type="button">
                        <Icon name="reply" /> تحويل
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <QuickTicketModal
        ticket={previewTicket}
        open={Boolean(previewTicket)}
        role="employee"
        onClose={() => setPreviewTicket(null)}
        onTransfer={canTransfer ? (no) => {
          const t = tickets.find((x) => x.no === no);
          if (t) { setPreviewTicket(null); openTransfer(t); }
        } : undefined}
      />
      <TicketOpsModals
        kind={opsKind}
        ticketNo={opsTicket?.no ?? ''}
        dept={opsTicket?.dept}
        lawyerId={opsTicket?.lawyerId ?? null}
        lawyers={lawyers}
        onClose={() => setOpsKind(null)}
        onDone={() => router.reload({ only: ['tickets'] })}
      />
    </div>
  );
};

export default EmployeeTickets;
