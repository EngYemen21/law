import React, { useState } from 'react';
import Modal from '@/components/babylon/Modal';
import Icon from '@/lib/icons';

/** خيارُ سبب إغلاق من الكتالوج (`ClosureCaseReasonCode::options()`) — الرمز يُرسل والتسمية تُعرض. */
export interface ClosureReasonOption { code: string; label: string }

/**
 * **نافذة إغلاق القضيّة بتسبيبٍ نظاميّ — واحدةٌ لقائمة القضايا ولصفحة التفاصيل.**
 *
 * كان زرّ الإغلاق في القائمة يرسل `{}` بلا نافذة، فيسجّل الخادم كلّ إغلاقٍ «صدور حكم نهائي»
 * وإن كان صلحاً أو تنازلاً، وكانت صفحة التفاصيل تحمل قائمة أسبابٍ منسوخةً من التعداد. الآن الأسباب
 * من الخادم، والخادم يشترط السبب (`closure_reason` مطلوب) — فلا طريق يتخطّاه.
 */
const CaseClosureModal: React.FC<{
  open: boolean;
  caseNo: string;
  reasons: ClosureReasonOption[];
  busy?: boolean;
  onClose: () => void;
  onSubmit: (reason: string, notes: string) => void;
}> = ({ open, caseNo, reasons, busy, onClose, onSubmit }) => {
  // بلا سببٍ مختارٍ سلفاً: الاختيار قرارُ الإدارة لا افتراضٌ يُسجَّل بصمت
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');

  // تبدأ النافذة نظيفةً في كلّ فتح: يُصفَّر الاختيار عند الإغلاق والإرسال (لا بتأثيرٍ يتبع `open`)
  const reset = () => {
    setReason('');
    setNotes('');
  };
  const close = () => {
    reset();
    onClose();
  };

  return (
    <Modal open={open} onClose={close} title={`إغلاق القضية ${caseNo} بتسبيب نظامي`} maxWidth={480}>
      <form onSubmit={(e) => {
        e.preventDefault();

        if (reason) {
          onSubmit(reason, notes.trim());
          reset();
        }
      }}>
        <div className="field">
          <label>سبب إغلاق القضية (نظامي) *</label>
          <select className="input" value={reason} onChange={(e) => setReason(e.target.value)} required>
            <option value="">— اختر سبب الإغلاق —</option>
            {reasons.map((r) => (
              <option key={r.code} value={r.code}>{r.label}</option>
            ))}
          </select>
        </div>
        <div className="field">
          <label>ملاحظات وقرار الإغلاق (اختياري)</label>
          <textarea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="اكتب تفاصيل أو حيثيات قرار الإغلاق..." />
        </div>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 14 }}>
          <button className="btn soft sm" type="button" onClick={close}>إلغاء</button>
          <button className="btn sm" type="submit" disabled={busy || !reason}><Icon name="check" /> تأكيد الإغلاق</button>
        </div>
      </form>
    </Modal>
  );
};

export default CaseClosureModal;
