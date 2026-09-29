import { router } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '@/components/babylon/Badge';
import Modal, { useBodyScrollLock, useEscapeLayer } from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { stageChanged, staffPatch } from '@/lib/consult-live';
import { CONFIRM_APPROVE_CONSULT_SUMMARY, CONFIRM_CANCEL_CONSULT_REQUEST, RichText, SummaryStateBadge } from '@/lib/consult-ui';
import type {ConsultCard, LawyerOpt} from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import { useSettings } from '@/lib/settings';
import {
  CONSULT_CHANNEL_OPTIONS,
  CONSULT_PRIORITIES,
  crChannelIcon,
  crChannelTone,
  maskClient,
} from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { consultMediaUrls, SessionMediaPanel } from '@/lib/recording-ui';
import { useServerAction } from '@/lib/use-server-action';

/**
 * سببُ تعذّر إعادة الإسناد — أو null إن كانت متاحة.
 *
 * يطابق حرفاً بحرفٍ حرّاس `Staff\ConsultController::refer` الثلاثة، فلا تُعرض دعوةٌ
 * إلى فعلٍ يردّه الخادم. و**دالّةٌ مُعلَنةٌ في نطاق الوحدة لا ثابتٌ داخل المكوّن**:
 * الثابتُ يُستعمل في `useMemo` أعلى تعريفه فيقع في المنطقة الميّتة (TDZ) وتبيضّ
 * الشاشة كلّها — وقع هذا فعلاً في شاشة استشارات الموظّف.
 */
function referBlockReason(c: ConsultCard | null): string | null {
    if (!c) {
        return null;
    }

    if (c.session === 'جلسة جارية') {
        return 'الجلسة منعقدة الآن — أنهِها قبل تغيير المستشار.';
    }

    if (c.isClosed) {
        return 'الاستشارة انتهت أو أُلغيت — لا تُحال إلى محامٍ.';
    }

    if (c.bookingStage != null) {
        return `ما زالت في دورة الحجز — حالتها «${c.status}». أكمل التسعير والسداد واختيار الموعد أوّلاً.`;
    }

    return null;
}

interface AdminConsultsProps {
  consults: ConsultCard[];
  preSessionRequests?: ConsultCard[];
  lawyers?: LawyerOpt[];
  /** السعر المقترح لكلّ قناة من الإعدادات (`Setting::consultPrices`). */
}

type ViewMode = 'table' | 'pipeline' | 'calendar' | 'analytics';
type CategoryFilter = 'all' | 'live' | 'pre_session' | 'in_flight' | 'completed' | 'late' | 'unassigned';
type DrawerTab = 'details' | 'actions' | 'ai_zoom' | 'audit';

/*
 * حدُّ التأخّر بالدقائق منذ الاستقبال — للطلبات المفتوحة وحدها. من إعدادات الخادم
 * (`consult_request_late_minutes`) لا منقوشاً: كانت هذه الشاشة تحمل 100 وجارتها «طلبات
 * الاستشارات» 120، فالطلب الواحد «متأخّر» هنا و«في الوقت» هناك.
 */

/**
 * **متأخّرٌ يعني قِيس فتجاوز الحدّ.**
 *
 * كان الشرط `(c.mins || 0) > 100` و`mins` عمودٌ **بلا كاتبٍ في المشروع كلّه** — فصفرٌ
 * أبداً: المؤشّر لا يرتفع، والتبويب يُفرغ الجدول، وكلاهما ميّتٌ بصمت. صار العمر
 * مقيساً من `created_at`، و`null` (لا سبيل إلى القياس) **لا يُعَدّ متأخّراً** بدل أن
 * يُقرأ صفراً — فغير المقيس ليس «في الوقت».
 */
/** وسمُ الملفّ المرفوع إلى الإدارة — يطابق `EscalateUnassignedTicketJob::SENIOR_LABEL`. */
const SENIOR_LABEL = 'الإدارة العليا';

/**
 * **ملفٌّ رُفع إليك لتوزّعه.**
 *
 * حين لا يتوفّر محامٍ في الوقت الذي اختاره العميل، لم يعد الحجز يُرفض: يُحجز الموعد
 * ويُسنَد الملفّ إلى أقدم إداريّ بوسم «الإدارة العليا» ليوزّعه. وبلا هذا المرشِّح يبقى
 * التصعيد **إشعاراً يمرّ** ثمّ يضيع الملفّ بين مئات الصفوف — والعميل قد سدّد وله موعد.
 */
function needsAssignment(c: ConsultCard): boolean {
  return (
    !c.isTerminal &&
    (c.lawyerId == null || c.lawyer === SENIOR_LABEL)
  );
}

function isLate(c: ConsultCard, lateAfterMins: number): boolean {
  return (
    c.ageMins != null &&
    c.ageMins > lateAfterMins &&
    c.status !== 'جاهزة للمحامي' && !c.isTerminal
  );
}

const CANCEL_REASONS = [
  'طلب العميل الإلغاء',
  'عدم توفر موعد مناسب أو تعذر التنسيق',
  'عدم سداد الرسوم أو انتهاء مهلة السداد',
  'بيانات الطلب غير مكتملة أو غير واضحة',
  'استشارة خارج اختصاص المكتب',
  'طلب مكرر أو أُرسل بالخطأ',
  'أخرى (توضيح في الملاحظات)',
];

type KanbanCol = 'pre_session' | 'scheduling' | 'review' | 'active_sessions' | 'completed';

/**
 * **عمودٌ واحدٌ لكلّ بطاقة — لا صفرٌ ولا اثنان.**
 *
 * كانت الأعمدة الخمسة مرشِّحاتٍ مستقلّة، فوقع عطلان متعاكسان:
 *
 * **ثقبٌ يبتلع.** `'محالة للمحامي'` و`'قيد الاستشارة'` ليستا في أيّ عمود: الثالث
 * يسرد حالات المراجعة دونهما، والرابع يشترط `startsAt` أو جلسةً جارية. فاستشارةٌ
 * أُحيلت إلى محامٍ ولم يُحدَّد لها موعدٌ بعد **تختفي من المسار كلّه** — وهي الحالة
 * التي يُفرد لها `refer` إشعاراً خاصّاً، أي أنّ النظام يعرفها ويعالجها ثمّ يُخفيها.
 *
 * **وتكرارٌ يضاعف.** العمود الرابع مبنيٌّ على `startsAt` لا على الحالة، فبطاقةٌ
 * «جاهزة للمحامي» لها موعدٌ تُعرض في الثالث والرابع معاً، ومجموع العدّادات يتجاوز
 * الإجمالي المكتوب فوق الشاشة.
 *
 * العلاجُ قسمةٌ صريحة بأولويّة: كلّ بطاقةٍ تُصنَّف مرّةً واحدة، والفرعُ الأخير جامعٌ
 * فلا تسقط بطاقةٌ مهما استُحدثت حالة.
 */
function kanbanColumnOf(c: ConsultCard): KanbanCol {
  if (c.isTerminal) {
    return 'completed';
  }

  if (c.session === 'جلسة جارية' || c.status === 'قيد الاستشارة') {
    return 'active_sessions';
  }

  // مرحلة الحجز من مفتاح الخادم (`bookingStage`) — كانت قائمتان نصّيّتان تُسقطان «بانتظار اعتماد
  // الموعد» فتقع الاستشارة في «المراجعة» بدل عمود الجدولة
  if (c.bookingStage === 'pricing' || c.bookingStage === 'payment') {
    return 'pre_session';
  }

  if (c.bookingStage === 'scheduling' || c.bookingStage === 'approval' || c.status === 'جديدة') {
    return 'scheduling';
  }

  // موعدٌ محدَّدٌ ولم تبدأ بعد ⇒ جلسةٌ قادمة، وإلّا فهي في المعالجة
  if (c.startsAt) {
    return 'active_sessions';
  }

  return 'review';
}

export const AdminConsults: React.FC<AdminConsultsProps> = ({
  consults: initialConsults = [],
  preSessionRequests: initialRequests = [],
  lawyers: initialLawyers = [],
}) => {
  const toast = useToast();
  const { consult_request_late_minutes: lateAfterMins } = useSettings();

  // State Management
  const [inFlightItems, setInFlightItems] = useState<ConsultCard[]>(initialConsults);
  const [requestItems, setRequestItems] = useState<ConsultCard[]>(initialRequests);

  // Active View & Filters
  const [viewMode, setViewMode] = useState<ViewMode>('table');
  const [categoryFilter, setCategoryFilter] = useState<CategoryFilter>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [channelFilter, setChannelFilter] = useState<string>('all');
  const [specialtyFilter, setSpecialtyFilter] = useState<string>('all');
  const [lawyerFilter, setLawyerFilter] = useState<string>('all');
  const [priorityFilter, setPriorityFilter] = useState<string>('all');
  const [cancelTarget, setCancelTarget] = useState<ConsultCard | null>(null);
  const [cancelReason, setCancelReason] = useState<string>('');
  const [cancelNotes, setCancelNotes] = useState<string>('');

  // Quick Action Drawer (Controlled by Ref String for rock-solid stability)
  const [drawerRef, setDrawerRef] = useState<string | null>(null);
  const [drawerTab, setDrawerTab] = useState<DrawerTab>('details');

  // Inline Controls State for 360° Drawer
  const [drawerLawyerId, setDrawerLawyerId] = useState<number | ''>('');
  const [drawerPrice, setDrawerPrice] = useState<string>('');
  // قفلٌ موحّد لأفعال الدرج والجداول (`useServerAction`) — `isProcessingAction` يعطّل أزرارها
  const { run, busy: isProcessingAction } = useServerAction();

  // Standalone Table Modals State
  const [pricingConsult, setPricingConsult] = useState<ConsultCard | null>(null);
  const [pricingChannel, setPricingChannel] = useState<string>('حضورية');
  const [inputPrice, setInputPrice] = useState<string>('');

  // **لا سعر مقترح** (قرار المالك 2026-09-29): المسعّر يكتب السعر لكلّ طلب؛ والخانة تبدأ بالسعر
  // المعتمد لطلبٍ سُعّر من قبل وحده — لا بما كُتب على الطلب قبل التسعير
  const pricedValue = (c: ConsultCard): string => (c.priced && c.price ? String(c.price) : '');

  // Realtime synchronization via Echo
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

  // Merge all consult items for 360° visibility
  const allItems = useMemo(() => {
    const map = new Map<number, ConsultCard>();
    inFlightItems.forEach((c) => map.set(c.id, c));
    requestItems.forEach((c) => map.set(c.id, c));

    return Array.from(map.values());
  }, [inFlightItems, requestItems]);

  // Get current active consult in the drawer
  const drawerConsult = useMemo(() => {
    if (!drawerRef) {
return null;
}

    return allItems.find((c) => c.ref === drawerRef) || null;
  }, [allItems, drawerRef]);

  // سببُ تعذّر إعادة الإسناد على الملفّ المفتوح — null إن كانت متاحة
  const referBlocked = referBlockReason(drawerConsult);

  // Comprehensive Lawyer List (From backend props + fallback from active database records)
  const lawyersList = useMemo(() => {
    if (initialLawyers && initialLawyers.length > 0) {
return initialLawyers;
}

    /*
      * **ولا قائمةَ احتياطيّة تُعمَّر بأسماءٍ لا تُسنِد.**
      *
      * كان الاحتياطيّ يبني الخيارات من أسماء المحامين المكتوبة في البطاقات ويمنح
      * كلاًّ منها `id: 0`. والإسناد يرسل `lawyer_id` ويُعطَّل زرُّه بلا محامٍ مختار
      * — و`0` قيمةٌ كاذبة. فالقائمة تمتلئ بأسماءٍ **يستحيل اختيار أيٍّ منها**، والمستخدم
      * ينقر الاسم ثمّ يجد الزرَّ معطَّلاً بلا سبب ظاهر.
      *
      * الفراغ أصدق: الشاشة تعرض «لا محامون متاحون» بدل قائمةٍ لا تعمل.
      */
    return [];
  }, [initialLawyers]);

  // قفل تمرير الصفحة عبر العدّاد المشترك مع Modal — «القيمة السابقة» كانت تجمّد الصفحة عند تراكب الطبقات
  useBodyScrollLock(!!drawerRef);

  // Escape يغلق الدرج — طبقةٌ في المكدّس المشترك (`useEscapeLayer`)، فنافذةٌ فوقه تُغلَق
  // وحدها. كان هنا فحصُ `.modal-bg.show` المحلّيّ، وصار عامّاً لكلّ الأدراج.
  useEscapeLayer(Boolean(drawerRef), () => setDrawerRef(null));

  // Synchronize inline drawer fields when drawerConsult changes
  useEffect(() => {
    if (drawerConsult) {
      const found = lawyersList.find((l) => l.name === drawerConsult.lawyer);
      setDrawerLawyerId(found ? found.id : '');
      setDrawerPrice(pricedValue(drawerConsult));
    }
  }, [drawerConsult, lawyersList]);

  // Telemetry & KPI Computations
  const telemetry = useMemo(() => {
    const total = allItems.length;
    const liveNow = allItems.filter((c) => c.session === 'جلسة جارية').length;
    // من الكتالوج المشترك لا من نسخةٍ مكتوبةٍ بيد — تُخالف عند أوّل تعديل
    const preSession = allItems.filter((c) => c.bookingStage != null).length;
    const needsPricing = allItems.filter((c) => c.needsPricing).length;
    const readyForLawyer = allItems.filter((c) => c.status === 'جاهزة للمحامي').length;
    const completed = allItems.filter((c) => c.isTerminal).length;
    const toCase = allItems.filter((c) => !!c.caseNo).length;
    const conversionRate = total > 0 ? Math.round((toCase / total) * 100) : 0;

    const late = allItems.filter((c) => isLate(c, lateAfterMins)).length;
    const unassigned = allItems.filter(needsAssignment).length;

    /*
     * **المحصَّل ما سُدِّد — لا ما انتهى.**
     *
     * كان الشرط `paid || TERMINAL`، و`TERMINAL` يضمّ **«ملغاة»** و**«لم يحضر»**. فطلبٌ
     * أُلغي وله تسعيرٌ سابق يُحتسب إيراداً محصَّلاً — و`cancelRequest` نفسه يُقرّ باحتمال
     * الإلغاء بعد السداد («سيتواصل معك المكتب بشأن الاسترداد»). **والصفّ نفسه يستوفي
     * شرط «المعلق»** فيُعرض مرّتين في البطاقة الواحدة: محصَّلاً ومعلَّقاً معاً.
     *
     * والمعيار الصادق في البطاقة: `paid` — وهو ما تستعمله «توزيع القنوات» في الشاشة
     * نفسها تحت العنوان نفسه، فكان رقمان لاسمٍ واحد.
     *
     * و«المعلَّق» يُقيَّد بما **سُعِّر فعلاً**: `total` وحده يشمل السعر المقترح الذي
     * يكتبه `ConsultBooking::request` قبل أن تُقرَّر الرسوم.
     */
    const paidRevenue = allItems
      .filter((c) => c.paid)
      .reduce((sum, c) => sum + (Number(c.total) || 0), 0);

    const pendingRevenue = allItems
      .filter((c) => !c.paid && c.priced && (Number(c.total) || 0) > 0)
      .reduce((sum, c) => sum + (Number(c.total) || 0), 0);

    return {
      total,
      liveNow,
      preSession,
      needsPricing,
      readyForLawyer,
      completed,
      toCase,
      conversionRate,
      late,
      paidRevenue,
      pendingRevenue,
      unassigned,
    };
  }, [allItems, lateAfterMins]);

  // Active live sessions for the Radar section
  const liveSessions = useMemo(() => {
    // **«المنعقدة الآن» تعني المنعقدة الآن.** كان الشرط يضمّ `startable` — وهي
    // «لم تبدأ بعدُ وموعدها قريب» — ثمّ تُوسَم كلّ بطاقةٍ «جلسة جارية» نصّاً مكتوباً.
    return allItems.filter((c) => c.session === 'جلسة جارية');
  }, [allItems]);

  // Unique lists for filter dropdowns
  const specialtiesList = useMemo(() => {
    const set = new Set<string>();
    allItems.forEach((c) => {
      if (c.specialty && c.specialty.trim() !== '') {
set.add(c.specialty.trim());
} else if (c.type && c.type.trim() !== '') {
set.add(c.type.trim());
}
    });

    return Array.from(set);
  }, [allItems]);

  const assignedLawyersList = useMemo(() => {
    const set = new Set<string>();
    allItems.forEach((c) => {
      if (c.lawyer && c.lawyer !== '—') {
set.add(c.lawyer.trim());
}
    });

    return Array.from(set);
  }, [allItems]);

  // Filtered consultations dataset
  const filteredItems = useMemo(() => {
    return allItems.filter((c) => {
      if (categoryFilter === 'live' && c.session !== 'جلسة جارية') {
return false;
}

      if (categoryFilter === 'pre_session' && c.bookingStage == null) {
return false;
}

      if (categoryFilter === 'in_flight' && (c.bookingStage != null || c.isTerminal)) {
return false;
}

      if (categoryFilter === 'completed' && !c.isTerminal) {
return false;
}

      if (categoryFilter === 'late' && !isLate(c, lateAfterMins)) {
return false;
}

      if (categoryFilter === 'unassigned' && !needsAssignment(c)) {
return false;
}

      if (channelFilter !== 'all' && c.channel !== channelFilter) {
return false;
}

      if (specialtyFilter !== 'all') {
        const sp = c.specialty || c.type;

        if (sp !== specialtyFilter) {
return false;
}
      }

      if (lawyerFilter !== 'all' && c.lawyer !== lawyerFilter) {
return false;
}

      if (priorityFilter !== 'all' && c.priority !== priorityFilter) {
return false;
}

      if (searchQuery.trim() !== '') {
        const q = searchQuery.toLowerCase().trim();
        const refMatch = (c.ref || '').toLowerCase().includes(q);
        const clientMatch = (c.client || '').toLowerCase().includes(q);
        const subjectMatch = (c.subject || '').toLowerCase().includes(q);
        const lawyerMatch = (c.lawyer || '').toLowerCase().includes(q);
        const phoneMatch = (c.phone || '').toLowerCase().includes(q);

        if (!refMatch && !clientMatch && !subjectMatch && !lawyerMatch && !phoneMatch) {
return false;
}
      }

      return true;
    });
  }, [allItems, categoryFilter, channelFilter, specialtyFilter, lawyerFilter, priorityFilter, searchQuery, lateAfterMins]);

  // Open & Close Drawer Actions
  const openDrawer = (ref: string) => {
    setDrawerRef(ref);
    setDrawerTab('details');
  };

  const closeDrawer = () => {
    setDrawerRef(null);
  };

  // Direct Drawer Actions Handlers
  const handleDrawerReassign = (consult: ConsultCard, lawyerId: number | '') => {
    if (!lawyerId) {
      toast('⚠️ الرجاء اختيار مستشار قانوني من القائمة');

      return;
    }

    void run(`/admin/consults/${consult.id}/refer`, {
      data: { lawyer_id: lawyerId },
      success: '✅ تم تحديث إسناد المحامي وإشعار العميل بنجاح',
      fallback: 'تعذر تغيير المحامي',
    });
  };

  const handleDrawerPricing = (consult: ConsultCard, priceStr: string) => {
    const priceNum = parseInt(priceStr, 10);

    // الحدُّ ريالٌ واحد لا صفر: فاتورةُ صفرٍ «مستحقّة» تقفل الحالة ولا تُسدَّد
    if (isNaN(priceNum) || priceNum < 1) {
      toast('⚠️ الحدّ الأدنى للتسعير ريال واحد');

      return;
    }

    void run(`/admin/consults/${consult.id}/price`, {
      data: { price: priceNum },
      success: '✅ تم تسعير الاستشارة وإصدار الفاتورة للعميل',
      fallback: 'تعذر تحديد السعر',
    });
  };

  // Table Modals Handlers
  const handleOpenPricingModal = (consult: ConsultCard) => {
    setPricingConsult(consult);
    setPricingChannel(consult.channel || 'حضورية');
    setInputPrice(pricedValue(consult));
  };

  const submitPricingModal = (e: React.FormEvent) => {
    e.preventDefault();

    if (!pricingConsult) {
      return;
    }

    const priceNum = parseInt(inputPrice, 10);

    if (isNaN(priceNum) || priceNum < 1) {
      toast('⚠️ الحدّ الأدنى للتسعير ريال واحد');

      return;
    }

    void run(`/admin/consults/${pricingConsult.id}/price`, {
      data: { price: priceNum, channel: pricingChannel },
      success: '✅ تم تسعير الاستشارة وإصدار الفاتورة للعميل',
      fallback: 'تعذر تحديد السعر',
      onSuccess: () => setPricingConsult(null),
    });
  };

  // التذكير يصل فريق المواعيد لا العميل (الحجز بيد الطاقم — قرار 2026-09-14)، ونصّ النجاح من الخادم
  const triggerRemindSchedule = (consult: ConsultCard) =>
    run(`/admin/consults/${consult.id}/remind-schedule`, { fallback: 'تعذر الإرسال' });

  // «بنجاح» حكمٌ لا تحمله الاستجابة: المتحكّم يعيد `back()` ولو سقط إلى الاحتياطيّ —
  // والنتيجة تُقرأ من البطاقة لا من التوست.
  const triggerRunAiAnalysis = (consult: ConsultCard) =>
    run(`/admin/consults/${consult.id}/analyze`, {
      success: 'انتهت المعالجة — راجع نتيجتها في بطاقة الاستشارة',
      fallback: 'تعذر تشغيل التحليل',
    });

  // `approveAnalysis` يكتب «جاهزة للمحامي» فقط — **والنقل فعلٌ آخر** (`refer`)
  const triggerApproveAiAnalysis = (consult: ConsultCard) =>
    run(`/admin/consults/${consult.id}/approve`, {
      success: '✅ اعتُمد التحليل — جاهزة للإحالة إلى محامٍ',
      fallback: 'تعذر اعتماد التحليل',
    });

  const triggerCancelRequest = (consult: ConsultCard) => {
    if (!cancelReason) {
      toast('⚠️ يُرجى اختيار سبب الإلغاء');

      return;
    }

    const finalReason = cancelReason === 'أخرى (توضيح في الملاحظات)'
      ? (cancelNotes.trim() || 'أخرى')
      : (cancelNotes.trim() ? `${cancelReason} — ${cancelNotes.trim()}` : cancelReason);

    // تأكيدٌ يقول الأثر قبل الإرسال (قرار المالك 2026-09-26) — الإلغاء لا يُتراجع عنه.
    // والخادم يرفض ما تجاوز مرحلة الحجز أو السبب الطويل — ورسالته تصل بدل صمتٍ.
    void run(`/admin/consults/${consult.id}/cancel-request`, {
      data: { reason: finalReason },
      confirm: CONFIRM_CANCEL_CONSULT_REQUEST,
      success: 'أُلغي الطلب وأُشعر العميل',
      fallback: 'تعذّر إلغاء الطلب',
      onSuccess: () => {
        setCancelTarget(null);
        setCancelReason('');
        setCancelNotes('');
        setDrawerRef(null);
      },
    });
  };

  /**
   * **ما بعد الجلسة: اعتمادُ الملخّص وتحويلُ القرارات مهامّ.**
   *
   * كان تبويب «الإجراءات» يخلو من كلّ فعلٍ على ملفٍّ منتهٍ — والإدارة هي صاحبة
   * الاعتماد: `CN-2026-4754` كانت واقفةً عند `summaryPending` ولا سبيل إلى اعتمادها
   * من هذه الشاشة. فبإخفاء أزرار دورة الحجز وحدها يبدو التبويب معطَّلاً لا مُحكَماً.
   */
  const triggerApproveSummary = (consult: ConsultCard) =>
    run(`/admin/consults/${consult.id}/summary/approve`, {
      confirm: CONFIRM_APPROVE_CONSULT_SUMMARY,
      success: '✅ اعتُمد الملخّص ووصل العميل، ونُشرت نتيجة التذكرة',
      fallback: 'تعذّر اعتماد الملخّص',
    });

  const triggerCreateTasks = (consult: ConsultCard) =>
    run(`/admin/consults/${consult.id}/tasks`, {
      success: '✅ أُنشئت المهامّ من قرارات الجلسة',
      fallback: 'تعذّر إنشاء المهامّ',
    });

  const triggerChangePriority = (consult: ConsultCard, priority: string) => {
    router.post(
      `/admin/consults/${consult.id}/priority`,
      { priority },
      {
        preserveScroll: true,
        onSuccess: () => toast(`تم تحديث الأولوية إلى «${priority}»`),
        onError: (err) => toast(`⚠️ ${Object.values(err)[0] || 'تعذر تغيير الأولوية'}`),
      }
    );
  };

  return (
    <div className="consults-360-root" style={{ paddingBottom: 60, width: '100%' }}>
      {/* ── CSS المخصص للاستجابة والشاشة الكاملة والأنيميشن ── */}
      <style>{`
        .consults-360-root {
          box-sizing: border-box;
          width: 100%;
        }

        /* الهيدر ومبدل طرق العرض */
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

        /* شبكة بطاقات الإحصائيات (KPI Ribbon) */
        .c360-kpi-grid {
          display: grid;
          grid-template-columns: repeat(6, 1fr);
          gap: 12px;
          margin: 16px 0 20px;
        }

        /* شريط فلترة المراحل */
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

        /* شبكة حقول البحث والقوائم المنسدلة */
        .c360-filter-grid {
          display: grid;
          grid-template-columns: 2fr repeat(4, 1fr);
          gap: 10px;
        }

        /* عروض الجدول: الديسكتوب مقابل كروت الموبايل */
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

        /* مسار الكانبان */
        .c360-kanban-grid {
          display: grid;
          grid-template-columns: repeat(5, 1fr);
          gap: 14px;
          align-items: start;
        }

        /* رادار الجلسات */
        .c360-radar-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
          gap: 12px;
        }

        /* أنيميشن الدرج المنبثق والخلفية الحرة على مستوى الشاشة */
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
          width: 100% !important;
          max-width: 580px !important;
          height: 100vh !important;
          height: 100dvh !important;
          background: #fff !important;
          box-shadow: -10px 0 35px rgba(0,0,0,0.35) !important;
          display: flex !important;
          flex-direction: column !important;
          box-sizing: border-box !important;
          animation: c360SlideInRight 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .c360-drawer-tabs {
          display: flex;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
          border-bottom: 1px solid rgba(0,0,0,0.08);
          background: #fafafa;
          white-space: nowrap;
        }


        /* ── استجابة الشاشات المتوسطة والتابلت (Max 1180px) ── */
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

        /* ── استجابة التابلت والموبايل (Max 768px) ── */
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
          .c360-filter-grid {
            grid-template-columns: 1fr;
          }

          /* تحويل الجدول إلى كروت لمس ذكية وتفاعلية على الشاشات الصغيرة */
          .c360-table-wrapper {
            display: none;
          }
          .c360-mobile-cards {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 12px;
          }
          .c360-radar-item {
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 10px !important;
          }
          .c360-radar-acts {
            align-items: stretch !important;
            width: 100% !important;
          }
          .c360-radar-acts .btn {
            width: 100% !important;
            justify-content: center !important;
          }
          .c360-drawer-panel {
            max-width: 100% !important;
          }
        }

        /* ── استجابة الشاشات الصغيرة جداً (Max 420px) ── */
        @media (max-width: 420px) {
          .c360-view-switcher {
            grid-template-columns: 1fr;
          }
          .c360-kpi-grid {
            grid-template-columns: 1fr;
          }
        }
      `}</style>

      {/* ── 1. الهيدر والترحيب ومبدل طرق العرض ── */}
      <div className="greet c360-header">
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0, fontSize: 'clamp(17px, 2.5vw, 22px)' }}>
            <Icon name="scale" cls="ic" />
            مركز قيادة الاستشارات 360° — الإدارة العليا
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--muted)', fontSize: 13 }}>
            الرؤية الشاملة لإدارة طلبات الاستشارات، المواعيد، الجلسات الحية، الإيرادات، وتوزيع الأحمال.
          </p>
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
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>كافة المراحل</div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #1E9D6B' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>إيرادات محصلة</span>
            <Icon name="card" />
          </div>
          <div style={{ fontSize: 'clamp(18px, 2.5vw, 22px)', fontWeight: 800, color: '#1E9D6B', marginTop: 4 }}>
            {telemetry.paidRevenue.toLocaleString()} <span style={{ fontSize: 11.5 }}>ر.س</span>
          </div>
          <div style={{ fontSize: 10.5, color: '#1E9D6B', marginTop: 2 }}>
            معلق: {telemetry.pendingRevenue.toLocaleString()} ر.س
          </div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #11A0C8' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>جلسات جارية / اليوم</span>
            <Icon name="video" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#11A0C8', marginTop: 4 }}>
            {telemetry.liveNow}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>انعقاد مرئي ومباشر</div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #C0832B' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>بانتظار التسعير والحجز</span>
            <Icon name="clock" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#C0832B', marginTop: 4 }}>
            {telemetry.preSession}
          </div>
          <div style={{ fontSize: 10.5, color: '#C0832B', marginTop: 2 }}>
            {telemetry.needsPricing} تحتاج تسعيراً فورياً
          </div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #0E5C9C' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>نسبة التحويل لقضايا</span>
            <Icon name="scale" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#0E5C9C', marginTop: 4 }}>
            {telemetry.conversionRate}%
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>
            {telemetry.toCase} استشارة أصبحت قضية
          </div>
        </div>

        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #C0392B' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>استشارات متأخرة</span>
            <Icon name="alert" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#C0392B', marginTop: 4 }}>
            {telemetry.late}
          </div>
          <div style={{ fontSize: 10.5, color: '#C0392B', marginTop: 2 }}>تجاوزت وقت المعالجة</div>
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
                  animation: 'pulse 1.5s infinite',
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
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <Badge text={c.session} tone={c.sessionTone} />
                    {/* ملفٌّ رُفع إلى الإدارة لتعذّر الإسناد التلقائيّ — يُعرَض لا يُترك لإشعارٍ يمرّ */}
                    {needsAssignment(c) && <Badge text="بانتظار إسناد مستشار" tone="b-amber" />}
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
                    className="btn primary sm"
                    type="button"
                    onClick={() => router.visit(`/admin/videoroom?ref=${encodeURIComponent(c.ref)}`)}
                  >
                    <Icon name="video" /> دخول الغرفة كمشرف
                  </button>
                  <button
                    className="btn soft sm"
                    type="button"
                    onClick={() => openDrawer(c.ref)}
                  >
                    <Icon name="info" /> تفاصيل وتفريغ
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ── 4. شريط الفلترة والبحث الذكي المتكيف ── */}
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
                ['pre_session', 'طلبات ما قبل الجلسة', telemetry.preSession],
                ['in_flight', 'قيد المعالجة', telemetry.total - telemetry.preSession - telemetry.completed],
                ['completed', 'منتهية ومغلقة', telemetry.completed],
                ['late', 'متأخرة', telemetry.late],
                ['unassigned', 'بانتظار إسناد مستشار', telemetry.unassigned],
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
                  }}
                >
                  {count}
                </span>
              </button>
            ))}
          </div>
        </div>

        {/* حقول البحث والقوائم المنسدلة */}
        <div className="c360-filter-grid">
          {/* حقل البحث */}
          <div style={{ position: 'relative' }}>
            <input
              type="text"
              placeholder="بحث بالمرجع، العميل، الموضوع..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              style={{
                width: '100%',
                padding: '9px 34px 9px 12px',
                borderRadius: 8,
                border: '1px solid rgba(0,0,0,0.15)',
                fontSize: 13,
                boxSizing: 'border-box',
              }}
            />
            <div style={{ position: 'absolute', right: 10, top: 10, pointerEvents: 'none', opacity: 0.5 }}>
              <Icon name="search" />
            </div>
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                style={{
                  position: 'absolute',
                  left: 8,
                  top: 9,
                  background: 'none',
                  border: 'none',
                  cursor: 'pointer',
                  fontSize: 12,
                  color: 'var(--muted)',
                }}
              >
                ✕
              </button>
            )}
          </div>

          {/* القناة */}
          <select
            value={channelFilter}
            onChange={(e) => setChannelFilter(e.target.value)}
            style={{ padding: '9px 12px', borderRadius: 8, border: '1px solid rgba(0,0,0,0.15)', fontSize: 13, width: '100%' }}
          >
            <option value="all">جميع القنوات</option>
            <option value="مرئية">مرئية (Zoom)</option>
            <option value="حضورية">حضورية (المكتب)</option>
            <option value="هاتفية">هاتفية</option>
          </select>

          {/* التخصص */}
          <select
            value={specialtyFilter}
            onChange={(e) => setSpecialtyFilter(e.target.value)}
            style={{ padding: '9px 12px', borderRadius: 8, border: '1px solid rgba(0,0,0,0.15)', fontSize: 13, width: '100%' }}
          >
            <option value="all">جميع التخصصات</option>
            {specialtiesList.map((sp) => (
              <option key={sp} value={sp}>
                {sp}
              </option>
            ))}
          </select>

          {/* المستشار */}
          <select
            value={lawyerFilter}
            onChange={(e) => setLawyerFilter(e.target.value)}
            style={{ padding: '9px 12px', borderRadius: 8, border: '1px solid rgba(0,0,0,0.15)', fontSize: 13, width: '100%' }}
          >
            <option value="all">جميع المستشارين</option>
            {assignedLawyersList.map((law) => (
              <option key={law} value={law}>
                {law}
              </option>
            ))}
          </select>

          {/* الأولوية */}
          <select
            value={priorityFilter}
            onChange={(e) => setPriorityFilter(e.target.value)}
            style={{ padding: '9px 12px', borderRadius: 8, border: '1px solid rgba(0,0,0,0.15)', fontSize: 13, width: '100%' }}
          >
            <option value="all">جميع الأولويات</option>
            {CONSULT_PRIORITIES.map((p) => (
              <option key={p} value={p}>{p}</option>
            ))}
          </select>
        </div>
      </div>

      {/* ── 5. طرق العرض (View Modes) ── */}

      {/* ── View A: الجدول الذكي التفاعلي ── */}
      {viewMode === 'table' && (
        <div className="card">
          <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <h3>قائمة الاستشارات</h3>
            <span className="sub">{filteredItems.length} استشارة مطابقة</span>
          </div>

          {/* 1) جدول الديسكتوب والتابلت العريض */}
          <div className="card-b c360-table-wrapper" style={{ padding: 0 }}>
            {filteredItems.length > 0 ? (
              <table className="c360-desktop-table">
                <thead>
                  <tr style={{ background: 'rgba(0,0,0,0.03)', borderBottom: '1px solid rgba(0,0,0,0.08)' }}>
                    <th style={{ padding: '12px 16px' }}>المرجع والعميل</th>
                    <th style={{ padding: '12px 14px' }}>القناة والتخصص</th>
                    <th style={{ padding: '12px 14px' }}>المستشار</th>
                    <th style={{ padding: '12px 14px' }}>الموعد والمكان</th>
                    <th style={{ padding: '12px 14px' }}>الرسوم والسداد</th>
                    <th style={{ padding: '12px 14px' }}>المرحلة والحالة</th>
                    <th style={{ padding: '12px 16px', textAlign: 'left' }}>إجراءات 360°</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredItems.map((c) => (
                    <tr
                      key={c.id}
                      style={{
                        borderBottom: '1px solid rgba(0,0,0,0.05)',
                        transition: 'background 0.15s',
                        cursor: 'pointer',
                      }}
                      onClick={() => openDrawer(c.ref)}
                      onMouseEnter={(e) => (e.currentTarget.style.background = 'rgba(14, 92, 156, 0.03)')}
                      onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                    >
                      {/* المرجع والعميل */}
                      <td style={{ padding: '12px 16px' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                          <div
                            style={{
                              width: 34,
                              height: 34,
                              borderRadius: 8,
                              background: 'rgba(14, 92, 156, 0.1)',
                              color: 'var(--primary)',
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'center',
                              fontWeight: 700,
                              flexShrink: 0,
                            }}
                          >
                            <Icon name={crChannelIcon(c.channel)} />
                          </div>
                          <div>
                            <b style={{ color: 'var(--primary)' }}>{c.ref}</b>
                            <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>
                              {maskClient(c.client)}
                            </div>
                          </div>
                        </div>
                      </td>

                      {/* القناة والتخصص */}
                      <td style={{ padding: '12px 14px' }}>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                          <Badge text={`استشارة ${c.channel}`} tone={crChannelTone(c.channel)} />
                          <span style={{ fontSize: 11.5, color: 'var(--muted)' }}>
                            {c.specialty || c.type}
                          </span>
                        </div>
                      </td>

                      {/* المستشار المسند */}
                      <td style={{ padding: '12px 14px' }}>
                        <b>{c.lawyer}</b>
                        {c.employee && c.employee !== '—' && (
                          <div style={{ fontSize: 11, color: 'var(--muted)' }}>
                            الموظف: {c.employee}
                          </div>
                        )}
                      </td>

                      {/* الموعد والمكان */}
                      <td style={{ padding: '12px 14px' }}>
                        <div style={{ fontSize: 12.5 }}>{c.when || 'لم يحدد بعد'}</div>
                        {c.place && (
                          <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>
                            {c.place}
                          </div>
                        )}
                      </td>

                      {/* الرسوم والسداد */}
                      <td style={{ padding: '12px 14px' }}>
                        {c.total ? (
                          <div>
                            <b style={{ color: c.paid ? '#1E9D6B' : 'inherit' }}>
                              {c.total} ر.س
                            </b>
                            <div style={{ fontSize: 11, color: c.paid ? '#1E9D6B' : '#C0832B' }}>
                              {c.paid ? 'مُسددة' : 'بانتظار السداد'}
                            </div>
                          </div>
                        ) : (
                          <span style={{ fontSize: 11.5, color: '#C0832B' }}>غير محدد</span>
                        )}
                      </td>

                      {/* المرحلة والحالة */}
                      <td style={{ padding: '12px 14px' }}>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 4, alignItems: 'flex-start' }}>
                          <Badge text={c.status} tone={c.tone} />
                          {c.session && c.session !== 'بانتظار الجلسة' && (
                            <Badge text={c.session} tone={c.sessionTone} />
                          )}
                        </div>
                      </td>

                      {/* أزرار الإجراءات السريعة */}
                      <td
                        style={{ padding: '12px 16px', textAlign: 'left' }}
                        onClick={(e) => e.stopPropagation()}
                      >
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                          {c.needsPricing && (
                            <button
                              className="btn primary sm"
                              type="button"
                              onClick={() => handleOpenPricingModal(c)}
                            >
                              تسعير
                            </button>
                          )}
                          {c.canRemindSchedule && (
                            <button
                              className="btn soft sm"
                              type="button"
                              onClick={() => triggerRemindSchedule(c)}
                            >
                              <Icon name="bell" /> تذكير
                            </button>
                          )}
                          {c.bookingStage != null && (
                            <button
                              className="btn soft sm"
                              type="button"
                              style={{ color: '#C0392B' }}
                              onClick={() => setCancelTarget(c)}
                              title="إلغاء الطلب"
                            >
                              <Icon name="close" />
                            </button>
                          )}
                          {c.session === 'جلسة جارية' && (
                            <button
                              className="btn primary sm"
                              type="button"
                              onClick={() => router.visit(`/admin/videoroom?ref=${encodeURIComponent(c.ref)}`)}
                            >
                              <Icon name="video" /> انضمام
                            </button>
                          )}
                          <button
                            className="btn soft sm"
                            type="button"
                            onClick={() => openDrawer(c.ref)}
                          >
                            <Icon name="out" /> تحكم 360°
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <div className="empty" style={{ padding: 40, textAlign: 'center' }}>
                <Icon name="folder" />
                <b style={{ display: 'block', marginTop: 8 }}>لا توجد استشارات مطابقة للفلتر المحدد</b>
              </div>
            )}
          </div>

          {/* 2) بطاقات الموبايل الذكية التفاعلية (Mobile View) */}
          <div className="c360-mobile-cards">
            {filteredItems.length > 0 ? (
              filteredItems.map((c) => (
                <div
                  key={c.id}
                  style={{
                    background: '#fff',
                    border: '1px solid rgba(0,0,0,0.08)',
                    borderRadius: 12,
                    padding: 14,
                    boxShadow: '0 2px 8px rgba(0,0,0,0.03)',
                    cursor: 'pointer',
                  }}
                  onClick={() => openDrawer(c.ref)}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <div
                        style={{
                          width: 32,
                          height: 32,
                          borderRadius: 8,
                          background: 'rgba(14, 92, 156, 0.1)',
                          color: 'var(--primary)',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          flexShrink: 0,
                        }}
                      >
                        <Icon name={crChannelIcon(c.channel)} />
                      </div>
                      <div>
                        <b style={{ color: 'var(--primary)', fontSize: 13.5 }}>{c.ref}</b>
                        <div style={{ fontSize: 12, color: 'var(--muted)' }}>{maskClient(c.client)}</div>
                      </div>
                    </div>
                    <Badge text={c.status} tone={c.tone} />
                  </div>

                  <div style={{ fontSize: 12.5, margin: '8px 0', lineHeight: 1.5, color: '#333' }}>
                    {c.subject}
                  </div>

                  <div
                    style={{
                      background: 'rgba(0,0,0,0.02)',
                      padding: '8px 10px',
                      borderRadius: 8,
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                      fontSize: 11.5,
                      margin: '8px 0',
                    }}
                  >
                    <span>مستشار: <b>{c.lawyer}</b></span>
                    <span>الموعد: <b>{c.when || 'غير محدد'}</b></span>
                  </div>

                  <div
                    style={{
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                      paddingTop: 8,
                      borderTop: '1px solid rgba(0,0,0,0.06)',
                    }}
                  >
                    <div>
                      {c.total ? (
                        <b style={{ color: c.paid ? '#1E9D6B' : '#C0832B', fontSize: 13 }}>
                          {c.total} ر.س ({c.paid ? 'مسددة' : 'معلقة'})
                        </b>
                      ) : (
                        <span style={{ fontSize: 11.5, color: '#C0832B' }}>غير مسعر</span>
                      )}
                    </div>
                    <button
                      className="btn soft sm"
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation();
                        openDrawer(c.ref);
                      }}
                      style={{ fontSize: 11.5, padding: '5px 10px' }}
                    >
                      <Icon name="out" /> فتح التحكم
                    </button>
                  </div>
                </div>
              ))
            ) : (
              <div className="empty" style={{ padding: 30, textAlign: 'center' }}>
                <Icon name="folder" />
                <b style={{ display: 'block', marginTop: 6 }}>لا توجد استشارات مطابقة</b>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── View B: مسار الكانبان والرحلة (Interactive Kanban Pipeline) ── */}
      {viewMode === 'pipeline' && (
        <div className="c360-kanban-grid">
          {(
            [
              { id: 'pre_session', title: '1. بانتظار التسعير والسداد', tone: '#C0832B' },
              { id: 'scheduling', title: '2. حجز الموعد وتعيين المحامي', tone: '#11A0C8' },
              { id: 'review', title: '3. قيد المعالجة والإحالة', tone: '#0E5C9C' },
              { id: 'active_sessions', title: '4. جلسات جارية وقادمة', tone: '#1E9D6B' },
              // كان العنوان «منتهية ومحولة لقضايا» و«محولة إلى قضية» حالةٌ لا يكتبها مسار
              { id: 'completed', title: '5. منتهية ومغلقة', tone: '#13314F' },
            ] as const
          ).map((c0) => ({ ...c0, items: filteredItems.filter((c) => kanbanColumnOf(c) === c0.id) })).map((col) => (
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
                      style={{
                        background: '#fff',
                        border: '1px solid rgba(0,0,0,0.08)',
                        borderRadius: 8,
                        padding: 12,
                        boxShadow: '0 2px 6px rgba(0,0,0,0.02)',
                        cursor: 'pointer',
                        transition: 'transform 0.15s, box-shadow 0.15s',
                      }}
                      onClick={() => openDrawer(c.ref)}
                      onMouseEnter={(e) => {
                        e.currentTarget.style.transform = 'translateY(-2px)';
                        e.currentTarget.style.boxShadow = '0 6px 14px rgba(0,0,0,0.08)';
                      }}
                      onMouseLeave={(e) => {
                        e.currentTarget.style.transform = 'translateY(0)';
                        e.currentTarget.style.boxShadow = '0 2px 6px rgba(0,0,0,0.02)';
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <b style={{ color: 'var(--primary)', fontSize: 13 }}>{c.ref}</b>
                        <Badge text={c.channel} tone={crChannelTone(c.channel)} />
                      </div>
                      <div style={{ fontSize: 12.5, fontWeight: 600, marginTop: 4 }}>
                        {maskClient(c.client)}
                      </div>
                      <div style={{ fontSize: 11.5, color: 'var(--muted)', marginTop: 2 }}>
                        {c.subject}
                      </div>
                      <div
                        style={{
                          marginTop: 8,
                          paddingTop: 8,
                          borderTop: '1px solid rgba(0,0,0,0.05)',
                          display: 'flex',
                          justifyContent: 'space-between',
                          alignItems: 'center',
                          fontSize: 11,
                        }}
                      >
                        <span>مستشار: {c.lawyer}</span>
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

      {/* ── View C: الأجندة والتقويم الزمني ── */}
      {viewMode === 'calendar' && (
        <div className="card">
          <div className="card-h">
            <h3>أجندة مواعيد وجلسات الاستشارات</h3>
            <span className="sub">مرتبة زمنياً حسب موعد الانعقاد</span>
          </div>
          <div className="card-b" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
            {filteredItems.filter((c) => c.when && c.when !== '—').length > 0 ? (
              filteredItems
                .filter((c) => c.when && c.when !== '—')
                /*
                 * **الفرز على `startsAt` لا على `when`.** كان العنوان يقول «مرتبة
                 * زمنياً حسب موعد الانعقاد» و`filteredItems` **يرشّح ولا يفرز** —
                 * فالترتيب ترتيبُ الاستعلام. و`when` نصٌّ («اليوم · 09:00») لا يُفرز،
                 * والبطاقة تحمل `startsAt` بصيغة ISO. وما لا موعد له يسقط للذيل.
                 */
                .slice()
                .sort((a, b) => {
                  if (!a.startsAt && !b.startsAt) {
                    return 0;
                  }

                  if (!a.startsAt) {
                    return 1;
                  }

                  if (!b.startsAt) {
                    return -1;
                  }

                  return new Date(a.startsAt).getTime() - new Date(b.startsAt).getTime();
                })
                .map((c) => (
                  <div
                    key={c.id}
                    className="item"
                    style={{
                      borderLeft: c.session === 'جلسة جارية' ? '4px solid #1E9D6B' : '4px solid var(--primary)',
                      cursor: 'pointer',
                    }}
                    onClick={() => openDrawer(c.ref)}
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
                          openDrawer(c.ref);
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

      {/* ── View D: التحليلات وتوزيع الأحمال ── */}
      {viewMode === 'analytics' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 300px), 1fr))', gap: 16 }}>
          {/* توزيع المستشارين وأحمالهم */}
          <div className="card" style={{ margin: 0 }}>
            <div className="card-h">
              <h3>أحمال المحامين والمستشارين</h3>
              <span className="sub">إجمالي الاستشارات المسندة</span>
            </div>
            <div className="card-b">
              {assignedLawyersList.map((law) => {
                const count = allItems.filter((c) => c.lawyer === law).length;
                const active = allItems.filter((c) => c.lawyer === law && !c.isTerminal).length;
                const pct = allItems.length > 0 ? Math.round((count / allItems.length) * 100) : 0;

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
                const count = allItems.filter((c) => c.channel === ch).length;
                const rev = allItems
                  .filter((c) => c.channel === ch && c.paid)
                  .reduce((sum, c) => sum + (Number(c.total) || 0), 0);

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
                        <div style={{ fontSize: 11.5, color: 'var(--muted)', marginTop: 2 }}>{count} استشارة</div>
                      </div>
                    </div>
                    <div style={{ textAlign: 'left' }}>
                      <b style={{ color: '#1E9D6B' }}>{rev.toLocaleString()} ر.س</b>
                      <div style={{ fontSize: 11, color: 'var(--muted)' }}>إيرادات محصلة</div>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          {/* سجل التدقيق العام */}
          <div className="card" style={{ margin: 0, gridColumn: '1 / -1' }}>
            <div className="card-h">
              <h3>سجل التدقيق الإداري (Audit Trail)</h3>
              <span className="sub">أحدث التعديلات والقرارات عبر جميع الاستشارات</span>
            </div>
            <div className="card-b">
              {allItems
                .flatMap((c) => c.audit.map((a) => ({ ref: c.ref, ...a })))
                // **«أحدث عشرة» كانت أوّل عشرة.** بلا فرزٍ يُقتطع من ترتيب الاستعلام
                // (`starts_at` ثمّ المعرّف) — فالمعروض سجلّ أوّل استشارةٍ أو اثنتين لا
                // أحدث ما جرى في المكتب.
                //
                // **والفرز على `at` لا على `time`:** الأخيرة نصٌّ بصيغة ١٢ ساعة
                // (`h:i` + ص/م) فلا تُفرز لفظياً — «١١:٠٠ ص» تسبق «٠١:٠٠ م» حرفياً
                // وهي بعدها زمنياً، فكان «الأحدث» مقلوباً. والقيود القديمة بلا `at`
                // تسقط إلى الذيل بدل أن تتصدّر بترتيبٍ عشوائيّ.
                .sort((a, b) => new Date(b.at ?? 0).getTime() - new Date(a.at ?? 0).getTime())
                .slice(0, 10)
                .map((a, idx) => (
                  <div key={idx} className="item">
                    <div className="iico"><Icon name="info" /></div>
                    <div className="imeta">
                      <b>{a.ref} · {a.field}</b>
                      <span style={{ display: 'block', marginTop: 2 }}>
                        بواسطة: {a.user} · ({a.before || '—'} ← {a.after || '—'})
                      </span>
                      <span style={{ color: 'var(--muted)', fontSize: 11 }}>{a.time}</span>
                    </div>
                  </div>
                ))}
            </div>
          </div>
        </div>
      )}

      {/* ── 6. درج التدخل والتحكم السريع المنبثق 360° (Slide-over Drawer عبر Portal طليق على مستوى الشاشة بالكامل) ── */}
      {drawerConsult && typeof document !== 'undefined' && createPortal(
        <div
          className="c360-portal-backdrop"
          onClick={(e) => {
            if (e.target === e.currentTarget) {
              closeDrawer();
            }
          }}
          aria-modal="true"
          role="dialog"
        >
          <div
            className="c360-drawer-panel"
            onClick={(e) => e.stopPropagation()}
          >
            {/* رأس الدرج مع زر إغلاق صريح وعالي الوضوح */}
            <div
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

              {/* زر الإغلاق المحسن والمستقل */}
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
                  pointerEvents: 'auto',
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
                  ['actions', 'التحكم والإجراءات', 'exec'],
                  ['ai_zoom', 'الذكاء و Zoom', 'video'],
                  ['audit', 'سجل التدقيق', 'clock'],
                ] as const
              ).map(([tabKey, label, iconName]) => (
                <button
                  key={tabKey}
                  type="button"
                  onClick={() => setDrawerTab(tabKey)}
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
            <div className="c360-drawer-body" style={{ padding: 20, flex: 1, minHeight: 0, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 14 }}>
              {/* Tab 1: التفاصيل والبيانات */}
              {drawerTab === 'details' && (
                <>
                  <div className="card" style={{ margin: 0, padding: 14 }}>
                    <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', marginBottom: 6 }}>
                      موضوع وتخصص الاستشارة
                    </div>
                    <div style={{ fontSize: 14, fontWeight: 600, lineHeight: 1.6 }}>{drawerConsult.subject}</div>
                    <div style={{ display: 'flex', gap: 8, marginTop: 10, flexWrap: 'wrap' }}>
                      <Badge text={drawerConsult.specialty || drawerConsult.type} tone="b-blue" />
                      <Badge text={`أولوية ${drawerConsult.priority}`} tone="b-grey" />
                    </div>
                  </div>

                  <div className="card" style={{ margin: 0, padding: 14, display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: 12 }}>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>المستشار المسند:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerConsult.lawyer}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>الموظف المنسق:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerConsult.employee}</div>
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
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>الفاتورة والرسوم:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2, color: drawerConsult.paid ? '#1E9D6B' : 'inherit' }}>
                        {drawerConsult.total ? `${drawerConsult.total} ر.س (${drawerConsult.paid ? 'مسددة' : 'معلقة'})` : 'غير مسعر'}
                      </div>
                    </div>
                  </div>

                  {/*
                    **«النهائي» كان يُقال عن نصٍّ محجوبٍ عن الموكّل.** `toCard()` يرسل
                    الملخّص بلا شرط اعتماد عمداً — بتعليقٍ صريح: «الطاقم يرى النصّ قبل
                    الاعتماد ليراجعه **ويرى أنّه** غير معتمَد». وهذه الشاشة كانت تُخفي
                    الشقّ الثاني، فتقرأ الإدارةُ رأياً قانونياً لم يعتمده أحدٌ تحت كلمة
                    «النهائي». والأداة موجودة في `consult-ui` وتستعملها بقيّة الشاشات.
                  */}
                  {drawerConsult.summary && (
                    <div className="card" style={{ margin: 0, padding: 14 }}>
                      <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', marginBottom: 8 }}>
                        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)' }}>
                          ملخّص الاستشارة:
                        </div>
                        <SummaryStateBadge consult={drawerConsult} />
                      </div>
                      <div style={{ fontSize: 13, lineHeight: 1.8, whiteSpace: 'pre-wrap' }}>
                        <RichText text={drawerConsult.summary} />
                      </div>
                    </div>
                  )}
                </>
              )}

              {/* Tab 2: التحكم والإجراءات الإدارية المباشرة */}
              {drawerTab === 'actions' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  {/*
                    * 1. إعادة إسناد المحامي — **مشروطةٌ بحرّاس `refer` الثلاثة نفسها**.
                    * كانت الكتلة بلا شرطٍ إطلاقاً، فتُعرض على استشارةٍ منتهيةٍ أو في
                    * دورة الحجز أو وجلستُها منعقدة — والخادم يردّ ٤٢٢ في الثلاث.
                    * (مقيسٌ حيّاً: اثنتان من ثلاث استشاراتٍ في القاعدة تردّان ٤٢٢.)
                    */}
                  {referBlocked ? (
                    <div className="card" style={{ margin: 0, padding: 16 }}>
                      <b>إعادة إسناد المستشار القانوني:</b>
                      <p style={{ fontSize: 12, color: 'var(--muted)', margin: '6px 0 0' }}>{referBlocked}</p>
                    </div>
                  ) : (
                  <div className="card" style={{ margin: 0, padding: 16 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                      <b>إعادة إسناد المستشار القانوني:</b>
                      <Badge text={drawerConsult.lawyer ? `المسند: ${drawerConsult.lawyer}` : 'غير مسند'} tone="b-blue" />
                    </div>
                    <p style={{ fontSize: 12, color: 'var(--muted)', margin: '0 0 10px' }}>
                      اختر المستشار المختص ثم اضغط تأكيد لتحديث الإسناد وإشعار العميل فورياً.
                    </p>

                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                      <select
                        value={drawerLawyerId}
                        onChange={(e) => setDrawerLawyerId(e.target.value ? Number(e.target.value) : '')}
                        style={{
                          width: '100%',
                          padding: '10px 12px',
                          borderRadius: 8,
                          border: '1px solid rgba(0,0,0,0.2)',
                          fontSize: 13.5,
                          boxSizing: 'border-box',
                        }}
                      >
                        <option value="">
                          {lawyersList.length === 0
                            ? '— لا محامون متاحون —'
                            : '-- اختر مستشاراً قانونياً من القائمة --'}
                        </option>
                        {lawyersList.map((l) => (
                          <option key={l.id || l.name} value={l.id}>
                            {l.name} ({l.dept})
                          </option>
                        ))}
                      </select>

                      <button
                        className="btn primary sm"
                        style={{ width: '100%', justifyContent: 'center', minHeight: 38 }}
                        type="button"
                        disabled={!drawerLawyerId || isProcessingAction}
                        onClick={() => handleDrawerReassign(drawerConsult, drawerLawyerId)}
                      >
                        <Icon name="user" /> {isProcessingAction ? 'جاري الإسناد...' : 'تأكيد تغيير المحامي وإشعار العميل'}
                      </button>
                    </div>
                  </div>
                  )}

                  {/* 2. التسعير المباشر وإصدار الفاتورة */}
                  {drawerConsult.needsPricing && (
                    <div className="card" style={{ margin: 0, padding: 16, borderRight: '4px solid #C0832B' }}>
                      <b>تسعير الاستشارة وإصدار الفاتورة:</b>
                      <p style={{ fontSize: 12, color: 'var(--muted)', margin: '4px 0 10px' }}>
                        أدخل السعر المطلوب لإصدار الفاتورة فوراً للعميل ليتمكن من السداد وحجز الموعد.
                      </p>
                      <div style={{ display: 'flex', gap: 8 }}>
                        <input
                          type="number"
                          min="1"
                          step="50"
                          value={drawerPrice}
                          onChange={(e) => setDrawerPrice(e.target.value)}
                          style={{
                            flex: 1,
                            padding: '9px 12px',
                            borderRadius: 8,
                            border: '1px solid rgba(0,0,0,0.2)',
                            fontSize: 15,
                            fontWeight: 700,
                            boxSizing: 'border-box',
                          }}
                          placeholder="السعر (ر.س)"
                        />
                        <button
                          className="btn primary sm"
                          type="button"
                          disabled={isProcessingAction}
                          onClick={() => handleDrawerPricing(drawerConsult, drawerPrice)}
                        >
                          <Icon name="card" /> {isProcessingAction ? 'جاري التسعير...' : 'إصدار وتأكيد'}
                        </button>
                      </div>
                    </div>
                  )}

                  {/* 3. تذكير الموعد */}
                  {drawerConsult.canRemindSchedule && (
                    <div className="card" style={{ margin: 0, padding: 14 }}>
                      <b>تذكير فريق المواعيد بحجز الموعد:</b>
                      <p style={{ fontSize: 12, color: 'var(--muted)', margin: '4px 0 10px' }}>
                        العميل سدّد الرسوم ولم يُحجز موعده بعد — الحجز بيد الطاقم، فيصل التذكير لمن يملك جدولة المواعيد.
                      </p>
                      <button
                        className="btn soft sm"
                        style={{ width: '100%', justifyContent: 'center' }}
                        type="button"
                        onClick={() => triggerRemindSchedule(drawerConsult)}
                      >
                        <Icon name="bell" /> تذكير فريق المواعيد
                      </button>
                    </div>
                  )}

                  {/*
                    * **زرّ الإلغاء — الفعلُ الوحيد الغائب.**
                    *
                    * كانت هذه الشاشة تعرض الطلبات وتُسعّرها وتُذكّرها ولا تُلغيها،
                    * فالمدير يرى طلباً معطَّلاً ولا سبيل له إلى إنهائه إلّا الانتقال
                    * إلى شاشة الطلبات. والمسار قائمٌ ومحروسٌ على الخادم.
                    */}
                  {drawerConsult.bookingStage != null && (
                    <div className="card" style={{ margin: 0, padding: 14, borderRight: '4px solid #C0392B' }}>
                      <b style={{ color: '#C0392B' }}>إلغاء طلب الاستشارة:</b>
                      <p style={{ fontSize: 12, color: 'var(--muted)', margin: '4px 0 10px' }}>
                        يُشعَر العميل بالإلغاء، وتُلغى الفاتورة القائمة أو يُنسَّق الاسترداد إن سُدِّدت مسبقاً.
                      </p>
                      <button
                        className="btn soft sm"
                        style={{ width: '100%', justifyContent: 'center', color: '#C0392B' }}
                        type="button"
                        disabled={isProcessingAction}
                        onClick={() => setCancelTarget(drawerConsult)}
                      >
                        <Icon name="close" /> إلغاء الطلب
                      </button>
                    </div>
                  )}

                  {/* 4. تعديل الأولوية */}
                  <div className="card" style={{ margin: 0, padding: 14 }}>
                    <b>تعديل درجة الأولوية:</b>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 80px), 1fr))', gap: 6, marginTop: 10 }}>
                      {CONSULT_PRIORITIES.map((p) => (
                        <button
                          key={p}
                          type="button"
                          className={`btn sm ${drawerConsult.priority === p ? 'primary' : 'soft'}`}
                          style={{ justifyContent: 'center', padding: '6px 4px', fontSize: 11.5 }}
                          onClick={() => triggerChangePriority(drawerConsult, p)}
                        >
                          {p}
                        </button>
                      ))}
                    </div>
                  </div>

                  {/* 5. تشغيل الذكاء الاصطناعي — لا يُعاد تحليل ملفٍّ انتهى (حارس `analyze`) */}
                  {!drawerConsult.isClosed && (
                  <div className="card" style={{ margin: 0, padding: 14 }}>
                    <b>تحليل الفريق القانوني الذكي:</b>
                    <p style={{ fontSize: 12, color: 'var(--muted)', margin: '4px 0 10px' }}>
                      تشغيل خوارزمية الذكاء الاصطناعي لتحليل الموضوع واقتراح التكييف والمحامي.
                    </p>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                      <button
                        className="btn soft sm"
                        style={{ flex: 1, justifyContent: 'center' }}
                        type="button"
                        onClick={() => triggerRunAiAnalysis(drawerConsult)}
                      >
                        <Icon name="sparkles" /> تشغيل التحليل
                      </button>
                      {drawerConsult.canApproveAnalysis && (
                        <button
                          className="btn primary sm"
                          style={{ flex: 1, justifyContent: 'center' }}
                          type="button"
                          onClick={() => triggerApproveAiAnalysis(drawerConsult)}
                        >
                          <Icon name="check" /> اعتماد التحليل
                        </button>
                      )}
                    </div>
                  </div>
                  )}

                  {/*
                    * 6. **ما بعد الجلسة.** يظهر حين تُختم الجلسة — وهو حيث تقع أفعال
                    * الإدارة الحقيقيّة على ملفٍّ منتهٍ. وكلُّ زرٍّ مشروطٌ بحارسه الخادميّ:
                    * `approveSummary` يردّ ٤٢٢ على المعتمد وعلى الفارغ، و`createTasks`
                    * يردّ ٤٠٩ على ما أُنشئت مهامُّه و٤٢٢ على ما لا قرارات له.
                    */}
                  {(drawerConsult.sessionEnded
                    || drawerConsult.isClosed) && (
                    <div className="card" style={{ margin: 0, padding: 14, borderRight: '4px solid #1E9D6B' }}>
                      <b>حصيلة الجلسة:</b>

                      <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 10 }}>
                        {/* الملخّص */}
                        {/* الزرّ بشروط الخادم (`canApproveSummary` ← `summaryApprovalBlocker`) —
                            كان يظهر لاستشارةٍ ملغاةٍ لها ملخّص فيُرفض لأنّ الجلسة لم تنعقد */}
                        {drawerConsult.summaryApproved ? (
                          <Badge text="✓ الملخّص معتمد ووصل العميل" tone="b-green" />
                        ) : drawerConsult.canApproveSummary ? (
                          <button
                            className="btn primary sm"
                            style={{ width: '100%', justifyContent: 'center' }}
                            type="button"
                            disabled={isProcessingAction}
                            onClick={() => triggerApproveSummary(drawerConsult)}
                          >
                            <Icon name="check" /> اعتماد الملخّص وإرساله للعميل
                          </button>
                        ) : (
                          <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                            {drawerConsult.summary
                              ? 'لم تنعقد هذه الجلسة — لا يُعتمد لها ملخّص جلسة.'
                              : 'لا ملخّص بعد — يُدوّنه المستشار في صفحة الاستشارة، ثمّ يُعتمد من هنا.'}
                          </span>
                        )}

                        {/* القرارات → مهامّ */}
                        {drawerConsult.tasksCreated ? (
                          <Badge text="✓ أُنشئت المهامّ من قرارات الجلسة" tone="b-green" />
                        ) : drawerConsult.decisions && drawerConsult.decisions.length > 0 ? (
                          <button
                            className="btn soft sm"
                            style={{ width: '100%', justifyContent: 'center' }}
                            type="button"
                            disabled={isProcessingAction}
                            onClick={() => triggerCreateTasks(drawerConsult)}
                          >
                            <Icon name="check" /> إنشاء المهامّ من القرارات ({drawerConsult.decisions.length})
                          </button>
                        ) : (
                          <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                            لا قرارات مستخرَجة من الجلسة — لا مهامّ تُنشأ.
                          </span>
                        )}
                      </div>
                    </div>
                  )}

                  {/* الانتقال للتفاصيل الكاملة */}
                  <button
                    className="btn soft"
                    style={{ width: '100%', justifyContent: 'center' }}
                    type="button"
                    onClick={() => router.visit(`/admin/consult?ref=${encodeURIComponent(drawerConsult.ref)}`)}
                  >
                    <Icon name="out" /> الانتقال لصفحة التفاصيل الكاملة
                  </button>
                </div>
              )}

              {/* Tab 3: الذكاء الاصطناعي و Zoom */}
              {drawerTab === 'ai_zoom' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  {drawerConsult.aiDone && (
                    <div className="card" style={{ margin: 0, padding: 14, background: 'rgba(14, 92, 156, 0.03)' }}>
                      <b style={{ color: 'var(--primary)' }}>تحليل الذكاء الاصطناعي المبدئي:</b>
                      <div style={{ marginTop: 6, fontSize: 13 }}>
                        التصنيف: <b>{drawerConsult.aiClass}</b> · المحامي المقترح: <b>{drawerConsult.aiLawyer}</b>
                      </div>
                      <div style={{ marginTop: 8, fontSize: 12.5, lineHeight: 1.7, color: '#333' }}>
                        <RichText text={drawerConsult.aiSummary} />
                      </div>
                    </div>
                  )}

                  {/* ملخّص Zoom AI — مادّة الجلسة كما وردت، بجوار الملخّص المعتمد لا بدلاً منه */}
                  {drawerConsult.zoomSummary && (
                    <div className="card" style={{ margin: 0, padding: 14 }}>
                      <b>🤖 ملخّص Zoom AI:</b>
                      <div style={{ marginTop: 8, fontSize: 12.5, lineHeight: 1.7, color: '#333' }}>
                        <RichText text={drawerConsult.zoomSummary} />
                      </div>
                    </div>
                  )}

                  {/* سجلّ الحضور من Zoom — وما لم يُسجَّل لا يُعرض */}
                  {drawerConsult.zoomParticipantsLog && drawerConsult.zoomParticipantsLog.length > 0 && (
                    <div className="card" style={{ margin: 0, padding: 14 }}>
                      <b>سجلّ حضور الجلسة:</b>
                      <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 6 }}>
                        {drawerConsult.zoomParticipantsLog.map((a, i) => (
                          <div
                            key={i}
                            style={{
                              display: 'flex', justifyContent: 'space-between', gap: 8,
                              fontSize: 12, padding: '6px 8px',
                              border: '1px solid rgba(0,0,0,0.06)', borderRadius: 6,
                            }}
                          >
                            <b>{a.name || '—'}</b>
                            <span style={{ color: 'var(--muted)', direction: 'ltr' }}>
                              {a.join_time ? `${a.join_time}${a.leave_time ? ` ← ${a.leave_time}` : ''}` : 'لم يدخل'}
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}

                  {/* خطوات Zoom AI المقترحة — اقتراحاتٌ لا التزامات */}
                  {drawerConsult.zoomAiNextSteps && drawerConsult.zoomAiNextSteps.length > 0 && (
                    <div className="card" style={{ margin: 0, padding: 14 }}>
                      <b>خطوات مقترَحة من Zoom AI:</b>
                      <p style={{ fontSize: 11.5, color: 'var(--muted)', margin: '4px 0 0' }}>
                        اقتراحاتُ نموذج — لا تُنشئ التزاماً حتى تعتمدها الإدارة كمهامّ.
                      </p>
                      <ul style={{ margin: '8px 0 0', paddingRight: 20, fontSize: 12.5, lineHeight: 1.8 }}>
                        {drawerConsult.zoomAiNextSteps.map((st, i) => <li key={i}>{st}</li>)}
                      </ul>
                    </div>
                  )}

                  {/* مخرجات Zoom */}
                  <div className="card" style={{ margin: 0, padding: 14 }}>
                    <b>مخرجات جلسة Zoom:</b>
                    {/* تشغيلٌ وتنزيلٌ عبر الخادم — كانا زرّين يفتحان سحابة Zoom خارج النظام */}
                    <div style={{ marginTop: 10 }}>
                      {drawerConsult.media ? (
                        <SessionMediaPanel media={drawerConsult.media} urls={consultMediaUrls('/admin', drawerConsult.id)} />
                      ) : (
                        <span style={{ fontSize: 12, color: 'var(--muted)' }}>لا يوجد تسجيل لهذه الجلسة بعد</span>
                      )}
                    </div>
                  </div>

                  {/* القرارات المستخرجة */}
                  {drawerConsult.decisions && drawerConsult.decisions.length > 0 && (
                    <div className="card" style={{ margin: 0, padding: 14 }}>
                      <b>القرارات والمهام المستخرجة:</b>
                      <ul style={{ margin: '8px 0 0', paddingRight: 20, fontSize: 12.5, lineHeight: 1.8 }}>
                        {drawerConsult.decisions.map((dec, idx) => (
                          <li key={idx}>{typeof dec === 'string' ? dec : JSON.stringify(dec)}</li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              )}

              {/* Tab 4: سجل التدقيق الزمني */}
              {drawerTab === 'audit' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                  {drawerConsult.audit && drawerConsult.audit.length > 0 ? (
                    drawerConsult.audit.map((a, i) => (
                      <div
                        key={i}
                        style={{
                          padding: 10,
                          border: '1px solid rgba(0,0,0,0.06)',
                          borderRadius: 8,
                          fontSize: 12,
                        }}
                      >
                        <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 600, flexWrap: 'wrap', gap: 4 }}>
                          <span>{a.field}</span>
                          <span style={{ color: 'var(--muted)', fontSize: 11 }}>{a.time}</span>
                        </div>
                        <div style={{ marginTop: 4, color: 'var(--muted)' }}>
                          بواسطة: <b>{a.user}</b>
                        </div>
                        <div style={{ marginTop: 2 }}>
                          {a.before || '—'} ← <b>{a.after || '—'}</b>
                        </div>
                      </div>
                    ))
                  ) : (
                    <div style={{ textAlign: 'center', color: 'var(--muted)', padding: 30 }}>
                      لا توجد سجلات تدقيق مسجلة بعد
                    </div>
                  )}
                </div>
              )}
            </div>

            {/* ذيل الدرج وزر إغلاق إضافي مريح */}
            <div style={{ padding: '12px 20px', borderTop: '1px solid rgba(0,0,0,0.08)', background: '#fafafa', display: 'flex', justifyContent: 'flex-end' }}>
              <button
                type="button"
                className="btn soft sm"
                onClick={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  closeDrawer();
                }}
                style={{ padding: '8px 22px', fontSize: 13, fontWeight: 700, cursor: 'pointer' }}
              >
                إغلاق النافذة
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* ── 7. نافذة التسعير المنبثقة من الجدول ── */}
      {pricingConsult && (
        <Modal
          title={`تسعير الاستشارة — ${pricingConsult.ref}`}
          open={!!pricingConsult}
          onClose={() => setPricingConsult(null)}
        >
          <form onSubmit={submitPricingModal} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <p style={{ fontSize: 13, color: 'var(--muted)', margin: 0 }}>
              العميل: <b>{maskClient(pricingConsult.client)}</b>
            </p>

            {/* منتقى قناة الاستشارة */}
            <div>
              <label style={{ display: 'block', fontSize: 12.5, fontWeight: 600, marginBottom: 6 }}>
                قناة الاستشارة:
              </label>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 110px), 1fr))', gap: 8 }}>
                {CONSULT_CHANNEL_OPTIONS.map((channel) => {
                  const isSelected = pricingChannel === channel;
                  return (
                    <button
                      key={channel}
                      type="button"
                      onClick={() => setPricingChannel(channel)}
                      style={{
                        padding: '8px',
                        borderRadius: 8,
                        border: isSelected ? '2px solid var(--primary)' : '1px solid rgba(0,0,0,0.15)',
                        background: isSelected ? 'rgba(14, 92, 156, 0.08)' : '#fff',
                        color: isSelected ? 'var(--primary)' : 'inherit',
                        fontWeight: 700,
                        fontSize: 12.5,
                        cursor: 'pointer',
                        transition: 'all 0.15s',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        gap: 6,
                      }}
                    >
                      <Icon name={crChannelIcon(channel)} />
                      <span>{channel}</span>
                    </button>
                  );
                })}
              </div>
            </div>

            <div>
              <label style={{ display: 'block', fontSize: 12.5, fontWeight: 600, marginBottom: 6 }}>
                سعر الاستشارة (ريال سعودي غير شامل الضريبة):
              </label>
              <input
                type="number"
                min="1"
                step="50"
                value={inputPrice}
                onChange={(e) => setInputPrice(e.target.value)}
                style={{
                  width: '100%',
                  padding: '10px 12px',
                  borderRadius: 8,
                  border: '1px solid rgba(0,0,0,0.2)',
                  fontSize: 16,
                  fontWeight: 700,
                  boxSizing: 'border-box',
                }}
                required
              />
            </div>
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
              <button type="button" className="btn soft" onClick={() => setPricingConsult(null)}>
                إلغاء
              </button>
              <button type="submit" className="btn primary">
                إصدار الفاتورة وتأكيد السعر
              </button>
            </div>
          </form>
        </Modal>
      )}

      {/* ── 8. تأكيد إلغاء الطلب مع تسجيل السبب ── */}
      <Modal
        title={`تأكيد إلغاء الطلب — ${cancelTarget?.ref ?? ''}`}
        open={!!cancelTarget}
        onClose={() => {
          setCancelTarget(null);
          setCancelReason('');
          setCancelNotes('');
        }}
      >
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <p style={{ fontSize: 13.5, lineHeight: 1.6, margin: 0 }}>
            هل أنت متأكد من إلغاء طلب الاستشارة <b>{cancelTarget?.ref}</b> للعميل <b>{maskClient(cancelTarget?.client ?? '')}</b>؟
          </p>

          <div style={{ background: '#FDF2E9', border: '1px solid #FADBD8', borderRadius: 8, padding: '10px 12px', fontSize: 12, color: '#78281F', lineHeight: 1.5 }}>
            ⚠️ سيتم إشعار العميل بالإلغاء فوراً، وإلغاء الفواتير غير المسددة، وتوثيق سبب الإلغاء في سجل التدقيق. وفي حال وجود مبالغ محصلة يتم التنسيق للاسترداد.
          </div>

          <div>
            <label style={{ display: 'block', fontSize: 12.5, fontWeight: 600, marginBottom: 6 }}>
              سبب الإلغاء: <span style={{ color: '#C0392B' }}>*</span>
            </label>
            <select
              className="form-control"
              style={{ width: '100%', fontSize: 13, padding: '8px 10px', borderRadius: 6, border: '1px solid var(--border)' }}
              value={cancelReason}
              onChange={(e) => setCancelReason(e.target.value)}
            >
              <option value="">— اختر سبب الإلغاء —</option>
              {CANCEL_REASONS.map((r) => (
                <option key={r} value={r}>{r}</option>
              ))}
            </select>
          </div>

          <div>
            <label style={{ display: 'block', fontSize: 12.5, fontWeight: 600, marginBottom: 6 }}>
              ملاحظات إضافية / تفاصيل (اختياري):
            </label>
            <textarea
              className="form-control"
              rows={2}
              style={{ width: '100%', fontSize: 12.5, padding: '8px 10px', borderRadius: 6, border: '1px solid var(--border)', resize: 'vertical' }}
              placeholder="اكتب تفاصيل إضافية لتوضيح السبب في سجل التدقيق والإشعار..."
              value={cancelNotes}
              maxLength={400} // السبب المختار + الملاحظة لا يتجاوزان حدّ الخادم (500)
              onChange={(e) => setCancelNotes(e.target.value)}
            />
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 8 }}>
            <button
              type="button"
              className="btn soft"
              disabled={isProcessingAction}
              onClick={() => {
                setCancelTarget(null);
                setCancelReason('');
                setCancelNotes('');
              }}
            >
              تراجع
            </button>
            <button
              type="button"
              className="btn primary"
              style={{ background: '#C0392B', borderColor: '#C0392B' }}
              disabled={isProcessingAction || !cancelReason}
              onClick={() => cancelTarget && triggerCancelRequest(cancelTarget)}
            >
              {isProcessingAction ? 'جارٍ الإلغاء...' : 'تأكيد الإلغاء'}
            </button>
          </div>
        </div>
      </Modal>

      {/*
        * **حُذفت نافذة الملخّص اليتيمة.**
        *
        * `summaryConsult` لم يكن يُسنَد إلّا `null` — لا مستدعيَ واحداً يفتحها. فالنافذة
        * مركَّبةٌ في الشجرة ولا تظهر أبداً، والملخّص يُعرض أصلاً داخل الدرج بشارة حالته.
        */}
    </div>
  );
};

export default AdminConsults;
