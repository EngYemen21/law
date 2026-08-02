import { router } from '@inertiajs/react';
import axios from 'axios';
import React, { useState } from 'react';
import DetailShell from '@/components/babylon/DetailShell';
import ChatThread from '@/components/babylon/ChatThread';
import FlowLine from '@/components/babylon/FlowLine';
import Badge from '@/components/babylon/Badge';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { CASE_LIFE, caseStage, type Hearing, HearingsCard } from '@/lib/case-ui';
import { type Message } from '@/lib/chat';

// يطابق clientCaseView — مسار القضية + الجلسات + سداد الأتعاب + المحادثة (من قاعدة البيانات)

interface CaseDetail {
  no: string; type: string; status: string; tone: string; update: string; next: string;
  invoice: string; paid: string; fee?: number | null; feeStatus?: string;
  installmentsPaid?: number; installmentsTotal?: number;
}
interface Props { case: CaseDetail; channel: string; messages: Message[]; hearings: Hearing[]; }

const CaseChat: React.FC<Props> = ({ case: c, channel, messages, hearings }) => {
  const toast = useToast();
  const [status, setStatus] = useState({ status: c.status, tone: c.tone });
  const send = (text: string) => {
    axios.post(`/cases/${encodeURIComponent(c.no)}/messages`, { body: text });
  };
  const pay = (plan: 'full' | 'install') =>
    router.post(`/cases/${encodeURIComponent(c.no)}/pay`, { plan }, { preserveScroll: true, onSuccess: () => toast('تم استلام السداد وتفعيل القضية') });
  const payInstallment = () =>
    router.post(`/cases/${encodeURIComponent(c.no)}/pay-installment`, {}, { preserveScroll: true, onSuccess: () => toast('تم استلام الدفعة') });

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
              <button className="btn" type="button" onClick={() => pay('full')}><Icon name="card" /> سداد كامل عبر ميسّر</button>
              <button className="btn soft" type="button" onClick={() => pay('install')}><Icon name="card" /> سداد على 3 دفعات</button>
            </div>
          </div>
        </div>
      )}
      {c.feeStatus === 'installments' && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="card-h"><h3>سداد الأقساط</h3><Badge text={`${c.installmentsPaid}/${c.installmentsTotal} مدفوعة`} tone="b-amber" /></div>
          <div className="card-b" style={{ padding: 16 }}>
            <div style={{ marginBottom: 12 }}>الأتعاب على دفعات — المتبقّي {(c.installmentsTotal || 0) - (c.installmentsPaid || 0)} دفعة.</div>
            <button className="btn" type="button" onClick={payInstallment}><Icon name="card" /> سداد الدفعة التالية</button>
          </div>
        </div>
      )}
      {hearings.length > 0 && <div style={{ marginBottom: 16 }}><HearingsCard hearings={hearings} /></div>}
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
        ['آخر تحديث', c.update],
        ['الفواتير المستحقة', c.invoice || '—'],
        ['المدفوعات', c.paid || '—'],
      ]}
      topExtra={flowCard}
    >
      <ChatThread initial={messages} channel={channel} onSend={send} onStatus={setStatus} placeholder="اكتب رسالتك للفريق القانوني…" />
    </DetailShell>
  );
};

export default CaseChat;
