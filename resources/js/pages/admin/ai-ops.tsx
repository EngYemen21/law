import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';

interface Metrics {
  total: number;
  fallback_rate: number | null;
  failure_rate: number | null;
  needs_review_rate: number | null;
  latency_p50_ms: number | null;
  latency_p95_ms: number | null;
  total_tokens: number;
  estimated_cost: number | null;
  cost_coverage: number | null;
}

interface Reason { code: string; label: string; total: number; highRisk: boolean }
interface RetentionRow { value: string; label: string; days: number | null; isDefault: boolean }

interface EvalResult {
  task: string;
  total: number;
  passed: number;
  skipped: number;
  rate: number;
  gate: number;
  meets: boolean;
  live: boolean;
  liveSkipped: string | null;
  failures: string[];
}

interface LastEvaluation {
  at: string;
  live: boolean;
  cost: number | null;
  by: string | null;
  failure: string | null;
  running: boolean;
  results: EvalResult[];
}

/** يُرسَل كما هو إلى الخادم، فيلزمه فهرس نصّيّ ليقبله عقد Inertia. */
interface PricingRow { [key: string]: string | number; model: string; input: number; output: number }

interface Props {
  days: number;
  metrics: Metrics;
  alerts: { code: string; message: string; value: number }[];
  failureCodes: Record<string, number>;
  rejectionReasons: Reason[];
  editRate: number | null;
  pendingReview: number;
  settings: {
    threshold: number;
    defaultThreshold: number;
    pricing: Record<string, { input: number; output: number }>;
    retention: RetentionRow[];
    retentionApproval: { by: string | null; at: string | null; basis: string | null };
    retentionApproved: boolean;
    /** `cap: null` = بلا سقف لا صفر — الصفر يمنع كل نداء. */
    budget: { cap: number | null; warnAt: number; stop: boolean };
  };
  spending: { thisMonth: number | null; stopped: boolean };
  taskSwitches: TaskSwitch[];
  calibration: Calibration;
  evaluation: {
    last: LastEvaluation | null;
    tasks: string[];
    liveCapable: string[];
    providerReady: boolean;
    diff: EvalDiff[];
    baselineAt: string | null;
    runsRecorded: number;
  };
}

/**
 * معايرة العتبة بالأرقام. `recommended: null` = العيّنة لا تكفي أو تعذّر تصفير
 * القبول الخاطئ — والصمت أصدق من رقمٍ مبنيّ على ثلاث مراجعات.
 */
interface Calibration {
  sample: number;
  threshold: number;
  wrongAccepts: number;
  wrongEscalations: number;
  recommended: number | null;
  reason: string;
  curve: { threshold: number; wrongAccepts: number; wrongEscalations: number }[];
}

/** مفتاح مسار وحصيلة تقييمه — `rate: null` = لم يُقَس بعد، لا صفر. */
interface TaskSwitch {
  task: string;
  enabled: boolean;
  gate: number;
  rate: number | null;
  meets: boolean | null;
}

/** فرق مهمّة عن تشغيلها السابق — «جديدة» تُميَّز عن «تراجعت». */
interface EvalDiff {
  task: string;
  rate: number;
  previous: number | null;
  delta: number | null;
  regressed: boolean;
  isNew: boolean;
}

/** «غير مقيسة» لا صفر: الصفر يقول إن القياس جرى ونتيجته صفر — وهو ادّعاء مختلف. */
const pct = (v: number | null): string => (v === null ? 'غير مقيسة' : `${Math.round(v * 100)}%`);

const AiOps: React.FC<Props> = ({ days, metrics, alerts, failureCodes, rejectionReasons, editRate, pendingReview, settings, spending, taskSwitches, calibration, evaluation }) => {
  const [threshold, setThreshold] = useState(settings.threshold);
  // السقف نصّ لا رقم: الفراغ يعني «بلا سقف» وهو معنى لا يمثّله أي رقم
  const [cap, setCap] = useState(settings.budget.cap === null ? '' : String(settings.budget.cap));
  const [warnAt, setWarnAt] = useState(Math.round(settings.budget.warnAt * 100));
  const [stop, setStop] = useState(settings.budget.stop);
  const [basis, setBasis] = useState(settings.retentionApproval.basis ?? '');
  const [switches, setSwitches] = useState<Record<string, boolean>>(
    Object.fromEntries(taskSwitches.map((t) => [t.task, t.enabled])),
  );
  const [retention, setRetention] = useState<Record<string, string>>(
    Object.fromEntries(settings.retention.map((r) => [r.value, r.days === null ? '' : String(r.days)])),
  );
  const [pricing, setPricing] = useState<PricingRow[]>(
    Object.entries(settings.pricing).map(([model, r]) => ({ model, input: r.input, output: r.output })),
  );
  const [busy, setBusy] = useState(false);

  const post = (url: string, data: Parameters<typeof router.post>[1]) => {
    setBusy(true);
    router.post(url, data, { preserveScroll: true, onFinish: () => setBusy(false) });
  };

  return (
    <div className="admin-ai-ops-root" style={{ paddingBottom: 60, width: '100%' }}>
      <div className="greet">
        <h1>تشغيل الذكاء وحوكمته</h1>
        <p>
          مؤشّرات آخر {days} يوماً، ومعايرة القرارات التي كانت حبيسة الشيفرة: عتبة القبول
          الآليّ، وأسعار النماذج، ومدد الاحتفاظ.
        </p>
      </div>

      {alerts.length > 0 && (
        <div className="card" style={{ marginBottom: 14, borderInlineStart: '3px solid var(--amber)' }}>
          <div className="card-b" style={{ padding: '12px 16px' }}>
            {alerts.map((a) => (
              <div key={a.code} className="mtg-pend"><Icon name="info" /> {a.message}</div>
            ))}
          </div>
        </div>
      )}

      {/* ── المؤشّرات ── */}
      <div className="kpi-row" style={{ marginBottom: 14 }}>
        <div className="kpi"><span>نداءات الفترة</span><b>{metrics.total}</b></div>
        <div className="kpi"><span>نسبة الاحتياطيّ</span><b>{pct(metrics.fallback_rate)}</b></div>
        <div className="kpi"><span>نسبة الفشل</span><b>{pct(metrics.failure_rate)}</b></div>
        <div className="kpi"><span>تحتاج مراجعة</span><b>{pct(metrics.needs_review_rate)}</b></div>
        <div className="kpi"><span>احتاج تعديلاً بشرياً</span><b>{pct(editRate)}</b></div>
        <div className="kpi"><span>بانتظار المراجعة</span><b>{pendingReview}</b></div>
      </div>

      <div className="kpi-row" style={{ marginBottom: 14 }}>
        <div className="kpi"><span>زمن P50</span><b>{metrics.latency_p50_ms === null ? '—' : `${metrics.latency_p50_ms} م.ث`}</b></div>
        <div className="kpi"><span>زمن P95</span><b>{metrics.latency_p95_ms === null ? '—' : `${metrics.latency_p95_ms} م.ث`}</b></div>
        <div className="kpi"><span>التوكنات</span><b>{metrics.total_tokens.toLocaleString('en-US')}</b></div>
        <div className="kpi">
          <span>الكلفة التقديريّة</span>
          <b>{metrics.estimated_cost === null ? 'غير معلومة' : metrics.estimated_cost}</b>
          {metrics.cost_coverage !== null && metrics.cost_coverage < 1 && (
            <small style={{ color: 'var(--amber)' }}>جزئيّة — تغطية {pct(metrics.cost_coverage)}</small>
          )}
        </div>
      </div>

      {/* ── أسباب الرفض: مادّة اجتماع الحوكمة ── */}
      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>أسباب الرفض — آخر {days} يوماً</h3></div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          {rejectionReasons.length === 0 ? (
            <p>لا رفض مسجَّل في الفترة.</p>
          ) : (
            rejectionReasons.map((r) => (
              <div key={r.code} className="cell-row">
                <span>{r.label}{r.highRisk && <span className="badge b-red" style={{ marginInlineStart: 6 }}>خطورة عالية</span>}</span>
                <span>{r.total}</span>
              </div>
            ))
          )}
          <p style={{ marginTop: 10, color: 'var(--muted)', fontSize: 12 }}>
            تراجعُ فئة عالية الخطورة يمنع اعتماد نموذج أو تعليمة جديدة <b>ولو تحسّن المتوسّط العام</b>.
          </p>
        </div>
      </div>

      {Object.keys(failureCodes).length > 0 && (
        <div className="card" style={{ marginBottom: 12 }}>
          <div className="card-h"><h3>أسباب التعذّر</h3></div>
          <div className="card-b" style={{ padding: '14px 16px' }}>
            {Object.entries(failureCodes).map(([code, total]) => (
              <div key={code} className="cell-row"><span>{code}</span><span>{total}</span></div>
            ))}
          </div>
        </div>
      )}

      {/* ── معايرة العتبة ── */}
      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>عتبة القبول الآليّ</h3></div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          <p style={{ color: 'var(--muted)', fontSize: 12.5 }}>
            تخصّ المهام <b>متوسّطة الحساسيّة</b> وحدها (الفرز وفحص المستندات). المخرجات
            القانونيّة لا تُقبل آلياً مهما بلغت ثقتها. الافتراضيّ {settings.defaultThreshold}%.
          </p>
          <div className="field" style={{ maxWidth: 220 }}>
            <label>العتبة (0–100)</label>
            <input
              className="input"
              type="number"
              min={0}
              max={100}
              value={threshold}
              onChange={(e) => setThreshold(Number(e.target.value))}
            />
          </div>
          <button className="btn sm" disabled={busy} type="button" onClick={() => post('/admin/ai-ops/threshold', { threshold })}>
            <Icon name="check" /> حفظ العتبة
          </button>

          {/* ── المعايرة بالأرقام ──
              الخطة تفرض المعايرة «بعد قياس لا بالحدس»، وكان القرار مطلوباً والأداة
              غائبة. والخطآن ليسا متساويين: قبولٌ آليّ رفضه إنسان يصل الملفَّ بلا
              مراجعة، وتصعيدٌ قَبِله إنسان يكلّف وقتاً فقط. */}
          <div style={{ marginTop: 14, paddingTop: 12, borderTop: '1px solid var(--line)' }}>
            <b style={{ fontSize: 12.5 }}>المعايرة على آخر 90 يوماً</b>

            {calibration.sample === 0 ? (
              <p style={{ color: 'var(--muted)', fontSize: 12.5 }}>{calibration.reason}</p>
            ) : (
              <>
                <div className="cell-row" style={{ fontSize: 12.5, marginTop: 6 }}>
                  <span>العيّنة: {calibration.sample} مراجعة مكتملة بثقة مقيسة</span>
                  <span style={{ color: calibration.wrongAccepts > 0 ? 'var(--red)' : undefined }}>
                    قُبل آلياً ثم رُفض: <b>{calibration.wrongAccepts}</b> · صُعِّد ثم قُبل: {calibration.wrongEscalations}
                  </span>
                </div>

                <p style={{ fontSize: 12.5, margin: '6px 0' }}>{calibration.reason}</p>

                {calibration.recommended !== null && (
                  <button
                    className="btn soft sm"
                    type="button"
                    disabled={busy || calibration.recommended === threshold}
                    onClick={() => setThreshold(calibration.recommended as number)}
                  >
                    العتبة الموصى بها: {calibration.recommended}% — املأ الحقل
                  </button>
                )}

                <details style={{ marginTop: 8 }}>
                  <summary style={{ fontSize: 12.5 }}>ثمن كل عتبة</summary>
                  <div style={{ marginTop: 6 }}>
                    {calibration.curve
                      .filter((p) => p.wrongAccepts > 0 || p.wrongEscalations > 0)
                      .map((p) => (
                        <div key={p.threshold} className="cell-row" style={{ fontSize: 12 }}>
                          <span>{p.threshold}%</span>
                          <span>
                            <span style={{ color: p.wrongAccepts > 0 ? 'var(--red)' : undefined }}>
                              قبول خاطئ {p.wrongAccepts}
                            </span>
                            {' · '}تصعيد زائد {p.wrongEscalations}
                          </span>
                        </div>
                      ))}
                  </div>
                </details>
              </>
            )}
          </div>
        </div>
      </div>

      {/* ── مفاتيح المسارات ── */}
      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>تفعيل المسارات</h3></div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          <p style={{ color: 'var(--muted)', fontSize: 12.5 }}>
            الخطة تفرض تفعيل المسارات <b>واحداً واحداً بعد تجاوز معياره</b> لا المنظومة دفعةً
            واحدة — فحصيلة التقييم معروضة بجانب كل مفتاح. ومسارٌ مُطفأ يسقط إلى الاحتياطيّ
            الموسوم نفسه، فلا يرى العميل شيئاً تشغيلياً.
          </p>

          {taskSwitches.map((t) => (
            <div key={t.task} className="cell-row" style={{ padding: '6px 0', borderBottom: '1px solid var(--line)' }}>
              <label style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <input
                  type="checkbox"
                  checked={switches[t.task] ?? true}
                  onChange={(e) => setSwitches({ ...switches, [t.task]: e.target.checked })}
                />
                {t.task}
              </label>
              <span>
                {t.rate === null ? (
                  <span className="badge b-amber">لم يُقَس بعد</span>
                ) : (
                  <span className={`badge ${t.meets ? 'b-green' : 'b-red'}`}>
                    {Math.round(t.rate * 100)}% · بوّابة {Math.round(t.gate * 100)}%
                  </span>
                )}
              </span>
            </div>
          ))}

          <button
            className="btn sm"
            type="button"
            disabled={busy}
            style={{ marginTop: 10 }}
            onClick={() => post('/admin/ai-ops/tasks', { tasks: switches })}
          >
            <Icon name="check" /> حفظ المفاتيح
          </button>
        </div>
      </div>

      {/* ── الميزانيّة الشهريّة ── */}
      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>الميزانيّة الشهريّة</h3></div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          <p style={{ color: 'var(--muted)', fontSize: 12.5 }}>
            إنفاق الشهر الجاري:{' '}
            <b>{spending.thisMonth === null ? 'غير معلوم — لا نداء مسعَّر بعد' : `$${spending.thisMonth}`}</b>
            {settings.budget.cap !== null && ` من $${settings.budget.cap}`}
          </p>

          {spending.stopped && (
            <div className="mtg-pend" style={{ color: 'var(--red)' }}>
              <Icon name="info" /> النداءات موقوفة الآن لتجاوز الميزانيّة — المخرجات احتياطيّة موسومة،
              والعميل لا يرى شيئاً تشغيلياً.
            </div>
          )}

          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <div className="field" style={{ maxWidth: 200 }}>
              <label>السقف الشهريّ (اتركه فارغاً = بلا سقف)</label>
              <input
                className="input"
                type="number"
                min={0}
                step="0.01"
                value={cap}
                onChange={(e) => setCap(e.target.value)}
                placeholder="بلا سقف"
              />
            </div>
            <div className="field" style={{ maxWidth: 160 }}>
              <label>التنبيه عند (%)</label>
              <input className="input" type="number" min={10} max={100} value={warnAt} onChange={(e) => setWarnAt(Number(e.target.value))} />
            </div>
            <label style={{ display: 'flex', gap: 6, alignItems: 'center', fontSize: 12.5, paddingBottom: 14 }}>
              <input type="checkbox" checked={stop} onChange={(e) => setStop(e.target.checked)} />
              إيقاف النداءات عند التجاوز
            </label>
            <button
              className="btn sm"
              type="button"
              style={{ marginBottom: 14 }}
              onClick={() => router.post('/admin/ai-ops/budget', { cap: cap === '' ? null : Number(cap), warnAt, stop }, { preserveScroll: true })}
            >
              <Icon name="check" /> حفظ الميزانيّة
            </button>
          </div>

          {/* أثرٌ واسع لا يُفتَرض: تفعيله يوقف معالجة الذكاء كلّها عند التجاوز */}
          <p style={{ color: stop ? 'var(--amber)' : 'var(--muted)', fontSize: 12 }}>
            {stop
              ? 'الإيقاف مفعَّل: عند تجاوز السقف تتوقّف كل نداءات الذكاء حتى بداية الشهر التالي أو رفع السقف.'
              : 'الإيقاف مُطفأ: التجاوز يُنبِّه ولا يمنع.'}
          </p>
          <p style={{ color: 'var(--muted)', fontSize: 12 }}>
            الميزانيّة تُقارَن بالكلفة <b>المعلومة</b>؛ ونموذجٌ بلا سعر لا يُحتسب صفراً، فقد يفوق
            الإنفاق الحقيقيّ المعروضَ ما دامت التغطية ناقصة.
          </p>
        </div>
      </div>

      {/* ── الأسعار ── */}
      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>أسعار النماذج — لكل مليون توكن</h3></div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          <p style={{ color: 'var(--muted)', fontSize: 12.5 }}>
            بلا سعر تبقى الكلفة «غير معلومة» ويُعلَن أن المجموع جزئيّ — لا صفر يوهم بأن
            النداء مجّانيّ.
          </p>
          {pricing.map((row, i) => (
            <div key={i} className="cell-row" style={{ gap: 8, alignItems: 'flex-end' }}>
              <div className="field" style={{ margin: 0 }}>
                <label>النموذج</label>
                <input className="input" value={row.model}
                  onChange={(e) => setPricing(pricing.map((p, j) => (j === i ? { ...p, model: e.target.value } : p)))} />
              </div>
              <div className="field" style={{ margin: 0, maxWidth: 130 }}>
                <label>إدخال</label>
                <input className="input" type="number" step="0.001" value={row.input}
                  onChange={(e) => setPricing(pricing.map((p, j) => (j === i ? { ...p, input: Number(e.target.value) } : p)))} />
              </div>
              <div className="field" style={{ margin: 0, maxWidth: 130 }}>
                <label>إخراج</label>
                <input className="input" type="number" step="0.001" value={row.output}
                  onChange={(e) => setPricing(pricing.map((p, j) => (j === i ? { ...p, output: Number(e.target.value) } : p)))} />
              </div>
              <button className="btn soft sm" type="button" onClick={() => setPricing(pricing.filter((_, j) => j !== i))}>حذف</button>
            </div>
          ))}
          <div style={{ marginTop: 10, display: 'flex', gap: 8 }}>
            <button className="btn soft sm" type="button" onClick={() => setPricing([...pricing, { model: '', input: 0, output: 0 }])}>
              إضافة نموذج
            </button>
            <button className="btn sm" disabled={busy} type="button" onClick={() => post('/admin/ai-ops/pricing', { pricing })}>
              <Icon name="check" /> حفظ الأسعار
            </button>
          </div>
        </div>
      </div>

      {/* ── الاحتفاظ ── */}
      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>مدد الاحتفاظ بالأيام</h3></div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          <p style={{ color: 'var(--muted)', fontSize: 12.5 }}>
            الفراغ = بلا حدّ. تُطبَّق بأمر <code>ai:purge --force</code>: تجريدُ الحقول
            السرّية أولاً ثم حذفٌ كامل — فلا تضيع المؤشّرات بمجرّد انقضاء مدّة المحتوى.
          </p>
          {settings.retention.map((r) => (
            <div key={r.value} className="field" style={{ maxWidth: 320 }}>
              <label>
                {r.label}
                {r.isDefault && <span style={{ color: 'var(--muted)', fontSize: 11 }}> — افتراض لم يُعتمد بعد</span>}
              </label>
              <input className="input" type="number" min={1} max={3650} value={retention[r.value] ?? ''}
                onChange={(e) => setRetention({ ...retention, [r.value]: e.target.value })} />
            </div>
          ))}
          {/* السند النظاميّ أمام من يقرّر — القرار بلا سنده حدسٌ آخر */}
          <details style={{ marginTop: 6 }}>
            <summary style={{ fontSize: 12.5 }}>السند النظاميّ لهذا القرار</summary>
            <div style={{ fontSize: 12.5, marginTop: 6, lineHeight: 1.9 }}>
              <p>
                <b>الحدّ الأعلى المرجعيّ — نظام المحاماة:</b> لا تُسمع دعوى الموكّل في مطالبة
                محاميه بالأوراق والمستندات المودعة لديه بعد مضيّ <b>خمس سنوات</b> من انتهاء
                مهمّته. فمسؤوليّة المكتب عن الملفّ تمتدّ هذه المدّة.
              </p>
              <p>
                <b>الحدّ الأدنى المبدئيّ — نظام حماية البيانات الشخصيّة (م/148):</b> تُتلَف
                البيانات الشخصيّة بعد انتهاء الغرض من جمعها <b>دون تأخير</b>؛ وإن وُجد مسوّغ
                نظاميّ للاحتفاظ مدّةً محدّدة، فتُتلَف بعد أطول المدّتين.
              </p>
              <p style={{ color: 'var(--amber)' }}>
                المدد الحاليّة أقصر بكثير من خمس سنوات. وهذا اختيارٌ مشروع — قيود الذكاء
                <b> لا تحوي محتوى</b> (رموز وأزمنة وأعداد)، والملفّ نفسه محفوظ في مكانه.
                لكنّ أثره أن نزاعاً في السنة الثالثة لن يجد قيداً يشرح كيف عولج الطلب.
              </p>
              <p style={{ color: 'var(--muted)' }}>
                هذه إحالات للاسترشاد لا فتوى؛ الاعتماد قرارُ المكتب.
              </p>
            </div>
          </details>

          <div className="field" style={{ marginTop: 8 }}>
            <label>سند القرار (يُحفظ مع الاعتماد)</label>
            <input className="input" value={basis} onChange={(e) => setBasis(e.target.value)}
              placeholder="مثل: قرار اجتماع الحوكمة بتاريخ… استناداً إلى نظام حماية البيانات" />
          </div>

          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button className="btn soft sm" disabled={busy} type="button" onClick={() => post('/admin/ai-ops/retention', { retention })}>
              <Icon name="check" /> حفظ المدد
            </button>
            {/* الاعتماد واقعةٌ تُسجَّل باسم من اتّخذها — لا وسمٌ يُرفع */}
            <button className="btn sm" disabled={busy} type="button"
              onClick={() => post('/admin/ai-ops/retention', { retention, approve: true, basis: basis || null })}>
              <Icon name="check" /> اعتمِد هذه المدد باسمي
            </button>
          </div>

          {settings.retentionApproved ? (
            <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 8 }}>
              معتمدة من <b>{settings.retentionApproval.by}</b> بتاريخ {settings.retentionApproval.at}
              {settings.retentionApproval.basis && ` — ${settings.retentionApproval.basis}`}
            </p>
          ) : (
            <p style={{ color: 'var(--amber)', fontSize: 12, marginTop: 8 }}>
              لم تُعتمد بعد: الأرقام الحاليّة اقتراحٌ هندسيّ لا قرارُ مكتب.
            </p>
          )}
        </div>
      </div>

      {/* ── مجموعة التقييم: الطبقة الثانية ── */}
      <div className="card">
        <div className="card-h"><h3>مجموعة التقييم</h3></div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          <p style={{ color: 'var(--muted)', fontSize: 12.5 }}>
            حالات <b>مصطنعة بالكامل</b> — لا بيانات عملاء تُرسَل. شغّلها قبل أي تغيير في
            نموذج أو تعليمة وقارن الحصيلة بالسابقة. سقوطُ مهمّة دون بوّابتها يمنع الاعتماد
            <b> ولو تحسّن المتوسّط العام</b>.
          </p>

          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', margin: '10px 0' }}>
            <button className="btn soft sm" disabled={busy} type="button" onClick={() => post('/admin/ai-ops/evaluate', { live: false })}>
              تشغيل جافّ — بلا نداء شبكيّ
            </button>
            <button
              className="btn sm"
              type="button"
              disabled={busy || !evaluation.providerReady}
              title={evaluation.providerReady ? 'يستهلك حصّة المزوّد الفعليّة' : 'لا مزوّد مهيَّأ'}
              onClick={() => post('/admin/ai-ops/evaluate', { live: true })}
            >
              <Icon name="sparkles" /> تشغيل حيّ على المزوّد
            </button>
          </div>

          {!evaluation.providerReady && (
            <p style={{ color: 'var(--amber)', fontSize: 12 }}>لا مزوّد مهيَّأ — التشغيل الحيّ متعذّر.</p>
          )}

          {evaluation.last === null ? (
            <p>لم تُشغَّل المجموعة بعد.</p>
          ) : (
            <>
              <p style={{ fontSize: 12.5 }}>
                آخر تشغيل: {evaluation.last.at} — {evaluation.last.live ? 'حيّ' : 'جافّ'}
                {evaluation.last.by && ` · بأمر ${evaluation.last.by}`}
                {evaluation.last.live && ` · الكلفة: ${evaluation.last.cost === null ? 'غير معلومة' : `$${evaluation.last.cost}`}`}
              </p>

              {evaluation.last.running && (
                <div className="mtg-pend"><Icon name="info" /> التشغيل الحيّ جارٍ الآن — حدّث الصفحة بعد دقائق. الأرقام أدناه من تشغيلٍ سابق.</div>
              )}
              {evaluation.last.failure && (
                <div className="mtg-pend" style={{ color: 'var(--red)' }}><Icon name="info" /> {evaluation.last.failure}</div>
              )}

              {/* الفرق عن السابق: النتيجة وحدها تقول «كم هي اليوم»، والسؤال الحاكم
                  «هل تراجعت». والمتوسّط يخفي التراجع، فتُقارَن كل مهمّة على حدة. */}
              {evaluation.diff.length === 0 ? (
                <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 8 }}>
                  {evaluation.runsRecorded <= 1
                    ? 'أوّل تشغيل مسجَّل — صار خطَّ الأساس، ولا سابق يُقارَن به.'
                    : 'لا مقارنة متاحة بعد.'}
                </p>
              ) : (
                <div style={{ marginTop: 10, padding: '10px 12px', borderInlineStart: `3px solid var(--${evaluation.diff.some((d) => d.regressed) ? 'red' : 'green'})` }}>
                  <b style={{ fontSize: 12.5 }}>الفرق عن التشغيل السابق{evaluation.baselineAt && ` (${evaluation.baselineAt})`}</b>
                  {evaluation.diff.some((d) => d.regressed) && (
                    <p style={{ color: 'var(--red)', fontSize: 12.5, margin: '4px 0' }}>
                      تراجعت مهمّة عن تشغيلها السابق — لا يُعتمد النموذج أو التعليمة ولو عبرت كل البوّابات.
                    </p>
                  )}
                  {evaluation.diff.map((d) => (
                    <div key={d.task} className="cell-row" style={{ fontSize: 12.5 }}>
                      <span>{d.task}</span>
                      <span style={{ color: d.regressed ? 'var(--red)' : undefined }}>
                        {d.isNew
                          ? 'مهمّة جديدة — لا سابق لها'
                          : d.delta === 0
                            ? 'بلا تغيّر'
                            : `${Math.round((d.previous ?? 0) * 100)}% ← ${Math.round(d.rate * 100)}%`}
                      </span>
                    </div>
                  ))}
                </div>
              )}

              {evaluation.last.results.map((r) => (
                <div key={r.task} style={{ marginTop: 10 }}>
                  <div className="cell-row">
                    <span>
                      {r.task}
                      <span className={`badge ${r.meets ? 'b-green' : 'b-red'}`} style={{ marginInlineStart: 6 }}>
                        {r.meets ? 'عبرت' : 'سقطت'}
                      </span>
                      {r.liveSkipped && <span className="badge b-amber" style={{ marginInlineStart: 6 }}>جافّة</span>}
                    </span>
                    <span>
                      {r.passed}/{r.total} · بوّابة {Math.round(r.gate * 100)}%
                      {r.skipped > 0 && ` · مؤجَّلة ${r.skipped}`}
                    </span>
                  </div>
                  {r.liveSkipped && (
                    <p style={{ color: 'var(--muted)', fontSize: 12, margin: '2px 0 0' }}>لم تُشغَّل حيّاً — {r.liveSkipped}</p>
                  )}
                  {/* لا اقتطاع صامت: «100%» على مقامٍ ناقص تُقرأ تغطيةً كاملة */}
                  {r.skipped > 0 && (
                    <p style={{ color: 'var(--muted)', fontSize: 12, margin: '2px 0 0' }}>
                      {r.skipped} حالة توقُّعها معلَّق بحكم النموذج — لا تُقاس على مخرجٍ مثبَّت، تحتاج تشغيلاً حيّاً.
                    </p>
                  )}
                  {r.failures.map((f, i) => (
                    <p key={i} style={{ color: 'var(--red)', fontSize: 12, margin: '2px 0 0' }}>✗ {f}</p>
                  ))}
                </div>
              ))}
            </>
          )}

          <p style={{ marginTop: 12, color: 'var(--muted)', fontSize: 12 }}>
            هذه <b>الطبقة الثانية</b>. الثالثة مراجعةُ محامٍ لعيّنة عمياء دوريّة، وتتجمّع
            أسبابها تلقائياً في صندوق المراجعة أعلاه. وللجدولة وخطّ التكامل: <code>ai:evaluate --live</code>.
          </p>
        </div>
      </div>
    </div>
  );
};

export default AiOps;
