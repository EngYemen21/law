import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Icon from '@/lib/icons';
import { useServerAction } from '@/lib/use-server-action';

/** طلب فتح التنفيذ القائم على القضيّة — من الخادم (`CaseExecutionRequest::pending`). */
export interface CaseExecutionRequestData {
  at: string | null;
  by: string;
  reason: string;
  /** المبلغ المحكوم به (ريال) — يصير قيمة المطالبة في ملفّ التنفيذ. */
  amount: number | null;
}

/** أقلّ طول لسبب الطلب — نظير `RequestCaseExecution::REASON_MIN`. */
const REASON_MIN = 10;

/**
 * **تنفيذ الحكم بطلبٍ تعتمده الإدارة العليا** (قرار المالك 2026-09-29) — بطاقةٌ واحدة لصفحتي القضيّة
 * عند المحامي المسنَد والموظّف: فُتح الملفّ، أو طلبٌ بانتظار الاعتماد، أو نموذج رفعه بسببٍ ونافذة تأكيد.
 * كان زرّ المحامي يفتح ملفّ التنفيذ مباشرةً بنقرةٍ واحدة بلا تأكيدٍ ولا اعتماد.
 */
const CaseExecutionRequestCard: React.FC<{
  base: string;
  canRequest: boolean;
  pending: CaseExecutionRequestData | null;
  converted: boolean;
  /** مبلغ المطالبة في التذكرة — اقتراحٌ يؤكّده رافع الطلب أو يصحّحه. */
  amountHint?: number | null;
}> = ({ base, canRequest, pending, converted, amountHint = null }) => {
  const action = useServerAction();
  const [reason, setReason] = useState('');
  const [amount, setAmount] = useState(amountHint ? String(amountHint) : '');
  // رقمٌ صحيح أكبر من صفر — والخادم يرفض غيره بالرسالة نفسها (`RequestCaseExecution`)
  const amountOk = /^\d+$/.test(amount.trim()) && Number(amount) > 0;

  if (!canRequest && !pending && !converted) {
    return null;
  }

  const submit = () =>
    action.run(`${base}/execution-request`, {
      data: { reason: reason.trim(), amount: Number(amount) },
      confirm: {
        title: 'رفع طلب فتح تنفيذ الحكم؟',
        message: `يُرفع الطلب بسببه ومبلغ ${Number(amount).toLocaleString('en-US')} ريال إلى الإدارة العليا، ولا يُفتح ملفّ التنفيذ إلا بعد اعتمادها.`,
        confirmLabel: 'رفع الطلب للإدارة',
        cancelLabel: 'تراجع',
      },
      success: 'رُفع طلب فتح التنفيذ للإدارة العليا',
      fallback: 'تعذّر رفع الطلب',
      onSuccess: () => setReason(''),
    });

  return (
    <div className="card">
      <div className="card-h">
        <h3>تنفيذ الحكم</h3>
        {converted && <Badge text="محوّل لتنفيذ" tone="b-cyan" />}
        {!converted && pending && <Badge text="بانتظار اعتماد الإدارة" tone="b-amber" />}
      </div>
      <div className="card-b" style={{ padding: 14 }}>
        {converted ? (
          <div className="empty"><Icon name="exec" /><b>فُتح طلب تنفيذ لهذا الحكم</b></div>
        ) : pending ? (
          <div style={{ fontSize: 13, lineHeight: 1.9 }}>
            <div>رفعه <b>{pending.by}</b>{pending.at ? ` ${pending.at}` : ''}.</div>
            {pending.amount ? <div>المبلغ المحكوم به: <b>{pending.amount.toLocaleString('en-US')} ريال</b></div> : null}
            <div className="sub" style={{ whiteSpace: 'pre-line' }}>السبب: {pending.reason}</div>
            <div className="sub" style={{ marginTop: 6 }}>يُفتح ملفّ التنفيذ فور اعتماد الإدارة العليا، أو يصلك سبب رفضه.</div>
          </div>
        ) : (
          <>
            <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 10 }}>
              صدر الحكم. ارفع طلب فتح التنفيذ للإدارة العليا بسببه — يُفتح الملفّ بعد اعتمادها.
            </div>
            <div className="field">
              <label htmlFor="exec-request-amount">المبلغ المحكوم به (ريال)</label>
              <input
                id="exec-request-amount"
                className="input"
                type="text"
                inputMode="numeric"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                placeholder="مثال: 150000"
              />
              {amount.trim() !== '' && !amountOk && <span style={{ fontSize: 11.5, color: 'var(--amber)' }}>أدخل مبلغاً صحيحاً أكبر من صفر.</span>}
            </div>
            <textarea
              className="input"
              rows={3}
              maxLength={1000}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="سبب طلب التنفيذ (مثل: امتناع المحكوم عليه عن السداد بعد اكتساب الحكم القطعيّة)…"
            />
            <button
              className="btn sm"
              type="button"
              style={{ marginTop: 8 }}
              disabled={action.busy || reason.trim().length < REASON_MIN || !amountOk}
              onClick={submit}
            >
              <Icon name="exec" /> رفع طلب التنفيذ للإدارة
            </button>
          </>
        )}
      </div>
    </div>
  );
};

export default CaseExecutionRequestCard;
