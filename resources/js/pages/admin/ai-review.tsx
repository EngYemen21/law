import { router, usePage } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { panelBase } from '@/lib/data';
import Icon from '@/lib/icons';
import { firstError } from '@/lib/server-message';

/** مخرج ينتظر قرار إنسان */
interface ReviewItem {
  id: number;
  taskType: string;
  /** اسم المهمّة من سجلّ التعليمات في الخادم — لا خريطة أسماء هنا */
  taskLabel: string;
  entityRef: string;
  source: string | null;
  sourceLabel: string | null;
  /** `null` = غير مقيسة — تُعرض كذلك ولا تُحوَّل صفراً */
  confidence: number | null;
  confidenceSignals: Record<string, unknown> | null;
  /** الإشارات مسمّاةً بالعربيّة من جوار تعريفها في الخادم (`AiConfidence::describe`) */
  signalRows: { key: string; label: string; ok: boolean }[];
  /** أعدادٌ وحجم لا محتوى — دليل أن المعرّفات مُوّهت قبل مغادرة الخادم. */
  outboundAudit: { chars: number; masked: Record<string, number> } | null;
  model: string;
  promptVersion: string;
  failureCode: string | null;
  failureLabel: string | null;
  traceId: string | null;
  /** نصّ المخرج للقراءة فقط — التحرير في شاشة الملفّ. `null` = لا مخرج محفوظ. */
  preview: { text: string; fullText?: string; truncated: boolean; label: string; href: string | null } | null;
  /** حالة الملفّ الآن (`AiReviewEntityState`)، و`stale` أثرُ القبول حين لا يصل العميل — كلاهما من الخادم. */
  entityState: { status: string | null; stale: string | null };
  createdAt: string | null;
}

interface ActionOption {
  value: string;
  label: string;
  requires_reason: boolean;
  requires_assignee: boolean;
}

interface ReasonOption {
  value: string;
  label: string;
  high_risk: boolean;
}

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

/** وسام الثقة البصري المتدرج */
const VisualConfidenceBadge: React.FC<{ value: number | null }> = ({ value }) => {
  if (value === null) {
    return (
      <span
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          gap: 6,
          padding: '4px 10px',
          borderRadius: 20,
          fontSize: 12,
          fontWeight: 700,
          background: 'rgba(0,0,0,0.06)',
          color: 'var(--muted)',
        }}
      >
        <span style={{ width: 7, height: 7, borderRadius: '50%', background: '#888' }} />
        غير مقيسة
      </span>
    );
  }

  const isHigh = value >= 70;
  const isMed = value >= 40 && value < 70;
  const bg = isHigh ? 'rgba(30, 157, 107, 0.12)' : isMed ? 'rgba(192, 131, 43, 0.12)' : 'rgba(192, 57, 43, 0.12)';
  const color = isHigh ? '#1E9D6B' : isMed ? '#C0832B' : '#C0392B';
  const label = isHigh ? 'ثقة عالية' : isMed ? 'ثقة متوسطة' : 'حرجة / يلزم تدقيق';

  return (
    <span
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        padding: '4px 12px',
        borderRadius: 20,
        fontSize: 12.5,
        fontWeight: 800,
        background: bg,
        color: color,
        border: `1px solid ${color}33`,
      }}
    >
      <span style={{ width: 8, height: 8, borderRadius: '50%', background: color }} />
      {value}% ({label})
    </span>
  );
};

/** تنظيف النص من أي وسوم HTML أو نصوص برمجية وفك تشفير الكيانات */
const cleanPreviewText = (rawText: string | null | undefined): string => {
  if (!rawText) return '';
  let cleaned = rawText
    .replace(/<div\b[^>]*>/gi, '')
    .replace(/<\/div>/gi, '')
    .replace(/<p\b[^>]*>/gi, '')
    .replace(/<\/p>/gi, '\n')
    .replace(/<br\s*\/?>/gi, '\n');

  cleaned = cleaned
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&nbsp;/g, ' ');

  return cleaned.trim();
};

export const AiReview: React.FC<{
  items: ReviewItem[];
  actions: ActionOption[];
  reasons: ReasonOption[];
  /** من يُصعَّد إليه — القائمة نفسها التي يتحقّق بها الخادم */
  assignees: { id: number; label: string }[];
  /** عملة الكلفة من الخادم (`AiCost::CURRENCY`) — لا تُكتب هنا */
  currency: string;
  metrics: Metrics;
}> = ({ items, actions, reasons, assignees, currency, metrics }) => {
  const toast = useToast();
  const base = panelBase((usePage().url as string).split('?')[0]);

  // حالة النوافذ والإجراءات
  const [activeActionItemId, setActiveActionItemId] = useState<number | null>(null);
  const [selectedAction, setSelectedAction] = useState<string>('accept');
  const [selectedReason, setSelectedReason] = useState<string>('');
  const [actionNote, setActionNote] = useState<string>('');
  const [escalatedTo, setEscalatedTo] = useState<string>('');
  // ما يحتاجه الفعل يعلنه الخادم (`requires_reason` / `requires_assignee`) — لا مقارنة باسم الفعل هنا
  const actionMeta = (value: string) => actions.find((a) => a.value === value);
  const needsAssignee = actionMeta(selectedAction)?.requires_assignee ?? false;
  const needsReason = actionMeta(selectedAction)?.requires_reason ?? false;
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);

  // نافذة القراءة الكاملة الموسعة
  const [readingModalItem, setReadingModalItem] = useState<ReviewItem | null>(null);

  // إظهار كود JSON الفني عند الحاجة
  const [showTechnicalJson, setShowTechnicalJson] = useState<Record<number, boolean>>({});

  // الفلاتر والبحث
  const [taskFilter, setTaskFilter] = useState<string>('all');
  const [confidenceFilter, setConfidenceFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');

  // استخراج أنواع المهام الفريدة للتبويبات
  const taskTypes = useMemo(() => {
    const set = new Set<string>();
    items.forEach((it) => {
      if (it.taskType) set.add(it.taskType);
    });
    return Array.from(set);
  }, [items]);

  // المخرجات بعد الفلترة
  const filteredItems = useMemo(() => {
    return items.filter((item) => {
      if (taskFilter !== 'all' && item.taskType !== taskFilter) {
        return false;
      }

      if (confidenceFilter === 'high' && (item.confidence === null || item.confidence < 70)) {
        return false;
      }
      if (confidenceFilter === 'medium' && (item.confidence === null || item.confidence < 40 || item.confidence >= 70)) {
        return false;
      }
      if (confidenceFilter === 'low' && (item.confidence === null || item.confidence >= 40)) {
        return false;
      }
      if (confidenceFilter === 'unmeasured' && item.confidence !== null) {
        return false;
      }

      if (searchQuery.trim() !== '') {
        const needle = searchQuery.toLowerCase();
        const matchesRef = item.entityRef?.toLowerCase().includes(needle);
        const matchesType = item.taskLabel?.toLowerCase().includes(needle);
        const matchesText = item.preview?.text?.toLowerCase().includes(needle);
        if (!matchesRef && !matchesType && !matchesText) {
          return false;
        }
      }

      return true;
    });
  }, [items, taskFilter, confidenceFilter, searchQuery]);

  // إرسال القرار
  const handleDecide = (itemId: number, actionValue: string, reasonValue?: string, noteValue?: string) => {
    setIsSubmitting(true);
    router.post(
      `${base}/ai-review/${itemId}/decide`,
      {
        action: actionValue,
        reason: reasonValue || null,
        note: noteValue || null,
        // يُرسل مع الفعل الذي يطلبه وحده — كان التصعيد لا يُرسله فيردّه الخادم دائماً
        escalated_to: actionMeta(actionValue)?.requires_assignee && escalatedTo ? Number(escalatedTo) : null,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setIsSubmitting(false);
          setActiveActionItemId(null);
          setReadingModalItem(null);
          setSelectedAction('accept');
          setSelectedReason('');
          setActionNote('');
          setEscalatedTo('');
          // لا توست نجاح هنا: رسالة الخادم («سُجّل القرار: …») يعرضها التخطيط مرّةً واحدة
        },
        onError: (err) => {
          setIsSubmitting(false);
          toast(`⚠️ ${firstError(err, 'تعذر تسجيل القرار')}`, 'error');
        },
      }
    );
  };

  // زر القبول السريع بلمسة واحدة
  const quickAccept = (item: ReviewItem) => {
    handleDecide(item.id, 'accept');
  };

  return (
    <div className="admin-ai-review-root" style={{ paddingBottom: 60, width: '100%' }}>
      {/* ── الأنماط والتصميم المتناسق مع هوية بابيلون ── */}
      <style>{`
        .air-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          flex-wrap: wrap;
          gap: 16px;
          margin-bottom: 20px;
        }
        .air-kpi-grid {
          display: grid;
          grid-template-columns: repeat(5, 1fr);
          gap: 12px;
          margin-bottom: 22px;
        }
        .air-filter-card {
          background: #fff;
          border-radius: 12px;
          border: 1px solid rgba(0,0,0,0.08);
          padding: 14px 16px;
          margin-bottom: 20px;
          display: flex;
          flex-direction: column;
          gap: 12px;
        }
        .air-pill-scroll {
          display: flex;
          gap: 8px;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
          padding-bottom: 4px;
        }
        .air-pill-btn {
          border: none;
          background: rgba(0,0,0,0.05);
          color: inherit;
          border-radius: 20px;
          padding: 6px 14px;
          font-size: 12.5px;
          font-weight: 600;
          cursor: pointer;
          white-space: nowrap;
          display: flex;
          align-items: center;
          gap: 6px;
          transition: all 0.2s;
        }
        .air-pill-btn.active {
          background: var(--primary);
          color: #fff;
        }
        .air-item-card {
          background: #fff;
          border-radius: 14px;
          border: 1px solid rgba(0,0,0,0.08);
          margin-bottom: 16px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.03);
          overflow: hidden;
          transition: transform 0.2s, box-shadow 0.2s;
        }
        .air-item-card:hover {
          box-shadow: 0 6px 18px rgba(0,0,0,0.06);
        }
        .air-card-top {
          padding: 14px 18px;
          background: #fafbfc;
          border-bottom: 1px solid rgba(0,0,0,0.06);
          display: flex;
          justify-content: space-between;
          align-items: center;
          flex-wrap: wrap;
          gap: 10px;
        }
        .air-card-content {
          padding: 18px 20px;
        }
        .air-meta-strip {
          display: flex;
          flex-wrap: wrap;
          gap: 16px;
          font-size: 12.5px;
          color: var(--muted);
          padding-bottom: 12px;
          border-bottom: 1px dashed rgba(0,0,0,0.08);
        }
        .air-signals-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
          gap: 8px;
          margin: 12px 0;
        }
        .air-signal-chip {
          display: flex;
          align-items: center;
          gap: 8px;
          padding: 6px 10px;
          border-radius: 8px;
          font-size: 12px;
          background: rgba(0,0,0,0.03);
        }
        .air-preview-viewport {
          margin-top: 14px;
          border: 1px solid rgba(0,0,0,0.1);
          border-radius: 10px;
          overflow: hidden;
          background: #fff;
        }
        .air-preview-head {
          padding: 8px 14px;
          background: rgba(0,0,0,0.03);
          border-bottom: 1px solid rgba(0,0,0,0.08);
          font-size: 12px;
          font-weight: 700;
          display: flex;
          justify-content: space-between;
          align-items: center;
        }
        .air-preview-body {
          padding: 14px 16px;
          white-space: pre-wrap;
          font-size: 13.5px;
          line-height: 1.9;
          max-height: 240px;
          overflow-y: auto;
          color: #1a1a1a;
          background: #fdfdfd;
        }
        .air-action-toolbar {
          margin-top: 16px;
          padding-top: 14px;
          border-top: 1px solid rgba(0,0,0,0.07);
          display: flex;
          justify-content: space-between;
          align-items: center;
          flex-wrap: wrap;
          gap: 10px;
        }
        @media (max-width: 1024px) {
          .air-kpi-grid {
            grid-template-columns: repeat(3, 1fr);
          }
        }
        @media (max-width: 680px) {
          .air-kpi-grid {
            grid-template-columns: repeat(2, 1fr);
          }
          .air-card-top {
            flex-direction: column;
            align-items: flex-start;
          }
        }
      `}</style>

      {/* ── 1. الهيدر والترحيب التنفيذي ── */}
      <div className="greet air-header">
        <div>
          <h1 style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0, fontSize: 'clamp(18px, 2.5vw, 24px)' }}>
            <Icon name="sparkles" cls="ic" />
            صندوق مراجعة وتدقيق مخرجات الذكاء الاصطناعي
          </h1>
          <p style={{ margin: '6px 0 0', color: 'var(--muted)', fontSize: 13.5 }}>
            بوابة الاعتماد البشري الإلزامي — تُحجب جميع المخرجات واللوائح عن العملاء حتى يعتمدها محامٍ مرخص.
          </p>
        </div>

        {metrics.pending > 0 && (
          <div
            style={{
              padding: '8px 16px',
              borderRadius: 30,
              background: 'rgba(192, 131, 43, 0.12)',
              border: '1px solid rgba(192, 131, 43, 0.3)',
              color: '#C0832B',
              fontSize: 13,
              fontWeight: 700,
              display: 'flex',
              alignItems: 'center',
              gap: 8,
            }}
          >
            <span style={{ width: 8, height: 8, borderRadius: '50%', background: '#C0832B', animation: 'pulse 1.5s infinite' }} />
            يوجد {metrics.pending} مخرج بانتظار قرارك
          </div>
        )}
      </div>

      {/* ── 2. شريط التنبيهات والأخطار التشغيلية إن وجدت ── */}
      {metrics.alerts.length > 0 && (
        <div
          className="card"
          style={{
            marginBottom: 16,
            borderRight: '4px solid #C0392B',
            background: 'rgba(192, 57, 43, 0.04)',
          }}
        >
          <div className="card-b" style={{ padding: '12px 18px' }}>
            <b style={{ color: '#C0392B', fontSize: 13, display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4 }}>
              <Icon name="alert" /> تنبيهات تشغيلية تتطلب مراجعة فورية:
            </b>
            {metrics.alerts.map((a) => (
              <div key={a.code} style={{ fontSize: 13, color: '#444', margin: '4px 0' }}>
                • {a.message}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ── 3. شريط المؤشرات اللحظية الذكي (Executive KPI Ribbon) ── */}
      <div className="air-kpi-grid">
        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid var(--primary)' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between' }}>
            <span>بانتظار المراجعة</span>
            <Icon name="folder" />
          </div>
          <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--primary)', marginTop: 4 }}>
            {metrics.pending}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>مخرجات تنتظر الاعتماد</div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #0E5C9C' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between' }}>
            <span>نسبة التدخل البشري</span>
            <Icon name="user" />
          </div>
          <div style={{ fontSize: 22, fontWeight: 800, color: '#0E5C9C', marginTop: 4 }}>
            {pct(metrics.editRate)}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>مخرجات عُدلت قبل القبول</div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #C0832B' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between' }}>
            <span>نسبة الاحتياطي</span>
            <Icon name="scale" />
          </div>
          <div style={{ fontSize: 22, fontWeight: 800, color: '#C0832B', marginTop: 4 }}>
            {pct(metrics.ops.fallback_rate)}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>تحويلات للنموذج البديل</div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #1E9D6B' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between' }}>
            <span>سرعة الاستجابة P95</span>
            <Icon name="check" />
          </div>
          <div style={{ fontSize: 20, fontWeight: 800, color: '#1E9D6B', marginTop: 4 }}>
            {metrics.ops.latency_p95_ms === null ? '—' : `${metrics.ops.latency_p95_ms} م.ث`}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>زمن المعالجة والاستخراج</div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #8e44ad' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between' }}>
            <span>الكلفة التقديرية</span>
            <Icon name="card" />
          </div>
          <div style={{ fontSize: 20, fontWeight: 800, color: '#8e44ad', marginTop: 4 }}>
            {metrics.ops.estimated_cost === null ? 'غير معلومة' : `${metrics.ops.estimated_cost} ${currency}`}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>
            {metrics.ops.cost_coverage !== null && metrics.ops.cost_coverage < 1 ? (
              <span style={{ color: 'var(--amber)' }}>تغطية تسعير جزئية</span>
            ) : (
              'الاستهلاك الفعليّ لدى المزوّد'
            )}
          </div>
        </div>
      </div>

      {/* ── 4. شريط الفلترة والتصنيف الذكي ── */}
      <div className="air-filter-card">
        {/* أ) تبويبات أنواع المهام */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
          <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', flexShrink: 0 }}>نوع المخرج:</span>
          <div className="air-pill-scroll" style={{ flex: 1 }}>
            <button
              type="button"
              className={`air-pill-btn ${taskFilter === 'all' ? 'active' : ''}`}
              onClick={() => setTaskFilter('all')}
            >
              جميع المهام ({items.length})
            </button>
            {taskTypes.map((t) => {
              const count = items.filter((i) => i.taskType === t).length;
              return (
                <button
                  key={t}
                  type="button"
                  className={`air-pill-btn ${taskFilter === t ? 'active' : ''}`}
                  onClick={() => setTaskFilter(t)}
                >
                  {items.find((i) => i.taskType === t)?.taskLabel ?? 'مخرج ذكاء'} ({count})
                </button>
              );
            })}
          </div>
        </div>

        {/* ب) خيارات الثقة والبحث */}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 10, paddingTop: 4 }}>
          {/* حقل البحث السريع */}
          <div style={{ position: 'relative' }}>
            <input
              type="text"
              placeholder="بحث بالمرجع (مثل: CN-2026, SB-2026) أو النص..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              style={{
                width: '100%',
                padding: '8px 32px 8px 12px',
                borderRadius: 8,
                border: '1px solid rgba(0,0,0,0.15)',
                fontSize: 13,
                boxSizing: 'border-box',
              }}
            />
            <span style={{ position: 'absolute', right: 10, top: 9, opacity: 0.5 }}>
              <Icon name="search" />
            </span>
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                style={{ position: 'absolute', left: 8, top: 8, background: 'none', border: 'none', cursor: 'pointer', color: '#999' }}
              >
                ✕
              </button>
            )}
          </div>

          {/* محدد مستوى الثقة */}
          <select
            value={confidenceFilter}
            onChange={(e) => setConfidenceFilter(e.target.value)}
            style={{
              padding: '8px 12px',
              borderRadius: 8,
              border: '1px solid rgba(0,0,0,0.15)',
              fontSize: 13,
            }}
          >
            <option value="all">كل مستويات الثقة</option>
            <option value="high">🟢 ثقة عالية (70% فأعلى)</option>
            <option value="medium">🟡 ثقة متوسطة (40% - 69%)</option>
            <option value="low">🔴 حرجة / تتطلب تدقيقاً (أقل من 40%)</option>
            <option value="unmeasured">⚪ غير مقيسة</option>
          </select>
        </div>
      </div>

      {/* ── 5. قائمة كروت المخرجات القانونية ── */}
      {filteredItems.length === 0 ? (
        <div className="card">
          <div className="card-b" style={{ padding: 40, textAlign: 'center' }}>
            <div style={{ fontSize: 32, marginBottom: 10 }}>✨</div>
            <h3 style={{ margin: '0 0 6px', color: 'var(--primary)' }}>لا توجد مخرجات معلقة مطابقة للبحث</h3>
            <p style={{ margin: 0, color: 'var(--muted)', fontSize: 13.5 }}>
              جميع مخرجات الذكاء الاصطناعي معتمدة ومطابقة، أو لم يتم العثور على نتائج تطابق معايير الفلترة المحددة.
            </p>
          </div>
        </div>
      ) : (
        filteredItems.map((item) => {
          const isDecidingThis = activeActionItemId === item.id;
          const showJson = Boolean(showTechnicalJson[item.id]);

          return (
            <div key={item.id} className="air-item-card">
              {/* ترويسة الكرت */}
              <div className="air-card-top">
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                  <b style={{ fontSize: 15, color: 'var(--primary)' }}>{item.entityRef}</b>
                  <Badge text={item.taskLabel} tone="b-blue" />
                  {item.entityState.status && <Badge text={`حالة الملف: ${item.entityState.status}`} tone={item.entityState.stale ? 'b-amber' : 'b-grey'} />}
                  {item.sourceLabel && (
                    <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                      المصدر: <b>{item.sourceLabel}</b>
                    </span>
                  )}
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <VisualConfidenceBadge value={item.confidence} />
                  <span style={{ fontSize: 12, color: 'var(--muted)' }}>{item.createdAt}</span>
                </div>
              </div>

              {/* جسم الكرت */}
              <div className="air-card-content">
                {item.entityState.stale && (
                  <div className="mtg-pend" role="note" style={{ marginBottom: 10 }}>
                    <Icon name="info" /> {item.entityState.stale}
                  </div>
                )}
                {/* شريط البيانات الفنية الأساسية */}
                <div className="air-meta-strip">
                  <span>النموذج المولد: <b>{item.model}</b></span>
                  <span>إصدار التوجيه: <b>{item.promptVersion}</b></span>
                  {item.traceId && <span>رقم التتبع: <code style={{ fontSize: 11 }}>{item.traceId.slice(0, 8)}</code></span>}
                  {item.failureLabel && (
                    <span style={{ color: '#C0392B', fontWeight: 700 }}>
                      ⚠️ سبب التعثر: {item.failureLabel}
                    </span>
                  )}
                </div>

                {/* دليل الخصوصية وتقليل البيانات */}
                {item.outboundAudit && (
                  <div
                    style={{
                      marginTop: 10,
                      padding: '8px 12px',
                      borderRadius: 8,
                      background: 'rgba(14, 92, 156, 0.04)',
                      border: '1px solid rgba(14, 92, 156, 0.1)',
                      fontSize: 12,
                      display: 'flex',
                      alignItems: 'center',
                      gap: 8,
                    }}
                  >
                    <Icon name="check" />
                    <span>
                      <b>حماية الخصوصية ومطابقة PDPL:</b> أُرسل {item.outboundAudit.chars.toLocaleString('ar')} حرفاً
                      {Object.keys(item.outboundAudit.masked).length === 0
                        ? ' · لا توجد معرّفات حساسة في الحمولة'
                        : ` · تم تمويه وحجب ${Object.entries(item.outboundAudit.masked).map(([mask, n]) => `${mask} (${n})`).join('، ')}`}
                    </span>
                  </div>
                )}

                {/* إشارات الثقة المنظمة بصرياً */}
                {item.signalRows.length > 0 && (
                  <div style={{ marginTop: 12 }}>
                    <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', marginBottom: 6 }}>
                      مؤشرات الجودة والتحقق الأوتوماتيكي:
                    </div>
                    <div className="air-signals-grid">
                      {item.signalRows.map(({ key, label, ok }) => {
                        return (
                          <div key={key} className="air-signal-chip" style={{ borderLeft: `3px solid ${ok ? '#1E9D6B' : '#C0832B'}` }}>
                            <span style={{ color: ok ? '#1E9D6B' : '#C0832B', fontSize: 13 }}>
                              {ok ? '✓' : '•'}
                            </span>
                            <span style={{ fontWeight: 600 }}>{label}</span>
                          </div>
                        );
                      })}
                    </div>

                    {/* زر لعرض الـ JSON الأصلي للخبراء */}
                    <button
                      type="button"
                      onClick={() => setShowTechnicalJson((prev) => ({ ...prev, [item.id]: !prev[item.id] }))}
                      style={{
                        background: 'none',
                        border: 'none',
                        color: 'var(--primary)',
                        fontSize: 11.5,
                        cursor: 'pointer',
                        padding: '4px 0',
                        textDecoration: 'underline',
                      }}
                    >
                      {showJson ? 'إخفاء البيانات الفنّيّة الخام' : 'عرض البيانات الفنّيّة الخام'}
                    </button>
                    {showJson && (
                      <pre
                        style={{
                          background: '#f8f9fa',
                          border: '1px solid rgba(0,0,0,0.1)',
                          borderRadius: 8,
                          padding: 10,
                          fontSize: 11.5,
                          whiteSpace: 'pre-wrap',
                          marginTop: 6,
                          maxHeight: 180,
                          overflowY: 'auto',
                        }}
                      >
                        {JSON.stringify(item.confidenceSignals, null, 2)}
                      </pre>
                    )}
                  </div>
                )}

                {/* نافذة معاينة النص القانوني */}
                {item.preview && (
                  <div className="air-preview-viewport">
                    <div className="air-preview-head">
                      <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <Icon name="doc" /> {item.preview.label}
                      </span>
                      <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                        <button
                          type="button"
                          className="btn soft sm"
                          style={{ padding: '3px 8px', fontSize: 11.5 }}
                          onClick={() => setReadingModalItem(item)}
                        >
                          <Icon name="out" /> قراءة كاملة موسعة
                        </button>
                        {item.preview.href && (
                          <a
                            href={item.preview.href}
                            target="_blank"
                            rel="noreferrer"
                            style={{ color: 'var(--primary)', textDecoration: 'none', fontSize: 11.5, fontWeight: 700 }}
                          >
                            فتح الملف الأصلي ↗
                          </a>
                        )}
                      </div>
                    </div>

                    <div className="air-preview-body">
                      {cleanPreviewText(item.preview.text)}
                    </div>

                    {item.preview.truncated && (
                      <div
                        style={{
                          padding: '6px 14px',
                          background: 'rgba(192, 131, 43, 0.08)',
                          color: '#C0832B',
                          fontSize: 12,
                          display: 'flex',
                          justifyContent: 'space-between',
                          alignItems: 'center',
                        }}
                      >
                        <span>⚠️ المعاينة مقتطعة — اضغط «قراءة كاملة موسعة» للاطلاع على اللائحة بكامل موادها قبل الاعتماد.</span>
                        <button
                          type="button"
                          onClick={() => setReadingModalItem(item)}
                          style={{ background: 'none', border: 'none', color: '#C0832B', fontWeight: 700, cursor: 'pointer', textDecoration: 'underline' }}
                        >
                          قراءة النص كاملاً
                        </button>
                      </div>
                    )}
                  </div>
                )}

                {/* ── شريط الإجراءات والقرارات الفورية (One-Touch Toolbar) ── */}
                <div className="air-action-toolbar">
                  {/* أزرار الإجراءات السريعة */}
                  {!isDecidingThis ? (
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', width: '100%', alignItems: 'center' }}>
                      {/* 1. قبول سريع بضغطة واحدة */}
                      <button
                        type="button"
                        className="btn primary sm"
                        style={{ background: '#1E9D6B', borderColor: '#1E9D6B' }}
                        disabled={isSubmitting}
                        onClick={() => quickAccept(item)}
                      >
                        <Icon name="check" /> اعتماد ونشر المخرج فوراً
                      </button>

                      {/* 2. فتح خيارات الرفض المنظم */}
                      <button
                        type="button"
                        className="btn soft sm"
                        style={{ color: '#C0392B' }}
                        disabled={isSubmitting}
                        onClick={() => {
                          setActiveActionItemId(item.id);
                          setSelectedAction('reject');
                        }}
                      >
                        <Icon name="cross" /> رفض مع سبب...
                      </button>

                      {/* 3. تعديل في الملف */}
                      {item.preview?.href && (
                        <a
                          href={item.preview.href}
                          className="btn soft sm"
                        >
                          <Icon name="edit" /> تحرير بالملف
                        </a>
                      )}

                      {/* 4. خيارات القرار الموسعة (إعادة تشغيل أو تصعيد) */}
                      <button
                        type="button"
                        className="btn soft sm"
                        disabled={isSubmitting}
                        onClick={() => {
                          setActiveActionItemId(item.id);
                          setSelectedAction('escalate');
                          setEscalatedTo('');
                        }}
                      >
                        خيارات متقدمة (تصعيد / إعادة تشغيل)...
                      </button>
                    </div>
                  ) : (
                    /* نموذج اتخاذ القرار المتقدم */
                    <div
                      style={{
                        width: '100%',
                        background: '#f8f9fb',
                        padding: 16,
                        borderRadius: 10,
                        border: '1px solid rgba(0,0,0,0.1)',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 12,
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <b style={{ fontSize: 13.5 }}>تسجيل قرار التدقيق للمخرج: {item.entityRef}</b>
                        <button
                          type="button"
                          onClick={() => setActiveActionItemId(null)}
                          style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 13, color: 'var(--muted)' }}
                        >
                          ✕ إلغاء
                        </button>
                      </div>

                      {/* اختيار نوع الإجراء */}
                      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 8 }}>
                        {actions.map((act) => {
                          const isAct = selectedAction === act.value;
                          return (
                            <button
                              key={act.value}
                              type="button"
                              className={`btn sm ${isAct ? 'primary' : 'soft'}`}
                              style={{ justifyContent: 'center' }}
                              onClick={() => setSelectedAction(act.value)}
                            >
                              {act.label}
                            </button>
                          );
                        })}
                      </div>

                      {/* سبب الرفض الإلزامي في حال تم اختيار رفض */}
                      {needsReason && (
                        <div style={{ marginTop: 4 }}>
                          <label style={{ fontSize: 12, fontWeight: 700, display: 'block', marginBottom: 4, color: '#C0392B' }}>
                            سبب الرفض المنظم (إلزامي لتدريب وتقييم الذكاء الاصطناعي):
                          </label>
                          <select
                            value={selectedReason}
                            onChange={(e) => setSelectedReason(e.target.value)}
                            style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #C0392B' }}
                          >
                            <option value="">-- اختر سبب الرفض المعياري --</option>
                            {reasons.map((r) => (
                              <option key={r.value} value={r.value}>
                                {r.label} {r.high_risk ? '⚠️ (صنف عالي الخطورة)' : ''}
                              </option>
                            ))}
                          </select>
                        </div>
                      )}

                      {/* المُصعَّد إليه — إلزاميّ حين يطلبه الفعل (`requires_assignee`) */}
                      {needsAssignee && (
                        <div style={{ marginTop: 4 }}>
                          <label style={{ fontSize: 12, fontWeight: 700, display: 'block', marginBottom: 4 }}>
                            يُصعَّد إلى (محامٍ أو إداريّ):
                          </label>
                          {assignees.length === 0 ? (
                            <div style={{ fontSize: 12.5, color: '#C0392B' }}>لا محامٍ ولا إداريّ فعّال غيرك يُصعَّد إليه.</div>
                          ) : (
                            <select
                              value={escalatedTo}
                              onChange={(e) => setEscalatedTo(e.target.value)}
                              style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid rgba(0,0,0,0.15)' }}
                            >
                              <option value="">-- اختر من يُصعَّد إليه --</option>
                              {assignees.map((a) => (
                                <option key={a.id} value={String(a.id)}>{a.label}</option>
                              ))}
                            </select>
                          )}
                        </div>
                      )}

                      {/* حقل الملاحظة التوضيحية */}
                      <div>
                        <label style={{ fontSize: 12, fontWeight: 700, display: 'block', marginBottom: 4 }}>
                          ملاحظات إضافية أو تعليل مهني (اختياري):
                        </label>
                        <textarea
                          rows={2}
                          value={actionNote}
                          onChange={(e) => setActionNote(e.target.value)}
                          placeholder="اكتب أي ملاحظة لتسجيلها في سجل التدقيق المعتمد..."
                          style={{
                            width: '100%',
                            padding: '8px 12px',
                            borderRadius: 8,
                            border: '1px solid rgba(0,0,0,0.15)',
                            fontSize: 13,
                            boxSizing: 'border-box',
                          }}
                        />
                      </div>

                      {/* أزرار الحفظ والإلغاء */}
                      <div style={{ display: 'flex', gap: 10 }}>
                        <button
                          type="button"
                          className="btn primary sm"
                          disabled={isSubmitting || (needsReason && !selectedReason) || (needsAssignee && !escalatedTo)}
                          onClick={() => handleDecide(item.id, selectedAction, selectedReason, actionNote)}
                        >
                          <Icon name="check" /> {isSubmitting ? 'جاري التسجيل...' : 'تأكيد وحفظ القرار'}
                        </button>
                        <button
                          type="button"
                          className="btn soft sm"
                          disabled={isSubmitting}
                          onClick={() => setActiveActionItemId(null)}
                        >
                          تراجع
                        </button>
                      </div>
                    </div>
                  )}
                </div>
              </div>
            </div>
          );
        })
      )}

      {/* ── 6. نافذة القراءة القانونية الموسعة (Full Reading Modal) ── */}
      {readingModalItem && (
        <Modal
          open={Boolean(readingModalItem)}
          onClose={() => setReadingModalItem(null)}
          title={`معاينة المخرج القانوني: ${readingModalItem.entityRef}`}
          subtitle={`${readingModalItem.taskLabel} · ${readingModalItem.model} · تاريخ: ${readingModalItem.createdAt}`}
          maxWidth={820}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
              <VisualConfidenceBadge value={readingModalItem.confidence} />
              {readingModalItem.preview?.href && (
                <a
                  href={readingModalItem.preview.href}
                  target="_blank"
                  rel="noreferrer"
                  className="btn soft sm"
                >
                  <Icon name="edit" /> تحرير الملف بالأصل ↗
                </a>
              )}
            </div>

            <div
              style={{
                padding: '18px 22px',
                background: '#fafbfc',
                border: '1px solid rgba(0,0,0,0.1)',
                borderRadius: 10,
                fontSize: 14.5,
                lineHeight: 2.1,
                whiteSpace: 'pre-wrap',
                maxHeight: '60vh',
                overflowY: 'auto',
                color: '#222',
              }}
            >
              {cleanPreviewText(readingModalItem.preview?.fullText || readingModalItem.preview?.text) || 'لا يتوفر نص للمعاينة.'}
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, borderTop: '1px solid rgba(0,0,0,0.08)', paddingTop: 14 }}>
              <button
                type="button"
                className="btn primary"
                style={{ background: '#1E9D6B', borderColor: '#1E9D6B' }}
                disabled={isSubmitting}
                onClick={() => {
                  quickAccept(readingModalItem);
                }}
              >
                <Icon name="check" /> اعتماد هذا المخرج فوراً
              </button>
              <button
                type="button"
                className="btn soft"
                onClick={() => setReadingModalItem(null)}
              >
                إغلاق النافذة
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
};

export default AiReview;
