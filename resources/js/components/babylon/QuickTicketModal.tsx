import React from 'react';
import { router } from '@inertiajs/react';
import Modal from '@/components/babylon/Modal';
import Badge from '@/components/babylon/Badge';
import Icon from '@/lib/icons';

export interface TicketPreviewData {
  no: string;
  client: string;
  type: string;
  dept: string;
  lawyer: string;
  status: string;
  tone: string;
  converted?: boolean;
}

interface Props {
  ticket: TicketPreviewData | null;
  open: boolean;
  role: 'employee' | 'lawyer' | 'admin';
  onClose: () => void;
  onTransfer?: (no: string) => void;
}

const QuickTicketModal: React.FC<Props> = ({
  ticket,
  open,
  role,
  onClose,
  onTransfer,
}) => {
  if (!ticket) return null;

  const goToChat = () => {
    onClose();
    const base = role === 'lawyer' ? '/lawyer' : role === 'admin' ? '/admin' : '/employee';
    router.visit(`${base}/tickets/${encodeURIComponent(ticket.no)}`);
  };

  return (
    <Modal title={`معاينة سريعة — ${ticket.no}`} open={open} onClose={onClose}>
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 16, alignItems: 'center' }}>
        <Badge text={ticket.status} tone={ticket.tone} />
        <span className="badge-s b-grey"><span className="d" /> {ticket.type}</span>
        <span className="badge-s b-grey"><span className="d" /> {ticket.dept}</span>
        {ticket.converted && <Badge text="محوّلة لقضية" tone="b-cyan" />}
      </div>

      <div style={{ background: 'var(--paper-2)', border: '1px solid var(--line-soft)', borderRadius: 12, padding: '14px 16px', marginBottom: 16 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8, fontSize: 13 }}>
          <span style={{ color: 'var(--muted)' }}>العميل:</span>
          <b style={{ color: 'var(--ink)' }}>{ticket.client}</b>
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8, fontSize: 13 }}>
          <span style={{ color: 'var(--muted)' }}>المستشار المسند:</span>
          <b style={{ color: 'var(--ink)' }}>{ticket.lawyer || '—'}</b>
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13 }}>
          <span style={{ color: 'var(--muted)' }}>القسم المختص:</span>
          <b style={{ color: 'var(--ink)' }}>{ticket.dept}</b>
        </div>
      </div>

      <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
        {onTransfer && (
          <button
            className="btn soft sm"
            type="button"
            onClick={() => { onClose(); onTransfer(ticket.no); }}
          >
            <Icon name="reply" /> تحويل لمستشار/فرع
          </button>
        )}
        <button className="btn sm" type="button" onClick={goToChat}>
          <Icon name="reply" /> الانتقال إلى المحادثة الكاملة
        </button>
      </div>
    </Modal>
  );
};

export default QuickTicketModal;
