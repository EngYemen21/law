import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';

/** مخرج ينتظر قرار إنسان. */
interface ReviewItem {
  id: number;
  taskType: string;
  entityRef: string;
  source: string | null;
  sourceLabel: string | null;
  /** `null` = غير مقيسة — تُعرض كذلك ولا تُحوَّل صفراً */
  confidence: number | null;
  confidenceSignals: Record<string, unknown> | null;
  model: string;
  promptVersion: string;
  failureCode: string | null;
  traceId: string | null;
  createdAt: string | null;
}

interface ActionOption { value: string; label: string; requires_reason: boolean; requires_assignee: boolean }
interface ReasonOption { value: string; label: string; high_risk: boolean }

interface Metrics {
  pending: number;
  /** `null` = لا مراجعات بعد؛ الصفر يعني «لا تعديل» وهو ادّعاء مختلف */
  editRate: number | null;
  rejectionReasons: Record<string, number>;
  ops: {
    total: number;
    fallback_rate: number | null;
    failure_rate: number | null;
    latency_p95_ms: number | null;
    estimated_cost: number | null;
    cost_coverage: number | null;
  };
  alerts: { code: string; message: string; value: number }[];
}

const pct = (v: number | null): string => (v === null ? 'غير مقيسة' : `${Math.round(v * 100)}%`);

/** الثقة: رقمٌ ونغمة، و«غير مقيسة» حين لا قياس — لا صفر مضلِّل. */
const ConfidenceBadge: React.FC<{ value: number | null }> = ({ value }) => {
  if (value === null) return <span className="badge b-grey">غير مقيسة</span>;
  const tone = value >= 70 ? 'b-green' : value >= 40 ? 'b-amber' : 'b-red';
  return <span className={`badge ${tone}`}>{value}%</span>;
};

const AiReview: React.FC<{ items: ReviewItem[]; actions: ActionOption[]; reasons: ReasonOption[]; metrics: Metrics }> = ({
  items,
  actions,
  reasons,
  metrics,
}) => {
  const [openId, setOpenId] = useState<number | null>(null);
  const [action, setAction] = useState('');
  const [reason, setReason] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);

  const chosen = actions.find((a) => a.value === action);

  const submit = (id: number) => {
    setBusy(true);
    router.post(
      `/admin/ai-review/${id}/decide`,
      { action, reason: reason || null, note: note || null },
      {
        preserveScroll: true,
        onFinish: () => {
          setBusy(false);
          setOpenId(null);
          setAction('');
          setReason('');
          setNote('');
        },
      },
    );
  };

  return (
    <div className="admin-ai-review-root" style={{ paddingBottom: 60, width: '100%' }}>
      <div className="greet">
        <h1>صندوق مراجعة مخرجات الذكاء</h1>
        <p>كل مخرج ينتظر قرار إنسان — المخرجات القانونيّة لا تُعتمد آلياً مهما بلغت ثقتها.</p>
      </div>

      {metrics.alerts.length > 0 && (
        <div className="card" style={{ marginBottom: 14, borderInlineStart: '3px solid var(--amber)' }}>
          <div className="card-b" style={{ padding: '12px 16px' }}>
            {metrics.alerts.map((a) => (
              <div key={a.code} className="mtg-pend">
                <Icon name="info" /> {a.message}
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="kpi-row" style={{ marginBottom: 14 }}>
        <div className="kpi"><span>بانتظار المراجعة</span><b>{metrics.pending}</b></div>
        <div className="kpi"><span>نسبة ما احتاج تعديلاً</span><b>{pct(metrics.editRate)}</b></div>
        <div className="kpi"><span>نسبة الاحتياطيّ</span><b>{pct(metrics.ops.fallback_rate)}</b></div>
        <div className="kpi"><span>زمن P95</span><b>{metrics.ops.latency_p95_ms === null ? '—' : `${metrics.ops.latency_p95_ms} م.ث`}</b></div>
        <div className="kpi">
          <span>الكلفة التقديريّة</span>
          <b>{metrics.ops.estimated_cost === null ? 'غير معلومة' : metrics.ops.estimated_cost}</b>
          {metrics.ops.cost_coverage !== null && metrics.ops.cost_coverage < 1 && (
            <small style={{ color: 'var(--amber)' }}>جزئيّة — بعض النماذج بلا سعر</small>
          )}
        </div>
      </div>

      {items.length === 0 ? (
        <div className="card"><div className="card-b" style={{ padding: 18 }}>لا مخرجات تنتظر المراجعة.</div></div>
      ) : (
        items.map((item) => (
          <div key={item.id} className="card" style={{ marginBottom: 12 }}>
            <div className="card-h">
              <h3>{item.entityRef} — {item.taskType}</h3>
              <ConfidenceBadge value={item.confidence} />
            </div>
            <div className="card-b" style={{ padding: '14px 16px' }}>
              <div className="cell-row">
                <span>المصدر: {item.sourceLabel ?? '—'}</span>
                <span>النموذج: {item.model}</span>
                <span>إصدار التعليمة: {item.promptVersion}</span>
                {item.failureCode && <span>سبب التعذّر: {item.failureCode}</span>}
                <span>{item.createdAt}</span>
              </div>

              {item.confidenceSignals && (
                <details style={{ marginTop: 8 }}>
                  <summary>إشارات الثقة</summary>
                  <pre style={{ whiteSpace: 'pre-wrap', fontSize: 12 }}>
                    {JSON.stringify(item.confidenceSignals, null, 2)}
                  </pre>
                </details>
              )}

              {openId === item.id ? (
                <div style={{ marginTop: 10 }}>
                  <div className="field">
                    <label>القرار</label>
                    <select value={action} onChange={(e) => setAction(e.target.value)}>
                      <option value="">— اختر القرار —</option>
                      {actions.map((a) => <option key={a.value} value={a.value}>{a.label}</option>)}
                    </select>
                  </div>

                  {chosen?.requires_reason && (
                    <div className="field">
                      <label>سبب الرفض (إلزاميّ — يتحوّل إلى بيانات تقييم)</label>
                      <select value={reason} onChange={(e) => setReason(e.target.value)}>
                        <option value="">— اختر السبب —</option>
                        {reasons.map((r) => (
                          <option key={r.value} value={r.value}>{r.label}{r.high_risk ? ' (خطورة عالية)' : ''}</option>
                        ))}
                      </select>
                    </div>
                  )}

                  <div className="field">
                    <label>ملاحظة (اختياريّة)</label>
                    <textarea className="input" rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
                  </div>

                  <button className="btn sm" disabled={busy || !action} onClick={() => submit(item.id)} type="button">
                    <Icon name="check" /> تسجيل القرار
                  </button>
                  <button className="btn soft sm" onClick={() => setOpenId(null)} type="button">إلغاء</button>
                </div>
              ) : (
                <button className="btn soft sm" style={{ marginTop: 10 }} onClick={() => setOpenId(item.id)} type="button">
                  <Icon name="scale" /> مراجعة
                </button>
              )}
            </div>
          </div>
        ))
      )}
    </div>
  );
};

export default AiReview;
