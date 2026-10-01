import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { firstError } from '@/lib/server-message';

export const CLOSURE_REASONS = [
  { code: 'OPINION_SATISFIED', label: 'اكتفاء بالرأي القانوني دون وجود نزاع' },
  { code: 'SETTLED_AMICABLY', label: 'تمت التسوية الودية والصلح بين الأطراف' },
  { code: 'NO_LEGAL_MERIT', label: 'انعدام السند النظامي أو ضعف الجدوى من التقاضي' },
  { code: 'OUTSIDE_FIRM_SCOPE', label: 'الموضوع يخرج عن نطاق اختصاص المكتب' },
  { code: 'CLIENT_INACTIVITY_DROP', label: 'حفظ الملف لعدم تجاوب العميل واستكمال النواقص' },
  { code: 'CLIENT_REQUESTED_CLOSURE', label: 'رغبة العميل الصريحة في عدم متابعة الإجراءات' },
  { code: 'OTHER_WITH_REASON', label: 'سبب نظامي آخر (مع تسبيب مفصل)' },
];

interface Props {
  open: boolean;
  ticketNo: string;
  role: 'lawyer' | 'admin' | string;
  onClose: () => void;
  onSuccess?: () => void;
}

const CloseTicketModal: React.FC<Props> = ({ open, ticketNo, role, onClose, onSuccess }) => {
  const toast = useToast();
  const [reasonCode, setReasonCode] = useState<string>('OPINION_SATISFIED');
  const [notes, setNotes] = useState<string>('');
  const [busy, setBusy] = useState<boolean>(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    if (reasonCode === 'OTHER_WITH_REASON' && !notes.trim()) {
      toast('⚠️ يرجى كتابة مبررات الإغلاق عند اختيار "سبب نظامي آخر"');
      return;
    }

    setBusy(true);
    const endpoint = `/${role}/tickets/${encodeURIComponent(ticketNo)}/close`;

    router.post(
      endpoint,
      {
        closure_reason_code: reasonCode,
        closure_notes: notes.trim() || undefined,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast('✅ تم حسم قرار المآل وإغلاق التذكرة بقرار مسبب');
          onClose();
          onSuccess?.();
        },
        onError: (errors) => {
          const msg = firstError(errors, 'تعذّر إغلاق التذكرة');
          toast(`⚠️ ${msg}`);
        },
        onFinish: () => setBusy(false),
      }
    );
  };

  return (
    <Modal title={`إغلاق مسبب للتذكرة — ${ticketNo}`} open={open} onClose={onClose} maxWidth={520}>
      <form onSubmit={handleSubmit}>
        <div className="action-hint" style={{ marginBottom: 14 }}>
          <Icon name="info" />
          <span>
            سيتم حسم قرار مآل التذكرة بالإغلاق المسبب وتجميد الملف في الأرشيف دون تحويله إلى قضية رسمية.
          </span>
        </div>

        <div className="field" style={{ marginBottom: 12 }}>
          <label style={{ fontWeight: 700, fontSize: 13, marginBottom: 5, display: 'block' }}>
            سبب الإغلاق النظامي المعتمد <span style={{ color: 'var(--red, #ef4444)' }}>*</span>
          </label>
          <select
            value={reasonCode}
            onChange={(e) => setReasonCode(e.target.value)}
            style={{ width: '100%', padding: '9px 12px', borderRadius: 8, border: '1px solid var(--line, #e2e8f0)', fontSize: 13.5 }}
          >
            {CLOSURE_REASONS.map((r) => (
              <option key={r.code} value={r.code}>
                {r.label}
              </option>
            ))}
          </select>
        </div>

        <div className="field" style={{ marginBottom: 16 }}>
          <label style={{ fontWeight: 700, fontSize: 13, marginBottom: 5, display: 'block' }}>
            ملاحظات وحيثيات الإغلاق {reasonCode === 'OTHER_WITH_REASON' && <span style={{ color: 'var(--red, #ef4444)' }}>*</span>}
          </label>
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={4}
            placeholder="اكتب حيثيات ومبررات الإغلاق للتوثيق في سجل التدقيق والمآل القانوني…"
            style={{ width: '100%', padding: '9px 12px', borderRadius: 8, border: '1px solid var(--line, #e2e8f0)', fontSize: 13 }}
          />
        </div>

        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 16 }}>
          <button
            type="button"
            className="btn soft"
            onClick={onClose}
            disabled={busy}
          >
            إلغاء
          </button>
          <button
            type="submit"
            className="btn"
            disabled={busy}
            style={{ minWidth: 140, justifyContent: 'center' }}
          >
            <Icon name="check" /> {busy ? 'جاري الإغلاق…' : 'تأكيد الإغلاق المسبب'}
          </button>
        </div>
      </form>
    </Modal>
  );
};

export default CloseTicketModal;
