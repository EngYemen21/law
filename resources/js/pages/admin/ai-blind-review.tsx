import { router, usePage } from '@inertiajs/react';
import React, { useState } from 'react';
import { panelBase } from '@/lib/data';
import Icon from '@/lib/icons';

/**
 * حالة في العيّنة العمياء.
 *
 * ما قبل الحكم يصل `null` من الخادم — لا يُخفى بـCSS: الإخفاء في الواجهة يُبقي
 * القيمة في الحمولة، ومن يفتح أدوات المطوّر يراها فيسقط العمى.
 */
interface Item {
  id: number;
  taskType: string | null;
  entityRef: string;
  createdAt: string | null;
  judged: boolean;
  verdict: string | null;
  verdictLabel: string | null;
  reasonLabel: string | null;
  machineStatus: string | null;
  machineConfidence: number | null;
  agrees: boolean | null;
  wasBlind: boolean | null;
}

interface Option { value: string; label: string; requires_reason?: boolean }

interface Props {
  items: Item[];
  summary: {
    judged: number;
    pending: number;
    agreement: number | null;
    machineTooStrict: number;
    machineTooLax: number;
    blindnessBroken: number;
  };
  actions: Option[];
  reasons: Option[];
  defaultSize: number;
  maxSize: number;
}

const AiBlindReview: React.FC<Props> = ({ items, summary, actions, reasons, defaultSize, maxSize }) => {
  // بادئة لوحة الدور: الشاشة مشتركة بين الإدارة والمحامي، وتثبيت `/admin` في
  // الإرسال يجعلها تُعرض للمحامي ثم تُمنع عند الحفظ بـ403 — شاشةٌ لا تعمل.
  const base = panelBase((usePage().url as string).split('?')[0]);
  const [openId, setOpenId] = useState<number | null>(null);
  const [verdict, setVerdict] = useState('');
  const [reason, setReason] = useState('');
  const [note, setNote] = useState('');
  const [size, setSize] = useState(defaultSize);
  const [busy, setBusy] = useState(false);

  const needsReason = actions.find((a) => a.value === verdict)?.requires_reason ?? false;

  const judge = (id: number) => {
    setBusy(true);
    router.post(`${base}/ai-blind-review/${id}/judge`, { verdict, reason: reason || null, note: note || null }, {
      preserveScroll: true,
      onFinish: () => { setBusy(false); setOpenId(null); setVerdict(''); setReason(''); setNote(''); },
    });
  };

  return (
    <div className="admin-blind-review-root" style={{ paddingBottom: 60, width: '100%' }}>
      <div className="greet">
        <h1>المراجعة العمياء</h1>
        <p>
          تحكم على المخرج <b>قبل أن تعرف مصدره أو درجة ثقته</b>. ثم يُكشف حكم النظام لتقارن.
          واختلافكما يكشف عيباً في <b>المعايير</b> لا في النموذج: فقد يُصعِّد النظام ما تقبله
          (تشدّدٌ يكلّف وقتاً) أو يقبل ما ترفضه (تساهلٌ يكلّف ملفّاً).
        </p>
      </div>

      <div className="kpi-row" style={{ marginBottom: 14 }}>
        <div className="kpi"><span>بانتظار حكمك</span><b>{summary.pending}</b></div>
        <div className="kpi"><span>حُكم عليها</span><b>{summary.judged}</b></div>
        <div className="kpi">
          <span>الاتّفاق مع النظام</span>
          {/* لا أحكام ⇒ «غير مقيس» لا صفر: نسبةٌ من صفرٍ ليست صفر اتّفاق */}
          <b>{summary.agreement === null ? 'غير مقيس' : `${Math.round(summary.agreement * 100)}%`}</b>
        </div>
        <div className="kpi"><span>النظام أشدّ منك</span><b>{summary.machineTooStrict}</b></div>
        <div className="kpi"><span>النظام أليَن منك</span><b>{summary.machineTooLax}</b></div>
      </div>

      {summary.machineTooLax > 0 && (
        <div className="card" style={{ marginBottom: 14, borderInlineStart: '3px solid var(--red)' }}>
          <div className="card-b" style={{ padding: '12px 16px' }}>
            <div className="mtg-pend" style={{ color: 'var(--red)' }}>
              <Icon name="info" /> النظام قَبِل {summary.machineTooLax} مخرجاً رفضتَه — تساهلٌ يصل الملفَّ
              بلا مراجعة. راجع العتبة في «تشغيل الذكاء وحوكمته».
            </div>
          </div>
        </div>
      )}

      {summary.blindnessBroken > 0 && (
        <div className="card" style={{ marginBottom: 14, borderInlineStart: '3px solid var(--amber)' }}>
          <div className="card-b" style={{ padding: '12px 16px' }}>
            <div className="mtg-pend">
              <Icon name="info" /> {summary.blindnessBroken} حكماً سُجِّل بعد كشف المصدر — لا يُحتسب
              شهادةً عمياء.
            </div>
          </div>
        </div>
      )}

      {/* ── سحب عيّنة ── */}
      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-b" style={{ padding: '14px 16px', display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div className="field" style={{ margin: 0, maxWidth: 160 }}>
            <label>حجم العيّنة (حتى {maxSize})</label>
            <input className="input" type="number" min={1} max={maxSize} value={size} onChange={(e) => setSize(Number(e.target.value))} />
          </div>
          <button
            className="btn sm"
            type="button"
            disabled={busy}
            style={{ marginBottom: 14 }}
            onClick={() => router.post(`${base}/ai-blind-review/draw`, { size }, { preserveScroll: true })}
          >
            <Icon name="reply" /> اسحب عيّنة جديدة
          </button>
          <p style={{ color: 'var(--muted)', fontSize: 12, marginBottom: 14 }}>
            العيّنة عشوائيّة لا الأحدث، وبلا ما سبق أن راجعتَه — الحكم على ما رأيتَه سابقاً ليس أعمى.
          </p>
        </div>
      </div>

      {items.length === 0 ? (
        <div className="card"><div className="card-b" style={{ padding: 18 }}>لا حالات بعد — اسحب عيّنة للبدء.</div></div>
      ) : (
        items.map((item) => (
          <div key={item.id} className="card" style={{ marginBottom: 12 }}>
            <div className="card-h">
              <h3>{item.taskType} · {item.entityRef}</h3>
              {item.judged ? (
                <span className={`badge ${item.agrees ? 'b-green' : 'b-amber'}`}>
                  {item.agrees ? 'اتّفقتما' : 'اختلفتما'}
                </span>
              ) : (
                <span className="badge b-grey">بانتظار حكمك</span>
              )}
            </div>
            <div className="card-b" style={{ padding: '14px 16px' }}>
              <div className="cell-row"><span>تاريخ المخرج: {item.createdAt ?? '—'}</span></div>

              {!item.judged && (
                <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>
                  مصدر المخرج ودرجة ثقته وحكم النظام محجوبة عنك حتى تحكم.
                </p>
              )}

              {item.judged ? (
                <div style={{ marginTop: 8 }}>
                  <div className="cell-row">
                    <span>حكمك: <b>{item.verdictLabel}</b>{item.reasonLabel && ` — ${item.reasonLabel}`}</span>
                    <span>
                      حكم النظام: <b>{item.machineStatus}</b>
                      {item.machineConfidence !== null && ` · ثقة ${item.machineConfidence}%`}
                    </span>
                  </div>
                  {item.wasBlind === false && (
                    <p style={{ color: 'var(--amber)', fontSize: 12 }}>سُجِّل هذا الحكم بعد الكشف — لا يُحتسب أعمى.</p>
                  )}
                </div>
              ) : openId === item.id ? (
                <div style={{ marginTop: 10 }}>
                  <div className="field">
                    <label>حكمك</label>
                    <select className="input" value={verdict} onChange={(e) => setVerdict(e.target.value)}>
                      <option value="">اختر…</option>
                      {actions.map((a) => <option key={a.value} value={a.value}>{a.label}</option>)}
                    </select>
                  </div>
                  {needsReason && (
                    <div className="field">
                      <label>السبب المنظَّم</label>
                      <select className="input" value={reason} onChange={(e) => setReason(e.target.value)}>
                        <option value="">اختر…</option>
                        {reasons.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                      </select>
                    </div>
                  )}
                  <div className="field">
                    <label>ملاحظة (اختياريّة)</label>
                    <textarea className="input" rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
                  </div>
                  <div style={{ display: 'flex', gap: 8 }}>
                    <button className="btn sm" type="button" disabled={busy || !verdict || (needsReason && !reason)} onClick={() => judge(item.id)}>
                      <Icon name="check" /> سجّل حكمي واكشف
                    </button>
                    <button className="btn soft sm" type="button" onClick={() => setOpenId(null)}>تراجع</button>
                  </div>
                </div>
              ) : (
                <button className="btn sm" type="button" style={{ marginTop: 10 }} onClick={() => { setOpenId(item.id); setVerdict(''); setReason(''); setNote(''); }}>
                  احكم على هذه الحالة
                </button>
              )}
            </div>
          </div>
        ))
      )}
    </div>
  );
};

export default AiBlindReview;
