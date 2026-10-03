import { Link, router } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '@/components/babylon/Badge';
import { useBodyScrollLock, useEscapeLayer } from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import ConsultOnBehalfButton from '@/components/consult/ConsultOnBehalfButton';
// اسم العميل صريحٌ في لوحات الطاقم (قرار المالك 2026-09-11) — `maskClient` صارت تمريراً.
import { stageChanged, staffPatch } from '@/lib/consult-live';
import { maskClient } from '@/lib/employee-data';
import { RichText, SummaryStateBadge } from '@/lib/consult-ui';
import type { ConsultCard, LawyerOpt } from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import {
  crChannelIcon,
  crChannelTone,
} from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { useCan } from '@/lib/permissions';
import { firstError } from '@/lib/server-message';

interface EmployeeConsultsProps {
  consults: ConsultCard[];
  preSessionRequests?: ConsultCard[];
  lawyers?: LawyerOpt[];
}

type ViewMode = 'table' | 'pipeline' | 'calendar' | 'analytics';
type CategoryFilter = 'all' | 'live' | 'new' | 'missing_docs' | 'in_progress' | 'ready_assign' | 'pre_session' | 'completed';
type DrawerTab = 'details' | 'documents' | 'scheduling' | 'audit';

/**
 * **هل أُسند محامٍ فعلاً؟** المعيار الإسناد لا نصُّه.
 *
 * `ConsultBooking::resolveContext` يضمن قيمةً نصّيّة دائماً («المستشار القانوني»
 * عند غياب المحامي)، فشرط `!c.lawyer || c.lawyer === '—'` **كاذبٌ أبداً**: زرّ
 * «+ إسناد محامٍ» لا يظهر، ومؤشّر «بانتظار إسناد» ينكمش إلى «جاهزة للمحامي»
 * وحدها، والنائب يظهر كاسم شخصٍ في المرشّح وله شريط حملٍ في الأحمال.
 *
 * **ولماذا في نطاق الوحدة `function` لا `const` داخل المكوّن؟**
 *
 * كانت `const hasLawyer` تُعرَّف **بعد** `telemetry`، و`useMemo` يُنفّذ دالّته أثناء
 * التصيير — فتُنادى قبل تهيئتها وتُلقي `ReferenceError: Cannot access 'hasLawyer'
 * before initialization`، فتنهار الشاشة كلّها بيضاء. و`tsc` لا يمسكها: الاستعمال داخل
 * دالّة ردٍّ، ولا سبيل إلى معرفة أنها تُنفَّذ فوراً. والدالّة المرفوعة لا يحكمها ترتيب
 * السطور أصلاً — وهي خالصةٌ لا تُغلِق على شيء.
 */
function hasLawyer(c: ConsultCard): boolean {
  return c.lawyerId != null;
}

export type EmpKanbanCol = 'new_intake' | 'docs_check' | 'scheduling' | 'active_sessions' | 'completed';

/**
 * **عمودٌ واحدٌ لكلّ بطاقة — نظير `kanbanColumnOf` في لوحة الإدارة.**
 *
 * كانت الأعمدة الخمسة مرشِّحاتٍ **مستقلّة**، فبطاقةٌ «جديدة» بلا محامٍ وجلستُها
 * الافتراضيّة «بانتظار الجلسة» تقع في ثلاثة أعمدة معاً (١ و٣ و٤)، ومجموعُ العدّادات
 * يتجاوز «إجمالي الاستشارات» المكتوب فوق الشاشة.
 *
 * وأسوأ من العدّ: **حالات دورة الحجز كانت تدخل «جلسات جارية وقادمة»** لأنّ
 * `session === 'بانتظار الجلسة'` هي القيمة الافتراضيّة لكلّ استشارة — فطلبٌ لم
 * يُسعَّر بعدُ يُعرض جلسةً قادمة.
 *
 * القسمةُ بأولويّة، والفرعُ الأخير جامعٌ فلا تسقط بطاقةٌ مهما استُحدثت حالة.
 */
function empKanbanColumnOf(c: ConsultCard): EmpKanbanCol {
  if (c.isTerminal) {
    return 'completed';
  }

  if (c.session === 'جلسة جارية' || c.status === 'قيد الاستشارة') {
    return 'active_sessions';
  }

  // دورةُ الحجز ليست جلسةً قادمة — تُعرض حيث يُنتظر فيها فعل
  if (c.bookingStage) {
    return 'scheduling';
  }

  if (c.status === 'بانتظار استكمال البيانات' || (c.missing && c.missing.length > 0)) {
    return 'docs_check';
  }

  if (c.status === 'جديدة') {
    return 'new_intake';
  }

  if (c.status === 'محالة للمحامي') {
    return 'active_sessions';
  }

  return 'scheduling';
}

const EmployeeConsults: React.FC<EmployeeConsultsProps> = ({
  consults: initialConsults = [],
  preSessionRequests: initialRequests = [],
  lawyers: initialLawyers = [],
}) => {
  const toast = useToast();

  // ── الحالة الأساسية والمزامنة اللحظية ──
  const [inFlightItems, setInFlightItems] = useState<ConsultCard[]>(initialConsults);
  const [requestItems, setRequestItems] = useState<ConsultCard[]>(initialRequests);

  // أنماط العرض والتصفية
  const [viewMode, setViewMode] = useState<ViewMode>('table');
  const canSchedule = useCan()('جدولة المواعيد');
  const [categoryFilter, setCategoryFilter] = useState<CategoryFilter>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [channelFilter, setChannelFilter] = useState<string>('all');
  const [specialtyFilter, setSpecialtyFilter] = useState<string>('all');
  const [lawyerFilter, setLawyerFilter] = useState<string>('all');
  const [priorityFilter, setPriorityFilter] = useState<string>('all');

  // درج العمليات 360°
  const [drawerRef, setDrawerRef] = useState<string | null>(null);
  const [drawerTab, setDrawerTab] = useState<DrawerTab>('details');
  const drawerBodyRef = React.useRef<HTMLDivElement>(null);
  const [selectedLawyerId, setSelectedLawyerId] = useState<number | ''>('');
  const [missingDocInput, setMissingDocInput] = useState<string>('');
  const [isProcessingAction, setIsProcessingAction] = useState(false);

  // مزامنة فورية عبر Laravel Echo
  useEffect(() => {
    setInFlightItems(initialConsults);
    setRequestItems(initialRequests);

    const allConsults = [...initialConsults, ...initialRequests];
    allConsults.forEach((c) => {
      echo.private(`consult.${c.id}`).listen('.status', (e: Partial<ConsultCard>) => {
        // القاعدة المشتركة (`lib/consult-live`): لا تسمية العميل ولا ملخّصه فوق بطاقة الطاقم
        const rest = staffPatch(e);
        const updater = (prev: ConsultCard[]) =>
          prev.map((x) => (x.id === c.id ? { ...x, ...rest } : x));
        setInFlightItems(updater);
        setRequestItems(updater);

        if (stageChanged(e, c)) {
          router.reload({ only: ['consults', 'preSessionRequests'] });
        }
      });
    });

    return () => {
      allConsults.forEach((c) => echo.leave(`consult.${c.id}`));
    };
  }, [initialConsults, initialRequests]);

  // دمج كافة الاستشارات في مصفوفة موحدة 360°
  const allItems = useMemo(() => {
    const map = new Map<number, ConsultCard>();
    inFlightItems.forEach((c) => map.set(c.id, c));
    requestItems.forEach((c) => map.set(c.id, c));
    return Array.from(map.values());
  }, [inFlightItems, requestItems]);

  // الاستشارة المفتوحة في الدرج
  const drawerConsult = useMemo(() => {
    if (!drawerRef) return null;
    return allItems.find((c) => c.ref === drawerRef || String(c.id) === drawerRef) || null;
  }, [allItems, drawerRef]);

  /*
   * **الملفّ المقفل لا تُعرض عليه أفعالٌ يرفضها الخادم.**
   *
   * `refer` و`requestDocs` يمنعان `Consult::CLOSED_STATUSES` — والدرج كان يعرض زرّ
   * الإسناد ونموذجَ طلب المستندات على استشارةٍ «منتهية»، فالضغط يعود ٤٢٢ حتماً. والحارس
   * على الخادم من عملنا، وهذا نظيرُه في الواجهة كي لا يُدعى المستخدم إلى ما يُصدّ عنه.
   *
   * `CLOSED` لا `TERMINAL`: «لم يحضر» نهايةٌ في التبويب لكنّها **حالة إنقاذ** تُعاد
   * جدولتها — فحجبُ أفعالها يسدّ باب الإنقاذ.
   */
  const isClosed = drawerConsult != null && drawerConsult.isClosed;

  // قائمة المحامين المعتمدين
  const lawyersList = useMemo(() => {
    if (initialLawyers && initialLawyers.length > 0) return initialLawyers;
    const dynamic: LawyerOpt[] = [];
    allItems.forEach((c) => {
      if (c.lawyer && c.lawyer !== '—' && !dynamic.find((l) => l.name === c.lawyer)) {
        dynamic.push({ id: 0, name: c.lawyer, dept: c.specialty || c.type || 'عام' });
      }
    });
    return dynamic;
  }, [initialLawyers, allItems]);

  // التحكم في الدرج
  const openDrawer = (ref: string, tab: DrawerTab = 'details') => {
    setDrawerRef(ref);
    setDrawerTab(tab);
  };

  const closeDrawer = () => {
    setDrawerRef(null);
  };

  useBodyScrollLock(Boolean(drawerRef));

  // Escape يغلق الدرج — طبقةٌ في المكدّس المشترك، فنافذةٌ فوقه تُغلَق وحدها
  useEscapeLayer(Boolean(drawerRef), closeDrawer);

  // مزامنة حقول الدرج عند تغير الاستشارة
  useEffect(() => {
    if (drawerConsult) {
      const found = lawyersList.find((l) => l.name === drawerConsult.lawyer);
      setSelectedLawyerId(found ? found.id : '');
      setMissingDocInput('');
    }
  }, [drawerConsult, lawyersList]);

  // ── مؤشرات الأداء والقيادة التشغيلية 360° ──
  const telemetry = useMemo(() => {
    const total = allItems.length;
    const liveNow = allItems.filter((c) => c.session === 'جلسة جارية').length;
    const newIntake = allItems.filter((c) => c.status === 'جديدة').length;
    const missingDocs = allItems.filter(
      (c) => c.status === 'بانتظار استكمال البيانات' || (c.missing && c.missing.length > 0)
    ).length;
    const readyForLawyer = allItems.filter(
      (c) => c.status === 'جاهزة للمحامي' || ! hasLawyer(c)
    ).length;
    // **العدّاد كان صفراً أبداً.** كان يبدأ بـ`if (!c.day) return false` و`day` لا
    // تُرسله البطاقة إطلاقاً — فيخرج قبل أن يصل إلى شرط «جلسة جارية». والمصدر
    // الصحيح `startsAt` (ISO) وهو مُرسَل فعلاً.
    const todaySessions = allItems.filter((c) => {
      if (c.session === 'جلسة جارية') return true;
      if (!c.startsAt) return false;
      return new Date(c.startsAt).toDateString() === new Date().toDateString();
    }).length;
    const preSession = allItems.filter((c) => Boolean(c.bookingStage)).length;
    const completed = allItems.filter(
      (c) => c.isTerminal
    ).length;
    // **ثلاث حالاتٍ كانت بلا تبويب** — وهي مربطُ عمل الموظّف: بين استلامه الطلب
    // وإحالته للمحامي. كانت الشاشة تعطي حبّةً لطلبات ما قبل الجلسة (وهي شغل الإدارة)
    // ولا تعطي حبّةً لما يشتغل عليه الموظّف نفسه، فلا يجد ملفّاته إلا في «الكل».
    const inProgress = allItems.filter((c) =>
      ['بانتظار اعتماد الموظف', 'محالة للمحامي'].includes(c.status)
    ).length;
    const toCase = allItems.filter((c) => !!c.caseNo).length;

    // **حُذف عدّاد «المتأخّرة».** كان `(c.mins || 0) > 100`، و`mins` **لا كاتبَ له في
    // `app/` كلّه** (عمود `default(0)` في هجرة، ثمّ `toCard` — ولا شيء بينهما).
    // فالشرط كاذبٌ أبداً. ولم يكن يُعرض أصلاً، فبقي العطل مخفيّاً — وهذا أسوأ من
    // عدّادٍ يكذب علناً. إعادتُه تلزمها كتابة `mins` أوّلاً.

    return {
      total,
      liveNow,
      newIntake,
      missingDocs,
      readyForLawyer,
      todaySessions,
      preSession,
      completed,
      inProgress,
      toCase,
    };
  }, [allItems]);

  // رادار الجلسات المنعقدة الحية
  const liveSessions = useMemo(() => {
    // **«المنعقدة الآن» تعني المنعقدة الآن.** كان الشرط يضمّ `startable` — وهي
    // «لم تبدأ بعدُ وموعدها قريب» — ثمّ تُوسَم كلّ بطاقةٍ «جلسة جارية» نصّاً مكتوباً
    // بيدٍ. فيقرأ الموظّف أن جلساتٍ تنعقد ولا أحد فيها.
    return allItems.filter((c) => c.session === 'جلسة جارية');
  }, [allItems]);

  // القوائم المنسدلة للفرز
  const specialtiesList = useMemo(() => {
    const set = new Set<string>();
    allItems.forEach((c) => {
      if (c.specialty?.trim()) set.add(c.specialty.trim());
      else if (c.type?.trim()) set.add(c.type.trim());
    });
    return Array.from(set);
  }, [allItems]);

  const assignedLawyersList = useMemo(() => {
    const set = new Set<string>();
    allItems.forEach((c) => {
      // النائب ليس شخصاً: إدراجه يجعله خياراً في المرشّح وصاحبَ أثقل حملٍ في الأحمال
      if (hasLawyer(c) && c.lawyer?.trim()) set.add(c.lawyer.trim());
    });
    return Array.from(set);
  }, [allItems]);

  // تصفية البيانات المتقدمة
  const filteredItems = useMemo(() => {
    return allItems.filter((c) => {
      if (categoryFilter === 'live' && c.session !== 'جلسة جارية') return false;
      if (categoryFilter === 'new' && c.status !== 'جديدة') return false;
      if (
        categoryFilter === 'missing_docs' &&
        c.status !== 'بانتظار استكمال البيانات' &&
        (!c.missing || c.missing.length === 0)
      ) return false;
      if (
        categoryFilter === 'in_progress' &&
        ! ['بانتظار اعتماد الموظف', 'محالة للمحامي'].includes(c.status)
      ) {
        return false;
      }

      if (
        categoryFilter === 'ready_assign' &&
        c.status !== 'جاهزة للمحامي' &&
        hasLawyer(c)
      ) return false;
      if (
        categoryFilter === 'pre_session' &&
        !c.bookingStage
      ) return false;
      if (
        categoryFilter === 'completed' &&
        !c.isTerminal
      ) return false;

      if (channelFilter !== 'all' && c.channel !== channelFilter) return false;

      if (specialtyFilter !== 'all') {
        const sp = c.specialty || c.type;
        if (sp !== specialtyFilter) return false;
      }

      if (lawyerFilter !== 'all' && c.lawyer !== lawyerFilter) return false;
      if (priorityFilter !== 'all' && c.priority !== priorityFilter) return false;

      if (searchQuery.trim() !== '') {
        const q = searchQuery.toLowerCase().trim();
        const refMatch = (c.ref || '').toLowerCase().includes(q);
        const clientMatch = (c.client || '').toLowerCase().includes(q);
        const subjectMatch = (c.subject || '').toLowerCase().includes(q);
        const lawyerMatch = (c.lawyer || '').toLowerCase().includes(q);
        const phoneMatch = (c.phone || '').toLowerCase().includes(q);

        if (!refMatch && !clientMatch && !subjectMatch && !lawyerMatch && !phoneMatch) return false;
      }

      return true;
    });
  }, [allItems, categoryFilter, channelFilter, specialtyFilter, lawyerFilter, priorityFilter, searchQuery]);

  /**
   * **الأجندة تَعِد بترتيبٍ زمنيّ فلتفرز.**
   *
   * كانت تعتمد ترتيب `allItems` — وهو دمجُ قائمتين: الاستشارات مرتّبةً بـ`starts_at`
   * ثمّ طلبات ما قبل الجلسة تُلحَق بعدها بترتيب المعرّف. فالوعد في العنوان («مرتبة
   * زمنياً») مكسورٌ بنيويّاً.
   *
   * والفرز على `startsAt` (ISO) لا على `when` النصّيّ. وما لا موعد له يُستبعد: كان
   * `whenLabel()` يُرجع `when_label` عند غياب `starts_at`، وقيمتُه قد تكون «بانتظار
   * اختيار موعد جديد» — فتدخل الأجندة استشارةٌ **بلا موعد** كأنّها مجدولة.
   */
  const agendaItems = useMemo(
    () =>
      filteredItems
        .filter((c) => !!c.startsAt)
        .sort((a, b) => new Date(a.startsAt!).getTime() - new Date(b.startsAt!).getTime()),
    [filteredItems]
  );

  // ── الإجراءات الميدانية للموظف ──

  const handleRequestDocs = (consult: ConsultCard) => {
    if (!missingDocInput.trim()) {
      toast('يرجى كتابة اسم المستند المطلوب تحديده للعميل');
      return;
    }

    setIsProcessingAction(true);
    router.post(
      `/employee/consults/${consult.id}/reqdocs`,
      { docs: missingDocInput.trim() },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast('تم إرسال إشعار طلب المستندات إلى العميل فوراً');
          setMissingDocInput('');
        },
        onError: (e) => toast(firstError(e, 'تعذر إرسال طلب المستندات')),
        onFinish: () => setIsProcessingAction(false),
      }
    );
  };

  const handleRefer = (consult: ConsultCard) => {
    if (!selectedLawyerId) {
      toast('يرجى اختيار المحامي المختص من القائمة أولاً');
      return;
    }

    if (consult.assignBlocker) {
      toast(consult.assignBlocker);
      return;
    }

    const lawyerObj = lawyersList.find((l) => l.id === selectedLawyerId);
    const lawyerName = lawyerObj?.name || '';

    setIsProcessingAction(true);
    router.post(
      `/employee/consults/${consult.id}/refer`,
      { lawyer_id: Number(selectedLawyerId), lawyer: lawyerName },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast(`تم إسناد الاستشارة (${consult.ref}) للمحامي: ${lawyerName}`);
        },
        onError: (e) => toast(firstError(e, 'تعذر إسناد الاستشارة للمحامي')),
        onFinish: () => setIsProcessingAction(false),
      }
    );
  };

  const handleZoomSync = (consult: ConsultCard) => {
    setIsProcessingAction(true);
    router.post(
      `/employee/consults/${consult.id}/zoom-sync`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => toast('تم تحديث ومزامنة بيانات الجلسة من سحابة Zoom بنجاح'),
        onError: (e) => toast(firstError(e, 'تعذر مزامنة بيانات Zoom')),
        onFinish: () => setIsProcessingAction(false),
      }
    );
  };

  return (
    <div className="consults-360-root" style={{ paddingBottom: 60, width: '100%' }} dir="rtl">
      {/* ── CSS المطابق بالكامل للوحة الإدارة العليا ── */}
      <style>{`
        .consults-360-root {
          box-sizing: border-box;
          width: 100%;
        }
        .c360-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          flex-wrap: wrap;
          gap: 14px;
          margin-bottom: 16px;
        }
        .c360-view-switcher {
          display: flex;
          background: rgba(0,0,0,0.06);
          padding: 4px;
          border-radius: 10px;
          gap: 4px;
        }
        .c360-kpi-grid {
          display: grid;
          grid-template-columns: repeat(6, 1fr);
          gap: 12px;
          margin: 16px 0 20px;
        }
        .c360-cat-scroll {
          display: flex;
          gap: 8px;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
          padding-bottom: 4px;
          white-space: nowrap;
        }
        .c360-cat-scroll::-webkit-scrollbar {
          height: 4px;
        }
        .c360-cat-scroll::-webkit-scrollbar-thumb {
          background: rgba(0,0,0,0.15);
          border-radius: 4px;
        }
        .c360-filter-grid {
          display: grid;
          grid-template-columns: 2fr repeat(4, 1fr);
          gap: 10px;
        }
        .c360-table-wrapper {
          display: block;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
        }
        .c360-desktop-table {
          width: 100%;
          border-collapse: collapse;
          text-align: right;
          font-size: 13px;
          min-width: 780px;
        }
        .c360-mobile-cards {
          display: none;
        }
        .c360-kanban-grid {
          display: grid;
          grid-template-columns: repeat(5, 1fr);
          gap: 14px;
          align-items: start;
        }
        .c360-radar-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
          gap: 12px;
        }
        @keyframes c360FadeIn {
          from { opacity: 0; }
          to { opacity: 1; }
        }
        @keyframes c360SlideInRight {
          from { transform: translateX(100%); }
          to { transform: translateX(0); }
        }
        .c360-portal-backdrop {
          position: fixed !important;
          inset: 0 !important;
          width: 100vw !important;
          height: 100vh !important;
          height: 100dvh !important;
          z-index: 99990 !important;
          background: rgba(10, 25, 45, 0.6) !important;
          backdrop-filter: blur(4px) !important;
          display: flex !important;
          justify-content: flex-end !important;
          direction: rtl !important;
          animation: c360FadeIn 0.2s ease-out;
        }
        .c360-drawer-panel {
          position: fixed !important;
          top: 0 !important;
          bottom: 0 !important;
          right: 0 !important;
          width: 100% !important;
          max-width: 580px !important;
          /* \`dvh\`: على الجوّال \`100vh\` أطول من المساحة الظاهرة فيُدفع الرأس خارجها */
          height: 100vh !important;
          height: 100dvh !important;
          /* لا يتمرّر اللوح نفسه — التمرير للمحتوى وحده، فلا يُقصّ الرأس عند تبديل التبويب */
          overflow: hidden !important;
          background: #fff !important;
          box-shadow: -10px 0 35px rgba(0,0,0,0.35) !important;
          display: flex !important;
          flex-direction: column !important;
          box-sizing: border-box !important;
          z-index: 99999 !important;
          animation: c360SlideInRight 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
        .c360-drawer-head, .c360-drawer-tabs {
          flex-shrink: 0;
        }
        .c360-drawer-tabs {
          display: flex;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
          border-bottom: 1px solid rgba(0,0,0,0.08);
          background: #fafafa;
          white-space: nowrap;
        }
        @media (max-width: 1180px) {
          .c360-kpi-grid {
            grid-template-columns: repeat(3, 1fr);
          }
          .c360-filter-grid {
            grid-template-columns: repeat(3, 1fr);
          }
          .c360-kanban-grid {
            display: flex;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scroll-snap-type: x mandatory;
            padding-bottom: 14px;
          }
          .c360-kanban-col {
            flex: 0 0 280px;
            min-width: 280px;
            scroll-snap-align: start;
          }
        }
        @media (max-width: 768px) {
          .c360-header {
            flex-direction: column;
            align-items: stretch;
          }
          .c360-view-switcher {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            width: 100%;
          }
          .c360-kpi-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
          }
          /* على الهاتف: قائمتان في كلّ صفّ، والبحث بعرض السطر فوقهما (قرار المالك 2026-09-29) */
          .c360-filter-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
          }
          .c360-filter-grid > :first-child {
            grid-column: 1 / -1;
          }
          .c360-table-wrapper {
            display: none;
          }
          .c360-mobile-cards {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 12px;
          }
          .c360-drawer-panel {
            max-width: 100% !important;
          }
          /* التبويبات الأربعة لا تُعصر في عرض الهاتف — تتمرّر أفقيّاً بمقاسها */
          .c360-drawer-tabs > button {
            flex: 0 0 auto !important;
            font-size: 12px !important;
            padding: 10px 12px !important;
          }
        }
        @media (max-width: 420px) {
          .c360-view-switcher {
            grid-template-columns: 1fr;
          }
          .c360-kpi-grid {
            grid-template-columns: 1fr;
          }
          .c360-drawer-head {
            padding: 12px 14px !important;
          }
          .c360-drawer-body {
            padding: 14px !important;
          }
        }
      `}</style>

      {/* ── 1. الهيدر والترحيب ومبدل طرق العرض (مطابق للإدارة) ── */}
      <div className="greet c360-header">
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0, fontSize: 'clamp(17px, 2.5vw, 22px)' }}>
            <Icon name="scale" cls="ic" />
            مركز قيادة وتنسيق الاستشارات 360° — لوحة الموظف
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--muted)', fontSize: 13 }}>
            غرفة العمليات والتنسيق اللوجستي: تدقيق مستندات العملاء، التأكد من الجاهزية، جدولة وإسناد المحامين المختصين.
          </p>
          {/* صلاحيّة المسار نفسها (`consults.request` خلف «جدولة المواعيد») */}
          {canSchedule && (
            <div style={{ marginTop: 10 }}>
              <ConsultOnBehalfButton className="btn sm" />
            </div>
          )}
        </div>

        {/* مبدل العرض المتكيف */}
        <div className="c360-view-switcher">
          <button
            type="button"
            className={`btn sm ${viewMode === 'table' ? 'primary' : 'soft'}`}
            style={{ borderRadius: 8, padding: '7px 12px', fontSize: 12.5, justifyContent: 'center' }}
            onClick={() => setViewMode('table')}
          >
            <Icon name="doc" /> الجدول الذكي
          </button>
          <button
            type="button"
            className={`btn sm ${viewMode === 'pipeline' ? 'primary' : 'soft'}`}
            style={{ borderRadius: 8, padding: '7px 12px', fontSize: 12.5, justifyContent: 'center' }}
            onClick={() => setViewMode('pipeline')}
          >
            <Icon name="compass" /> مسار الكانبان
          </button>
          <button
            type="button"
            className={`btn sm ${viewMode === 'calendar' ? 'primary' : 'soft'}`}
            style={{ borderRadius: 8, padding: '7px 12px', fontSize: 12.5, justifyContent: 'center' }}
            onClick={() => setViewMode('calendar')}
          >
            <Icon name="calgrid" /> الأجندة الزمنية
          </button>
          <button
            type="button"
            className={`btn sm ${viewMode === 'analytics' ? 'primary' : 'soft'}`}
            style={{ borderRadius: 8, padding: '7px 12px', fontSize: 12.5, justifyContent: 'center' }}
            onClick={() => setViewMode('analytics')}
          >
            <Icon name="exec" /> التحليلات والأحمال
          </button>
        </div>
      </div>

      {/* ── 2. شريط المؤشرات والقيادة اللحظي المتكيف (KPI Ribbon) ── */}
      <div className="c360-kpi-grid">
        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid var(--primary)' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>إجمالي الاستشارات</span>
            <Icon name="folder" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: 'var(--primary)', marginTop: 4 }}>
            {telemetry.total}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>كافة المراحل الميدانية</div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #11A0C8' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>طلبات جديدة</span>
            <Icon name="check" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#11A0C8', marginTop: 4 }}>
            {telemetry.newIntake}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>بانتظار استلام المنسق</div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #C0832B' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>نواقص الأوراق</span>
            <Icon name="upload" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#C0832B', marginTop: 4 }}>
            {telemetry.missingDocs}
          </div>
          <div style={{ fontSize: 10.5, color: '#C0832B', marginTop: 2 }}>تتطلب استكمال العميل</div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #7e22ce' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>بانتظار إسناد محامٍ</span>
            <Icon name="scale" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#7e22ce', marginTop: 4 }}>
            {telemetry.readyForLawyer}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>مستوفية وجاهزة للإحالة</div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #1E9D6B' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>جلسات اليوم المجدولة</span>
            <Icon name="video" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#1E9D6B', marginTop: 4 }}>
            {telemetry.todaySessions}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>انعقاد مرئي ومكتبي</div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #475569' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>طلبات ما قبل الجلسة</span>
            <Icon name="clock" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#475569', marginTop: 4 }}>
            {telemetry.preSession}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>تسعير وسداد ومواعيد</div>
        </div>

        {/*
          **مؤشّرٌ صادقٌ كان يُحسب ولا يُعرض.** حلّ `caseNo` محلّ الحالة الميتة
          `'محولة إلى قضية'` (لا كاتبَ لها)، فصار العدّاد حقيقياً — ثمّ بقي مشتقّاً
          بلا بطاقة. والتحويل يقع على **التذكرة**، فدليلُه وجود قضيّةٍ لها.
        */}
        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #0E5C9C' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>تحوّلت إلى قضايا</span>
            <Icon name="folder" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#0E5C9C', marginTop: 4 }}>
            {telemetry.toCase}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>فُتح لها ملفّ قضيّة</div>
        </div>
      </div>

      {/* ── 3. رادار الجلسات المنعقدة الحية (Live Radar) ── */}
      {liveSessions.length > 0 && (
        <div
          className="card"
          style={{
            background: 'linear-gradient(135deg, rgba(17, 160, 200, 0.08), rgba(30, 157, 107, 0.08))',
            borderColor: '#11A0C8',
            marginBottom: 20,
            boxShadow: '0 4px 18px rgba(17, 160, 200, 0.12)',
          }}
        >
          <div className="card-h" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <span
                style={{
                  display: 'inline-block',
                  width: 10,
                  height: 10,
                  borderRadius: '50%',
                  background: '#1E9D6B',
                  boxShadow: '0 0 10px #1E9D6B',
                  animation: 'c360FadeIn 1.5s infinite alternate',
                }}
              />
              <h3 style={{ margin: 0, color: '#0E5C9C', fontSize: 15 }}>رادار الجلسات المنعقدة الآن (Live Radar)</h3>
            </div>
            <span style={{ fontSize: 12, color: 'var(--muted)' }}>
              {liveSessions.length} جلسة تحت البث المباشر
            </span>
          </div>
          <div className="card-b c360-radar-grid">
            {liveSessions.map((c) => (
              <div
                key={c.id}
                className="c360-radar-item"
                style={{
                  background: '#fff',
                  border: '1px solid rgba(17, 160, 200, 0.3)',
                  borderRadius: 10,
                  padding: 14,
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                }}
              >
                <div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <Badge text={c.session} tone={c.sessionTone} />
                    <b>{c.ref}</b>
                  </div>
                  <div style={{ fontSize: 13, marginTop: 4 }}>
                    العميل: <b>{maskClient(c.client)}</b> · المستشار: <b>{c.lawyer}</b>
                  </div>
                  <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>
                    الموضوع: {c.subject}
                  </div>
                </div>
                <div className="c360-radar-acts" style={{ display: 'flex', flexDirection: 'column', gap: 6, alignItems: 'flex-end' }}>
                  <button
                    className="btn soft sm"
                    type="button"
                    onClick={() => openDrawer(c.ref, 'scheduling')}
                  >
                    <Icon name="info" /> تفاصيل ومتابعة Zoom
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ── 4. شريط الفلترة والبحث الذكي المتكيف (مطابق للإدارة) ── */}
      <div
        className="card"
        style={{
          padding: '14px 16px',
          marginBottom: 18,
          display: 'flex',
          flexDirection: 'column',
          gap: 12,
        }}
      >
        {/* شريط تمرير أزرار المراحل (Category Pills) */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', flexShrink: 0 }}>المرحلة:</span>
          <div className="c360-cat-scroll" style={{ flex: 1 }}>
            {(
              [
                ['all', 'جميع الاستشارات', allItems.length],
                ['live', 'جلسات جارية', telemetry.liveNow],
                ['new', 'جديدة', telemetry.newIntake],
                ['missing_docs', 'نواقص الأوراق', telemetry.missingDocs],
                ['in_progress', 'قيد المعالجة', telemetry.inProgress],
                ['ready_assign', 'جاهزة للإسناد', telemetry.readyForLawyer],
                ['pre_session', 'طلبات ما قبل الجلسة', telemetry.preSession],
                ['completed', 'منتهية ومغلقة', telemetry.completed],
              ] as const
            ).map(([key, label, count]) => (
              <button
                key={key}
                type="button"
                onClick={() => setCategoryFilter(key)}
                style={{
                  border: 'none',
                  background: categoryFilter === key ? 'var(--primary)' : 'rgba(0,0,0,0.05)',
                  color: categoryFilter === key ? '#fff' : 'inherit',
                  borderRadius: 20,
                  padding: '6px 12px',
                  fontSize: 12,
                  cursor: 'pointer',
                  fontWeight: categoryFilter === key ? 700 : 500,
                  display: 'flex',
                  alignItems: 'center',
                  gap: 6,
                  flexShrink: 0,
                  transition: 'all 0.2s',
                }}
              >
                <span>{label}</span>
                <span
                  style={{
                    background: categoryFilter === key ? 'rgba(255,255,255,0.25)' : 'rgba(0,0,0,0.1)',
                    borderRadius: 10,
                    padding: '1px 6px',
                    fontSize: 10,
                    fontWeight: 700,
                  }}
                >
                  {count}
                </span>
              </button>
            ))}
          </div>
        </div>

        {/* شبكة حقول البحث والقوائم المنسدلة */}
        <div className="c360-filter-grid">
          <div style={{ position: 'relative' }}>
            <input
              type="text"
              placeholder="ابحث بالرقم المرجعي (CN-2026), اسم العميل, الجوال, أو الموضوع..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              style={{
                width: '100%',
                padding: '9px 34px 9px 12px',
                borderRadius: 8,
                border: '1px solid rgba(0,0,0,0.12)',
                fontSize: 13,
                boxSizing: 'border-box',
              }}
            />
            <div style={{ position: 'absolute', right: 10, top: 10, opacity: 0.5 }}>
              <Icon name="search" />
            </div>
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                style={{
                  position: 'absolute',
                  left: 10,
                  top: 8,
                  background: 'none',
                  border: 'none',
                  cursor: 'pointer',
                  color: 'var(--muted)',
                }}
              >
                ✕
              </button>
            )}
          </div>

          <select
            value={specialtyFilter}
            onChange={(e) => setSpecialtyFilter(e.target.value)}
            style={{
              padding: '9px 10px',
              borderRadius: 8,
              border: '1px solid rgba(0,0,0,0.12)',
              fontSize: 12.5,
              background: '#fff',
            }}
          >
            <option value="all">كل التخصصات</option>
            {specialtiesList.map((sp) => (
              <option key={sp} value={sp}>
                {sp}
              </option>
            ))}
          </select>

          <select
            value={channelFilter}
            onChange={(e) => setChannelFilter(e.target.value)}
            style={{
              padding: '9px 10px',
              borderRadius: 8,
              border: '1px solid rgba(0,0,0,0.12)',
              fontSize: 12.5,
              background: '#fff',
            }}
          >
            <option value="all">كل القنوات</option>
            <option value="مرئية">🎥 مرئية (Zoom)</option>
            <option value="هاتفية">📞 هاتفية</option>
            <option value="حضورية">🏢 حضورية بالمكتب</option>
          </select>

          <select
            value={lawyerFilter}
            onChange={(e) => setLawyerFilter(e.target.value)}
            style={{
              padding: '9px 10px',
              borderRadius: 8,
              border: '1px solid rgba(0,0,0,0.12)',
              fontSize: 12.5,
              background: '#fff',
            }}
          >
            <option value="all">كل المستشارين</option>
            {assignedLawyersList.map((law) => (
              <option key={law} value={law}>
                {law}
              </option>
            ))}
          </select>

          <select
            value={priorityFilter}
            onChange={(e) => setPriorityFilter(e.target.value)}
            style={{
              padding: '9px 10px',
              borderRadius: 8,
              border: '1px solid rgba(0,0,0,0.12)',
              fontSize: 12.5,
              background: '#fff',
            }}
          >
            <option value="all">كل الأولويات</option>
            <option value="عالية">⚡ عالية</option>
            <option value="متوسطة">🔹 متوسطة</option>
            <option value="منخفضة">⚪ منخفضة</option>
          </select>
        </div>
      </div>

      {/* ── View A: الجدول الذكي الميداني المتكيف ── */}
      {viewMode === 'table' && (
        <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
          <div className="c360-table-wrapper">
            <table className="c360-desktop-table">
              <thead>
                <tr style={{ background: 'rgba(0,0,0,0.03)', borderBottom: '1px solid rgba(0,0,0,0.08)' }}>
                  <th style={{ padding: '12px 16px' }}>الرقم والموضوع</th>
                  <th style={{ padding: '12px 14px' }}>العميل والتواصل</th>
                  <th style={{ padding: '12px 14px' }}>القناة والموعد</th>
                  <th style={{ padding: '12px 14px' }}>المستشار المسند</th>
                  <th style={{ padding: '12px 14px' }}>سلامة المستندات</th>
                  <th style={{ padding: '12px 14px' }}>الموقف المالي</th>
                  <th style={{ padding: '12px 14px' }}>المرحلة والحالة</th>
                  <th style={{ padding: '12px 16px', textAlign: 'center' }}>التحكم 360°</th>
                </tr>
              </thead>
              <tbody>
                {filteredItems.length > 0 ? (
                  filteredItems.map((c) => {
                    const isSelected = drawerRef === c.ref;
                    const hasMissing = Boolean(c.missing && c.missing.length > 0);
                    const isPaid = c.paid || c.status === 'منتهية' || c.status === 'محالة للمحامي';

                    return (
                      <tr
                        key={c.id}
                        style={{
                          borderBottom: '1px solid rgba(0,0,0,0.05)',
                          background: isSelected ? 'rgba(14, 92, 156, 0.05)' : 'transparent',
                          transition: 'background 0.15s',
                        }}
                      >
                        <td style={{ padding: '12px 16px' }}>
                          <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                            {/* `<button>` لا `<span>`: كان المرجع يفتح الدرج بالنقر
                                وحده، فلا يبلغه من يتنقّل بلوحة المفاتيح. */}
                            <button
                              type="button"
                              style={{
                                fontWeight: 800,
                                color: 'var(--primary)',
                                cursor: 'pointer',
                                background: 'none',
                                border: 0,
                                padding: 0,
                                font: 'inherit',
                                textAlign: 'start',
                              }}
                              onClick={() => openDrawer(c.ref, 'details')}
                            >
                              {c.ref}
                            </button>
                            <span style={{ fontSize: 12.5, fontWeight: 600, maxWidth: 220, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} title={c.subject}>
                              {c.subject || 'استشارة عامة'}
                            </span>
                            <span style={{ fontSize: 11, color: 'var(--muted)' }}>
                              التخصص: <b>{c.specialty || c.type || 'عام'}</b>
                            </span>
                          </div>
                        </td>

                        <td style={{ padding: '12px 14px' }}>
                          <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                            <b>{maskClient(c.client)}</b>
                            <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                              📞 {c.phone || '—'}
                            </span>
                          </div>
                        </td>

                        <td style={{ padding: '12px 14px' }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                            <span className={`emp-channel-tag ${crChannelTone(c.channel)}`}>
                              <Icon name={crChannelIcon(c.channel)} /> {c.channel || 'مرئية'}
                            </span>
                          </div>
                          <div style={{ fontSize: 11.5, color: '#444', marginTop: 4 }}>
                            {c.when || 'غير مجدول'}
                          </div>
                        </td>

                        <td style={{ padding: '12px 14px' }}>
                          {hasLawyer(c) ? (
                            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                              <span>⚖️</span>
                              <b>{c.lawyer}</b>
                            </div>
                          ) : (
                            <button
                              type="button"
                              className="btn soft sm"
                              style={{ padding: '3px 8px', fontSize: 11.5 }}
                              onClick={() => openDrawer(c.ref, 'scheduling')}
                            >
                              + إسناد محامٍ
                            </button>
                          )}
                        </td>

                        <td style={{ padding: '12px 14px' }}>
                          {hasMissing ? (
                            <span
                              style={{
                                display: 'inline-block',
                                padding: '3px 8px',
                                borderRadius: 6,
                                background: '#fffbeb',
                                color: '#b45309',
                                fontSize: 11.5,
                                fontWeight: 700,
                                cursor: 'pointer',
                              }}
                              onClick={() => openDrawer(c.ref, 'documents')}
                            >
                              ⚠️ {c.missing?.length || 1} أوراق ناقصة
                            </span>
                          ) : (
                            <span style={{ color: '#15803d', fontSize: 11.5, fontWeight: 700 }}>
                              ✓ مكتملة
                            </span>
                          )}
                        </td>

                        <td style={{ padding: '12px 14px' }}>
                          {isPaid ? (
                            <span style={{ color: '#15803d', fontWeight: 700, fontSize: 12 }}>
                              ✓ مسددة ({c.total || c.price || '—'} ر.س)
                            </span>
                          ) : c.status === 'بانتظار التسعير' ? (
                            <span style={{ color: '#854d0e', fontSize: 11.5, background: '#fefce8', padding: '2px 6px', borderRadius: 4 }}>
                              بانتظار تسعير الإدارة
                            </span>
                          ) : (
                            <span style={{ color: '#b91c1c', fontSize: 11.5 }}>
                              بانتظار سداد العميل
                            </span>
                          )}
                        </td>

                        <td style={{ padding: '12px 14px' }}>
                          <Badge text={c.status} tone={c.tone} />
                        </td>

                        <td style={{ padding: '12px 16px', textAlign: 'center' }}>
                          <div style={{ display: 'flex', gap: 6, justifyContent: 'center' }}>
                            <button
                              type="button"
                              className="btn soft sm"
                              style={{ padding: '3px 8px', fontSize: 11.5 }}
                              onClick={() => openDrawer(c.ref, 'details')}
                            >
                              <Icon name="out" /> فتح 360°
                            </button>
                          </div>
                        </td>
                      </tr>
                    );
                  })
                ) : (
                  <tr>
                    <td colSpan={8} style={{ padding: 30, textAlign: 'center', color: 'var(--muted)' }}>
                      {allItems.length === 0 ? 'لا توجد استشارات بعد' : 'لا توجد استشارات مطابقة لمعايير الفرز'}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          {/* كروت الموبايل المتكيفة */}
          <div className="c360-mobile-cards">
            {filteredItems.map((c) => (
              <div
                key={c.id}
                role="button"
                tabIndex={0}
                style={{
                  padding: 12,
                  border: '1px solid rgba(0,0,0,0.08)',
                  borderRadius: 10,
                  background: '#fff',
                  cursor: 'pointer',
                }}
                onClick={() => openDrawer(c.ref, 'details')}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openDrawer(c.ref, 'details');
                  }
                }}
              >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <b style={{ color: 'var(--primary)' }}>{c.ref}</b>
                  <Badge text={c.status} tone={c.tone} />
                </div>
                <div style={{ fontSize: 13, fontWeight: 600, margin: '6px 0' }}>{c.subject}</div>
                <div style={{ fontSize: 12, color: 'var(--muted)' }}>
                  العميل: {maskClient(c.client)} · المستشار: {c.lawyer}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ── View B: مسار الكانبان والرحلة التفاعلية (Interactive Kanban) ── */}
      {viewMode === 'pipeline' && (
        <div className="c360-kanban-grid">
          {(
            [
              {
                id: 'new_intake',
                title: '1. طلبات جديدة للاستلام',
                tone: '#11A0C8',
                items: filteredItems.filter((c) => empKanbanColumnOf(c) === 'new_intake'),
              },
              {
                id: 'docs_check',
                title: '2. تدقيق الأوراق والنواقص',
                tone: '#C0832B',
                items: filteredItems.filter((c) => empKanbanColumnOf(c) === 'docs_check'),
              },
              {
                id: 'scheduling',
                title: '3. الحجز والإسناد',
                tone: '#7e22ce',
                items: filteredItems.filter((c) => empKanbanColumnOf(c) === 'scheduling'),
              },
              {
                id: 'active_sessions',
                title: '4. جلسات جارية وقادمة',
                tone: '#1E9D6B',
                items: filteredItems.filter((c) => empKanbanColumnOf(c) === 'active_sessions'),
              },
              {
                id: 'completed',
                title: '5. منتهية ومغلقة',
                tone: '#13314F',
                items: filteredItems.filter((c) => empKanbanColumnOf(c) === 'completed'),
              },
            ] as const
          ).map((col) => (
            <div
              key={col.id}
              className="card c360-kanban-col"
              style={{
                margin: 0,
                padding: '14px',
                borderTop: `4px solid ${col.tone}`,
                background: 'rgba(255, 255, 255, 0.95)',
                minHeight: 400,
                boxSizing: 'border-box',
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
                <b style={{ fontSize: 13, color: col.tone }}>{col.title}</b>
                <span
                  style={{
                    background: 'rgba(0,0,0,0.06)',
                    padding: '2px 8px',
                    borderRadius: 10,
                    fontSize: 11,
                    fontWeight: 700,
                  }}
                >
                  {col.items.length}
                </span>
              </div>

              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                {col.items.length > 0 ? (
                  col.items.map((c) => (
                    <div
                      key={c.id}
                      role="button"
                      tabIndex={0}
                      style={{
                        padding: 12,
                        background: '#fff',
                        border: '1px solid rgba(0,0,0,0.08)',
                        borderRadius: 8,
                        cursor: 'pointer',
                        transition: 'all 0.15s',
                        boxShadow: '0 1px 3px rgba(0,0,0,0.02)',
                      }}
                      onClick={() => openDrawer(c.ref, 'details')}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                          e.preventDefault();
                          openDrawer(c.ref, 'details');
                        }
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                        <b style={{ color: 'var(--primary)', fontSize: 12.5 }}>{c.ref}</b>
                        <span className={`emp-channel-tag ${crChannelTone(c.channel)}`}>
                          {c.channel || 'مرئية'}
                        </span>
                      </div>
                      <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 4, lineHeight: 1.4 }}>
                        {c.subject}
                      </div>
                      <div style={{ fontSize: 11.5, color: 'var(--muted)', marginBottom: 6 }}>
                        العميل: {maskClient(c.client)}
                      </div>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderTop: '1px solid #f1f5f9', paddingTop: 6 }}>
                        <span style={{ fontSize: 11, color: 'var(--muted)' }}>
                          المستشار: <b>{c.lawyer}</b>
                        </span>
                        <Badge text={c.status} tone={c.tone} />
                      </div>
                    </div>
                  ))
                ) : (
                  <div style={{ textAlign: 'center', padding: '30px 10px', color: 'var(--muted)', fontSize: 12 }}>
                    لا توجد استشارات في هذه المرحلة
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* ── View C: الأجندة والتقويم الزمني (Calendar View) ── */}
      {viewMode === 'calendar' && (
        <div className="card">
          <div className="card-h">
            <h3>أجندة مواعيد وجلسات الاستشارات الميدانية</h3>
            <span className="sub">مرتبة زمنياً حسب توقيت الانعقاد ومتابعة المنسق</span>
          </div>
          <div className="card-b" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
            {agendaItems.length > 0 ? (
              agendaItems
                .map((c) => (
                  <div
                    key={c.id}
                    className="item"
                    style={{
                      borderLeft: c.session === 'جلسة جارية' ? '4px solid #1E9D6B' : '4px solid var(--primary)',
                      cursor: 'pointer',
                    }}
                    onClick={() => openDrawer(c.ref, 'scheduling')}
                  >
                    <div className="iico">
                      <Icon name={crChannelIcon(c.channel)} />
                    </div>
                    <div className="imeta">
                      <b>{c.when} · {c.ref}</b>
                      <span style={{ display: 'block', marginTop: 3 }}>
                        العميل: <b>{maskClient(c.client)}</b> · المستشار: <b>{c.lawyer}</b> · القناة: <b>{c.channel}</b>
                        {c.place ? ` · المكان: ${c.place}` : ''}
                      </span>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>
                        الموضوع: {c.subject}
                      </span>
                    </div>
                    <div className="iact">
                      <Badge text={c.session || 'بانتظار الجلسة'} tone={c.sessionTone} />
                      <button
                        className="btn soft sm"
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          openDrawer(c.ref, 'scheduling');
                        }}
                      >
                        <Icon name="out" /> تحكم
                      </button>
                    </div>
                  </div>
                ))
            ) : (
              <div className="empty">
                <Icon name="cal" />
                <b>لا توجد استشارات مجدولة بمواعيد محددة حالياً</b>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── View D: التحليلات وتوزيع الأحمال (Analytics View) ── */}
      {viewMode === 'analytics' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 300px), 1fr))', gap: 16 }}>
          {/* أحمال المستشارين والمحامين */}
          <div className="card" style={{ margin: 0 }}>
            <div className="card-h">
              <h3>أحمال المحامين والمستشارين</h3>
              <span className="sub">إجمالي الاستشارات المسندة لكل مستشار</span>
            </div>
            <div className="card-b">
              {assignedLawyersList.map((law) => {
                // **تتبع المرشّحات كبقيّة العروض.** كانت وحدها تقرأ `allItems`، فيُصفّي
                // الموظّف على تخصّصٍ أو قناة ثمّ يرى أحمالاً لا تصف ما أمامه.
                const count = filteredItems.filter((c) => c.lawyer === law).length;
                const active = filteredItems.filter((c) => c.lawyer === law && ! c.isTerminal).length;
                const pct = filteredItems.length > 0 ? Math.round((count / filteredItems.length) * 100) : 0;

                return (
                  <div key={law} style={{ marginBottom: 14 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 4 }}>
                      <b>{law}</b>
                      <span>
                        {count} استشارة ({active} نشطة)
                      </span>
                    </div>
                    <div style={{ width: '100%', height: 8, background: 'rgba(0,0,0,0.06)', borderRadius: 4, overflow: 'hidden' }}>
                      <div style={{ width: `${pct}%`, height: '100%', background: 'var(--primary)', borderRadius: 4 }} />
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          {/* توزيع القنوات الاستشارية */}
          <div className="card" style={{ margin: 0 }}>
            <div className="card-h">
              <h3>توزيع القنوات والاستخدام</h3>
              <span className="sub">مرئية مقابل حضورية وهاتفية</span>
            </div>
            <div className="card-b" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              {(['مرئية', 'حضورية', 'هاتفية'] as const).map((ch) => {
                const count = filteredItems.filter((c) => c.channel === ch).length;

                return (
                  <div
                    key={ch}
                    style={{
                      padding: 12,
                      border: '1px solid rgba(0,0,0,0.06)',
                      borderRadius: 8,
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                      <div className="iico"><Icon name={crChannelIcon(ch)} /></div>
                      <div>
                        <b>استشارة {ch}</b>
                        <div style={{ fontSize: 11.5, color: 'var(--muted)', marginTop: 2 }}>{count} استشارة مسجلة</div>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      )}

      {/* ── 5. درج التدخل والتحكم السريع المنبثق 360° (مطابق للإدارة العليا) ── */}
      {drawerConsult && typeof document !== 'undefined' && createPortal(
        <div
          className="c360-portal-backdrop"
          onClick={(e) => {
            if (e.target === e.currentTarget) closeDrawer();
          }}
          aria-modal="true"
          role="dialog"
        >
          <div
            className="c360-drawer-panel"
            onClick={(e) => e.stopPropagation()}
          >
            {/* رأس الدرج مع زر إغلاق صريح ومستقل */}
            <div
              className="c360-drawer-head"
              style={{
                padding: '16px 20px',
                borderBottom: '1px solid rgba(0,0,0,0.08)',
                background: 'rgba(14, 92, 156, 0.04)',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                gap: 10,
              }}
            >
              <div style={{ minWidth: 0, flex: 1 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                  <h3 style={{ margin: 0, color: 'var(--primary)', fontSize: 17 }}>{drawerConsult.ref}</h3>
                  <Badge text={`استشارة ${drawerConsult.channel}`} tone={crChannelTone(drawerConsult.channel)} />
                  <Badge text={drawerConsult.status} tone={drawerConsult.tone} />
                </div>
                <div style={{ fontSize: 12.5, color: 'var(--muted)', marginTop: 4, textOverflow: 'ellipsis', overflow: 'hidden', whiteSpace: 'nowrap' }}>
                  العميل: {maskClient(drawerConsult.client)}
                </div>
              </div>

              <button
                type="button"
                className="btn soft sm"
                onClick={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  closeDrawer();
                }}
                style={{
                  padding: '8px 16px',
                  fontSize: 13.5,
                  fontWeight: 700,
                  borderRadius: 8,
                  cursor: 'pointer',
                  flexShrink: 0,
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: 6,
                  background: 'rgba(0,0,0,0.06)',
                  border: '1px solid rgba(0,0,0,0.1)',
                  zIndex: 100,
                }}
                aria-label="إغلاق"
              >
                <span style={{ fontSize: 15, fontWeight: 900 }}>✕</span>
                <span>إغلاق</span>
              </button>
            </div>

            {/* ألسنة التبويب للدرج */}
            <div className="c360-drawer-tabs">
              {(
                [
                  ['details', 'التفاصيل والبيانات', 'doc'],
                  ['documents', 'تدقيق المستندات', 'upload'],
                  ['scheduling', 'الموعد وإسناد المحامي', 'scale'],
                  ['audit', 'سجل التدقيق', 'clock'],
                ] as const
              ).map(([tabKey, label, iconName]) => (
                <button
                  key={tabKey}
                  type="button"
                  onClick={(e) => {
                    setDrawerTab(tabKey);
                    // التبويب الجديد يبدأ من أعلاه — كان يُفتح في موضع تمرير السابق فيبدو مقصوصاً
                    drawerBodyRef.current?.scrollTo({ top: 0 });
                    // وعلى الهاتف يُمرَّر شريط التبويبات حتى يظهر المختار كاملاً
                    e.currentTarget.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                  }}
                  style={{
                    flex: 1,
                    padding: '12px 10px',
                    border: 'none',
                    background: 'none',
                    borderBottom: drawerTab === tabKey ? '3px solid var(--primary)' : '3px solid transparent',
                    color: drawerTab === tabKey ? 'var(--primary)' : 'var(--muted)',
                    fontWeight: drawerTab === tabKey ? 700 : 500,
                    fontSize: 12.5,
                    cursor: 'pointer',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: 6,
                    flexShrink: 0,
                  }}
                >
                  <Icon name={iconName} /> {label}
                </button>
              ))}
            </div>

            {/* محتوى لسان التبويب */}
            {/*
              * `minHeight: 0` ليس زينةً: العنصر المرن افتراضُه `min-height: auto`، فلا
              * ينكمش دون حجم محتواه مهما كتبتَ `overflow-y: auto`. فيتمدّد داخل درجٍ
              * ارتفاعُه `100vh` ويُقصّ ما زاد **بلا شريط تمرير** — فسجلُّ تدقيقٍ طويل
              * يُقرأ نصفُه ولا سبيل إلى بقيّته. قِيس ذلك على `CN-2026-7173`.
              */}
            <div ref={drawerBodyRef} className="c360-drawer-body" style={{ padding: 20, flex: 1, minHeight: 0, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 14 }}>
              {/* Tab 1: التفاصيل والبيانات */}
              {drawerTab === 'details' && (
                <>
                  {/* خطوةُ الاستلام اليدويّة أُزيلت بقرار المالك 2026-09-08. */}

                  {/*
                    **صفحة رحلة الاستشارة كانت يتيمة:** لا رابط إليها من أيّ شاشة
                    موظّف، فإجراءاتها (التحليل، حفظ التحليل، الاعتماد، تحويل القرارات
                    إلى مهامّ) مساراتها مفتوحة وصفحتها لا تُبلَغ إلّا بكتابة الرابط يدوياً.
                  */}
                  <Link
                    href={`/employee/consult?ref=${encodeURIComponent(drawerConsult.ref)}`}
                    className="btn soft sm"
                    style={{ alignSelf: 'flex-start' }}
                  >
                    <Icon name="out" /> فتح رحلة الاستشارة الكاملة (تحليل واعتماد)
                  </Link>

                  <div className="card" style={{ margin: 0, padding: 14 }}>
                    <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', marginBottom: 6 }}>
                      موضوع وتخصص الاستشارة
                    </div>
                    <div style={{ fontSize: 14, fontWeight: 600, lineHeight: 1.6 }}>{drawerConsult.subject}</div>
                    <div style={{ display: 'flex', gap: 8, marginTop: 10, flexWrap: 'wrap' }}>
                      <Badge text={drawerConsult.specialty || drawerConsult.type || 'عام'} tone="b-blue" />
                      <Badge text={`أولوية ${drawerConsult.priority || 'متوسطة'}`} tone="b-grey" />
                    </div>
                  </div>

                  <div className="card" style={{ margin: 0, padding: 14, display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: 12 }}>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>المستشار المسند:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerConsult.lawyer}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>الموظف المنسق:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerConsult.employee || 'بانتظار الاستلام'}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>موعد الجلسة:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerConsult.when || 'غير محدد'}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>المكان / القناة:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerConsult.place || drawerConsult.channel}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>هاتف العميل:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerConsult.phone || '—'}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>الموقف المالي (للعلم):</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2, color: drawerConsult.paid ? '#1E9D6B' : 'inherit' }}>
                        {drawerConsult.total ? `${drawerConsult.total} ر.س (${drawerConsult.paid ? 'مسددة' : 'معلقة'})` : 'غير مسعر'}
                      </div>
                    </div>
                  </div>

                  {/*
                    **كلٌّ باسمه.** كان الثلاثة ينهارون تحت عنوان «تفاصيل الوقائع
                    المدخلة»: ملخّصُ الجلسة (رأيٌ قانونيّ قد يكون محجوباً عن الموكّل)،
                    وتحليلُ النموذج الداخليّ، وجملةٌ مختلَقة عند غيابهما — فيُنسب إلى
                    العميل كلامٌ لم يقله، ويُقرأ رأيٌ غير معتمَد كأنّه واقعة.
                  */}
                  {drawerConsult.summary && (
                    <div className="card" style={{ margin: 0, padding: 14 }}>
                      <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', marginBottom: 8 }}>
                        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)' }}>ملخّص الجلسة:</div>
                        <SummaryStateBadge consult={drawerConsult} />
                      </div>
                      <div style={{ fontSize: 13, lineHeight: 1.8, whiteSpace: 'pre-wrap', color: '#334155' }}>
                        <RichText text={drawerConsult.summary} />
                      </div>
                    </div>
                  )}

                  {drawerConsult.aiSummary && (
                    <div className="card" style={{ margin: 0, padding: 14 }}>
                      <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', marginBottom: 6 }}>
                        تحليل الفريق القانوني (داخليّ — لا يصل الموكّل):
                      </div>
                      <div style={{ fontSize: 13, lineHeight: 1.8, whiteSpace: 'pre-wrap', color: '#334155' }}>
                        <RichText text={drawerConsult.aiSummary} />
                      </div>
                    </div>
                  )}
                </>
              )}

              {/* Tab 2: تدقيق المستندات */}
              {drawerTab === 'documents' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  <div className="card" style={{ margin: 0, padding: 16 }}>
                    <h4 style={{ margin: '0 0 10px', fontSize: 14, color: 'var(--primary)' }}>
                      📑 التدقيق الشكلي للمرفقات
                    </h4>
                    <p style={{ fontSize: 12.5, color: 'var(--muted)', margin: '0 0 12px' }}>
                      فحص المرفقات والتأكد من وضوح الصكوك والعقود قبل إحالتها للمحامي.
                    </p>

                    {drawerConsult.missing && drawerConsult.missing.length > 0 ? (
                      <div style={{ padding: '10px 14px', background: '#fffbeb', border: '1px solid #fef3c7', borderRadius: 8, color: '#b45309', fontSize: 13, marginBottom: 14 }}>
                        <b>النواقص المسجلة حالياً:</b>
                        <ul style={{ margin: '6px 0 0', paddingInlineStart: 20 }}>
                          {drawerConsult.missing.map((m, idx) => (
                            <li key={idx}>{m}</li>
                          ))}
                        </ul>
                      </div>
                    ) : (
                      <div style={{ padding: '10px 14px', background: '#f0fdf4', borderRadius: 8, color: '#15803d', fontSize: 13, marginBottom: 14 }}>
                        ✓ المستندات مستوفية ولا توجد نواقص مسجلة.
                      </div>
                    )}

                    {/*
                      * **لا يُدعى المستخدم إلى فعلٍ يُصدّ عنه.**
                      *
                      * `requestDocs` يمنع `CLOSED_STATUSES` على الخادم، فطلبُ مستندٍ على ملفٍّ
                      * منتهٍ يعود ٤٢٢ حتماً. وكان النموذج يُعرض كاملاً على استشارةٍ «منتهية».
                      */}
                    {isClosed ? (
                      <div style={{ borderTop: '1px solid rgba(0,0,0,0.06)', paddingTop: 14, fontSize: 12.5, color: 'var(--muted)' }}>
                        الملفّ مقفل — لا تُطلب مستنداتٌ بعد انتهاء الجلسة.
                      </div>
                    ) : (
                    <div style={{ borderTop: '1px solid rgba(0,0,0,0.06)', paddingTop: 14 }}>
                      <label style={{ display: 'block', fontSize: 12.5, fontWeight: 600, marginBottom: 6 }}>
                        طلب مستند إضافي من العميل:
                      </label>
                      <div style={{ display: 'flex', gap: 8 }}>
                        <input
                          type="text"
                          placeholder="مثال: يرجى إرفاق صورة العقد الموقع أو السجل التجاري..."
                          value={missingDocInput}
                          onChange={(e) => setMissingDocInput(e.target.value)}
                          style={{
                            flex: 1,
                            padding: '8px 12px',
                            borderRadius: 6,
                            border: '1px solid rgba(0,0,0,0.15)',
                            fontSize: 13,
                          }}
                        />
                        <button
                          type="button"
                          className="btn primary sm"
                          disabled={isProcessingAction || !missingDocInput.trim()}
                          onClick={() => handleRequestDocs(drawerConsult)}
                        >
                          إرسال إشعار
                        </button>
                      </div>
                    </div>
                    )}
                  </div>
                </div>
              )}

              {/* Tab 3: الموعد وإسناد المحامي */}
              {drawerTab === 'scheduling' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  <div className="card" style={{ margin: 0, padding: 16 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                      <h4 style={{ margin: 0, fontSize: 14, color: 'var(--primary)' }}>
                        🎥 موعد وقناة الانعقاد
                      </h4>
                      {/* الحكم من الخادم (`zoomSyncable`) — كان يظهر لكلّ مرئيّة والخادم يرفضه بلا اجتماعٍ أو بعد الاعتماد */}
                      {drawerConsult.zoomSyncable && (
                        <button
                          type="button"
                          className="btn soft sm"
                          disabled={isProcessingAction}
                          onClick={() => handleZoomSync(drawerConsult)}
                        >
                          <Icon name="video" /> تحديث بيانات Zoom
                        </button>
                      )}
                    </div>
                    <div style={{ fontSize: 13, color: '#334155' }}>
                      الموعد المحدد: <b>{drawerConsult.when || (drawerConsult.proposedWhen ? `مقترح: ${drawerConsult.proposedWhen} (بانتظار الاعتماد)` : 'لم يحدد موعد بعد')}</b>
                    </div>
                  </div>

                  <div className="card" style={{ margin: 0, padding: 16 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                      <b>إسناد المستشار القانوني المختص:</b>
                      <Badge
                        text={drawerConsult.lawyer ? `${drawerConsult.lawyerTentative ? 'المرشّح' : 'المسند'}: ${drawerConsult.lawyer}` : 'غير مسند'}
                        tone={drawerConsult.lawyerTentative ? 'b-amber' : 'b-blue'}
                      />
                    </div>
                    <p style={{ fontSize: 12, color: 'var(--muted)', margin: '0 0 10px' }}>
                      اختر المستشار القانوني المطابق للتخصص ثم اضغط تأكيد لتحديث الإسناد ومزامنة التذكرة المرتبطة.
                    </p>

                    {drawerConsult.assignBlocker && (
                      <div style={{ padding: '10px 14px', background: '#fffbeb', border: '1px solid #fef3c7', borderRadius: 8, color: '#b45309', fontSize: 12.5, marginBottom: 12 }}>
                        {drawerConsult.assignBlocker}
                      </div>
                    )}

                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                      <select
                        value={selectedLawyerId}
                        onChange={(e) => setSelectedLawyerId(e.target.value ? Number(e.target.value) : '')}
                        style={{
                          width: '100%',
                          padding: '10px 12px',
                          borderRadius: 8,
                          border: '1px solid rgba(0,0,0,0.15)',
                          fontSize: 13.5,
                          background: '#fff',
                        }}
                        disabled={isClosed || Boolean(drawerConsult.assignBlocker)}
                      >
                        <option value="">-- اختر مستشاراً قانونياً --</option>
                        {lawyersList.map((l) => (
                          <option key={l.id || l.name} value={l.id}>
                            {l.name} {l.dept !== '—' ? `(${l.dept})` : ''}
                          </option>
                        ))}
                      </select>

                      <button
                        type="button"
                        className="btn primary"
                        disabled={
                          isProcessingAction ||
                          !selectedLawyerId ||
                          // `refer` يمنع النهايات المُقفَلة على الخادم — فلا يُعرض الزرّ فاعلاً
                          isClosed ||
                          Boolean(drawerConsult.assignBlocker)
                        }
                        onClick={() => handleRefer(drawerConsult)}
                        style={{ padding: '9px 16px', fontSize: 13, justifyContent: 'center' }}
                      >
                        تأكيد الإسناد وإشعار العميل والمحامي
                      </button>
                      {isClosed && (
                        <p style={{ fontSize: 12, color: 'var(--muted)', margin: '8px 0 0' }}>
                          الملفّ مقفل — لا يُعاد الإسناد بعد انتهاء الجلسة أو إلغائها.
                        </p>
                      )}
                    </div>
                  </div>
                </div>
              )}

              {/* Tab 4: سجل التدقيق */}
              {drawerTab === 'audit' && (
                <div className="card" style={{ margin: 0, padding: 16 }}>
                  <h4 style={{ margin: '0 0 12px', fontSize: 14, color: 'var(--primary)' }}>
                    📜 سجل التدقيق الإداري (Audit Trail)
                  </h4>
                  {drawerConsult.audit && drawerConsult.audit.length > 0 ? (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                      {drawerConsult.audit.map((a, idx) => (
                        <div
                          key={idx}
                          style={{
                            padding: '10px 12px',
                            background: '#f8fafc',
                            borderRadius: 6,
                            border: '1px solid rgba(0,0,0,0.05)',
                          }}
                        >
                          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12 }}>
                            <b style={{ color: 'var(--primary)' }}>{a.field}</b>
                            <span style={{ color: 'var(--muted)' }}>{a.time}</span>
                          </div>
                          <div style={{ fontSize: 12.5, color: '#334155', marginTop: 4 }}>
                            بواسطة: <b>{a.user}</b> · {a.before} ➔ {a.after}
                          </div>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div style={{ color: 'var(--muted)', fontSize: 13, textAlign: 'center', padding: 20 }}>
                      لا توجد حركات مسجلة على هذه الاستشارة بعد.
                    </div>
                  )}
                </div>
              )}
            </div>

            {/* تذييل الدرج */}
            <div
              style={{
                padding: '12px 20px',
                borderTop: '1px solid rgba(0,0,0,0.08)',
                background: '#fafafa',
                display: 'flex',
                justifyContent: 'flex-end',
                alignItems: 'center',
              }}
            >
              <button
                type="button"
                className="btn soft"
                onClick={closeDrawer}
                style={{ padding: '8px 22px', fontSize: 13, fontWeight: 700, cursor: 'pointer' }}
              >
                إغلاق النافذة
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}
    </div>
  );
};

export default EmployeeConsults;
