import { router } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '@/components/babylon/Badge';
import Modal, { useBodyScrollLock } from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { maskClient } from '@/lib/admin-data';
import {
  
  
  sessTone,
  SummaryModal
} from '@/lib/consult-ui';
import type {ConsultCard, LawyerOpt} from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import {
  cTone,
  crChannelIcon,
  crChannelTone,
  CONSULT_TERMINAL_STATUSES,
  CONSULT_BOOKING_STATUSES,
} from '@/lib/employee-data';
import Icon from '@/lib/icons';

interface AdminConsultsProps {
  consults: ConsultCard[];
  preSessionRequests?: ConsultCard[];
  lawyers?: LawyerOpt[];
}

type ViewMode = 'table' | 'pipeline' | 'calendar' | 'analytics';
type CategoryFilter = 'all' | 'live' | 'pre_session' | 'in_flight' | 'completed' | 'late';
type DrawerTab = 'details' | 'actions' | 'ai_zoom' | 'audit';

export const AdminConsults: React.FC<AdminConsultsProps> = ({
  consults: initialConsults = [],
  preSessionRequests: initialRequests = [],
  lawyers: initialLawyers = [],
}) => {
  const toast = useToast();

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

  // Quick Action Drawer (Controlled by Ref String for rock-solid stability)
  const [drawerRef, setDrawerRef] = useState<string | null>(null);
  const [drawerTab, setDrawerTab] = useState<DrawerTab>('details');
  const [summaryConsult, setSummaryConsult] = useState<ConsultCard | null>(null);

  // Inline Controls State for 360° Drawer
  const [drawerLawyerId, setDrawerLawyerId] = useState<number | ''>('');
  const [drawerPrice, setDrawerPrice] = useState<string>('600');
  const [isProcessingAction, setIsProcessingAction] = useState(false);

  // Standalone Table Modals State
  const [pricingConsult, setPricingConsult] = useState<ConsultCard | null>(null);
  const [inputPrice, setInputPrice] = useState<string>('600');
  const [reassignConsult, setReassignConsult] = useState<ConsultCard | null>(null);
  const [selectedLawyerId, setSelectedLawyerId] = useState<number | ''>('');

  // Realtime synchronization via Echo
  useEffect(() => {
    setInFlightItems(initialConsults);
    setRequestItems(initialRequests);

    const allConsults = [...initialConsults, ...initialRequests];
    allConsults.forEach((c) => {
      echo.private(`consult.${c.id}`).listen('.status', (e: Partial<ConsultCard>) => {
        // `summary` **يُستبعد من الدمج**: الحمولة تُبثّ للعميل أيضاً، فلا تحمل إلّا
        // المعتمَد — والنشر الكامل كان يمسح النصّ المعروض للإدارة لحظة إنهاء الجلسة.
        const rest = { ...e };

        delete rest.summary;
        const updater = (prev: ConsultCard[]) =>
          prev.map((x) => (x.id === c.id ? { ...x, ...rest } : x));
        setInFlightItems(updater);
        setRequestItems(updater);

        if (e.session === 'منتهية') {
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

  // Comprehensive Lawyer List (From backend props + fallback from active database records)
  const lawyersList = useMemo(() => {
    if (initialLawyers && initialLawyers.length > 0) {
return initialLawyers;
}

    const dynamic: LawyerOpt[] = [];
    allItems.forEach((c) => {
      if (c.lawyer && c.lawyer !== '—' && !dynamic.find((l) => l.name === c.lawyer)) {
        dynamic.push({ id: 0, name: c.lawyer, dept: c.specialty || c.type || 'عام' });
      }
    });

    return dynamic;
  }, [initialLawyers, allItems]);

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
      const found = lawyersList.find((l) => l.name === drawerConsult.lawyer);
      setDrawerLawyerId(found ? found.id : '');
      setDrawerPrice(String(drawerConsult.price || 600));
    }
  }, [drawerConsult, lawyersList]);

  // Telemetry & KPI Computations
  const telemetry = useMemo(() => {
    const total = allItems.length;
    const liveNow = allItems.filter((c) => c.session === 'جلسة جارية').length;
    const preSession = allItems.filter((c) =>
      ['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد'].includes(c.status)
    ).length;
    const needsPricing = allItems.filter((c) => c.status === 'بانتظار التسعير').length;
    const readyForLawyer = allItems.filter((c) => c.status === 'جاهزة للمحامي').length;
    const completed = allItems.filter((c) => CONSULT_TERMINAL_STATUSES.includes(c.status)).length;
    const toCase = allItems.filter((c) => !!c.caseNo).length;
    const conversionRate = total > 0 ? Math.round((toCase / total) * 100) : 0;

    const late = allItems.filter(
      (c) => (c.mins || 0) > 100 && !['جاهزة للمحامي', ...CONSULT_TERMINAL_STATUSES].includes(c.status)
    ).length;

    // Financial calculations
    const paidRevenue = allItems
      .filter((c) => c.paid || CONSULT_TERMINAL_STATUSES.includes(c.status))
      .reduce((sum, c) => sum + (Number(c.total) || 0), 0);

    const pendingRevenue = allItems
      .filter((c) => !c.paid && (Number(c.total) || 0) > 0)
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
    };
  }, [allItems]);

  // Active live sessions for the Radar section
  const liveSessions = useMemo(() => {
    return allItems.filter((c) => c.session === 'جلسة جارية' || (c.channel === 'مرئية' && c.startable));
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

      if (categoryFilter === 'pre_session' && !['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد'].includes(c.status)) {
return false;
}

      if (categoryFilter === 'in_flight' && ([...CONSULT_BOOKING_STATUSES, ...CONSULT_TERMINAL_STATUSES].includes(c.status))) {
return false;
}

      if (categoryFilter === 'completed' && !CONSULT_TERMINAL_STATUSES.includes(c.status)) {
return false;
}

      if (categoryFilter === 'late') {
        const isLate = (c.mins || 0) > 100 && !['جاهزة للمحامي', ...CONSULT_TERMINAL_STATUSES].includes(c.status);

        if (!isLate) {
return false;
}
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
  }, [allItems, categoryFilter, channelFilter, specialtyFilter, lawyerFilter, priorityFilter, searchQuery]);

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

    setIsProcessingAction(true);
    router.post(
      `/admin/consults/${consult.id}/refer`,
      { lawyer_id: lawyerId },
      {
        preserveScroll: true,
        onSuccess: () => {
          setIsProcessingAction(false);
          toast('✅ تم تحديث إسناد المحامي وإشعار العميل بنجاح');
        },
        onError: (err) => {
          setIsProcessingAction(false);
          toast(`⚠️ ${Object.values(err)[0] || 'تعذر تغيير المحامي'}`);
        },
      }
    );
  };

  const handleDrawerPricing = (consult: ConsultCard, priceStr: string) => {
    const priceNum = parseInt(priceStr, 10);

    if (isNaN(priceNum) || priceNum < 0) {
      toast('⚠️ يرجى إدخال مبلغ تسعير صحيح');

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
          toast('✅ تم تسعير الاستشارة وإصدار الفاتورة للعميل');
        },
        onError: (err) => {
          setIsProcessingAction(false);
          toast(`⚠️ ${Object.values(err)[0] || 'تعذر تحديد السعر'}`);
        },
      }
    );
  };

  // Table Modals Handlers
  const handleOpenPricingModal = (consult: ConsultCard) => {
    setPricingConsult(consult);
    setInputPrice(String(consult.price || 600));
  };

  const submitPricingModal = (e: React.FormEvent) => {
    e.preventDefault();

    if (!pricingConsult) {
return;
}

    const priceNum = parseInt(inputPrice, 10);

    if (isNaN(priceNum) || priceNum < 0) {
      toast('⚠️ يرجى إدخال مبلغ صحيح');

      return;
    }

    router.post(
      `/admin/consults/${pricingConsult.id}/price`,
      { price: priceNum },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast('✅ تم تسعير الاستشارة وإصدار الفاتورة للعميل');
          setPricingConsult(null);
        },
        onError: (err) => {
          toast(`⚠️ ${Object.values(err)[0] || 'تعذر تحديد السعر'}`);
        },
      }
    );
  };

  // مُعلَّق: لا زرّ يستدعيه — مودال إعادة التعيين المستقل يتيم (الإسناد يتم من درج 360°).
  // يُعاد تفعيله إن أُضيف زرّ «إعادة تعيين» خارج الدرج.
  // const handleOpenReassignModal = (consult: ConsultCard) => {
  //   setReassignConsult(consult);
  //   const curr = lawyersList.find((l) => l.name === consult.lawyer);
  //   setSelectedLawyerId(curr ? curr.id : '');
  // };

  const submitReassignModal = (e: React.FormEvent) => {
    e.preventDefault();

    if (!reassignConsult || !selectedLawyerId) {
return;
}

    router.post(
      `/admin/consults/${reassignConsult.id}/refer`,
      { lawyer_id: selectedLawyerId },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast('✅ تم تعيين المحامي وإشعار العميل بنجاح');
          setReassignConsult(null);
        },
        onError: (err) => {
          toast(`⚠️ ${Object.values(err)[0] || 'تعذر تعيين المحامي'}`);
        },
      }
    );
  };

  const triggerRemindSchedule = (consult: ConsultCard) => {
    router.post(
      `/admin/consults/${consult.id}/remind-schedule`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => toast('🔔 تم إرسال تذكير الموعد للعميل بنجاح'),
        onError: (err) => toast(`⚠️ ${Object.values(err)[0] || 'تعذر الإرسال'}`),
      }
    );
  };

  const triggerRunAiAnalysis = (consult: ConsultCard) => {
    router.post(
      `/admin/consults/${consult.id}/analyze`,
      {},
      {
        preserveScroll: true,
        // «بنجاح» حكمٌ لا تحمله الاستجابة: المتحكّم يعيد `back()` ولو سقط
        // إلى الاحتياطيّ. والنتيجة تُقرأ من البطاقة لا من التوست.
        onSuccess: () => toast('انتهت المعالجة — راجع نتيجتها في بطاقة الاستشارة'),
        onError: (err) => toast(`⚠️ ${Object.values(err)[0] || 'تعذر تشغيل التحليل'}`),
      }
    );
  };

  const triggerApproveAiAnalysis = (consult: ConsultCard) => {
    router.post(
      `/admin/consults/${consult.id}/approve`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => toast('✅ تم اعتماد التحليل ونقله للمحامي'),
        onError: (err) => toast(`⚠️ ${Object.values(err)[0] || 'تعذر اعتماد التحليل'}`),
      }
    );
  };

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
          grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
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

        /* ضمان بقاء النوافذ المنبثقة العامة (Modals) في أعلى طبقة دوماً */
        .modal-bg {
          z-index: 100000 !important;
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
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <Badge text="جلسة جارية" tone="b-amber" />
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
                ['completed', 'منتهية ومحولة', telemetry.completed],
                ['late', 'متأخرة', telemetry.late],
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
            <option value="عالية">عالية</option>
            <option value="متوسطة">متوسطة</option>
            <option value="عادية">عادية</option>
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
                          <Badge text={c.status} tone={cTone(c.status)} />
                          {c.session && c.session !== 'بانتظار الجلسة' && (
                            <Badge text={c.session} tone={sessTone(c.session)} />
                          )}
                        </div>
                      </td>

                      {/* أزرار الإجراءات السريعة */}
                      <td
                        style={{ padding: '12px 16px', textAlign: 'left' }}
                        onClick={(e) => e.stopPropagation()}
                      >
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                          {c.status === 'بانتظار التسعير' && (
                            <button
                              className="btn primary sm"
                              type="button"
                              onClick={() => handleOpenPricingModal(c)}
                            >
                              تسعير
                            </button>
                          )}
                          {c.status === 'بانتظار تحديد الموعد' && (
                            <button
                              className="btn soft sm"
                              type="button"
                              onClick={() => triggerRemindSchedule(c)}
                            >
                              <Icon name="bell" /> تذكير
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
              {
                id: 'pre_session',
                title: '1. بانتظار التسعير والسداد',
                tone: '#C0832B',
                items: filteredItems.filter((c) => ['بانتظار التسعير', 'بانتظار السداد'].includes(c.status)),
              },
              {
                id: 'scheduling',
                title: '2. حجز الموعد وتعيين المحامي',
                tone: '#11A0C8',
                items: filteredItems.filter((c) => ['بانتظار تحديد الموعد', 'جديدة'].includes(c.status)),
              },
              {
                id: 'review',
                title: '3. قيد المعالجة والتحليل الذكي',
                tone: '#0E5C9C',
                items: filteredItems.filter((c) =>
                  ['قيد مراجعة الموظف', 'بانتظار استكمال البيانات', 'بانتظار اعتماد الموظف', 'جاهزة للمحامي'].includes(c.status)
                ),
              },
              {
                id: 'active_sessions',
                title: '4. جلسات جارية وقادمة',
                tone: '#1E9D6B',
                items: filteredItems.filter((c) => c.session === 'جلسة جارية' || (c.startsAt && !CONSULT_TERMINAL_STATUSES.includes(c.status))),
              },
              {
                id: 'completed',
                title: '5. منتهية ومحولة لقضايا',
                tone: '#13314F',
                items: filteredItems.filter((c) => CONSULT_TERMINAL_STATUSES.includes(c.status)),
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
                        <Badge text={c.status} tone={cTone(c.status)} />
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
                      <Badge text={c.session || 'بانتظار الجلسة'} tone={sessTone(c.session)} />
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
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 16 }}>
          {/* توزيع المستشارين وأحمالهم */}
          <div className="card" style={{ margin: 0 }}>
            <div className="card-h">
              <h3>أحمال المحامين والمستشارين</h3>
              <span className="sub">إجمالي الاستشارات المسندة</span>
            </div>
            <div className="card-b">
              {assignedLawyersList.map((law) => {
                const count = allItems.filter((c) => c.lawyer === law).length;
                const active = allItems.filter((c) => c.lawyer === law && !CONSULT_TERMINAL_STATUSES.includes(c.status)).length;
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
                  <Badge text={drawerConsult.status} tone={cTone(drawerConsult.status)} />
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
            <div style={{ padding: 20, flex: 1, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 14 }}>
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

                  {drawerConsult.summary && (
                    <div className="card" style={{ margin: 0, padding: 14 }}>
                      <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', marginBottom: 6 }}>
                        ملخص الاستشارة النهائي:
                      </div>
                      <div style={{ fontSize: 13, lineHeight: 1.8, whiteSpace: 'pre-wrap' }}>
                        {drawerConsult.summary}
                      </div>
                    </div>
                  )}
                </>
              )}

              {/* Tab 2: التحكم والإجراءات الإدارية المباشرة */}
              {drawerTab === 'actions' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  {/* 1. إعادة إسناد المحامي المباشر */}
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
                        <option value="">-- اختر مستشاراً قانونياً من القائمة --</option>
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

                  {/* 2. التسعير المباشر وإصدار الفاتورة */}
                  {drawerConsult.status === 'بانتظار التسعير' && (
                    <div className="card" style={{ margin: 0, padding: 16, borderRight: '4px solid #C0832B' }}>
                      <b>تسعير الاستشارة وإصدار الفاتورة:</b>
                      <p style={{ fontSize: 12, color: 'var(--muted)', margin: '4px 0 10px' }}>
                        أدخل السعر المطلوب لإصدار الفاتورة فوراً للعميل ليتمكن من السداد وحجز الموعد.
                      </p>
                      <div style={{ display: 'flex', gap: 8 }}>
                        <input
                          type="number"
                          min="0"
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
                  {drawerConsult.status === 'بانتظار تحديد الموعد' && (
                    <div className="card" style={{ margin: 0, padding: 14 }}>
                      <b>تذكير العميل باختيار الموعد:</b>
                      <p style={{ fontSize: 12, color: 'var(--muted)', margin: '4px 0 10px' }}>
                        العميل سدد الرسوم ولم يحدد موعداً بعد.
                      </p>
                      <button
                        className="btn soft sm"
                        style={{ width: '100%', justifyContent: 'center' }}
                        type="button"
                        onClick={() => triggerRemindSchedule(drawerConsult)}
                      >
                        <Icon name="bell" /> إرسال إشعار تذكير
                      </button>
                    </div>
                  )}

                  {/* 4. تعديل الأولوية */}
                  <div className="card" style={{ margin: 0, padding: 14 }}>
                    <b>تعديل درجة الأولوية:</b>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 6, marginTop: 10 }}>
                      {(['عالية', 'متوسطة', 'منخفضة'] as const).map((p) => (
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

                  {/* 5. تشغيل الذكاء الاصطناعي */}
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
                      {drawerConsult.status === 'بانتظار اعتماد الموظف' && (
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
                        {drawerConsult.aiSummary}
                      </div>
                    </div>
                  )}

                  {/* مخرجات Zoom */}
                  <div className="card" style={{ margin: 0, padding: 14 }}>
                    <b>مخرجات جلسة Zoom:</b>
                    <div style={{ marginTop: 10, display: 'flex', flexDirection: 'column', gap: 8 }}>
                      {drawerConsult.recording ? (
                        <a
                          href={drawerConsult.recording}
                          target="_blank"
                          rel="noreferrer"
                          className="btn soft sm"
                          style={{ display: 'inline-flex', alignItems: 'center', gap: 6, justifyContent: 'center' }}
                        >
                          <Icon name="video" /> مشاهدة التسجيل السحابي
                        </a>
                      ) : (
                        <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                          لا يوجد تسجيل سحابي متاح لهذه الجلسة
                        </span>
                      )}

                      {drawerConsult.zoomAudioUrl && (
                        <a
                          href={drawerConsult.zoomAudioUrl}
                          target="_blank"
                          rel="noreferrer"
                          className="btn soft sm"
                          style={{ display: 'inline-flex', alignItems: 'center', gap: 6, justifyContent: 'center' }}
                        >
                          <Icon name="mic" /> تنزيل التسجيل الصوتي
                        </a>
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
              العميل: <b>{maskClient(pricingConsult.client)}</b> · القناة: <b>{pricingConsult.channel}</b>
            </p>
            <div>
              <label style={{ display: 'block', fontSize: 12.5, fontWeight: 600, marginBottom: 6 }}>
                سعر الاستشارة (ريال سعودي غير شامل الضريبة):
              </label>
              <input
                type="number"
                min="0"
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

      {/* ── 8. نافذة إعادة التعيين المنبثقة من الجدول ── */}
      {reassignConsult && (
        <Modal
          title={`إعادة إسناد المحامي — ${reassignConsult.ref}`}
          open={!!reassignConsult}
          onClose={() => setReassignConsult(null)}
        >
          <form onSubmit={submitReassignModal} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <div>
              <label style={{ display: 'block', fontSize: 12.5, fontWeight: 600, marginBottom: 6 }}>
                اختر المستشار القانوني:
              </label>
              <select
                value={selectedLawyerId}
                onChange={(e) => setSelectedLawyerId(e.target.value ? Number(e.target.value) : '')}
                style={{
                  width: '100%',
                  padding: '10px 12px',
                  borderRadius: 8,
                  border: '1px solid rgba(0,0,0,0.2)',
                  fontSize: 14,
                  boxSizing: 'border-box',
                }}
                required
              >
                <option value="">-- اختر من القائمة --</option>
                {lawyersList.map((l) => (
                  <option key={l.id || l.name} value={l.id}>
                    {l.name} ({l.dept})
                  </option>
                ))}
              </select>
            </div>
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
              <button type="button" className="btn soft" onClick={() => setReassignConsult(null)}>
                إلغاء
              </button>
              <button type="submit" className="btn primary" disabled={!selectedLawyerId}>
                تأكيد الإسناد وإشعار العميل
              </button>
            </div>
          </form>
        </Modal>
      )}

      {/* ── 9. نافذة الملخص ── */}
      <SummaryModal consult={summaryConsult} onClose={() => setSummaryConsult(null)} />
    </div>
  );
};

export default AdminConsults;
