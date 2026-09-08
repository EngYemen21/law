import { router } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '@/components/babylon/Badge';
import { useBodyScrollLock } from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
// **النسخة التي تُخفي فعلاً.** `admin-data` يصدّر `maskClient` وهي `return name`
// — لا تُخفي شيئاً — بينما `employee-data` تحمل الإخفاء الحقيقيّ الذي تستعمله
// بقيّة شاشات الطاقم. فكانت الشاشة تنادي دالّةً باسمٍ يَعِد بما لا يفعل.
import { maskClient } from '@/lib/employee-data';
import { RichText, SummaryStateBadge } from '@/lib/consult-ui';
import type { ConsultCard } from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import {
  cTone,
  crChannelIcon,
  crChannelTone,
  CONSULT_BOOKING_STATUSES,
  CONSULT_CLOSED_STATUSES,
  CONSULT_TERMINAL_STATUSES,
} from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { useCan } from '@/lib/permissions';

export type LawyerKanbanCol = 'waiting' | 'live' | 'drafting' | 'completed';

/**
 * عمودٌ واحدٌ لكلّ بطاقة في كانبان المستشار — نظير `empKanbanColumnOf` عند الموظف و`kanbanColumnOf` عند الإدارة.
 *
 * يمنع ظهور البطاقة في عمودين معاً (كالاستشارة الملغاة أو التي لم تنعقد
 * التي كانت تدخل عمود «بانتظار الانعقاد» وعمود «منتهية ومغلقة» معاً لأن cancelRequest لا يمس session).
 */
export function lawyerKanbanColumnOf(c: ConsultCard): LawyerKanbanCol {
  if (CONSULT_TERMINAL_STATUSES.includes(c.status) || c.session === 'لم تُعقد') {
    return 'completed';
  }

  if (c.session === 'جلسة جارية' || c.status === 'قيد الاستشارة') {
    return 'live';
  }

  if (c.session === 'منتهية') {
    return c.summaryApproved ? 'completed' : 'drafting';
  }

  // دورة الحجز لا تدخل جلسات الانعقاد عند المحامي
  if (CONSULT_BOOKING_STATUSES.includes(c.status)) {
    return 'completed';
  }

  return 'waiting';
}

interface LawyerConsultsProps {
  consults: ConsultCard[];
}

type ViewMode = 'table' | 'kanban';
type LawyerFilter = 'all' | 'today' | 'upcoming' | 'needs_summary' | 'completed';
type LawyerDrawerTab = 'facts' | 'session' | 'report' | 'actions';

export const LawyerConsults: React.FC<LawyerConsultsProps> = ({
  consults: initialConsults = [],
}) => {
  const toast = useToast();

  // ── الحالة الأساسية ومزامنة البيانات ──
  const [items, setItems] = useState<ConsultCard[]>(initialConsults);
  const [viewMode, setViewMode] = useState<ViewMode>('table');
  const [filterMode, setFilterMode] = useState<LawyerFilter>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [channelFilter, setChannelFilter] = useState('all');

  // درج المستشار القانوني 360° المباشر والمضمون
  const [drawerConsult, setDrawerConsult] = useState<ConsultCard | null>(null);
  const [drawerTab, setDrawerTab] = useState<LawyerDrawerTab>('facts');
  const [sessionNotes, setSessionNotes] = useState<string>('');
  const [clientReport, setClientReport] = useState<string>('');
  const [isProcessing, setIsProcessing] = useState(false);

  // نصّ الموكّل يحرّره ويعتمده **من يملك الصلاحيّة** — لا من يفتح الصفحة.
  const mayEditSummary = useCan()('اعتماد/تعديل ملخص الاستشارة');

  // مزامنة فورية عبر Laravel Echo
  useEffect(() => {
    setItems(initialConsults);

    initialConsults.forEach((c) => {
      echo.private(`consult.${c.id}`).listen('.status', (e: Partial<ConsultCard>) => {
        setItems((prev) =>
          prev.map((x) => (x.id === c.id ? { ...x, ...e } : x))
        );

        if (e.session === 'منتهية') {
          router.reload({ only: ['consults'] });
        }
      });
    });

    return () => {
      initialConsults.forEach((c) => echo.leave(`consult.${c.id}`));
    };
  }, [initialConsults]);

  // تحديث الاستشارة المفتوحة في الدرج عند وصول أي بث من Echo
  useEffect(() => {
    if (drawerConsult) {
      const updated = items.find((c) => c.id === drawerConsult.id);
      if (updated) setDrawerConsult(updated);
    }
  }, [items]);

  // فتح وإغلاق الدرج المباشر
  const openDrawer = (c: ConsultCard, tab: LawyerDrawerTab = 'facts') => {
    setDrawerConsult(c);
    setDrawerTab(tab);
  };

  const closeDrawer = () => {
    setDrawerConsult(null);
  };

  // قفل التمرير عند فتح الدرج
  useBodyScrollLock(Boolean(drawerConsult));

  // إغلاق الدرج بمفتاح Escape
  useEffect(() => {
    if (!drawerConsult) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') closeDrawer();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [drawerConsult]);

  // مزامنة الملاحظات والتقرير عند فتح الاستشارة
  useEffect(() => {
    if (drawerConsult) {
      // كان يقرأ `notes` ولا تُرسلها البطاقة، فيفتح المحامي الدرج فيرى حقلاً فارغاً
      // وتدوينُه محفوظ — فيظنّه ضائعاً أو يكتب فوقه.
      setSessionNotes(drawerConsult.sessionNotes || '');
      setClientReport(drawerConsult.summary || '');
    }
  }, [drawerConsult]);

  // ── مؤشرات الأداء القانونية الخاصة بالمحامي ──
  const telemetry = useMemo(() => {
    const total = items.length;
    // **كان صفراً أبداً:** يخرج عند `!c.day` و`day` لا تُرسل — فلا يصل شرط «جارية».
    const todaySessions = items.filter((c) => {
      if (c.session === 'جلسة جارية') return true;
      if (!c.startsAt) return false;
      return new Date(c.startsAt).toDateString() === new Date().toDateString();
    }).length;
    const liveNow = items.filter((c) => c.session === 'جلسة جارية').length;
    const upcoming = items.filter(
      (c) => c.session === 'بانتظار الجلسة' || c.status === 'محالة للمحامي' 
    ).length;
    const needsSummary = items.filter(
      (c) => c.session === 'منتهية' && !c.summaryApproved
    ).length;
    const completed = items.filter(
      (c) => CONSULT_TERMINAL_STATUSES.includes(c.status)
    ).length;

    return {
      total,
      todaySessions,
      liveNow,
      upcoming,
      needsSummary,
      completed,
    };
  }, [items]);

  // تصفية الاستشارات
  const filteredItems = useMemo(() => {
    return items.filter((c) => {
      // 1. فلتر المسار
      if (filterMode === 'today') {
        // كان `c.day?.includes(...)` وهي دائماً undefined، فيُظهر الجارية وحدها
        // ويُسقط جلسات اليوم المجدولة.
        const isToday = c.session === 'جلسة جارية'
          || (!!c.startsAt && new Date(c.startsAt).toDateString() === new Date().toDateString());
        if (!isToday) return false;
      }
      if (filterMode === 'upcoming') {
        const isUpcoming =
          c.session === 'بانتظار الجلسة' || c.status === 'محالة للمحامي';
        if (!isUpcoming) return false;
      }
      if (filterMode === 'needs_summary') {
        if (c.session !== 'منتهية' || c.summaryApproved) return false;
      }
      if (filterMode === 'completed') {
        if (!CONSULT_TERMINAL_STATUSES.includes(c.status)) return false;
      }

      // 2. فلتر القناة
      if (channelFilter !== 'all' && c.channel !== channelFilter) return false;

      // 3. البحث
      if (searchQuery.trim() !== '') {
        const q = searchQuery.toLowerCase();
        const matchesRef = c.ref?.toLowerCase().includes(q);
        const matchesClient = c.client?.toLowerCase().includes(q);
        const matchesSubj = c.subject?.toLowerCase().includes(q);
        if (!matchesRef && !matchesClient && !matchesSubj) return false;
      }

      return true;
    });
  }, [items, filterMode, channelFilter, searchQuery]);

  // ── الإجراءات الميدانية للمحامي ──

  // بدء الجلسة
  const handleStart = (consult: ConsultCard) => {
    setIsProcessing(true);
    router.post(
      `/lawyer/consults/${consult.id}/start`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => toast(`بدأت جلسة الاستشارة (${consult.ref}) وتم إشعار العميل فوراً`),
        // رسالة الخادم لا نصّ ثابت: «فات الموعد» و«قبل الموعد بربع ساعة» سببان
        // مختلفان، وإخفاؤهما خلف «تعذر» يترك المحامي يعيد المحاولة بلا فهم.
        onError: (errors) => toast(Object.values(errors)[0] || 'تعذر بدء الجلسة'),
        onFinish: () => setIsProcessing(false),
      }
    );
  };

  // إنهاء الجلسة وحفظ الملاحظات
  const handleEnd = (consult: ConsultCard) => {
    setIsProcessing(true);
    router.post(
      `/lawyer/consults/${consult.id}/end`,
      { notes: sessionNotes },
      {
        preserveScroll: true,
        onSuccess: () => {
          // «وجارٍ استخراج مسودة التقرير» ليست مضمونة: `FinalizeConsultJob` **لا
          // ينادي النموذج بلا مادّة** — بل يُنبّه المحامي ليدوّن. والوعد يقع في
          // الحالة التي كُتب لها ذلك الفرع أصلاً.
          toast(sessionNotes.trim() === ''
            ? 'خُتمت الجلسة بلا تدوين — لا ملخّص حتّى تُدوّن'
            : 'خُتمت الجلسة وحُفظ التدوين — تُعدّ المسودّة الآن');
          setDrawerTab('report');
        },
        onError: (errors) => toast(Object.values(errors)[0] || 'تعذر إنهاء الجلسة'),
        onFinish: () => setIsProcessing(false),
      }
    );
  };

  // تسجيل عدم حضور العميل
  const handleNoShow = (consult: ConsultCard) => {
    if (!confirm('هل أنت متأكد من تسجيل عدم حضور العميل للجلسة؟')) return;
    setIsProcessing(true);
    router.post(
      `/lawyer/consults/${consult.id}/no-show`,
      {},
      {
        preserveScroll: true,
        // `noShow()` يُشعر **العميل وحده** — لا إشعار إدارةٍ في المسار.
        onSuccess: () => toast('سُجّل عدم الحضور وأُشعر الموكّل — يمكنك إعادة الجدولة'),
        onError: (errors) => toast(Object.values(errors)[0] || 'تعذر تسجيل عدم الحضور'),
        onFinish: () => setIsProcessing(false),
      }
    );
  };

  // حفظ مسودة التقرير النهائي للعميل
  const handleSaveReport = (consult: ConsultCard) => {
    if (!clientReport.trim()) {
      toast('يرجى كتابة نص التقرير أو الرأي القانوني');
      return;
    }

    setIsProcessing(true);
    router.post(
      `/lawyer/consults/${consult.id}/summary`,
      { summary: clientReport.trim() },
      {
        preserveScroll: true,
        onSuccess: () => {
          // كان يقول «جاهز للاعتماد في صندوق المراجعة» — وتقريرٌ كُتب بلا مخرج نموذج
          // لا قيد له في `ai_runs` فلا يبلغ الصندوق قطّ. الوعد كان يُخفي الحجب.
          toast('حُفظت المسودّة — لم تصل الموكّل بعد؛ الإرسال يقع بالاعتماد');
        },
        onError: () => toast('تعذر حفظ التقرير'),
        onFinish: () => setIsProcessing(false),
      }
    );
  };

  // تحويل القرارات إلى مهام
  // الاعتماد من شاشة الملفّ — لا من الصندوق وحده. ملخّصٌ بلا قيد `ai_runs` لا يبلغ
  // الصندوق أبداً، فكان يبقى محجوباً عن الموكّل مهما طال.
  const handleApproveReport = (consult: ConsultCard) => {
    /*
     * **يُعتمد المحفوظ لا المعروض.** الخادم يعتمد `$consult->summary` المخزَّن،
     * والمحرّر قد يحمل تحريراً لم يُحفظ — فمن يُحرّر ثمّ يضغط «اعتماد» يُرسل إلى
     * الموكّل النصّ **القديم** وهو يقرأ الجديد على الشاشة. فيُنبَّه صراحةً.
     */
    const unsaved = clientReport.trim() !== (consult.summary ?? '').trim();

    if (unsaved) {
      toast('لديك تحريرٌ لم يُحفظ — احفظ المسودّة أوّلاً، فالاعتماد يُرسل النصّ المحفوظ');

      return;
    }

    if (!window.confirm('سيصل الملخّص إلى الموكّل فور الاعتماد. متابعة؟')) {
      return;
    }

    setIsProcessing(true);
    router.post(`/lawyer/consults/${consult.id}/summary/approve`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('اعتُمد الملخّص وأُرسل إلى الموكّل'),
      onError: (errors) => toast(Object.values(errors)[0] || 'تعذّر اعتماد الملخّص'),
      onFinish: () => setIsProcessing(false),
    });
  };

  /**
   * إعادة الجدولة — مسارُ إنقاذ الفائتة.
   *
   * فعلٌ مُدمِّر: يحذف اجتماع Zoom ويصفّر الموعد وأختام التذكير ويُشعر الموكّل.
   * فيُستأذَن قبله.
   */
  const handleReschedule = (consult: ConsultCard) => {
    if (!window.confirm('سيُلغى الموعد الحاليّ وغرفة Zoom، ويُطلب من الموكّل اختيار موعدٍ جديد. متابعة؟')) {
      return;
    }

    setIsProcessing(true);
    router.post(`/lawyer/consults/${consult.id}/reschedule`, {}, {
      preserveScroll: true,
      onSuccess: () => {
        toast('أُعيدت الجدولة — الموكّل يختار موعداً جديداً');
        closeDrawer();
      },
      onError: (errors) => toast(Object.values(errors)[0] || 'تعذّرت إعادة الجدولة'),
      onFinish: () => setIsProcessing(false),
    });
  };

  const handleCreateTasks = (consult: ConsultCard) => {
    setIsProcessing(true);
    router.post(
      `/lawyer/consults/${consult.id}/tasks`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => toast('تم تحويل قرارات الجلسة إلى مهام عمل تنفيذية بنجاح'),
        onError: () => toast('تعذر تحويل القرارات إلى مهام'),
        onFinish: () => setIsProcessing(false),
      }
    );
  };

  // تحويل الاستشارة إلى قضية تمثيل قضائي
  const handleConvertToCase = (consult: ConsultCard) => {
    if (!consult.ticketNo) {
      toast('هذه الاستشارة غير مرتبطة بتذكرة نظامية للتحويل المباشر');
      return;
    }

    if (!confirm(`هل ترغب في تحويل ملف الاستشارة (${consult.ref}) إلى ملف قضية تمثيل قضائي رسمي؟`)) {
      return;
    }

    setIsProcessing(true);
    router.post(
      `/lawyer/tickets/${consult.ticketNo}/convert`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => {
          // كان يقول «وفتح ملف القضية الجديد» — و`convertToCase` يُعيد `back()`
          // بتعليقٍ صريح أنّه **لا ينقل** المستخدم. فالوعد لا يقع، والدرج يُغلق
          // بعده فلا يبقى للمحامي شيء.
          toast('حُوّلت إلى قضية — تجدها في «القضايا»');
          closeDrawer();
        },
        // نصّ الرفض من الخادم: «تم تحويل هذه التذكرة لقضية مسبقاً» و«التذكرة غير
        // مكتملة» سببان مختلفان، وابتلاعُهما يترك المحامي يعيد المحاولة بلا فهم.
        onError: (errors) => toast(Object.values(errors)[0] || 'تعذر تحويل الاستشارة إلى قضية'),
        onFinish: () => setIsProcessing(false),
      }
    );
  };

  return (
    <div className="lawyer-consults-wrap" dir="rtl">
      {/* ── 1. ترويسة الصفحة ── */}
      <div className="lawyer-header">
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <h2 style={{ margin: 0, fontSize: 22, color: 'var(--primary, #1b2559)' }}>
              جلسات الاستشارات القانونية (لوحة المستشار 360°)
            </h2>
            <span className="lawyer-pulse-badge">
              <span className="lawyer-pulse-dot" />
              قاعات حية
            </span>
          </div>
          <p style={{ margin: '4px 0 0', color: 'var(--muted, #64748b)', fontSize: 13.5 }}>
            المنصة القانونية للمستشار: دراسة وقائع النزاع، الانعقاد المرئي مع الموكلين، صياغة الآراء والتقارير المعتمدة، وإطلاق مهام التقاضي.
          </p>
        </div>

        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <button
            type="button"
            className={`btn ${viewMode === 'table' ? 'primary' : 'soft'} sm`}
            onClick={() => setViewMode('table')}
          >
            <Icon name="list" /> جدول الجلسات
          </button>
          <button
            type="button"
            className={`btn ${viewMode === 'kanban' ? 'primary' : 'soft'} sm`}
            onClick={() => setViewMode('kanban')}
          >
            <Icon name="folder" /> كانبان الجلسات
          </button>
        </div>
      </div>

      {/* ── 2. مؤشرات الأداء القانونية للمحامي ── */}
      <div className="lawyer-kpi-grid">
        <div
          className={`lawyer-kpi-card ${filterMode === 'all' ? 'active' : ''}`}
          onClick={() => setFilterMode('all')}
        >
          <div className="kpi-icon" style={{ background: '#e0e7ff', color: '#4338ca' }}>
            <Icon name="scale" />
          </div>
          <div className="kpi-info">
            <span className="kpi-val">{telemetry.total}</span>
            <span className="kpi-lbl">استشاراتي المسندة</span>
          </div>
        </div>

        <div
          className={`lawyer-kpi-card ${filterMode === 'today' ? 'active' : ''}`}
          onClick={() => setFilterMode('today')}
        >
          <div className="kpi-icon" style={{ background: '#dcfce7', color: '#15803d' }}>
            <Icon name="video" />
          </div>
          <div className="kpi-info">
            <span className="kpi-val">{telemetry.todaySessions}</span>
            <span className="kpi-lbl">جلسات اليوم والمباشرة</span>
          </div>
        </div>

        <div
          className={`lawyer-kpi-card ${filterMode === 'upcoming' ? 'active' : ''}`}
          onClick={() => setFilterMode('upcoming')}
        >
          <div className="kpi-icon" style={{ background: '#e0f2fe', color: '#0284c7' }}>
            <Icon name="clock" />
          </div>
          <div className="kpi-info">
            <span className="kpi-val">{telemetry.upcoming}</span>
            <span className="kpi-lbl">قادمة بانتظار الجلسة</span>
          </div>
        </div>

        <div
          className={`lawyer-kpi-card ${filterMode === 'needs_summary' ? 'active' : ''}`}
          onClick={() => setFilterMode('needs_summary')}
        >
          <div className="kpi-icon" style={{ background: '#fef3c7', color: '#b45309' }}>
            <Icon name="doc" />
          </div>
          <div className="kpi-info">
            <span className="kpi-val">{telemetry.needsSummary}</span>
            <span className="kpi-lbl">بانتظار صياغة/اعتماد التقرير</span>
          </div>
        </div>

        <div
          className={`lawyer-kpi-card ${filterMode === 'completed' ? 'active' : ''}`}
          onClick={() => setFilterMode('completed')}
        >
          <div className="kpi-icon" style={{ background: '#f1f5f9', color: '#475569' }}>
            <Icon name="check" />
          </div>
          <div className="kpi-info">
            <span className="kpi-val">{telemetry.completed}</span>
            <span className="kpi-lbl">منتهية ومعتمدة</span>
          </div>
        </div>
      </div>

      {/* ── 3. شريط الفلاتر والبحث الذكي ── */}
      <div className="lawyer-filter-box">
        <div className="lawyer-pill-scroll">
          <button
            type="button"
            className={`lawyer-pill ${filterMode === 'all' ? 'active' : ''}`}
            onClick={() => setFilterMode('all')}
          >
            جميع الجلسات ({telemetry.total})
          </button>
          <button
            type="button"
            className={`lawyer-pill ${filterMode === 'today' ? 'active' : ''}`}
            onClick={() => setFilterMode('today')}
          >
            جلسات اليوم ({telemetry.todaySessions})
          </button>
          <button
            type="button"
            className={`lawyer-pill ${filterMode === 'upcoming' ? 'active' : ''}`}
            onClick={() => setFilterMode('upcoming')}
          >
            قادمة ({telemetry.upcoming})
          </button>
          <button
            type="button"
            className={`lawyer-pill ${filterMode === 'needs_summary' ? 'active' : ''}`}
            onClick={() => setFilterMode('needs_summary')}
          >
            بانتظار التقرير ({telemetry.needsSummary})
          </button>
          <button
            type="button"
            className={`lawyer-pill ${filterMode === 'completed' ? 'active' : ''}`}
            onClick={() => setFilterMode('completed')}
          >
            مكتملة ({telemetry.completed})
          </button>
        </div>

        <div className="lawyer-filter-row">
          <div className="lawyer-search-wrap">
            <input
              type="text"
              placeholder="بحث بالرقم المرجعي (CN-2026), اسم العميل, أو موضوع النزاع..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="lawyer-search-input"
            />
            <span className="lawyer-search-icon">
              <Icon name="search" />
            </span>
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                className="lawyer-search-clear"
              >
                ✕
              </button>
            )}
          </div>

          <select
            value={channelFilter}
            onChange={(e) => setChannelFilter(e.target.value)}
            className="lawyer-select"
          >
            <option value="all">كل قنوات الانعقاد</option>
            <option value="مرئية">🎥 مرئية (Zoom)</option>
            <option value="هاتفية">📞 استشارة هاتفية</option>
            <option value="حضورية">🏢 حضورية بالمكتب</option>
          </select>
        </div>
      </div>

      {/* ── 4. العرض القانوني (جدول الجلسات / كانبان) ── */}
      {filteredItems.length === 0 ? (
        <div className="lawyer-empty-box">
          <div style={{ fontSize: 36, marginBottom: 8 }}>⚖️</div>
          <h3 style={{ margin: '0 0 6px', color: 'var(--primary)' }}>لا توجد جلسات استشارات مطابقة</h3>
          <p style={{ margin: 0, color: 'var(--muted)', fontSize: 13.5 }}>
            لا توجد جلسات استشارات مسندة تطابق خيارات التصفية الحالية.
          </p>
        </div>
      ) : viewMode === 'table' ? (
        <div className="lawyer-table-card">
          <table className="lawyer-table">
            <thead>
              <tr>
                <th>الرقم المرجعي والموضوع</th>
                <th>الموكل والتواصل</th>
                <th>قناة وتوقيت الجلسة</th>
                <th>حالة الجلسة</th>
                <th>تقرير الرأي القانوني</th>
                <th>حالة الملف</th>
                <th style={{ textAlign: 'center' }}>إجراءات المستشار 360°</th>
              </tr>
            </thead>
            <tbody>
              {filteredItems.map((c) => {
                const isLive = c.session === 'جلسة جارية';
                const hasSummary = Boolean(c.summary);
                const isSelected = drawerConsult?.id === c.id;

                return (
                  <tr key={c.id} className={isSelected ? 'active-row' : ''}>
                    <td>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                        <span
                          className="lawyer-ref-link"
                          onClick={() => openDrawer(c, 'facts')}
                        >
                          {c.ref}
                        </span>
                        <span className="lawyer-subj-text" title={c.subject}>
                          {c.subject || 'استشارة قانونية'}
                        </span>
                        <span style={{ fontSize: 11, color: 'var(--muted)' }}>
                          التخصص: <b>{c.specialty || c.type || 'عام'}</b>
                        </span>
                      </div>
                    </td>

                    <td>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                        <b>{maskClient(c.client)}</b>
                        <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                          📞 {c.phone || 'غير مسجل'}
                        </span>
                      </div>
                    </td>

                    <td>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <span className={`emp-channel-tag ${crChannelTone(c.channel)}`}>
                          <Icon name={crChannelIcon(c.channel)} /> {c.channel || 'مرئية'}
                        </span>
                      </div>
                      <div style={{ fontSize: 12, fontWeight: 700, color: '#1e293b', marginTop: 4 }}>
                        {c.when || 'غير محدد'}
                      </div>
                    </td>

                    <td>
                      {/*
                        * **الفرعُ الجامع كان يبتلع نهايتين.** `session === 'لم تُعقد'`
                        * (يكتبها `AutoCloseMissedConsults` و`noShow`) و`status === 'ملغاة'`
                        * (و`cancelRequest` **لا يمسّ `session`** فتبقى «بانتظار الجلسة»)
                        * كانتا تُعرضان «بانتظار الانعقاد» — فيحضّر المحامي لجلسةٍ أُلغيت
                        * أو ينتظر جلسةً فاتت.
                        */}
                      {isLive ? (
                        <span className="lawyer-status-pill live">🔴 جلسة جارية الآن</span>
                      ) : CONSULT_CLOSED_STATUSES.includes(c.status) && c.status === 'ملغاة' ? (
                        <span className="lawyer-status-pill cancelled">✕ ملغاة</span>
                      ) : c.session === 'منتهية' ? (
                        <span className="lawyer-status-pill ended">✓ الجلسة انتهت</span>
                      ) : c.session === 'لم تُعقد' ? (
                        <span className="lawyer-status-pill missed">✕ لم تنعقد</span>
                      ) : (
                        <span className="lawyer-status-pill wait">⏳ بانتظار الانعقاد</span>
                      )}
                    </td>

                    <td>
                      {c.summaryApproved ? (
                        <span className="lawyer-summary-badge approved">
                          ✓ معتمد ومُرسل للموكل
                        </span>
                      ) : hasSummary ? (
                        <span
                          className="lawyer-summary-badge pending"
                          onClick={() => openDrawer(c, 'report')}
                        >
                          ✏️ مسودة بانتظار الاعتماد
                        </span>
                      ) : (
                        <span className="lawyer-summary-badge empty">
                          بانتظار الصياغة
                        </span>
                      )}
                    </td>

                    <td>
                      <Badge text={c.status} tone={cTone(c.status)} />
                    </td>

                    <td style={{ textAlign: 'center' }}>
                      <div style={{ display: 'flex', gap: 6, justifyContent: 'center' }}>
                        {/* **`canJoin` يُحترم هنا كما يُحترم في الدرج.** كان صفّ
                            الجدول يعرض الرابط بلا التفاتٍ إليه — و`hostLink` هو
                            `start_url` خارجيّ لا يمرّ بحارس `sdkSignature`، فيُفتح
                            قبل إطلاق الرابط وبعد انتهاء الجلسة سواء. القاعدة كانت
                            تُطبَّق في موضعٍ وتُخرَق في آخر من الملفّ نفسه. */}
                        {c.channel === 'مرئية' && c.canJoin !== false && (c.hostLink || c.slink) && (
                          <a
                            href={c.hostLink || c.slink}
                            target="_blank"
                            rel="noreferrer"
                            className="btn primary sm"
                            style={{ padding: '3px 8px', fontSize: 11.5 }}
                          >
                            <Icon name="video" /> دخول Zoom
                          </a>
                        )}
                        <button
                          type="button"
                          className="btn soft sm"
                          style={{ padding: '3px 8px', fontSize: 11.5 }}
                          onClick={() => openDrawer(c, 'facts')}
                        >
                          ملف القضية 360°
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ) : (
        /* كانبان المستشار القانوني */
        <div className="lawyer-kanban-board">
          {/* عمود 1: بانتظار الجلسة */}
          <div className="lawyer-kanban-col">
            <div className="col-head blue">
              <span>بانتظار الانعقاد</span>
              <span className="count">
                {filteredItems.filter((c) => lawyerKanbanColumnOf(c) === 'waiting').length}
              </span>
            </div>
            <div className="col-body">
              {filteredItems
                .filter((c) => lawyerKanbanColumnOf(c) === 'waiting')
                .map((c) => (
                  <div
                    key={c.id}
                    className="lawyer-kanban-card"
                    onClick={() => openDrawer(c, 'session')}
                  >
                    <div className="card-top">
                      <b>{c.ref}</b>
                      <span className={`emp-channel-tag ${crChannelTone(c.channel)}`}>
                        {c.channel || 'مرئية'}
                      </span>
                    </div>
                    <div className="card-subj">{c.subject}</div>
                    <div className="card-client">👤 {maskClient(c.client)}</div>
                    <div className="card-foot">
                      <span style={{ fontSize: 11.5, color: 'var(--primary)', fontWeight: 700 }}>
                        📅 {c.when}
                      </span>
                      <button type="button" className="btn soft sm" style={{ padding: '2px 6px', fontSize: 11 }}>
                        فتح
                      </button>
                    </div>
                  </div>
                ))}
            </div>
          </div>

          {/* عمود 2: جارية الآن */}
          <div className="lawyer-kanban-col">
            <div className="col-head green">
              <span>جلسات جارية الآن</span>
              <span className="count">
                {filteredItems.filter((c) => lawyerKanbanColumnOf(c) === 'live').length}
              </span>
            </div>
            <div className="col-body">
              {filteredItems
                .filter((c) => lawyerKanbanColumnOf(c) === 'live')
                .map((c) => (
                  <div
                    key={c.id}
                    className="lawyer-kanban-card live-border"
                    onClick={() => openDrawer(c, 'session')}
                  >
                    <div className="card-top">
                      <b>{c.ref}</b>
                      <span className="lawyer-status-pill live">🔴 مباشرة</span>
                    </div>
                    <div className="card-subj">{c.subject}</div>
                    <div className="card-client">👤 {maskClient(c.client)}</div>
                    <div className="card-foot">
                      {(c.hostLink || c.slink) ? (
                        <a
                          href={c.hostLink || c.slink}
                          target="_blank"
                          rel="noreferrer"
                          className="btn primary sm"
                          style={{ padding: '2px 8px', fontSize: 11 }}
                          onClick={(e) => e.stopPropagation()}
                        >
                          دخول Zoom
                        </a>
                      ) : (
                        <span style={{ fontSize: 11, color: '#15803d' }}>جلسة مكتبية</span>
                      )}
                    </div>
                  </div>
                ))}
            </div>
          </div>

          {/* عمود 3: إعداد التقرير القانوني */}
          <div className="lawyer-kanban-col">
            <div className="col-head amber">
              <span>بانتظار إعداد التقرير</span>
              <span className="count">
                {filteredItems.filter((c) => lawyerKanbanColumnOf(c) === 'drafting').length}
              </span>
            </div>
            <div className="col-body">
              {filteredItems
                .filter((c) => lawyerKanbanColumnOf(c) === 'drafting')
                .map((c) => (
                  <div
                    key={c.id}
                    className="lawyer-kanban-card"
                    onClick={() => openDrawer(c, 'report')}
                  >
                    <div className="card-top">
                      <b>{c.ref}</b>
                      <span className="lawyer-summary-badge pending">صياغة الرأي</span>
                    </div>
                    <div className="card-subj">{c.subject}</div>
                    <div className="card-client">👤 {maskClient(c.client)}</div>
                    <div className="card-foot">
                      <span style={{ fontSize: 11, color: '#b45309' }}>الجلسة انتهت</span>
                      <button type="button" className="btn soft sm" style={{ padding: '2px 6px', fontSize: 11 }}>
                        كتابة التقرير
                      </button>
                    </div>
                  </div>
                ))}
            </div>
          </div>

          {/* عمود 4: منتهية ومغلقة */}
          <div className="lawyer-kanban-col">
            <div className="col-head gray">
              <span>منتهية ومغلقة</span>
              <span className="count">
                {filteredItems.filter((c) => lawyerKanbanColumnOf(c) === 'completed').length}
              </span>
            </div>
            <div className="col-body">
              {filteredItems
                .filter((c) => lawyerKanbanColumnOf(c) === 'completed')
                .map((c) => (
                  <div
                    key={c.id}
                    className="lawyer-kanban-card"
                    onClick={() => openDrawer(c, 'report')}
                  >
                    <div className="card-top">
                      <b>{c.ref}</b>
                      <Badge text={c.status} tone={cTone(c.status)} />
                    </div>
                    <div className="card-subj">{c.subject}</div>
                    <div className="card-client">👤 {maskClient(c.client)}</div>
                    <div className="card-foot">
                      <SummaryStateBadge consult={c} />
                    </div>
                  </div>
                ))}
            </div>
          </div>
        </div>
      )}

      {/* ── 5. درج المستشار القانوني الشامل 360° المباشر والمضمون ── */}
      {drawerConsult && typeof document !== 'undefined' && (
        createPortal(
          <div className="emp-drawer-overlay" onClick={closeDrawer}>
            <div
              className="emp-drawer-panel"
              onClick={(e) => e.stopPropagation()}
              dir="rtl"
            >
              {/* ترويسة الدرج */}
              <div className="emp-drawer-head">
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <h3 style={{ margin: 0, fontSize: 18, color: 'var(--primary)' }}>
                    ملف الاستشارة: {drawerConsult.ref}
                  </h3>
                  <Badge text={drawerConsult.status} tone={cTone(drawerConsult.status)} />
                </div>
                <button
                  type="button"
                  className="emp-drawer-close"
                  onClick={closeDrawer}
                  aria-label="إغلاق"
                >
                  ✕ إغلاق
                </button>
              </div>

              {/* تبويبات الدرج الأربعة الخاصة بالمحامي */}
              <div className="emp-drawer-tabs">
                <button
                  type="button"
                  className={`d-tab ${drawerTab === 'facts' ? 'active' : ''}`}
                  onClick={() => setDrawerTab('facts')}
                >
                  📖 الوقائع والمستندات
                </button>
                <button
                  type="button"
                  className={`d-tab ${drawerTab === 'session' ? 'active' : ''}`}
                  onClick={() => setDrawerTab('session')}
                >
                  🎥 إدارة الجلسة (Zoom)
                </button>
                <button
                  type="button"
                  className={`d-tab ${drawerTab === 'report' ? 'active' : ''}`}
                  onClick={() => setDrawerTab('report')}
                >
                  ⚖️ التقرير القانوني
                </button>
                <button
                  type="button"
                  className={`d-tab ${drawerTab === 'actions' ? 'active' : ''}`}
                  onClick={() => setDrawerTab('actions')}
                >
                  🎯 القرارات وتحويل القضية
                </button>
              </div>

              {/* محتوى التبويبات */}
              <div className="emp-drawer-body">
                {/* ── التبويب 1: الوقائع والمستندات ── */}
                {drawerTab === 'facts' && (
                  <div className="emp-d-content">
                    <div className="emp-box">
                      <h4 className="box-title">📑 وقائع النزاع وتفاصيل الاستشارة</h4>
                      <div style={{ fontSize: 14.5, fontWeight: 700, color: 'var(--primary)', marginBottom: 8 }}>
                        {drawerConsult.subject || 'استشارة قانونية'}
                      </div>
                      <div className="emp-box-desc">
                        <RichText
                          text={drawerConsult.summary || drawerConsult.aiSummary}
                          fallback="تم قيد الاستشارة بناءً على مستندات العميل وطلبه، بانتظار استعراض تفاصيل الدفوع والوقائع في الجلسة."
                        />
                      </div>
                      <div style={{ marginTop: 10, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <span className="emp-chip">
                          التخصص: <b>{drawerConsult.specialty || drawerConsult.type || 'عام'}</b>
                        </span>
                        <span className="emp-chip">
                          القناة: <b>{drawerConsult.channel || 'مرئية'}</b>
                        </span>
                        <span className="emp-chip">
                          الموكل: <b>{maskClient(drawerConsult.client)}</b>
                        </span>
                      </div>
                    </div>

                    <div className="emp-box">
                      <h4 className="box-title">📎 المستندات والمرفقات القانونية</h4>
                      {drawerConsult.missing && drawerConsult.missing.length > 0 ? (
                        <div style={{ padding: '10px 14px', background: '#fef3c7', borderRadius: 8, color: '#b45309', fontSize: 13, marginBottom: 10 }}>
                          ⚠️ توجد نواقص لم يرفعها العميل بعد: {drawerConsult.missing.join('، ')}
                        </div>
                      ) : (
                        <div style={{ padding: '10px 14px', background: '#f0fdf4', borderRadius: 8, color: '#15803d', fontSize: 13, marginBottom: 10 }}>
                          ✓ المستندات المرفقة جاهزة ومستوفية للمعاينة والدراسة.
                        </div>
                      )}
                      <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>
                        يمكنك الاطلاع على كافة العقود المرفقة مباشرة من ملف التذكرة أو من غرفة الجلسة.
                      </div>
                    </div>
                  </div>
                )}

                {/* ── التبويب 2: إدارة الجلسة ── */}
                {drawerTab === 'session' && (
                  <div className="emp-d-content">
                    <div className="emp-box">
                      <h4 className="box-title">🎥 غرفة الجلسة وموعد الانعقاد</h4>
                      <div className="emp-grid-2">
                        <div>
                          <span className="field-lbl">موعد الجلسة:</span>
                          <span className="field-val" style={{ fontWeight: 700 }}>
                            {drawerConsult.when || 'غير مجدول بعد'}
                          </span>
                        </div>
                        <div>
                          <span className="field-lbl">حالة الجلسة:</span>
                          <span className="field-val" style={{ fontWeight: 700 }}>
                            {drawerConsult.session || 'بانتظار الجلسة'}
                          </span>
                        </div>
                      </div>

                      {/* زر دخول Zoom المباشر */}
                      {/*
                        **الرابط يتبع نافذة الدخول.** `hostLink` هو `start_url` خارجيّ
                        فلا يمرّ بحارس `ZoomController@sdkSignature` الذي يفرض `canJoin`
                        — وكان يُعرض دائماً للقناة المرئية: قبل إطلاق الرابط وبعد
                        انتهاء الجلسة سواء.
                      */}
                      {drawerConsult.channel === 'مرئية' && (drawerConsult.hostLink || drawerConsult.slink) && (
                        <div style={{ marginTop: 14, paddingTop: 12, borderTop: '1px solid #e2e8f0' }}>
                          {drawerConsult.canJoin === false ? (
                            <p className="action-hint" style={{ margin: 0 }}>
                              <Icon name="info" /> يُفتح رابط الغرفة قبل الموعد بخمس دقائق.
                            </p>
                          ) : (
                            <a
                              href={drawerConsult.hostLink || drawerConsult.slink}
                              target="_blank"
                              rel="noreferrer"
                              className="btn primary"
                              style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}
                            >
                              <Icon name="video" /> دخول غرفة الاجتماع المرئية (Zoom) ↗
                            </a>
                          )}
                        </div>
                      )}
                    </div>

                    <div className="emp-box">
                      <h4 className="box-title">⚡ التحكم في انعقاد الجلسة</h4>
                      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
                        {/* النافذة التي يعلنها الخادم (`startable`) ويفرضها الآن —
                            كان الزرّ ظاهراً لموعدٍ بعد ثلاثة أسابيع أو فائتٍ منذ شهر. */}
                        {drawerConsult.session === 'بانتظار الجلسة' && (
                          <button
                            type="button"
                            className="btn primary sm"
                            disabled={isProcessing || drawerConsult.startable === false}
                            title={drawerConsult.startable === false ? 'خارج نافذة البدء (ربع ساعة قبل الموعد)' : undefined}
                            onClick={() => handleStart(drawerConsult)}
                          >
                            <Icon name="check" /> بدء الجلسة الآن
                          </button>
                        )}

                        {drawerConsult.session === 'جلسة جارية' && (
                          <button
                            type="button"
                            className="btn sm"
                            style={{ background: '#dc2626', borderColor: '#dc2626', color: '#fff' }}
                            disabled={isProcessing}
                            onClick={() => handleEnd(drawerConsult)}
                          >
                            إنهاء الجلسة وحفظ الملاحظات
                          </button>
                        )}

                        {/* الخادم يشترط جلسةً منتظِرةً فات موعدها (`isMissed`) — وكان
                            الزرّ ظاهراً بلا شرط، فيُضغط على جلسةٍ لم يحن وقتها ويُردّ
                            برسالةٍ لا تُعرض. */}
                        <button
                          type="button"
                          className="btn soft sm"
                          disabled={isProcessing || !drawerConsult.missed}
                          title={!drawerConsult.missed ? 'يُتاح بعد فوات الموعد بلا حضور' : undefined}
                          onClick={() => handleNoShow(drawerConsult)}
                        >
                          تسجيل عدم حضور العميل
                        </button>

                        {/*
                          **مسار التعافي كان مفقوداً.** `'لم يحضر'` حالةُ تعافٍ لا نهاية
                          (انظر `Consult::CLOSED_STATUSES`) ومخرجُها إعادة الجدولة —
                          والمسار مسجَّل للمحامي ويقبله الخادم، ولا زرّ له في الشاشة
                          كلّها. فيسِم المحامي «لم يحضر» ثمّ لا يجد ما يُنقذ به الملفّ.
                        */}
                        {(drawerConsult.missed || drawerConsult.session === 'لم تُعقد') && (
                          <button
                            type="button"
                            className="btn soft sm"
                            disabled={isProcessing}
                            onClick={() => handleReschedule(drawerConsult)}
                          >
                            <Icon name="cal" /> إعادة الجدولة
                          </button>
                        )}
                      </div>

                      <div>
                        <label className="field-lbl" style={{ fontWeight: 700 }}>
                          تدوين ملاحظات ومحضر الجلسة:
                        </label>
                        <textarea
                          rows={4}
                          value={sessionNotes}
                          onChange={(e) => setSessionNotes(e.target.value)}
                          placeholder="دوّن هنا أبرز ما دار في الجلسة، الدفوع المقدمة، والاتفاق مع الموكل..."
                          className="emp-search-input"
                          style={{ width: '100%', resize: 'vertical' }}
                        />
                        {drawerConsult.session === 'جلسة جارية' && (
                          <button
                            type="button"
                            className="btn soft sm"
                            style={{ marginTop: 8 }}
                            disabled={isProcessing}
                            onClick={() => handleEnd(drawerConsult)}
                          >
                            حفظ الملاحظات وإنهاء الجلسة
                          </button>
                        )}
                      </div>
                    </div>
                  </div>
                )}

                {/* ── التبويب 3: التقرير القانوني المعتمد للعميل ── */}
                {drawerTab === 'report' && (
                  <div className="emp-d-content">
                    <div className="emp-box">
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                        <h4 className="box-title" style={{ margin: 0 }}>
                          📝 صياغة الرأي والتقرير القانوني (نسخة الموكل)
                        </h4>
                        {/* الشارة المشتركة: تضيف **مصدر النصّ** — وتعثّرُ الصياغة
                            الآليّة كان غير مرئيّ في الشاشتين معاً. */}
                        <SummaryStateBadge consult={drawerConsult} />
                      </div>

                      <p style={{ margin: '0 0 10px', fontSize: 13, color: 'var(--muted)' }}>
                        {drawerConsult.summaryApproved
                          ? 'هذا هو التقرير المعتمد الظاهر في لوحة الموكل وتطبيقه.'
                          : 'مسودّة لم تصل الموكل بعد — تظهر له فور الاعتماد.'}
                      </p>

                      {/*
                        **المحرّر خلف الصلاحيّة، ويُقفل بعد الاعتماد.**
                        مسارا الحفظ والاعتماد محروسان بـ`permission:اعتماد/تعديل ملخص
                        الاستشارة`، وهذه الشاشة لم تكن تفحصها إطلاقاً — فمن لا يملكها
                        يحرّر ويضغط الحفظ فيسقط الطلب. والنظير المشترك في
                        `consult-ui.tsx` عولج بـ`useCan` وتُرك هذا.
                        والنصّ المعتمَد **لا يُحرَّر**: الخادم يردّ «اعتُمد هذا الملخّص
                        ووصل العميل»، والفقرة أعلاه تقول إنّه المعتمَد ثمّ تدعو لحفظه.
                      */}
                      {mayEditSummary && ! drawerConsult.summaryApproved ? (
                        <textarea
                          rows={10}
                          value={clientReport}
                          onChange={(e) => setClientReport(e.target.value)}
                          placeholder="اكتب هنا التكييف النظامي، الرأي القانوني المعتمد، والتوصيات للموكل..."
                          className="emp-search-input"
                          style={{ width: '100%', lineHeight: 1.8, fontSize: 13.5, resize: 'vertical' }}
                        />
                      ) : (
                        <div style={{ whiteSpace: 'pre-wrap', lineHeight: 1.9, fontSize: 13.5 }}>
                          <RichText text={drawerConsult.summary} fallback="— لا مسودّة بعد —" />
                        </div>
                      )}

                      <div style={{ display: 'flex', gap: 10, marginTop: 12, alignItems: 'center', flexWrap: 'wrap' }}>
                        {mayEditSummary && ! drawerConsult.summaryApproved && (
                        <button
                          type="button"
                          className="btn primary sm"
                          disabled={isProcessing || !clientReport.trim()}
                          onClick={() => handleSaveReport(drawerConsult)}
                        >
                          <Icon name="check" /> حفظ مسودة التقرير
                        </button>
                        )}

                        {mayEditSummary && (
                        <button
                          type="button"
                          className="btn sm"
                          disabled={isProcessing || !drawerConsult.summary || drawerConsult.summaryApproved}
                          onClick={() => handleApproveReport(drawerConsult)}
                        >
                          <Icon name="scale" /> {drawerConsult.summaryApproved ? 'معتمَد وواصل للموكّل' : 'اعتماد وإرسال للموكّل'}
                        </button>
                        )}

                        <a
                          href="/lawyer/ai-review"
                          target="_blank"
                          rel="noreferrer"
                          className="btn soft sm"
                        >
                          <Icon name="scale" /> صندوق المراجعة ↗
                        </a>
                      </div>

                      {drawerConsult.zoomSummary && (
                        <div style={{ marginTop: 14, paddingTop: 12, borderTop: '1px solid #e2e8f0' }}>
                          <details>
                            <summary style={{ cursor: 'pointer', fontWeight: 700, fontSize: 13 }}>
                              💡 تفريغ الذكاء الاصطناعي من جلسة Zoom (للاستئناس والبناء)
                            </summary>
                            <div style={{ fontSize: 12.5, lineHeight: 1.8, marginTop: 8, padding: 10, background: '#f8fafc', borderRadius: 6 }}>
                              <RichText text={drawerConsult.zoomSummary} />
                            </div>
                          </details>
                        </div>
                      )}
                    </div>
                  </div>
                )}

                {/* ── التبويب 4: القرارات وتحويل القضية ── */}
                {drawerTab === 'actions' && (
                  <div className="emp-d-content">
                    <div className="emp-box" style={{ border: '1px solid #bbf7d0', background: '#f0fdf4' }}>
                      <h4 className="box-title" style={{ color: '#15803d' }}>
                        ⚖️ تحويل الاستشارة إلى قضية تمثيل قضائي (Legal Case)
                      </h4>
                      <p style={{ margin: '0 0 12px', fontSize: 13, color: '#166534' }}>
                        في حال اتفق الموكل معكم على رفع دعوى أمام المحكمة أو تمثيل قضائي، يمكنك تحويل هذا الملف مباشرة إلى قضية رسمية لفتح ملف القضية وتعيين الأتعاب.
                      </p>
                      {/* **الملفّ المحوَّل لا يُحوَّل مرّتين.** `caseNo` أُضيف إلى البطاقة
                          ليكون الإشارة الصادقة على التحويل، ولم يكن يُقرأ هنا —
                          فيبقى الزرّ أخضرَ مفعّلاً على ملفٍّ حُوِّل أمس، والخادم يردّ
                          «تم تحويل هذه التذكرة لقضية مسبقاً». */}
                      {drawerConsult.caseNo && (
                        <p className="action-hint" style={{ margin: '0 0 10px' }}>
                          <Icon name="check" /> حُوّلت إلى القضية <b>{drawerConsult.caseNo}</b>.
                        </p>
                      )}
                      <button
                        type="button"
                        className="btn sm"
                        style={{ background: '#16a34a', borderColor: '#16a34a', color: '#fff' }}
                        disabled={isProcessing || !drawerConsult.ticketNo || !!drawerConsult.caseNo}
                        onClick={() => handleConvertToCase(drawerConsult)}
                      >
                        <Icon name="scale" /> تحويل الاستشارة إلى قضية تمثيل قضائي فوراً
                      </button>
                    </div>

                    <div className="emp-box">
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                        <h4 className="box-title" style={{ margin: 0 }}>
                          🎯 القرارات والتوصيات التنفيذية
                        </h4>
                        {drawerConsult.decisions?.length ? (
                          <button
                            type="button"
                            className="btn soft sm"
                            disabled={isProcessing || drawerConsult.tasksCreated}
                            onClick={() => handleCreateTasks(drawerConsult)}
                          >
                            {drawerConsult.tasksCreated ? 'حُوّلت إلى مهام ✓' : 'تحويل القرارات إلى مهام'}
                          </button>
                        ) : null}
                      </div>

                      {drawerConsult.decisions && drawerConsult.decisions.length > 0 ? (
                        <ul style={{ margin: 0, paddingInlineStart: 20, lineHeight: 1.9, fontSize: 13.5 }}>
                          {drawerConsult.decisions.map((d, idx) => (
                            <li key={idx}>
                              {typeof d === 'string' ? d : (d as { title?: string }).title || JSON.stringify(d)}
                            </li>
                          ))}
                        </ul>
                      ) : (
                        <div style={{ color: 'var(--muted)', fontSize: 13 }}>
                          لا توجد قرارات مستخرجة تلقائياً بعد.
                        </div>
                      )}
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>,
          document.body
        )
      )}

      {/* ── التنسيقات البصرية ── */}
      <style>{`
        .lawyer-consults-wrap {
          padding: 20px;
          max-width: 1380px;
          margin: 0 auto;
          font-family: inherit;
        }
        .lawyer-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 20px;
          flex-wrap: wrap;
          gap: 14px;
        }
        .lawyer-pulse-badge {
          display: inline-flex;
          align-items: center;
          gap: 6px;
          padding: 3px 10px;
          border-radius: 99px;
          background: #ecfdf5;
          color: #047857;
          font-size: 11.5px;
          font-weight: 700;
        }
        .lawyer-pulse-dot {
          width: 7px;
          height: 7px;
          border-radius: 50%;
          background: #10b981;
          box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
          animation: emp-pulse 2s infinite;
        }
        @keyframes emp-pulse {
          0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
          70% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
          100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        .lawyer-kpi-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
          gap: 12px;
          margin-bottom: 20px;
        }
        .lawyer-kpi-card {
          background: #fff;
          border: 1px solid rgba(0,0,0,0.08);
          border-radius: 12px;
          padding: 14px 16px;
          display: flex;
          align-items: center;
          gap: 12px;
          cursor: pointer;
          transition: all 0.2s ease;
        }
        .lawyer-kpi-card:hover {
          transform: translateY(-2px);
          box-shadow: 0 4px 12px rgba(0,0,0,0.05);
          border-color: #cbd5e1;
        }
        .lawyer-kpi-card.active {
          border-color: var(--primary, #1b2559);
          background: #f8fafc;
          box-shadow: 0 2px 8px rgba(27, 37, 89, 0.08);
        }
        .kpi-icon {
          width: 40px;
          height: 40px;
          border-radius: 10px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 16px;
        }
        .kpi-info {
          display: flex;
          flex-direction: column;
        }
        .kpi-val {
          font-size: 20px;
          font-weight: 800;
          color: var(--primary, #1b2559);
          line-height: 1.2;
        }
        .kpi-lbl {
          font-size: 11.5px;
          color: var(--muted, #64748b);
          margin-top: 2px;
        }
        .lawyer-filter-box {
          background: #fff;
          border: 1px solid rgba(0,0,0,0.08);
          border-radius: 12px;
          padding: 14px 16px;
          margin-bottom: 20px;
          display: flex;
          flex-direction: column;
          gap: 12px;
        }
        .lawyer-pill-scroll {
          display: flex;
          gap: 8px;
          overflow-x: auto;
          padding-bottom: 4px;
        }
        .lawyer-pill {
          padding: 6px 14px;
          border-radius: 8px;
          font-size: 12.5px;
          font-weight: 600;
          border: 1px solid #e2e8f0;
          background: #fff;
          color: #475569;
          cursor: pointer;
          white-space: nowrap;
          transition: all 0.15s;
        }
        .lawyer-pill:hover { background: #f8fafc; border-color: #cbd5e1; }
        .lawyer-pill.active {
          background: var(--primary, #1b2559);
          color: #fff;
          border-color: var(--primary, #1b2559);
        }
        .lawyer-filter-row {
          display: flex;
          gap: 10px;
          flex-wrap: wrap;
        }
        .lawyer-search-wrap {
          flex: 1;
          min-width: 260px;
          position: relative;
        }
        .lawyer-search-input {
          width: 100%;
          padding: 8px 32px 8px 12px;
          border: 1px solid #cbd5e1;
          border-radius: 8px;
          font-size: 13px;
          box-sizing: border-box;
        }
        .lawyer-search-icon {
          position: absolute;
          right: 10px;
          top: 9px;
          opacity: 0.5;
        }
        .lawyer-search-clear {
          position: absolute;
          left: 8px;
          top: 8px;
          background: none;
          border: none;
          cursor: pointer;
          color: #94a3b8;
        }
        .lawyer-select {
          padding: 8px 12px;
          border: 1px solid #cbd5e1;
          border-radius: 8px;
          font-size: 13px;
          background: #fff;
        }
        .lawyer-table-card {
          background: #fff;
          border: 1px solid rgba(0,0,0,0.08);
          border-radius: 12px;
          overflow: hidden;
          box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .lawyer-table {
          width: 100%;
          border-collapse: collapse;
          text-align: right;
          font-size: 13px;
        }
        .lawyer-table th {
          background: #f8fafc;
          padding: 12px 14px;
          color: #475569;
          font-weight: 700;
          font-size: 12px;
          border-bottom: 1px solid #e2e8f0;
        }
        .lawyer-table td {
          padding: 12px 14px;
          border-bottom: 1px solid #f1f5f9;
          vertical-align: middle;
        }
        .lawyer-table tr:hover { background: #f8fafc; }
        .lawyer-table tr.active-row { background: #eff6ff; }
        .lawyer-ref-link {
          font-weight: 800;
          color: var(--primary, #1b2559);
          cursor: pointer;
        }
        .lawyer-ref-link:hover { text-decoration: underline; }
        .lawyer-subj-text {
          max-width: 240px;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
          font-size: 12.5px;
          color: #334155;
        }
        .lawyer-status-pill {
          display: inline-block;
          padding: 2px 8px;
          border-radius: 6px;
          font-size: 11px;
          font-weight: 700;
        }
        .lawyer-status-pill.live { background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }
        .lawyer-status-pill.ended { background: #f0fdf4; color: #15803d; }
        .lawyer-status-pill.cancelled { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .lawyer-status-pill.missed { background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; }
        .lawyer-status-pill.wait { background: #f8fafc; color: #475569; }
        .lawyer-summary-badge {
          display: inline-block;
          padding: 2px 8px;
          border-radius: 6px;
          font-size: 11px;
          font-weight: 700;
        }
        .lawyer-summary-badge.approved { background: #dcfce7; color: #15803d; }
        .lawyer-summary-badge.pending { background: #fef3c7; color: #b45309; cursor: pointer; }
        .lawyer-summary-badge.empty { background: #f1f5f9; color: #64748b; }
        .lawyer-kanban-board {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
          gap: 16px;
        }
        .lawyer-kanban-col {
          background: #f8fafc;
          border: 1px solid #e2e8f0;
          border-radius: 12px;
          display: flex;
          flex-direction: column;
          max-height: 75vh;
        }
        .col-head {
          padding: 12px 14px;
          border-bottom: 1px solid #e2e8f0;
          font-weight: 700;
          font-size: 13.5px;
          display: flex;
          justify-content: space-between;
          align-items: center;
          border-radius: 12px 12px 0 0;
        }
        .col-head.blue { background: #eff6ff; color: #1d4ed8; }
        .col-head.amber { background: #fffbeb; color: #b45309; }
        .col-head.purple { background: #faf5ff; color: #7e22ce; }
        .col-head.green { background: #f0fdf4; color: #15803d; }
        .col-head.gray { background: #f1f5f9; color: #475569; }
        .col-head .count {
          background: #fff;
          padding: 2px 8px;
          border-radius: 99px;
          font-size: 11.5px;
        }
        .col-body {
          padding: 12px;
          overflow-y: auto;
          display: flex;
          flex-direction: column;
          gap: 10px;
        }
        .lawyer-kanban-card {
          background: #fff;
          border: 1px solid #e2e8f0;
          border-radius: 10px;
          padding: 12px;
          cursor: pointer;
          transition: all 0.15s;
        }
        .lawyer-kanban-card:hover {
          transform: translateY(-2px);
          box-shadow: 0 4px 10px rgba(0,0,0,0.05);
          border-color: #cbd5e1;
        }
        .lawyer-kanban-card.live-border {
          border-color: #ef4444;
          box-shadow: 0 0 0 1px #ef4444;
        }
        .lawyer-empty-box {
          background: #fff;
          border: 1px solid #e2e8f0;
          border-radius: 12px;
          padding: 40px 20px;
          text-align: center;
        }

        /* ── تنسيق الدرج المنبثق المحسن والمضمون ── */
        .emp-drawer-overlay {
          position: fixed !important;
          inset: 0 !important;
          width: 100vw !important;
          height: 100vh !important;
          background: rgba(15, 23, 42, 0.6) !important;
          z-index: 99990 !important;
          backdrop-filter: blur(4px) !important;
          cursor: pointer;
        }
        .emp-drawer-panel {
          position: fixed !important;
          top: 0 !important;
          bottom: 0 !important;
          right: 0 !important;
          width: 600px !important;
          max-width: 92vw !important;
          height: 100vh !important;
          background: #ffffff !important;
          z-index: 99999 !important;
          box-shadow: -10px 0 35px rgba(0, 0, 0, 0.3) !important;
          display: flex !important;
          flex-direction: column !important;
          box-sizing: border-box !important;
          cursor: default;
          animation: slideInFromRight 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
        @keyframes slideInFromRight {
          from { transform: translateX(100%); }
          to { transform: translateX(0); }
        }
        .emp-drawer-head {
          padding: 16px 20px;
          border-bottom: 1px solid #e2e8f0;
          display: flex;
          justify-content: space-between;
          align-items: center;
          background: #f8fafc;
        }
        .emp-drawer-close {
          background: #f1f5f9;
          border: 1px solid #cbd5e1;
          border-radius: 6px;
          font-size: 13px;
          font-weight: 700;
          color: #475569;
          cursor: pointer;
          padding: 5px 10px;
        }
        .emp-drawer-close:hover { background: #e2e8f0; color: #0f172a; }
        .emp-drawer-tabs {
          display: flex;
          border-bottom: 1px solid #e2e8f0;
          background: #f8fafc;
        }
        .d-tab {
          flex: 1;
          padding: 12px 6px;
          background: none;
          border: none;
          border-bottom: 2px solid transparent;
          font-size: 12.5px;
          font-weight: 700;
          color: #64748b;
          cursor: pointer;
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 6px;
        }
        .d-tab.active {
          color: var(--primary, #1b2559);
          border-bottom-color: var(--primary, #1b2559);
          background: #fff;
        }
        .d-tab-count {
          padding: 1px 6px;
          border-radius: 99px;
          font-size: 10.5px;
        }
        .d-tab-count.warn { background: #fef3c7; color: #b45309; }
        .emp-drawer-body {
          flex: 1;
          overflow-y: auto;
          padding: 20px;
        }
        .emp-d-content {
          display: flex;
          flex-direction: column;
          gap: 16px;
        }
        .emp-box {
          background: #fff;
          border: 1px solid #e2e8f0;
          border-radius: 10px;
          padding: 14px 16px;
        }
        .box-title {
          margin: 0 0 10px;
          font-size: 14px;
          color: var(--primary, #1b2559);
        }
        .emp-grid-2 {
          display: grid;
          grid-template-columns: 1fr 1fr;
          gap: 10px;
        }
        .field-lbl {
          display: block;
          font-size: 11.5px;
          color: #64748b;
          margin-bottom: 2px;
        }
        .field-val {
          font-size: 13.5px;
          color: #1e293b;
        }
        .emp-box-desc {
          background: #f8fafc;
          padding: 10px 12px;
          border-radius: 8px;
          font-size: 13px;
          line-height: 1.7;
          color: #334155;
          border: 1px solid #f1f5f9;
        }
        .emp-chip {
          padding: 3px 8px;
          background: #f1f5f9;
          border-radius: 6px;
          font-size: 12px;
          color: #475569;
        }
      `}</style>
    </div>
  );
};

export default LawyerConsults;
