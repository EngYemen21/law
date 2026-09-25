import { usePage } from '@inertiajs/react';
import React, { useState } from 'react';
import Modal from '@/components/babylon/Modal';
import Icon from '@/lib/icons';

/**
 * **نافذةُ إعادة الجدولة — واحدةٌ للاستشارات والاجتماعات وجلسات المحكمة.**
 *
 * كان زرّ «إعادة الجدولة» في خمس واجهات، كلٌّ بسلوكه: نافذةٌ خاصّة من مئتي سطر في استقبال
 * الإدارة، ونافذة تأكيدٍ في درج المحامي، و**لا تأكيدَ أصلاً** في المكوّن المشترك وجدول الموظّف —
 * ضغطةٌ واحدة تُلغي الموعد واجتماع Zoom. وثلاثُ شاشاتٍ منها تقول «يُطلب من العميل اختيار موعد»
 * والعميل لا يختار موعده (قرار المالك 2026-09-14).
 *
 * والسبب إلزاميّ من قائمةٍ مغلقة (قرار المالك 2026-09-25). **والقائمة من الخادم** (`reschedule`
 * المشترك في كلّ صفحة للطاقم) لا مكتوبةٌ هنا — كي لا تتباعد عن قواعد التحقّق.
 *
 * وما يخصّ كلّ مجال (تاريخ الموعد الجديد للاجتماع والجلسة) يُمرَّر أبناءً، والإرسال للمنادي.
 */

export type RescheduleDomain = 'consult' | 'meeting' | 'hearing';

interface ReasonOption {
  value: string;
  label: string;
  needsNote: boolean;
}

interface ReschedulePolicy {
  reasons: Record<RescheduleDomain, ReasonOption[]>;
  limit: number;
}

export interface RescheduleChoice {
  reason: string;
  note: string;
}

/** سياسة إعادة الجدولة من الخادم — `null` لغير الطاقم. */
export function useReschedulePolicy(): ReschedulePolicy | null {
  return (usePage().props as { reschedule?: ReschedulePolicy | null }).reschedule ?? null;
}

interface Props {
  /** يُركَّب المكوّن عند الحاجة فيبقى `true` — الحقل موجودٌ ليقرأ النداءُ كنافذةٍ لا كسحر. */
  open: boolean;
  domain: RescheduleDomain;
  title: string;
  /** ما سيقع حين يُؤكَّد — بلغة المستخدم، بلا وعودٍ لا يفي بها النظام. */
  consequence: React.ReactNode;
  /** مرّات إعادة الجدولة السابقة — تُعرض ليعرف المستخدم أين هو من السقف. */
  count?: number;
  /** حقولٌ خاصّة بالمجال (الموعد الجديد) — تُرسم فوق السبب. */
  children?: React.ReactNode;
  /** الإرسال للمنادي — يعيد وعداً فتبقى النافذة مشغولةً حتى يُحسم. */
  onSubmit: (choice: RescheduleChoice) => Promise<void> | void;
  onClose: () => void;
  confirmLabel?: string;
  /** يمنع الإرسال حتى تكتمل حقول المجال — السبب يُحرس هنا. */
  extraReady?: boolean;
}

const RescheduleDialog: React.FC<Props> = ({
  open, domain, title, consequence, count = 0, children, onSubmit, onClose, confirmLabel = 'إعادة الجدولة', extraReady = true,
}) => {
  const policy = useReschedulePolicy();
  const options = policy?.reasons[domain] ?? [];
  // الحالة تبدأ نظيفةً لأنّ النافذة **تُركَّب عند الحاجة** (`{target && <RescheduleDialog …/>}`) —
  // فلا يُرحَّل سببُ ملفٍّ سابق إلى ملفٍّ آخر، بلا تصفيرٍ داخل تأثير.
  const [reason, setReason] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);

  const chosen = options.find((o) => o.value === reason);
  const noteMissing = chosen?.needsNote === true && note.trim() === '';
  const ready = chosen !== undefined && !noteMissing && extraReady && !busy;
  const limit = policy?.limit ?? 0;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!ready) {
      return;
    }

    setBusy(true);

    try {
      await onSubmit({ reason, note: note.trim() });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title={title} open={open} onClose={() => !busy && onClose()} maxWidth={520}>
      <form onSubmit={submit}>
        <div className="action-hint" style={{ marginBottom: 14 }}>
          <Icon name="alert" />
          <span>{consequence}</span>
        </div>

        {domain === 'consult' && count > 0 && (
          <p style={{ margin: '0 0 12px', fontSize: 12.5, color: count >= limit ? 'var(--red, #ef4444)' : 'var(--muted)' }}>
            أُعيدت جدولة هذه الاستشارة {count} {count === 1 ? 'مرّة' : 'مرّات'} من قبل
            {count >= limit ? ' — بلغت الحدّ، والإعادة التالية للإدارة العليا وحدها.' : '.'}
          </p>
        )}

        {children}

        <fieldset style={{ border: 0, padding: 0, margin: '0 0 12px' }}>
          <legend style={{ fontWeight: 700, fontSize: 13, marginBottom: 6 }}>
            سبب إعادة الجدولة <span style={{ color: 'var(--red, #ef4444)' }}>*</span>
          </legend>
          <div style={{ display: 'grid', gap: 6 }}>
            {options.map((o) => (
              <label
                key={o.value}
                style={{
                  display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', borderRadius: 8, cursor: 'pointer', fontSize: 13.5,
                  border: `1px solid ${reason === o.value ? 'var(--cyan, #11A0C8)' : 'var(--line, #e2e8f0)'}`,
                }}
              >
                <input type="radio" name="reschedule-reason" value={o.value} checked={reason === o.value} onChange={() => setReason(o.value)} />
                {o.label}
              </label>
            ))}
          </div>
        </fieldset>

        <div className="field" style={{ marginBottom: 16 }}>
          <label htmlFor="reschedule-note" style={{ fontWeight: 700, fontSize: 13, marginBottom: 5, display: 'block' }}>
            شرح {chosen?.needsNote ? <span style={{ color: 'var(--red, #ef4444)' }}>*</span> : <span style={{ color: 'var(--muted)', fontWeight: 400 }}>(اختياريّ)</span>}
          </label>
          <textarea
            id="reschedule-note"
            value={note}
            onChange={(e) => setNote(e.target.value)}
            rows={2}
            maxLength={500}
            placeholder="يُحفظ مع السبب في سجلّ الملفّ"
            style={{ width: '100%', padding: '9px 12px', borderRadius: 8, border: '1px solid var(--line, #e2e8f0)', fontSize: 13 }}
          />
        </div>

        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
          <button type="button" className="btn soft" onClick={onClose} disabled={busy}>
            تراجع
          </button>
          <button
            type="submit"
            className="btn"
            disabled={!ready}
            style={{ minWidth: 140, justifyContent: 'center', background: 'var(--red, #ef4444)', borderColor: 'var(--red, #ef4444)' }}
          >
            <Icon name="cal" /> {busy ? 'جارٍ التنفيذ…' : confirmLabel}
          </button>
        </div>
      </form>
    </Modal>
  );
};

export default RescheduleDialog;
