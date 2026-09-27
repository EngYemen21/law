import { router } from '@inertiajs/react';
import axios from 'axios';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import ChatThread from '@/components/babylon/ChatThread';
import DetailShell from '@/components/babylon/DetailShell';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import { CASE_LIFE, caseStage,  HearingsCard } from '@/lib/case-ui';
import type {Hearing} from '@/lib/case-ui';
import type {Message} from '@/lib/chat';
import Icon from '@/lib/icons';
import { installmentsText, useSettings } from '@/lib/settings';
import { useServerAction } from '@/lib/use-server-action';
import type { CaseDocumentCard, TicketDocumentCard } from '@/types';

// يطابق clientCaseView — مسار القضية + الجلسات + سداد الأتعاب + المحادثة (من قاعدة البيانات)

interface CaseDetail {
  no: string; type: string; status: string; tone: string; update: string; next: string;
  /** أعلام الحالة من الخادم (`LegalCase::stateFlags`) — في البطاقة والبثّ. */
  isActive: boolean; postJudgment: boolean;

  invoice: string; paid: string; fee?: number | null; feeStatus?: string;
  installmentsPaid?: number; installmentsTotal?: number;
  najiz?: { requestNo?: string | null; caseNo?: string | null; court?: string | null; circuit?: string | null } | null;
  appeal?: {
    status: string;
    statusLabel: string;
    deadlineAt?: string | null;
    daysRemaining?: number | null;
    isDeadlineOver: boolean;
    requestNo?: string | null;
    court?: string | null;
    circuit?: string | null;
    ruling?: string | null;
    filedAt?: string | null;
    judgedAt?: string | null;
  } | null;
}
/** مرفقٌ من التذكرة قبل التحويل (`CaseTicketDocuments`). */
/** `CaseTicketDocuments::for` — النوع المشترك (`@/types`). */
type TicketDoc = TicketDocumentCard;
/** `CaseDocument::toData` — النوع المشترك (`@/types`). */
type CaseDoc = CaseDocumentCard;
interface Props { case: CaseDetail; channel: string; messages: Message[]; hearings: Hearing[]; documents: CaseDoc[]; ticketDocuments?: TicketDoc[]; }

const CaseChat: React.FC<Props> = ({ case: c, channel, messages, hearings, documents, ticketDocuments = [] }) => {
  const toast = useToast();
  // قفلٌ موحّد: نقرتان على «ادفع» لا تفتحان جلستَي دفع
  const payment = useServerAction();
  // عدد دفعات الخطّة الجديدة من إعدادات الإدارة لا «3» منقوشة — الخادم يقسّم بـ`installments_count`
  const { installments_count: installments } = useSettings();
  const [status, setStatus] = useState<{ status: string; tone: string } & Record<string, unknown>>({ status: c.status, tone: c.tone, isActive: c.isActive });
  const send = (text: string) => axios.post(`/cases/${encodeURIComponent(c.no)}/messages`, { body: text });
  // المحادثة والرفع متاحان ما لم تكن القضية مغلقة/مؤرشفة — علم الخادم (`isActive`) من الصفحة ثمّ من البثّ
  const chatOpen = status.isActive !== false;
  // رفع مستند فعلي لملف القضية — تظهر رسالته لحظياً عبر البثّ، وتُحدَّث قائمة المستندات فور نجاح الرفع
  const attach = (file?: File) => {
    if (!file) {
return;
}

    const fd = new FormData();
    fd.append('file', file);

    return axios.post(`/cases/${encodeURIComponent(c.no)}/attach`, fd)
      .then(() => {
 toast('تم رفع المستند'); router.reload({ only: ['documents'] }); 
});
  };
  // الخطّتان تحوّلان المتصفّح لبوّابة ميسّر عند النجاح، فأي توست نجاح هنا يعني الفشل
  // (نجاح كاذب). وكان التقسيط يعرض «تم استلام الدفعة الأولى» بلا أي سداد فعليّ.
  const pay = (plan: 'full' | 'install') =>
    payment.run(`/cases/${encodeURIComponent(c.no)}/pay`, { data: { plan }, fallback: 'تعذّر بدء الدفع، حاول بعد قليل' });
  const payInstallment = () =>
    payment.run(`/cases/${encodeURIComponent(c.no)}/pay-installment`, { fallback: 'تعذّر بدء الدفع، حاول بعد قليل' });

  const flowCard = (
    <>
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h"><h3>القضية {c.no}</h3><Badge text={status.status} tone={status.tone} /></div>
        <div className="card-b" style={{ padding: '16px 18px' }}><FlowLine steps={CASE_LIFE} cur={caseStage(status.status)} /></div>
      </div>
      {c.feeStatus === 'pending_payment' && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="card-h"><h3>سداد الأتعاب</h3><Badge text="بانتظار السداد" tone="b-amber" /></div>
          <div className="card-b" style={{ padding: 16 }}>
            <div style={{ marginBottom: 12 }}>{c.invoice || `أتعاب القضية: ${(c.fee || 0).toLocaleString()} ر.س`}</div>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <button className="btn" type="button" disabled={payment.busy} onClick={() => pay('full')}><Icon name="card" /> سداد كامل عبر ميسّر</button>
              <button className="btn soft" type="button" disabled={payment.busy} onClick={() => pay('install')}><Icon name="card" /> تقسيط على {installmentsText(installments)}</button>
            </div>
          </div>
        </div>
      )}
      {c.feeStatus === 'installments' && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="card-h"><h3>سداد الأقساط</h3><Badge text={`${c.installmentsPaid}/${c.installmentsTotal} مدفوعة`} tone="b-amber" /></div>
          <div className="card-b" style={{ padding: 16 }}>
            <div style={{ marginBottom: 12 }}>الأتعاب على دفعات — المتبقّي {(c.installmentsTotal || 0) - (c.installmentsPaid || 0)} دفعة.</div>
            <button className="btn" type="button" disabled={payment.busy} onClick={payInstallment}><Icon name="card" /> سداد الدفعة التالية عبر ميسّر</button>
          </div>
        </div>
      )}
      {c.appeal && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="card-h">
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Icon name="scale" />
              <h3>مسار الاستئناف والاعتراض</h3>
            </div>
            <Badge
              text={c.appeal.statusLabel}
              tone={c.appeal.status === 'appeal_judged' ? 'b-green' : c.appeal.status === 'appeal_filed' ? 'b-blue' : 'b-amber'}
            />
          </div>
          <div className="card-b" style={{ padding: 16 }}>
            {c.appeal.status === 'pending_appeal' && (
              <div style={{ padding: '12px 14px', background: c.appeal.isDeadlineOver ? 'var(--red-soft, #fee2e2)' : 'var(--amber-soft, #fef3c7)', borderRadius: 8 }}>
                <div style={{ fontWeight: 700, marginBottom: 4 }}>
                  مهلة الاعتراض النظامية على الحكم الابتدائي:
                </div>
                <div style={{ fontSize: 13, color: 'var(--muted)' }}>
                  {c.appeal.isDeadlineOver
                    ? `انقضت مهلة الاعتراض بتاريخ ${c.appeal.deadlineAt} ويصبح الحكم مكتسباً للقطعية.`
                    : `متبقّي ${c.appeal.daysRemaining} يوم لتقديم لائحة الاستئناف (تنتهي بتاريخ: ${c.appeal.deadlineAt}).`}
                </div>
              </div>
            )}

            {c.appeal.status === 'appeal_filed' && (
              <div>
                <div style={{ fontSize: 13, marginBottom: 8, color: 'var(--ink)' }}>
                  تم قيد لائحة الاستئناف لدى محكمة الاستئناف:
                </div>
                <div className="tc-body" style={{ padding: 0 }}>
                  {c.appeal.requestNo && <div className="tc-row"><span className="k">رقم طلب الاستئناف</span><span className="v">{c.appeal.requestNo}</span></div>}
                  {c.appeal.court && <div className="tc-row"><span className="k">المحكمة</span><span className="v">{c.appeal.court}</span></div>}
                  {c.appeal.circuit && <div className="tc-row"><span className="k">الدائرة</span><span className="v">{c.appeal.circuit}</span></div>}
                  {c.appeal.filedAt && <div className="tc-row"><span className="k">تاريخ القيد</span><span className="v">{c.appeal.filedAt}</span></div>}
                </div>
              </div>
            )}

            {c.appeal.status === 'appeal_judged' && (
              <div>
                <div style={{ fontSize: 13, marginBottom: 8, color: 'var(--ink)' }}>
                  صدر حكم محكمة الاستئناف بتاريخ {c.appeal.judgedAt}:
                </div>
                <div style={{ padding: '12px 14px', background: 'var(--paper-2)', borderRadius: 8, lineHeight: 1.8, fontSize: 13.5 }}>
                  {c.appeal.ruling}
                </div>
              </div>
            )}
          </div>
        </div>
      )}
      {hearings.length > 0 && <div style={{ marginBottom: 16 }}><HearingsCard hearings={hearings} documents={documents} /></div>}
      {documents.length > 0 && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="card-h"><h3>مستندات القضية</h3><span className="sub">{documents.length} مستند</span></div>
          <div className="card-b">
            {documents.map((d) => (
              <div key={d.id} className="item">
                <div className="iico"><Icon name="doc" /></div>
                <div className="imeta">
                  <b>{d.name}</b>
                  <span>{d.by} · {d.date}{d.docType ? ` · ${d.docType}` : ''}</span>
                  {d.hearingTitle && (
                    <div style={{ marginTop: 4 }}>
                      <Badge text={`جلسة: ${d.hearingTitle}`} tone="b-blue" />
                    </div>
                  )}
                  {d.summary && <span style={{ display: 'block', marginTop: 3, fontSize: 11.5, color: 'var(--muted)' }}>{d.summary}</span>}
                </div>
                {/* كان الاسم يُعرض بلا أي رابط — مسار العميل القائم يخدم نفس الملف */}
                <a className="btn soft sm" href={`/documents/download-file?type=case&id=${d.id}`}>
                  <Icon name="download" /> تنزيل
                </a>
              </div>
            ))}
          </div>
        </div>
      )}
      {/* مرفقات الطلب قبل التحويل — من التذكرة نفسها (`CaseTicketDocuments`)، بلا المرفوض «غير مرتبط» */}
      {ticketDocuments.length > 0 && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="card-h"><h3>مرفقات الطلب قبل التحويل</h3><span className="sub">{ticketDocuments.length} مستند</span></div>
          <div className="card-b">
            {ticketDocuments.map((d) => (
              <div key={`t-${d.id}`} className="item">
                <div className="iico"><Icon name="doc" /></div>
                <div className="imeta">
                  <b>{d.name}</b>
                  <span>{d.by} · {d.date}{d.docType ? ` · ${d.docType}` : ''}</span>
                  {d.summary && <span style={{ display: 'block', marginTop: 3, fontSize: 11.5, color: 'var(--muted)' }}>{d.summary}</span>}
                </div>
                {d.downloadUrl && (
                  <a className="btn soft sm" href={d.downloadUrl} title="تنزيل المستند"><Icon name="download" /> تنزيل</a>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
    </>
  );

  return (
    <DetailShell
      backHref="/cases"
      backLabel="رجوع"
      title={`القضية ${c.no}`}
      no={c.no}
      status={status.status}
      tone={status.tone}
      info={[
        ['الحالة', status.status],
        ['النوع', c.type],
        ['الجلسة القادمة', c.next || '—'],
        ...(c.najiz?.caseNo
          ? [['رقم القضية', c.najiz.caseNo], ['المحكمة والدائرة', [c.najiz.court, c.najiz.circuit].filter(Boolean).join(' — ')]] as [string, string][]
          : c.najiz?.requestNo ? [['رقم طلب ناجز', `${c.najiz.requestNo} — بانتظار القيد`]] as [string, string][] : []),
        ['آخر تحديث', c.update],
        ['الفواتير المستحقة', c.invoice || '—'],
        ['المدفوعات', c.paid || '—'],
      ]}
      topExtra={flowCard}
    >
      <ChatThread initial={messages} channel={channel} onSend={send} onAttach={attach} onStatus={setStatus} readOnly={!chatOpen} placeholder="اكتب رسالتك للفريق القانوني…" />
    </DetailShell>
  );
};

export default CaseChat;
