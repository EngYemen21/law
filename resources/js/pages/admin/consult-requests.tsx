import { router } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '@/components/babylon/Badge';
import Modal, { useBodyScrollLock } from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { maskClient } from '@/lib/admin-data';
import type {ConsultCard} from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import { CONSULT_BOOKING_STATUSES, crChannelIcon, crChannelTone, cTone } from '@/lib/employee-data';
import Icon from '@/lib/icons';

interface AdminConsultRequestsProps {
  consults: ConsultCard[];
  /** نسبة الضريبة من إعدادات المكتب — كانت مصلَّبة 0.15 في الحاسبة. */
  vatRate?: number;
  /** الأسعار المعتمدة لكلّ قناة — بديل «الباقات المعياريّة» المكتوبة بيد. */
  suggestedPrices?: Record<string, number>;
}

type ViewMode = 'pipeline' | 'table';
type CategoryFilter = 'all' | 'pricing' | 'payment' | 'scheduling' | 'late';

/** حدُّ التأخّر بالدقائق منذ الاستقبال — ساعتان. */
const LATE_AFTER_MINS = 120;
type DrawerTab = 'pricing' | 'details' | 'actions' | 'audit';

/*
 * **خريطة النغمات المحلّيّة أُزيلت.** كانت تعطي «بانتظار السداد» أزرقَ و«بانتظار تحديد
 * الموعد» سماويّاً، بينما `cTone` المشترك يعطيهما كهرمانيّاً — فالطلب الواحد يُعرض
 * بلونين بين هذه الشاشة وشاشة «إدارة الاستشارات» التي تعرض الطلبات نفسها.
 *
 * و`PRE_SESSION_STATUSES` كانت **نسخةً ثالثة** يدويّة من قائمةٍ يحملها النموذج
 * (`Consult::PRE_SESSION_STATUSES`) ويصدّرها `employee-data` — فأوّل تعديلٍ خادميّ
 * يُفرّقها بصمت وتختفي طلباتٌ ماليّة من الشاشة بلا رسالة.
 */
export const AdminConsultRequests: React.FC<AdminConsultRequestsProps> = ({
  consults: initialConsults = [],
  vatRate = 15,
  suggestedPrices = {},
}) => {
  const toast = useToast();

  // State Management
  const [items, setItems] = useState<ConsultCard[]>(initialConsults);
  const [viewMode, setViewMode] = useState<ViewMode>('pipeline');
  const [categoryFilter, setCategoryFilter] = useState<CategoryFilter>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [channelFilter, setChannelFilter] = useState<string>('all');
  const [specialtyFilter, setSpecialtyFilter] = useState<string>('all');
  const [sortBy, setSortBy] = useState<'latest' | 'oldest' | 'price_desc' | 'price_asc'>('latest');

  // Quick Action Drawer (Controlled by Ref String)
  const [drawerRef, setDrawerRef] = useState<string | null>(null);
  const [drawerTab, setDrawerTab] = useState<DrawerTab>('pricing');

  // Inline Drawer Pricing Input & Action State
  const [inputPrice, setInputPrice] = useState<string>('600');
  const [isProcessing, setIsProcessingAction] = useState(false);

  // Fast Pricing Modal (for Table view quick clicks)
  const [pricingModalConsult, setPricingModalConsult] = useState<ConsultCard | null>(null);
  const [modalPrice, setModalPrice] = useState<string>('600');

  // Cancel Confirmation Modal State
  const [cancelTargetConsult, setCancelTargetConsult] = useState<ConsultCard | null>(null);

  // Realtime synchronization via Echo
  useEffect(() => {
    setItems(initialConsults);

    initialConsults.forEach((c) => {
      echo.private(`consult.${c.id}`).listen(
        '.status',
        (e: {
          status?: string;
          price?: number;
          vat?: number;
          total?: number;
          priced?: boolean;
          paid?: boolean;
          invoiceNo?: string | null;
          when?: string | null;
        }) => {
          setItems((prev) =>
            prev.map((x) =>
              x.id === c.id
                ? {
                    ...x,
                    status: e.status ?? x.status,
                    price: e.price ?? x.price,
                    vat: e.vat ?? x.vat,
                    total: e.total ?? x.total,
                    priced: e.priced ?? x.priced,
                    paid: e.paid ?? x.paid,
                    invoiceNo: e.invoiceNo ?? x.invoiceNo,
                    when: e.when ?? x.when,
                  }
                : x
            )
          );
        }
      );
    });

    return () => {
      initialConsults.forEach((c) => echo.leave(`consult.${c.id}`));
    };
  }, [initialConsults]);

  // Live active pre-session intake requests
  const liveItems = useMemo(() => {
    return items.filter((c) => CONSULT_BOOKING_STATUSES.includes(c.status));
  }, [items]);

  // Currently active consult in the slide-over drawer
  const drawerConsult = useMemo(() => {
    if (!drawerRef) {
return null;
}

    return liveItems.find((c) => c.ref === drawerRef) || null;
  }, [liveItems, drawerRef]);

  // قفل تمرير الصفحة عبر العدّاد المشترك مع Modal — «القيمة السابقة» كانت تجمّد الصفحة عند تراكب الطبقات
  useBodyScrollLock(!!drawerRef);

  // Escape يغلق الدرج
  useEffect(() => {
    if (!drawerRef) {
return;
}

    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setDrawerRef(null);
      }
    };
    document.addEventListener('keydown', onKey);

    return () => document.removeEventListener('keydown', onKey);
  }, [drawerRef]);

  // Synchronize inline drawer fields when drawerConsult changes
  useEffect(() => {
    if (drawerConsult) {
      setInputPrice(String(drawerConsult.price || 600));
    }
  }, [drawerConsult]);

  // Open & Close Drawer Actions
  const openDrawer = (ref: string, initialTab: DrawerTab = 'pricing') => {
    setDrawerRef(ref);
    setDrawerTab(initialTab);
  };

  const closeDrawer = () => {
    setDrawerRef(null);
  };

  // Telemetry & KPI Computations
  const telemetry = useMemo(() => {
    const total = liveItems.length;
    const pendingPricing = liveItems.filter((c) => c.status === 'بانتظار التسعير').length;
    const awaitingPayment = liveItems.filter((c) => c.status === 'بانتظار السداد').length;
    const awaitingSchedule = liveItems.filter((c) => c.status === 'بانتظار تحديد الموعد').length;

    // Financial volume calculations
    const pendingPaymentAmount = liveItems
      .filter((c) => c.status === 'بانتظار السداد')
      .reduce((sum, c) => sum + (Number(c.total) || 0), 0);

    const paidCollectedAmount = liveItems
      .filter((c) => c.status === 'بانتظار تحديد الموعد' || c.paid)
      .reduce((sum, c) => sum + (Number(c.total) || 0), 0);

    /*
     * **الانتظار مقيسٌ من وقت الاستقبال — و`null` تعني «لم يُقَس».**
     *
     * كان الحسابان مبنيّين على `c.mins`، وهو عمودٌ **بلا كاتبٍ في المشروع كلّه**: فالمتوسّط
     * صفرٌ أبداً تحت عنوان «متوسط زمن المعالجة»، والعدّاد صفرٌ أبداً فتبويب «متأخرة» يُفرغ
     * الجدول. والعنوان نفسه كان كاذباً مرّتين: لا يقيس **المعالجة** بل عمرَ الطلب المفتوح.
     */
    const measured = liveItems.map((c) => c.ageMins).filter((m): m is number => m != null);
    const late = measured.filter((m) => m > LATE_AFTER_MINS).length;
    const avgMins = measured.length > 0
      ? Math.round(measured.reduce((acc, m) => acc + m, 0) / measured.length)
      : null;

    return {
      total,
      pendingPricing,
      awaitingPayment,
      awaitingSchedule,
      pendingPaymentAmount,
      paidCollectedAmount,
      late,
      avgMins,
    };
  }, [liveItems]);

  // Unique list of specialties
  const specialtiesList = useMemo(() => {
    const set = new Set<string>();
    liveItems.forEach((c) => {
      const sp = c.specialty || c.type;

      if (sp && sp.trim() !== '') {
set.add(sp.trim());
}
    });

    return Array.from(set);
  }, [liveItems]);

  // Filtered & Sorted consultations dataset
  const filteredItems = useMemo(() => {
    return liveItems
      .filter((c) => {
        if (categoryFilter === 'pricing' && c.status !== 'بانتظار التسعير') {
return false;
}

        if (categoryFilter === 'payment' && c.status !== 'بانتظار السداد') {
return false;
}

        if (categoryFilter === 'scheduling' && c.status !== 'بانتظار تحديد الموعد') {
return false;
}

        // غيرُ المقيس ليس «في الوقت» — فلا يدخل تبويب المتأخّرة ولا يُنفى منه بصفرٍ مصطنع
        if (categoryFilter === 'late' && !(c.ageMins != null && c.ageMins > LATE_AFTER_MINS)) {
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

        if (searchQuery.trim() !== '') {
          const q = searchQuery.toLowerCase().trim();
          const refMatch = (c.ref || '').toLowerCase().includes(q);
          const clientMatch = (c.client || '').toLowerCase().includes(q);
          const subjectMatch = (c.subject || '').toLowerCase().includes(q);
          const phoneMatch = (c.phone || '').toLowerCase().includes(q);
          const invoiceMatch = (c.invoiceNo || '').toLowerCase().includes(q);

          if (!refMatch && !clientMatch && !subjectMatch && !phoneMatch && !invoiceMatch) {
return false;
}
        }

        return true;
      })
      .sort((a, b) => {
        if (sortBy === 'latest') {
return b.id - a.id;
}

        if (sortBy === 'oldest') {
return a.id - b.id;
}

        if (sortBy === 'price_desc') {
return (b.total || 0) - (a.total || 0);
}

        if (sortBy === 'price_asc') {
return (a.total || 0) - (b.total || 0);
}

        return 0;
      });
  }, [liveItems, categoryFilter, channelFilter, specialtyFilter, searchQuery, sortBy]);

  // Submit Pricing Action (Base + VAT)
  const handlePricingSubmit = (consult: ConsultCard, priceStr: string) => {
    const priceNum = parseInt(priceStr, 10);

    // **الصفر ممنوع.** كان `< 0` يسمح به، ومودال الجدول يفحص `isProcessing` وحده —
    // فتُنشأ فاتورة ٠ ر.س «مستحقّة» ويعلق الطلب بلا مخرج (الخادم يمنعه الآن أيضاً).
    if (isNaN(priceNum) || priceNum < 1) {
      toast('⚠️ أقلّ سعرٍ للاستشارة ريالٌ واحد');

      return;
    }

    setIsProcessingAction(true);
    router.post(
      `/admin/consults/${consult.id}/price`,
      { price: priceNum },
      {
        preserveScroll: true,
        onSuccess: () => {
          setIsProcessingAction(false);
          toast('✅ تم تسعير الاستشارة وإصدار الفاتورة وإشعار العميل بنجاح');
          setPricingModalConsult(null);
        },
        onError: (err) => {
          setIsProcessingAction(false);
          toast(`⚠️ ${Object.values(err)[0] || 'تعذر تحديد السعر'}`);
        },
      }
    );
  };

  // Submit Reminder for slot scheduling
  const handleRemindSchedule = (consult: ConsultCard) => {
    // نقرتان متتاليتان كانتا تُرسلان إشعارين للعميل وقيدَي تدقيق — لا تعطيل ولا حالة
    if (isProcessing) {
      return;
    }

    setIsProcessingAction(true);
    router.post(
      `/admin/consults/${consult.id}/remind-schedule`,
      {},
      {
        preserveScroll: true,
        onFinish: () => setIsProcessingAction(false),
        onSuccess: () => toast('🔔 تم إرسال تذكير الموعد للعميل بنجاح'),
        onError: (err) => toast(`⚠️ ${Object.values(err)[0] || 'تعذر الإرسال'}`),
      }
    );
  };

  /**
   * **تصحيح تسعيرٍ خاطئ.**
   *
   * كان زرّ «تعديل السعر» يفتح درج التسعير الذي يرتدّ ٤٢٢ دائماً — `setPrice` يشترط
   * «بانتظار التسعير» وأوّلُ تسعيرٍ يقفلها. فرقمٌ خاطئ في فاتورةٍ وصلت عميلاً لم يكن
   * له مخرجٌ إلّا إلغاء الطلب كلّه.
   */
  const handleReprice = (consult: ConsultCard) => {
    if (isProcessing) {
      return;
    }

    if (!window.confirm(`ستُلغى فاتورة (${consult.ref}) ويُشعَر العميل، ويعود الطلب إلى التسعير. متابعة؟`)) {
      return;
    }

    setIsProcessingAction(true);
    router.post(`/admin/consults/${consult.id}/reprice`, {}, {
      preserveScroll: true,
      onSuccess: () => {
        toast('أُلغيت الفاتورة — الطلب عاد إلى التسعير');
        openDrawer(consult.ref, 'pricing');
      },
      onError: (err) => toast(`⚠️ ${Object.values(err)[0] || 'تعذّر تصحيح السعر'}`),
      onFinish: () => setIsProcessingAction(false),
    });
  };

  // Submit Cancel Request
  const handleCancelRequest = () => {
    if (!cancelTargetConsult) {
return;
}

    if (isProcessing) {
      return;
    }

    setIsProcessingAction(true);
    router.post(
      `/admin/consults/${cancelTargetConsult.id}/cancel-request`,
      {},
      {
        preserveScroll: true,
        onFinish: () => setIsProcessingAction(false),
        onSuccess: () => {
          toast('✅ تم إلغاء طلب الاستشارة وإشعار العميل');
          setCancelTargetConsult(null);
          closeDrawer();
        },
        onError: (err) => {
          toast(`⚠️ ${Object.values(err)[0] || 'تعذر إلغاء الطلب'}`);
        },
      }
    );
  };

  // Helper VAT computations for the pricing engine
  /*
   * **نسبة الضريبة من الخادم لا مصلَّبة.**
   *
   * كانت `0.15` مكتوبةً في الشيفرة والعنوان «(15%)» نصّاً — بينما النسبة إعدادٌ إداريّ
   * حيّ (`Setting::vatRate()`) له شاشةُ ضبطٍ في اللوحة نفسها. فلو ضُبطت على ٥٪ لعرضت
   * الحاسبةُ على المسعّر إجمالاً غير الذي سيُفوتَر ويُطالَب به العميل.
   */
  /*
   * **الأسعار المعتمدة لا «باقاتٌ معياريّة».** كانت ستّة أرقامٍ مكتوبةٍ بيد
   * (`[300,500,750,1000,1500,2000]`) موسومةً «معياريّة» — **ولا واحدٌ منها يطابق سعراً
   * معتمداً** في `Setting::consultPrices()`، ولا تتغيّر بتغيير الإعدادات، ولا تفرّق
   * بين القنوات الثلاث. فاللافتة تعطي الرقم سلطةً لا يملكها.
   */
  const pricePresets = useMemo(
    () => Array.from(new Set(Object.values(suggestedPrices).filter((n) => n > 0))).sort((a, b) => a - b),
    [suggestedPrices]
  );

  const parsedDrawerPrice = parseInt(inputPrice, 10) || 0;
  const drawerVatAmount = Math.round((parsedDrawerPrice * vatRate) / 100);
  const drawerTotalAmount = parsedDrawerPrice + drawerVatAmount;

  return (
    <div className="consult-requests-360-root" style={{ paddingBottom: 60, width: '100%' }}>
      {/* ── CSS المخصص للاستجابة والشاشة الكاملة والأنيميشن ── */}
      <style>{`
        .consult-requests-360-root {
          box-sizing: border-box;
          width: 100%;
        }

        /* الهيدر ومبدل طرق العرض */
        .cr360-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          flex-wrap: wrap;
          gap: 14px;
          margin-bottom: 16px;
        }
        .cr360-view-switcher {
          display: flex;
          background: rgba(0,0,0,0.06);
          padding: 4px;
          border-radius: 10px;
          gap: 4px;
        }

        /* شبكة بطاقات الإحصائيات (KPI Ribbon) */
        .cr360-kpi-grid {
          display: grid;
          grid-template-columns: repeat(5, 1fr);
          gap: 12px;
          margin: 16px 0 20px;
        }

        /* شريط فلترة المراحل */
        .cr360-cat-scroll {
          display: flex;
          gap: 8px;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
          padding-bottom: 4px;
          white-space: nowrap;
        }
        .cr360-cat-scroll::-webkit-scrollbar {
          height: 4px;
        }
        .cr360-cat-scroll::-webkit-scrollbar-thumb {
          background: rgba(0,0,0,0.15);
          border-radius: 4px;
        }

        /* شبكة حقول البحث والقوائم المنسدلة */
        .cr360-filter-grid {
          display: grid;
          grid-template-columns: 2fr repeat(3, 1fr);
          gap: 10px;
        }

        /* مسار كانبان الاستقبال والتسعير */
        .cr360-kanban-grid {
          display: grid;
          grid-template-columns: repeat(3, 1fr);
          gap: 16px;
          align-items: start;
        }

        /* عروض الجدول: الديسكتوب مقابل كروت الموبايل */
        .cr360-table-wrapper {
          display: block;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
        }
        .cr360-desktop-table {
          width: 100%;
          border-collapse: collapse;
          text-align: right;
          font-size: 13px;
          min-width: 760px;
        }
        .cr360-mobile-cards {
          display: none;
        }

        /* أنيميشن الدرج المنبثق والخلفية الحرة على مستوى الشاشة */
        @keyframes cr360FadeIn {
          from { opacity: 0; }
          to { opacity: 1; }
        }
        @keyframes cr360SlideInRight {
          from { transform: translateX(100%); }
          to { transform: translateX(0); }
        }

        .cr360-portal-backdrop {
          position: fixed !important;
          inset: 0 !important;
          width: 100vw !important;
          height: 100vh !important;
          z-index: 99990 !important;
          background: rgba(10, 25, 45, 0.6) !important;
          backdrop-filter: blur(4px) !important;
          display: flex !important;
          justify-content: flex-end !important;
          direction: rtl !important;
          animation: cr360FadeIn 0.2s ease-out;
        }

        .cr360-drawer-panel {
          width: 100% !important;
          max-width: 580px !important;
          height: 100vh !important;
          background: #fff !important;
          box-shadow: -10px 0 35px rgba(0,0,0,0.35) !important;
          display: flex !important;
          flex-direction: column !important;
          box-sizing: border-box !important;
          animation: cr360SlideInRight 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .cr360-drawer-tabs {
          display: flex;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
          border-bottom: 1px solid rgba(0,0,0,0.08);
          background: #fafafa;
          white-space: nowrap;
        }


        /* ── استجابة الشاشات المتوسطة والتابلت (Max 1180px) ── */
        @media (max-width: 1180px) {
          .cr360-kpi-grid {
            grid-template-columns: repeat(3, 1fr);
          }
          .cr360-kanban-grid {
            display: flex;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scroll-snap-type: x mandatory;
            padding-bottom: 14px;
          }
          .cr360-kanban-col {
            flex: 0 0 320px;
            min-width: 320px;
            scroll-snap-align: start;
          }
        }

        /* ── استجابة التابلت والموبايل (Max 768px) ── */
        @media (max-width: 768px) {
          .cr360-header {
            flex-direction: column;
            align-items: stretch;
          }
          .cr360-view-switcher {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            width: 100%;
          }
          .cr360-kpi-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
          }
          .cr360-filter-grid {
            grid-template-columns: 1fr;
          }

          /* تحويل الجدول إلى كروت لمس ذكية وتفاعلية على الشاشات الصغيرة */
          .cr360-table-wrapper {
            display: none;
          }
          .cr360-mobile-cards {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 12px;
          }
          .cr360-drawer-panel {
            max-width: 100% !important;
          }
        }

        /* ── استجابة الشاشات الصغيرة جداً (Max 420px) ── */
        @media (max-width: 420px) {
          .cr360-view-switcher {
            grid-template-columns: 1fr;
          }
          .cr360-kpi-grid {
            grid-template-columns: 1fr;
          }
        }
      `}</style>

      {/* ── 1. الهيدر والترحيب ومبدل طرق العرض ── */}
      <div className="greet cr360-header">
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0, fontSize: 'clamp(17px, 2.5vw, 22px)' }}>
            <Icon name="card" cls="ic" />
            مركز استقبال وتسعير الاستشارات 360° — الإدارة العليا
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--muted)', fontSize: 13 }}>
            مراجعة طلبات الاستشارات الواردة، حساب الضريبة وإصدار الفواتير الفورية، وتتبع سداد العملاء وحجز المواعيد.
          </p>
        </div>

        {/* مبدل العرض المتكيف */}
        <div className="cr360-view-switcher">
          <button
            type="button"
            className={`btn sm ${viewMode === 'pipeline' ? 'primary' : 'soft'}`}
            style={{ borderRadius: 8, padding: '7px 14px', fontSize: 12.5, justifyContent: 'center' }}
            onClick={() => setViewMode('pipeline')}
          >
            <Icon name="compass" /> مسار التسعير (Kanban)
          </button>
          <button
            type="button"
            className={`btn sm ${viewMode === 'table' ? 'primary' : 'soft'}`}
            style={{ borderRadius: 8, padding: '7px 14px', fontSize: 12.5, justifyContent: 'center' }}
            onClick={() => setViewMode('table')}
          >
            <Icon name="doc" /> الجدول والعمليات
          </button>
        </div>
      </div>

      {/* ── 2. شريط المؤشرات المالية والتشغيلية اللحظي (KPI Ribbon) ── */}
      <div className="cr360-kpi-grid">
        {/* إجمالي الطلبات */}
        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid var(--primary)' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>إجمالي طلبات ما قبل الجلسة</span>
            <Icon name="folder" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: 'var(--primary)', marginTop: 4 }}>
            {telemetry.total}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>تحت الإجراء المالي</div>
        </div>

        {/* بانتظار التسعير الفوري */}
        <div
          className="card"
          style={{
            padding: '12px 14px',
            margin: 0,
            borderRight: '4px solid #C0832B',
            background: telemetry.pendingPricing > 0 ? 'rgba(192, 131, 43, 0.04)' : '#fff',
          }}
        >
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>تحتاج تسعيراً فورياً</span>
            <Icon name="clock" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#C0832B', marginTop: 4 }}>
            {telemetry.pendingPricing}
          </div>
          <div style={{ fontSize: 10.5, color: '#C0832B', marginTop: 2 }}>بانتظار تحديد القيمة</div>
        </div>

        {/* فواتير مصدرة بانتظار السداد */}
        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #0E5C9C' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>بانتظار السداد من العميل</span>
            <Icon name="card" />
          </div>
          <div style={{ fontSize: 'clamp(18px, 2.5vw, 22px)', fontWeight: 800, color: '#0E5C9C', marginTop: 4 }}>
            {telemetry.pendingPaymentAmount.toLocaleString()} <span style={{ fontSize: 11.5 }}>ر.س</span>
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>
            {telemetry.awaitingPayment} فاتورة مصدرة
          </div>
        </div>

        {/* إيرادات محصلة بانتظار حجز الموعد */}
        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #1E9D6B' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>مسددة وبانتظار حجز الموعد</span>
            <Icon name="cal" />
          </div>
          <div style={{ fontSize: 'clamp(18px, 2.5vw, 22px)', fontWeight: 800, color: '#1E9D6B', marginTop: 4 }}>
            {telemetry.paidCollectedAmount.toLocaleString()} <span style={{ fontSize: 11.5 }}>ر.س</span>
          </div>
          <div style={{ fontSize: 10.5, color: '#1E9D6B', marginTop: 2 }}>
            {telemetry.awaitingSchedule} عميل جاهز للحجز
          </div>
        </div>

        {/* متوسط زمن المعالجة والتأخر */}
        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #11A0C8' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>متوسط انتظار الطلبات المفتوحة</span>
            <Icon name="clock" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#11A0C8', marginTop: 4 }}>
            {telemetry.avgMins == null ? '—' : telemetry.avgMins} <span style={{ fontSize: 12 }}>دقيقة</span>
          </div>
          <div style={{ fontSize: 10.5, color: telemetry.late > 0 ? '#C0392B' : 'var(--muted)', marginTop: 2 }}>
            {telemetry.avgMins == null
              ? 'لا طلبات مفتوحة'
              : telemetry.late > 0
                ? `${telemetry.late} طلب تجاوز ${LATE_AFTER_MINS / 60} ساعة`
                : 'الانتظار ضمن المعدل'}
          </div>
        </div>
      </div>

      {/* ── 3. شريط الفلترة والبحث الذكي المتكيف ── */}
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
          <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', flexShrink: 0 }}>الحالة:</span>
          <div className="cr360-cat-scroll" style={{ flex: 1 }}>
            {(
              [
                ['all', 'جميع الطلبات الواردة', liveItems.length],
                ['pricing', '1. بانتظار التسعير', telemetry.pendingPricing],
                ['payment', '2. بانتظار السداد', telemetry.awaitingPayment],
                ['scheduling', '3. بانتظار تحديد الموعد', telemetry.awaitingSchedule],
                ['late', 'متأخرة (> ساعتين)', telemetry.late],
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
        <div className="cr360-filter-grid">
          {/* حقل البحث */}
          <div style={{ position: 'relative' }}>
            <input
              type="text"
              placeholder="بحث بالمرجع، العميل، الموضوع، الفاتورة..."
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

          {/* الترتيب */}
          <select
            value={sortBy}
            onChange={(e) => setSortBy(e.target.value as any)}
            style={{ padding: '9px 12px', borderRadius: 8, border: '1px solid rgba(0,0,0,0.15)', fontSize: 13, width: '100%' }}
          >
            <option value="latest">الأحدث وروداً</option>
            <option value="oldest">الأقدم (الأولى بالمعالجة)</option>
            <option value="price_desc">الأعلى قيمة</option>
            <option value="price_asc">الأقل قيمة</option>
          </select>
        </div>
      </div>

      {/* ── 4. طرق العرض (View Modes) ── */}

      {/* ── View A: مسار كانبان الاستقبال والتسعير (Intake Pipeline) ── */}
      {viewMode === 'pipeline' && (
        <div className="cr360-kanban-grid">
          {/* 1. عمود: بانتظار التسعير */}
          <div
            className="card cr360-kanban-col"
            style={{
              margin: 0,
              padding: 16,
              borderTop: '4px solid #C0832B',
              background: 'rgba(255, 255, 255, 0.96)',
              minHeight: 450,
              boxSizing: 'border-box',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
              <div>
                <b style={{ fontSize: 14, color: '#C0832B' }}>1. بانتظار التسعير</b>
                <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>مراجعة الطلب وإصدار الفاتورة</div>
              </div>
              <span
                style={{
                  background: 'rgba(192, 131, 43, 0.12)',
                  color: '#C0832B',
                  padding: '3px 10px',
                  borderRadius: 12,
                  fontSize: 12,
                  fontWeight: 800,
                }}
              >
                {filteredItems.filter((c) => c.status === 'بانتظار التسعير').length}
              </span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              {filteredItems.filter((c) => c.status === 'بانتظار التسعير').length > 0 ? (
                filteredItems
                  .filter((c) => c.status === 'بانتظار التسعير')
                  .map((c) => (
                    <div
                      key={c.id}
                      style={{
                        background: '#fff',
                        border: '1px solid rgba(192, 131, 43, 0.25)',
                        borderRadius: 10,
                        padding: 14,
                        boxShadow: '0 2px 8px rgba(192, 131, 43, 0.06)',
                        cursor: 'pointer',
                        transition: 'transform 0.15s, box-shadow 0.15s',
                      }}
                      onClick={() => openDrawer(c.ref, 'pricing')}
                      onMouseEnter={(e) => {
                        e.currentTarget.style.transform = 'translateY(-2px)';
                        e.currentTarget.style.boxShadow = '0 6px 16px rgba(192, 131, 43, 0.12)';
                      }}
                      onMouseLeave={(e) => {
                        e.currentTarget.style.transform = 'translateY(0)';
                        e.currentTarget.style.boxShadow = '0 2px 8px rgba(192, 131, 43, 0.06)';
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                          <Icon name={crChannelIcon(c.channel)} />
                          <b style={{ color: 'var(--primary)', fontSize: 13.5 }}>{c.ref}</b>
                        </div>
                        <Badge text={c.channel} tone={crChannelTone(c.channel)} />
                      </div>

                      <div style={{ fontSize: 13, fontWeight: 700, marginTop: 6, color: '#13314F' }}>
                        {maskClient(c.client)}
                      </div>

                      <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4, lineHeight: 1.5 }}>
                        {c.subject}
                      </div>

                      <div style={{ display: 'flex', gap: 6, marginTop: 8, flexWrap: 'wrap' }}>
                        <Badge text={c.specialty || c.type || 'عام'} tone="b-grey" />
                        {c.ageMins != null ? (
                          <span style={{ fontSize: 10.5, color: c.ageMins > LATE_AFTER_MINS ? '#C0392B' : 'var(--muted)', alignSelf: 'center' }}>
                            {c.received}
                          </span>
                        ) : null}
                      </div>

                      {/* زر الإجراء السريع */}
                      <div
                        style={{
                          marginTop: 10,
                          paddingTop: 10,
                          borderTop: '1px solid rgba(0,0,0,0.06)',
                          display: 'flex',
                          justifyContent: 'space-between',
                          alignItems: 'center',
                        }}
                      >
                        <button
                          className="btn primary sm"
                          type="button"
                          style={{ width: '100%', justifyContent: 'center', fontWeight: 700 }}
                          onClick={(e) => {
                            e.stopPropagation();
                            openDrawer(c.ref, 'pricing');
                          }}
                        >
                          <Icon name="card" /> تسعير الطلب الآن 360°
                        </button>
                      </div>
                    </div>
                  ))
              ) : (
                <div style={{ textAlign: 'center', padding: '40px 10px', color: 'var(--muted)', fontSize: 12.5 }}>
                  <Icon name="check" />
                  <div style={{ marginTop: 6, fontWeight: 600 }}>لا توجد طلبات بانتظار التسعير حالياً</div>
                </div>
              )}
            </div>
          </div>

          {/* 2. عمود: بانتظار السداد */}
          <div
            className="card cr360-kanban-col"
            style={{
              margin: 0,
              padding: 16,
              borderTop: '4px solid #0E5C9C',
              background: 'rgba(255, 255, 255, 0.96)',
              minHeight: 450,
              boxSizing: 'border-box',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
              <div>
                <b style={{ fontSize: 14, color: '#0E5C9C' }}>2. بانتظار السداد</b>
                <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>فواتير مصدرة للعملاء</div>
              </div>
              <span
                style={{
                  background: 'rgba(14, 92, 156, 0.12)',
                  color: '#0E5C9C',
                  padding: '3px 10px',
                  borderRadius: 12,
                  fontSize: 12,
                  fontWeight: 800,
                }}
              >
                {filteredItems.filter((c) => c.status === 'بانتظار السداد').length}
              </span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              {filteredItems.filter((c) => c.status === 'بانتظار السداد').length > 0 ? (
                filteredItems
                  .filter((c) => c.status === 'بانتظار السداد')
                  .map((c) => (
                    <div
                      key={c.id}
                      style={{
                        background: '#fff',
                        border: '1px solid rgba(14, 92, 156, 0.25)',
                        borderRadius: 10,
                        padding: 14,
                        boxShadow: '0 2px 8px rgba(14, 92, 156, 0.06)',
                        cursor: 'pointer',
                        transition: 'transform 0.15s, box-shadow 0.15s',
                      }}
                      onClick={() => openDrawer(c.ref, 'details')}
                      onMouseEnter={(e) => {
                        e.currentTarget.style.transform = 'translateY(-2px)';
                        e.currentTarget.style.boxShadow = '0 6px 16px rgba(14, 92, 156, 0.12)';
                      }}
                      onMouseLeave={(e) => {
                        e.currentTarget.style.transform = 'translateY(0)';
                        e.currentTarget.style.boxShadow = '0 2px 8px rgba(14, 92, 156, 0.06)';
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                          <Icon name={crChannelIcon(c.channel)} />
                          <b style={{ color: 'var(--primary)', fontSize: 13.5 }}>{c.ref}</b>
                        </div>
                        <Badge text={c.channel} tone={crChannelTone(c.channel)} />
                      </div>

                      <div style={{ fontSize: 13, fontWeight: 700, marginTop: 6, color: '#13314F' }}>
                        {maskClient(c.client)}
                      </div>

                      <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4, lineHeight: 1.5 }}>
                        {c.subject}
                      </div>

                      <div
                        style={{
                          background: 'rgba(14, 92, 156, 0.04)',
                          padding: '8px 10px',
                          borderRadius: 8,
                          margin: '8px 0',
                          display: 'flex',
                          justifyContent: 'space-between',
                          alignItems: 'center',
                        }}
                      >
                        <span style={{ fontSize: 11.5, color: 'var(--muted)' }}>
                          الفاتورة: <b>{c.invoiceNo || 'غير محدد'}</b>
                        </span>
                        <b style={{ color: '#0E5C9C', fontSize: 13.5 }}>
                          {c.total ? `${c.total} ر.س` : '—'}
                        </b>
                      </div>

                      <div
                        style={{
                          marginTop: 10,
                          paddingTop: 10,
                          borderTop: '1px solid rgba(0,0,0,0.06)',
                          display: 'flex',
                          justifyContent: 'space-between',
                          gap: 6,
                        }}
                      >
                        <button
                          className="btn soft sm"
                          type="button"
                          style={{ flex: 1, justifyContent: 'center' }}
                          disabled={isProcessing || c.paid}
                          onClick={(e) => {
                            e.stopPropagation();
                            handleReprice(c);
                          }}
                        >
                          <Icon name="card" /> تصحيح السعر
                        </button>
                        <button
                          className="btn soft sm"
                          type="button"
                          onClick={(e) => {
                            e.stopPropagation();
                            openDrawer(c.ref, 'details');
                          }}
                        >
                          <Icon name="out" />
                        </button>
                      </div>
                    </div>
                  ))
              ) : (
                <div style={{ textAlign: 'center', padding: '40px 10px', color: 'var(--muted)', fontSize: 12.5 }}>
                  <Icon name="folder" />
                  <div style={{ marginTop: 6, fontWeight: 600 }}>لا توجد طلبات بانتظار السداد</div>
                </div>
              )}
            </div>
          </div>

          {/* 3. عمود: بانتظار تحديد الموعد */}
          <div
            className="card cr360-kanban-col"
            style={{
              margin: 0,
              padding: 16,
              borderTop: '4px solid #1E9D6B',
              background: 'rgba(255, 255, 255, 0.96)',
              minHeight: 450,
              boxSizing: 'border-box',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
              <div>
                <b style={{ fontSize: 14, color: '#1E9D6B' }}>3. مسددة / بانتظار الموعد</b>
                <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>سدد الرسوم ولم يحدد موعداً</div>
              </div>
              <span
                style={{
                  background: 'rgba(30, 157, 107, 0.12)',
                  color: '#1E9D6B',
                  padding: '3px 10px',
                  borderRadius: 12,
                  fontSize: 12,
                  fontWeight: 800,
                }}
              >
                {filteredItems.filter((c) => c.status === 'بانتظار تحديد الموعد').length}
              </span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              {filteredItems.filter((c) => c.status === 'بانتظار تحديد الموعد').length > 0 ? (
                filteredItems
                  .filter((c) => c.status === 'بانتظار تحديد الموعد')
                  .map((c) => (
                    <div
                      key={c.id}
                      style={{
                        background: '#fff',
                        border: '1px solid rgba(30, 157, 107, 0.25)',
                        borderRadius: 10,
                        padding: 14,
                        boxShadow: '0 2px 8px rgba(30, 157, 107, 0.06)',
                        cursor: 'pointer',
                        transition: 'transform 0.15s, box-shadow 0.15s',
                      }}
                      onClick={() => openDrawer(c.ref, 'actions')}
                      onMouseEnter={(e) => {
                        e.currentTarget.style.transform = 'translateY(-2px)';
                        e.currentTarget.style.boxShadow = '0 6px 16px rgba(30, 157, 107, 0.12)';
                      }}
                      onMouseLeave={(e) => {
                        e.currentTarget.style.transform = 'translateY(0)';
                        e.currentTarget.style.boxShadow = '0 2px 8px rgba(30, 157, 107, 0.06)';
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                          <Icon name={crChannelIcon(c.channel)} />
                          <b style={{ color: 'var(--primary)', fontSize: 13.5 }}>{c.ref}</b>
                        </div>
                        <Badge text="مُسددة" tone="b-green" />
                      </div>

                      <div style={{ fontSize: 13, fontWeight: 700, marginTop: 6, color: '#13314F' }}>
                        {maskClient(c.client)}
                      </div>

                      <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4, lineHeight: 1.5 }}>
                        {c.subject}
                      </div>

                      <div
                        style={{
                          background: 'rgba(30, 157, 107, 0.05)',
                          padding: '8px 10px',
                          borderRadius: 8,
                          margin: '8px 0',
                          display: 'flex',
                          justifyContent: 'space-between',
                          alignItems: 'center',
                          fontSize: 11.5,
                        }}
                      >
                        <span>المبلغ المحصل: <b style={{ color: '#1E9D6B' }}>{c.total} ر.س</b></span>
                        {c.paidAgo && <span style={{ color: 'var(--muted)' }}>دُفع {c.paidAgo}</span>}
                      </div>

                      <div
                        style={{
                          marginTop: 10,
                          paddingTop: 10,
                          borderTop: '1px solid rgba(0,0,0,0.06)',
                          display: 'flex',
                          gap: 6,
                        }}
                      >
                        <button
                          className="btn primary sm"
                          type="button"
                          style={{ flex: 1, justifyContent: 'center' }}
                          onClick={(e) => {
                            e.stopPropagation();
                            handleRemindSchedule(c);
                          }}
                        >
                          <Icon name="bell" /> تذكير العميل بالحجز
                        </button>
                        <button
                          className="btn soft sm"
                          type="button"
                          onClick={(e) => {
                            e.stopPropagation();
                            setCancelTargetConsult(c);
                          }}
                          title="إلغاء الطلب"
                        >
                          <Icon name="close" />
                        </button>
                      </div>
                    </div>
                  ))
              ) : (
                <div style={{ textAlign: 'center', padding: '40px 10px', color: 'var(--muted)', fontSize: 12.5 }}>
                  <Icon name="cal" />
                  <div style={{ marginTop: 6, fontWeight: 600 }}>لا توجد طلبات معلقة لاختيار الموعد</div>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* ── View B: الجدول والعمليات الذكية ── */}
      {viewMode === 'table' && (
        <div className="card">
          <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <h3>جدول طلبات الاستشارات</h3>
            <span className="sub">{filteredItems.length} طلب مطابق</span>
          </div>

          {/* 1) جدول الديسكتوب والتابلت */}
          <div className="card-b cr360-table-wrapper" style={{ padding: 0 }}>
            {filteredItems.length > 0 ? (
              <table className="cr360-desktop-table">
                <thead>
                  <tr style={{ background: 'rgba(0,0,0,0.03)', borderBottom: '1px solid rgba(0,0,0,0.08)' }}>
                    <th style={{ padding: '12px 16px' }}>المرجع والعميل</th>
                    <th style={{ padding: '12px 14px' }}>القناة والتخصص</th>
                    <th style={{ padding: '12px 14px' }}>موضوع الطلب</th>
                    <th style={{ padding: '12px 14px' }}>الرسوم والفاتورة</th>
                    <th style={{ padding: '12px 14px' }}>الحالة الراهنة</th>
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
                      onClick={() => openDrawer(c.ref, 'pricing')}
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

                      {/* الموضوع */}
                      <td style={{ padding: '12px 14px', maxWidth: 260 }}>
                        <div style={{ fontSize: 12.5, lineHeight: 1.5, color: '#333' }}>
                          {c.subject}
                        </div>
                        {c.phone && (
                          <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>
                            هاتف: {c.phone}
                          </div>
                        )}
                      </td>

                      {/* الرسوم والفاتورة */}
                      <td style={{ padding: '12px 14px' }}>
                        {/* **المقترح غير المقرَّر.** `request()` يكتب `price/vat/total`
                            من الإعدادات كاقتراح و`priced_at` تبقى فارغة — فالشرط على
                            `total` وحده كان يعرض «450 ر.س — بانتظار السداد» لطلبٍ لم
                            يُسعَّر، بينما عمود الحالة في الصفّ نفسه يقول «بانتظار
                            التسعير». والمعيار الصادق `priced`. */}
                        {c.priced && c.total ? (
                          <div>
                            <b style={{ color: c.paid ? '#1E9D6B' : 'inherit' }}>
                              {c.total} ر.س
                            </b>
                            <div style={{ fontSize: 11, color: 'var(--muted)' }}>
                              {c.invoiceNo ? `فاتورة: ${c.invoiceNo}` : c.paid ? 'مُسددة' : 'بانتظار السداد'}
                            </div>
                          </div>
                        ) : c.total ? (
                          <div>
                            <span style={{ fontSize: 12, color: 'var(--muted)' }}>{c.total} ر.س</span>
                            <div style={{ fontSize: 11, color: '#C0832B', fontWeight: 600 }}>سعرٌ مقترح — لم يُعتمد</div>
                          </div>
                        ) : (
                          <span style={{ fontSize: 11.5, color: '#C0832B', fontWeight: 600 }}>
                            لم تُسعر بعد
                          </span>
                        )}
                      </td>

                      {/* الحالة */}
                      <td style={{ padding: '12px 14px' }}>
                        <Badge text={c.status} tone={cTone(c.status)} />
                        {c.paidAgo && (
                          <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 3 }}>
                            دُفع {c.paidAgo}
                          </div>
                        )}
                      </td>

                      {/* إجراءات سريعة */}
                      <td
                        style={{ padding: '12px 16px', textAlign: 'left' }}
                        onClick={(e) => e.stopPropagation()}
                      >
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                          {c.status === 'بانتظار التسعير' && (
                            <button
                              className="btn primary sm"
                              type="button"
                              onClick={() => {
                                setPricingModalConsult(c);
                                setModalPrice(String(c.price || 600));
                              }}
                            >
                              <Icon name="card" /> تسعير
                            </button>
                          )}
                          {c.status === 'بانتظار تحديد الموعد' && (
                            <button
                              className="btn soft sm"
                              type="button"
                              onClick={() => handleRemindSchedule(c)}
                            >
                              <Icon name="bell" /> تذكير
                            </button>
                          )}
                          <button
                            className="btn soft sm"
                            type="button"
                            onClick={() => openDrawer(c.ref, 'pricing')}
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
                <b style={{ display: 'block', marginTop: 8 }}>لا توجد طلبات مطابقة للفلتر المحدد</b>
              </div>
            )}
          </div>

          {/* 2) بطاقات الموبايل الذكية التفاعلية */}
          <div className="cr360-mobile-cards">
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
                  onClick={() => openDrawer(c.ref, 'pricing')}
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
                    <Badge text={c.status} tone={cTone(c.status)} />
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
                    <span>القناة: <b>{c.channel}</b></span>
                    <span>التخصص: <b>{c.specialty || c.type}</b></span>
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
                      {c.priced && c.total ? (
                        <b style={{ color: c.paid ? '#1E9D6B' : 'inherit', fontSize: 13 }}>
                          {c.total} ر.س ({c.paid ? 'مسددة' : 'بانتظار السداد'})
                        </b>
                      ) : c.total ? (
                        <span style={{ fontSize: 12, color: '#C0832B', fontWeight: 600 }}>
                          {c.total} ر.س — سعرٌ مقترح
                        </span>
                      ) : (
                        <span style={{ fontSize: 11.5, color: '#C0832B', fontWeight: 700 }}>
                          بانتظار التسعير
                        </span>
                      )}
                    </div>
                    <button
                      className="btn primary sm"
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation();
                        openDrawer(c.ref, 'pricing');
                      }}
                      style={{ fontSize: 11.5, padding: '5px 10px' }}
                    >
                      <Icon name="card" /> تسعير وتحكم
                    </button>
                  </div>
                </div>
              ))
            ) : (
              <div className="empty" style={{ padding: 30, textAlign: 'center' }}>
                <Icon name="folder" />
                <b style={{ display: 'block', marginTop: 6 }}>لا توجد طلبات مطابقة</b>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── 5. درج التسعير والتحكم السريع المنبثق 360° (Slide-over Portal Drawer) ── */}
      {drawerConsult && typeof document !== 'undefined' && createPortal(
        <div
          className="cr360-portal-backdrop"
          onClick={(e) => {
            if (e.target === e.currentTarget) {
              closeDrawer();
            }
          }}
          aria-modal="true"
          role="dialog"
        >
          <div
            className="cr360-drawer-panel"
            onClick={(e) => e.stopPropagation()}
          >
            {/* رأس الدرج */}
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
                  <Badge text={drawerConsult.status} tone={cTone(drawerConsult.status)} />
                </div>
                <div style={{ fontSize: 12.5, color: 'var(--muted)', marginTop: 4, textOverflow: 'ellipsis', overflow: 'hidden', whiteSpace: 'nowrap' }}>
                  العميل: {maskClient(drawerConsult.client)}
                </div>
              </div>

              {/* زر الإغلاق المحسن */}
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
            <div className="cr360-drawer-tabs">
              {(
                [
                  ['pricing', 'حاسبة التسعير والفاتورة', 'card'],
                  ['details', 'تفاصيل وبيانات الطلب', 'doc'],
                  ['actions', 'الإجراءات والمتابعة', 'exec'],
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
              {/* Tab 1: حاسبة التسعير والفاتورة الذكية */}
              {drawerTab === 'pricing' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  {/* البطاقة الإرشادية */}
                  <div
                    className="card"
                    style={{
                      margin: 0,
                      padding: 16,
                      background: 'linear-gradient(135deg, rgba(14, 92, 156, 0.05), rgba(30, 157, 107, 0.05))',
                      borderColor: 'rgba(14, 92, 156, 0.2)',
                    }}
                  >
                    <b style={{ color: 'var(--primary)', fontSize: 13.5 }}>محرك التسعير وإصدار الفاتورة الضريبية</b>
                    <p style={{ margin: '6px 0 0', fontSize: 12.5, color: 'var(--muted)', lineHeight: 1.6 }}>
                      حدد السعر الأساسي للاستشارة. سيقوم النظام آلياً بحساب ضريبة القيمة المضافة (15%) وإصدار فاتورة إلكترونية معتمدة وإشعار العميل فوراً للسداد.
                    </p>
                  </div>

                  {/* أزرار التسعير السريع الموصى بها */}
                  <div>
                    <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--muted)', marginBottom: 8 }}>
                      الأسعار المعتمدة لكلّ قناة:
                    </label>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 8 }}>
                      {pricePresets.map((p) => (
                        <button
                          key={p}
                          type="button"
                          onClick={() => setInputPrice(String(p))}
                          style={{
                            padding: '9px 8px',
                            borderRadius: 8,
                            border: inputPrice === String(p) ? '2px solid var(--primary)' : '1px solid rgba(0,0,0,0.15)',
                            background: inputPrice === String(p) ? 'rgba(14, 92, 156, 0.08)' : '#fff',
                            color: inputPrice === String(p) ? 'var(--primary)' : 'inherit',
                            fontWeight: 700,
                            fontSize: 13,
                            cursor: 'pointer',
                            transition: 'all 0.15s',
                          }}
                        >
                          {p} ر.س
                        </button>
                      ))}
                    </div>
                  </div>

                  {/* حقل الإدخال المخصص */}
                  <div className="card" style={{ margin: 0, padding: 16 }}>
                    <label style={{ display: 'block', fontSize: 12.5, fontWeight: 700, marginBottom: 8 }}>
                      السعر الأساسي للاستشارة (غير شامل الضريبة):
                    </label>
                    <div style={{ position: 'relative' }}>
                      <input
                        type="number"
                        min="1"
                        step="50"
                        value={inputPrice}
                        onChange={(e) => setInputPrice(e.target.value)}
                        style={{
                          width: '100%',
                          padding: '12px 14px',
                          borderRadius: 8,
                          border: '1px solid rgba(0,0,0,0.2)',
                          fontSize: 18,
                          fontWeight: 800,
                          color: 'var(--primary)',
                          boxSizing: 'border-box',
                        }}
                        placeholder="أدخل السعر بالريال..."
                      />
                      <span
                        style={{
                          position: 'absolute',
                          left: 14,
                          top: 13,
                          fontSize: 13,
                          fontWeight: 700,
                          color: 'var(--muted)',
                          pointerEvents: 'none',
                        }}
                      >
                        ر.س
                      </span>
                    </div>

                    {/* حاسبة الفاتورة والضريبة التفاعلية */}
                    <div
                      style={{
                        background: 'rgba(0,0,0,0.03)',
                        borderRadius: 8,
                        padding: 12,
                        marginTop: 14,
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 6,
                        fontSize: 12.5,
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                        <span style={{ color: 'var(--muted)' }}>السعر الأساسي:</span>
                        <b>{parsedDrawerPrice.toLocaleString()} ر.س</b>
                      </div>
                      <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                        <span style={{ color: 'var(--muted)' }}>ضريبة القيمة المضافة (15%):</span>
                        <b>{drawerVatAmount.toLocaleString()} ر.س</b>
                      </div>
                      <div
                        style={{
                          display: 'flex',
                          justifyContent: 'space-between',
                          borderTop: '1px dashed rgba(0,0,0,0.15)',
                          paddingTop: 8,
                          marginTop: 4,
                          fontSize: 14,
                        }}
                      >
                        <span style={{ fontWeight: 700 }}>إجمالي الفاتورة المطلوب سداده:</span>
                        <b style={{ color: '#1E9D6B', fontSize: 16 }}>{drawerTotalAmount.toLocaleString()} ر.س</b>
                      </div>
                    </div>

                    {/* زر التأكيد والإصدار */}
                    <button
                      className="btn primary"
                      style={{ width: '100%', justifyContent: 'center', marginTop: 14, minHeight: 44, fontSize: 14 }}
                      type="button"
                      disabled={isProcessing || parsedDrawerPrice <= 0}
                      onClick={() => handlePricingSubmit(drawerConsult, inputPrice)}
                    >
                      <Icon name="card" />
                      {isProcessing ? 'جاري إصدار الفاتورة...' : 'إصدار الفاتورة وتأكيد السعر وإشعار العميل'}
                    </button>
                  </div>
                </div>
              )}

              {/* Tab 2: تفاصيل وبيانات الطلب */}
              {drawerTab === 'details' && (
                <>
                  <div className="card" style={{ margin: 0, padding: 14 }}>
                    <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', marginBottom: 6 }}>
                      موضوع واستفسار العميل:
                    </div>
                    <div style={{ fontSize: 14, fontWeight: 600, lineHeight: 1.6 }}>{drawerConsult.subject}</div>
                    <div style={{ display: 'flex', gap: 8, marginTop: 10, flexWrap: 'wrap' }}>
                      <Badge text={drawerConsult.specialty || drawerConsult.type} tone="b-blue" />
                      <Badge text={`قناة ${drawerConsult.channel}`} tone={crChannelTone(drawerConsult.channel)} />
                      <Badge text={drawerConsult.status} tone={cTone(drawerConsult.status)} />
                    </div>
                  </div>

                  <div className="card" style={{ margin: 0, padding: 14, display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: 12 }}>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>اسم العميل:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{maskClient(drawerConsult.client)}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>هاتف العميل:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerConsult.phone || '—'}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>المستشار المقترح:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerConsult.lawyer || 'بانتظار التعيين'}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>رقم الفاتورة:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerConsult.invoiceNo || 'لم تُصدر بعد'}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>إجمالي المبلغ:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2, color: drawerConsult.paid ? '#1E9D6B' : 'inherit' }}>
                        {drawerConsult.priced && drawerConsult.total
                          ? `${drawerConsult.total} ر.س`
                          : drawerConsult.total
                            ? `${drawerConsult.total} ر.س — مقترح لم يُعتمد`
                            : 'غير محدد'}
                      </div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>حالة السداد:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2, color: drawerConsult.paid ? '#1E9D6B' : '#C0832B' }}>
                        {drawerConsult.paid ? 'تم السداد' : 'بانتظار الدفع'}
                      </div>
                    </div>
                  </div>
                </>
              )}

              {/* Tab 3: الإجراءات والمتابعة */}
              {drawerTab === 'actions' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                  {/* تذكير السداد أو حجز الموعد */}
                  {drawerConsult.status === 'بانتظار تحديد الموعد' && (
                    <div className="card" style={{ margin: 0, padding: 14 }}>
                      <b>تذكير العميل باختيار موعد الجلسة:</b>
                      <p style={{ fontSize: 12, color: 'var(--muted)', margin: '4px 0 10px' }}>
                        العميل سدد رسوم الاستشارة بالكامل ولم يختر موعداً بعد.
                      </p>
                      <button
                        className="btn primary sm"
                        style={{ width: '100%', justifyContent: 'center' }}
                        type="button"
                        onClick={() => handleRemindSchedule(drawerConsult)}
                      >
                        <Icon name="bell" /> إرسال إشعار تذكير فوري
                      </button>
                    </div>
                  )}

                  {/* إلغاء الطلب */}
                  <div className="card" style={{ margin: 0, padding: 14, borderRight: '4px solid #C0392B' }}>
                    <b style={{ color: '#C0392B' }}>إلغاء طلب الاستشارة:</b>
                    <p style={{ fontSize: 12, color: 'var(--muted)', margin: '4px 0 10px' }}>
                      سيتم إلغاء الطلب وإشعار العميل. إذا تم سداد رسوم مسبقاً، يتم تنسيق الاسترداد المالي يدوياً.
                    </p>
                    <button
                      className="btn soft sm"
                      style={{ width: '100%', justifyContent: 'center', color: '#C0392B' }}
                      type="button"
                      onClick={() => setCancelTargetConsult(drawerConsult)}
                    >
                      <Icon name="close" /> إلغاء هذا الطلب
                    </button>
                  </div>

                  {/* الانتقال لصفحة التفاصيل الكاملة */}
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

            {/* ذيل الدرج */}
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

      {/* ── 6. نافذة التسعير السريع المنبثقة من الجدول ── */}
      {pricingModalConsult && (
        <Modal
          title={`تسعير الاستشارة — ${pricingModalConsult.ref}`}
          open={!!pricingModalConsult}
          onClose={() => setPricingModalConsult(null)}
        >
          <form
            onSubmit={(e) => {
              e.preventDefault();
              handlePricingSubmit(pricingModalConsult, modalPrice);
            }}
            style={{ display: 'flex', flexDirection: 'column', gap: 16 }}
          >
            <p style={{ fontSize: 13, color: 'var(--muted)', margin: 0 }}>
              العميل: <b>{maskClient(pricingModalConsult.client)}</b> · القناة: <b>{pricingModalConsult.channel}</b>
            </p>
            <div>
              <label style={{ display: 'block', fontSize: 12.5, fontWeight: 600, marginBottom: 6 }}>
                سعر الاستشارة (ريال سعودي غير شامل الضريبة):
              </label>
              <input
                type="number"
                min="1"
                step="50"
                value={modalPrice}
                onChange={(e) => setModalPrice(e.target.value)}
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
              <button type="button" className="btn soft" onClick={() => setPricingModalConsult(null)}>
                إلغاء
              </button>
              <button type="submit" className="btn primary" disabled={isProcessing}>
                {isProcessing ? 'جاري الإصدار...' : 'إصدار الفاتورة وتأكيد السعر'}
              </button>
            </div>
          </form>
        </Modal>
      )}

      {/* ── 7. نافذة تأكيد الإلغاء المنبثقة ── */}
      {cancelTargetConsult && (
        <Modal
          title={`تأكيد إلغاء الطلب — ${cancelTargetConsult.ref}`}
          open={!!cancelTargetConsult}
          onClose={() => setCancelTargetConsult(null)}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
            <p style={{ fontSize: 13.5, color: '#333', margin: 0, lineHeight: 1.6 }}>
              هل أنت متأكد من رغبتك في إلغاء طلب الاستشارة <b>{cancelTargetConsult.ref}</b> للعميل <b>{maskClient(cancelTargetConsult.client)}</b>؟
            </p>
            <p style={{ fontSize: 12, color: 'var(--muted)', margin: 0 }}>
              سيتم إشعار العميل بالإلغاء فوراً، وفي حال وجود مبالغ محصلة يتم التنسيق للاسترداد.
            </p>
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 10 }}>
              <button type="button" className="btn soft" onClick={() => setCancelTargetConsult(null)}>
                تراجع
              </button>
              <button
                type="button"
                className="btn primary"
                style={{ background: '#C0392B', borderColor: '#C0392B' }}
                onClick={handleCancelRequest}
              >
                تأكيد الإلغاء
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
};

export default AdminConsultRequests;
