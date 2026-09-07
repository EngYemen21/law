import { router } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '@/components/babylon/Badge';
import Modal, { useBodyScrollLock } from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { maskClient } from '@/lib/admin-data';
import type {ConsultCard} from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import { crChannelIcon, crChannelTone, sessTone } from '@/lib/employee-data';
import Icon from '@/lib/icons';

interface Props {
  consults: ConsultCard[];
}

type ViewMode = 'grid' | 'table';
type FilterChannel = 'all' | 'مرئية' | 'حضورية' | 'هاتفية' | '_live' | '_missed' | '_ended';
type DrawerTab = 'actions' | 'summary' | 'details' | 'audit';

export const AdminConsultRecv: React.FC<Props> = ({ consults = [] }) => {
  const toast = useToast();

  // State Management
  const [items, setItems] = useState<ConsultCard[]>(consults);
  const [viewMode, setViewMode] = useState<ViewMode>('grid');
  const [activeFilter, setActiveFilter] = useState<FilterChannel>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [specialtyFilter, setSpecialtyFilter] = useState<string>('all');
  const [lawyerFilter, setLawyerFilter] = useState<string>('all');

  // Slide-over Drawer state (Driven by Ref string)
  const [drawerRef, setDrawerRef] = useState<string | null>(null);
  const [drawerTab, setDrawerTab] = useState<DrawerTab>('actions');

  // Confirmation Modal state for Rescheduling
  // نافذة التدوين عند إنهاء الجلسة — مادّة الملخّص الوحيدة
  const [endingOf, setEndingOf] = useState<ConsultCard | null>(null);
  const [endNotes, setEndNotes] = useState('');

  const [rescheduleTarget, setRescheduleTarget] = useState<ConsultCard | null>(null);
  const [isRescheduling, setIsRescheduling] = useState(false);

  // Real-time Echo WebSocket subscriptions
  useEffect(() => {
    setItems(consults);
    consults.forEach((c) => {
      echo.private(`consult.${c.id}`).listen(
        '.status',
        (e: { session?: string; status?: string; canJoin?: boolean }) => {
          setItems((prev) =>
            prev.map((x) =>
              x.id === c.id
                ? {
                    ...x,
                    session: e.session ?? x.session,
                    status: e.status ?? x.status,
                    canJoin: e.canJoin ?? x.canJoin,
                  }
                : x
            )
          );

          // الملخّص لا يُؤخذ من البثّ: الحمولة نفسها تُبثّ للعميل فلا تحمل إلّا
          // المعتمَد. والإدارة تحتاج غير المعتمَد لتراجعه — فيُجلب بصلاحيّتها.
          if (e.session === 'منتهية') {
            router.reload({ only: ['consults'] });
          }
        }
      );
    });

    return () => {
      consults.forEach((c) => echo.leave(`consult.${c.id}`));
    };
  }, [consults]);

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

  // Currently selected item in drawer
  const drawerItem = useMemo(() => {
    if (!drawerRef) {
return null;
}

    return items.find((c) => c.ref === drawerRef) || null;
  }, [items, drawerRef]);

  // Open & Close Drawer
  const openDrawer = (ref: string, initialTab: DrawerTab = 'actions') => {
    setDrawerRef(ref);
    setDrawerTab(initialTab);
  };

  const closeDrawer = () => {
    setDrawerRef(null);
  };

  // Telemetry computations
  const telemetry = useMemo(() => {
    const total = items.length;
    const liveNow = items.filter((c) => c.session === 'جلسة جارية').length;
    const waiting = items.filter((c) => c.session === 'بانتظار الجلسة' && !c.missed).length;
    const videoCount = items.filter((c) => c.channel === 'مرئية').length;
    const officeCount = items.filter((c) => c.channel === 'حضورية').length;
    const phoneCount = items.filter((c) => c.channel === 'هاتفية').length;
    const missedCount = items.filter((c) => c.missed).length;
    const endedCount = items.filter((c) => c.session === 'منتهية').length;

    return {
      total,
      liveNow,
      waiting,
      videoCount,
      officeCount,
      phoneCount,
      missedCount,
      endedCount,
    };
  }, [items]);

  // Unique filters
  const specialtiesList = useMemo(() => {
    const set = new Set<string>();
    items.forEach((c) => {
      const sp = (c as any).specialty || c.type;

      if (sp && sp.trim() !== '') {
set.add(sp.trim());
}
    });

    return Array.from(set);
  }, [items]);

  const lawyersList = useMemo(() => {
    const set = new Set<string>();
    items.forEach((c) => {
      if (c.lawyer && c.lawyer !== '—') {
set.add(c.lawyer.trim());
}
    });

    return Array.from(set);
  }, [items]);

  // Filtered dataset
  const filteredItems = useMemo(() => {
    return items.filter((c) => {
      if (activeFilter === '_live' && c.session !== 'جلسة جارية') {
return false;
}

      if (activeFilter === '_missed' && !c.missed) {
return false;
}

      if (activeFilter === '_ended' && c.session !== 'منتهية') {
return false;
}

      if (activeFilter === 'مرئية' && c.channel !== 'مرئية') {
return false;
}

      if (activeFilter === 'حضورية' && c.channel !== 'حضورية') {
return false;
}

      if (activeFilter === 'هاتفية' && c.channel !== 'هاتفية') {
return false;
}

      const sp = (c as any).specialty || c.type || '';

      if (specialtyFilter !== 'all' && sp !== specialtyFilter) {
return false;
}

      if (lawyerFilter !== 'all' && c.lawyer !== lawyerFilter) {
return false;
}

      if (searchQuery.trim() !== '') {
        const q = searchQuery.toLowerCase().trim();
        const refMatch = (c.ref || '').toLowerCase().includes(q);
        const clientMatch = (c.client || '').toLowerCase().includes(q);
        const lawyerMatch = (c.lawyer || '').toLowerCase().includes(q);
        const subjectMatch = (c.subject || '').toLowerCase().includes(q);

        if (!refMatch && !clientMatch && !lawyerMatch && !subjectMatch) {
return false;
}
      }

      return true;
    });
  }, [items, activeFilter, specialtyFilter, lawyerFilter, searchQuery]);

  // Action Handlers
  const handleStart = (c: ConsultCard, e?: React.MouseEvent) => {
    e?.stopPropagation();
    const msg =
      c.channel === 'مرئية'
        ? 'تم بدء الجلسة المرئية مع العميل'
        : c.channel === 'هاتفية'
        ? 'تم بدء المكالمة الهاتفية مع العميل'
        : 'تم تسجيل وصول العميل وبدء الجلسة الحضورية';

    router.post(
      `/admin/consults/${c.id}/start`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => toast(msg),
      }
    );
  };

  // كان يُرسل حمولةً فارغة `{}` ويُقال «تم توليد وتوثيق ملخص الاستشارة».
  // وبعد حارس «بلا مادّة ⇒ لا نداء» صار ذاك الفرع **مضموناً ألّا يعمل**: الخادم
  // يحسب `notes = ''` فيُكتب في سجلّ التدقيق «لم يُولَّد» والتوست يقول عكسه.
  // والتوأم في `consult-ui.tsx` أُصلح وفات هذا — فيُنسخ حلّه حرفياً.
  const handleEnd = (c: ConsultCard, e?: React.MouseEvent) => {
    e?.stopPropagation();
    setEndingOf(c);
    setEndNotes('');
  };

  const submitEnd = () => {
    if (endingOf === null) {
      return;
    }

    const notes = endNotes.trim();

    router.post(
      `/admin/consults/${endingOf.id}/end`,
      { notes },
      {
        preserveScroll: true,
        onSuccess: () => {
          setEndingOf(null);
          setEndNotes('');
          toast(notes === ''
            ? 'خُتمت الجلسة بلا تدوين — لا ملخّص حتّى تُدوّن ما دار فيها'
            : 'خُتمت الجلسة وحُفظ تدوينك — يُعدّ الملخّص لاعتماد المستشار');
        },
      }
    );
  };

  const handleEnterRoom = (c: ConsultCard, e?: React.MouseEvent) => {
    e?.stopPropagation();
    const room = `/admin/videoroom?ref=${encodeURIComponent(c.ref)}`;

    if (c.session === 'بانتظار الجلسة') {
      router.post(
        `/admin/consults/${c.id}/start`,
        {},
        {
          preserveScroll: true,
          onSuccess: () => router.visit(room),
          onError: () => toast('تعذّر بدء الجلسة'),
        }
      );

      return;
    }

    router.visit(room);
  };

  const handleNoShow = (c: ConsultCard, e?: React.MouseEvent) => {
    e?.stopPropagation();
    router.post(
      `/admin/consults/${c.id}/no-show`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => toast('وُسمت الاستشارة «لم يحضر» وأُشعر العميل'),
        onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر الوسم'}`),
      }
    );
  };

  const handleReschedule = (c: ConsultCard, e?: React.MouseEvent) => {
    e?.stopPropagation();
    setRescheduleTarget(c);
  };

  const confirmReschedule = () => {
    if (!rescheduleTarget) {
return;
}

    setIsRescheduling(true);
    router.post(
      `/admin/consults/${rescheduleTarget.id}/reschedule`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => {
          setIsRescheduling(false);
          setRescheduleTarget(null);
          toast('تمت إعادة جدولة الاستشارة بنجاح وأُشعر العميل لاختيار موعد جديد');
        },
        onError: (errors) => {
          setIsRescheduling(false);
          toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر إعادة الجدولة'}`);
        },
      }
    );
  };

  const handleCopyLink = (c: ConsultCard, e?: React.MouseEvent) => {
    e?.stopPropagation();

    if (navigator.clipboard && c.slink) {
      void navigator.clipboard.writeText(c.slink);
      toast('تم نسخ رابط الاجتماع المباشر');
    }
  };

  return (
    <div className="admin-consultrecv-360-root" style={{ paddingBottom: 60, width: '100%' }}>
      {/* ── CSS المخصص للاستجابة والشاشة الكاملة والأنيميشن ── */}
      <style>{`
        .admin-consultrecv-360-root {
          box-sizing: border-box;
          width: 100%;
        }

        /* الهيدر ومبدل طرق العرض */
        .recv360-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          flex-wrap: wrap;
          gap: 14px;
          margin-bottom: 16px;
        }
        .recv360-view-switcher {
          display: flex;
          background: rgba(0,0,0,0.06);
          padding: 4px;
          border-radius: 10px;
          gap: 4px;
        }

        /* شبكة بطاقات الإحصائيات (KPI Cockpit) */
        .recv360-kpi-grid {
          display: grid;
          grid-template-columns: repeat(6, 1fr);
          gap: 12px;
          margin: 16px 0 20px;
        }

        /* نبض الجلسات الجارية الحية */
        @keyframes pulseGlow {
          0% { box-shadow: 0 0 0 0 rgba(30, 157, 107, 0.5); }
          70% { box-shadow: 0 0 0 10px rgba(30, 157, 107, 0); }
          100% { box-shadow: 0 0 0 0 rgba(30, 157, 107, 0); }
        }
        .recv360-live-pulse {
          animation: pulseGlow 2s infinite;
        }

        /* شريط فلترة القنوات والمراحل */
        .recv360-cat-scroll {
          display: flex;
          gap: 8px;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
          padding-bottom: 4px;
          white-space: nowrap;
        }
        .recv360-cat-scroll::-webkit-scrollbar {
          height: 4px;
        }
        .recv360-cat-scroll::-webkit-scrollbar-thumb {
          background: rgba(0,0,0,0.15);
          border-radius: 4px;
        }

        /* شبكة حقول البحث والقوائم المنسدلة */
        .recv360-filter-grid {
          display: grid;
          grid-template-columns: 2fr repeat(2, 1fr);
          gap: 10px;
        }

        /* شبكة بطاقات صالة الاستقبال */
        .recv360-cards-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
          gap: 16px;
        }

        /* عروض الجدول: الديسكتوب مقابل كروت الموبايل */
        .recv360-table-wrapper {
          display: block;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
        }
        .recv360-desktop-table {
          width: 100%;
          border-collapse: collapse;
          text-align: right;
          font-size: 13px;
          min-width: 820px;
        }
        .recv360-mobile-cards {
          display: none;
        }

        /* أنيميشن الدرج المنبثق والخلفية الحرة على مستوى الشاشة */
        @keyframes recv360FadeIn {
          from { opacity: 0; }
          to { opacity: 1; }
        }
        @keyframes recv360SlideInRight {
          from { transform: translateX(100%); }
          to { transform: translateX(0); }
        }

        .recv360-portal-backdrop {
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
          animation: recv360FadeIn 0.2s ease-out;
        }

        .recv360-drawer-panel {
          width: 100% !important;
          max-width: 580px !important;
          height: 100vh !important;
          background: #fff !important;
          box-shadow: -10px 0 35px rgba(0,0,0,0.35) !important;
          display: flex !important;
          flex-direction: column !important;
          box-sizing: border-box !important;
          animation: recv360SlideInRight 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .recv360-drawer-tabs {
          display: flex;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
          border-bottom: 1px solid rgba(0,0,0,0.08);
          background: #fafafa;
          white-space: nowrap;
        }

        /* ── استجابة الشاشات المتوسطة والتابلت (Max 1180px) ── */
        @media (max-width: 1180px) {
          .recv360-kpi-grid {
            grid-template-columns: repeat(3, 1fr);
          }
          .recv360-filter-grid {
            grid-template-columns: 1fr 1fr;
          }
        }

        /* ── استجابة التابلت والموبايل (Max 768px) ── */
        @media (max-width: 768px) {
          .recv360-header {
            flex-direction: column;
            align-items: stretch;
          }
          .recv360-view-switcher {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            width: 100%;
          }
          .recv360-kpi-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
          }
          .recv360-filter-grid {
            grid-template-columns: 1fr;
          }

          .recv360-table-wrapper {
            display: none;
          }
          .recv360-mobile-cards {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 12px;
          }
          .recv360-drawer-panel {
            max-width: 100% !important;
          }
        }

        /* ── استجابة الشاشات الصغيرة جداً (Max 420px) ── */
        @media (max-width: 420px) {
          .recv360-view-switcher {
            grid-template-columns: 1fr;
          }
          .recv360-kpi-grid {
            grid-template-columns: 1fr;
          }
        }
      `}</style>

      {/* ── 1. الهيدر الترحيبي ومبدل طرق العرض ── */}
      <div className="greet recv360-header">
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0, fontSize: 'clamp(17px, 2.5vw, 22px)' }}>
            <Icon name="compass" cls="ic" />
            مركز استقبال وإدارة جلسات الاستشارات 360° — الإدارة العليا
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--muted)', fontSize: 13 }}>
            صالة الاستقبال والتحكم المباشر في الجلسات الجارية والمجدولة (مرئية Zoom، حضورية بالمكتب، هاتفية) وإدارتها لحظياً.
          </p>
        </div>

        {/* مبدل العرض المتكيف */}
        <div className="recv360-view-switcher">
          <button
            type="button"
            className={`btn sm ${viewMode === 'grid' ? 'primary' : 'soft'}`}
            style={{ borderRadius: 8, padding: '7px 14px', fontSize: 12.5, justifyContent: 'center' }}
            onClick={() => setViewMode('grid')}
          >
            <Icon name="compass" /> صالة الاستقبال
          </button>
          <button
            type="button"
            className={`btn sm ${viewMode === 'table' ? 'primary' : 'soft'}`}
            style={{ borderRadius: 8, padding: '7px 14px', fontSize: 12.5, justifyContent: 'center' }}
            onClick={() => setViewMode('table')}
          >
            <Icon name="doc" /> جدول العمليات
          </button>
        </div>
      </div>

      {/* ── 2. شريط المؤشرات والتحكم اللحظي (Executive KPI Cockpit) ── */}
      <div className="recv360-kpi-grid">
        {/* الجلسات الجارية الآن */}
        <div
          className={`card ${telemetry.liveNow > 0 ? 'recv360-live-pulse' : ''}`}
          style={{
            padding: '12px 14px',
            margin: 0,
            borderRight: '4px solid #1E9D6B',
            background: telemetry.liveNow > 0 ? 'rgba(30, 157, 107, 0.05)' : '#fff',
            cursor: 'pointer',
          }}
          onClick={() => setActiveFilter('_live')}
        >
          <div style={{ fontSize: 11.5, color: '#1E9D6B', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontWeight: 700 }}>
            <span>جلسات جارية الآن</span>
            <span style={{ display: 'inline-block', width: 8, height: 8, borderRadius: '50%', background: '#1E9D6B' }} />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#1E9D6B', marginTop: 4 }}>
            {telemetry.liveNow}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>مباشرة على الهواء</div>
        </div>

        {/* بانتظار الجلسة */}
        <div
          className="card"
          style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid var(--primary)', cursor: 'pointer' }}
          onClick={() => setActiveFilter('all')}
        >
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>بانتظار بدء الجلسة</span>
            <Icon name="clock" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: 'var(--primary)', marginTop: 4 }}>
            {telemetry.waiting}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>مجدولة ومسددة</div>
        </div>

        {/* استشارات مرئية Zoom */}
        <div
          className="card"
          style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #0E5C9C', cursor: 'pointer' }}
          onClick={() => setActiveFilter('مرئية')}
        >
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>مرئية (Zoom)</span>
            <Icon name="video" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#0E5C9C', marginTop: 4 }}>
            {telemetry.videoCount}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>جلسات بالفيديو</div>
        </div>

        {/* استشارات حضورية */}
        <div
          className="card"
          style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #11A0C8', cursor: 'pointer' }}
          onClick={() => setActiveFilter('حضورية')}
        >
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>حضورية (بالمكتب)</span>
            <Icon name="office" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#11A0C8', marginTop: 4 }}>
            {telemetry.officeCount}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>استقبال في المقر</div>
        </div>

        {/* استشارات هاتفية */}
        <div
          className="card"
          style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #C0832B', cursor: 'pointer' }}
          onClick={() => setActiveFilter('هاتفية')}
        >
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>هاتفية</span>
            <Icon name="phone" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#C0832B', marginTop: 4 }}>
            {telemetry.phoneCount}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>مكالمات صوتية</div>
        </div>

        {/* جلسات فائتة وتحتاج تدخل */}
        <div
          className="card"
          style={{
            padding: '12px 14px',
            margin: 0,
            borderRight: '4px solid #dc2626',
            background: telemetry.missedCount > 0 ? 'rgba(220, 38, 38, 0.04)' : '#fff',
            cursor: 'pointer',
          }}
          onClick={() => setActiveFilter('_missed')}
        >
          <div style={{ fontSize: 11.5, color: '#dc2626', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontWeight: 700 }}>
            <span>فائتة / تحتاج إجراء</span>
            <Icon name="clock" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#dc2626', marginTop: 4 }}>
            {telemetry.missedCount}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>لم يحضر / إعادة جدولة</div>
        </div>
      </div>

      {/* ── 3. شريط الفلترة والبحث السريع ── */}
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
        {/* شريط تمرير أزرار القنوات والحالات (Category Pills) */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', flexShrink: 0 }}>تصفية الصالة:</span>
          <div className="recv360-cat-scroll" style={{ flex: 1 }}>
            {(
              [
                ['all', 'جميع الجلسات', items.length],
                ['_live', '🟢 جلسة جارية', telemetry.liveNow],
                ['مرئية', '📹 مرئية Zoom', telemetry.videoCount],
                ['حضورية', '🏛️ حضورية بالمكتب', telemetry.officeCount],
                ['هاتفية', '📞 هاتفية', telemetry.phoneCount],
                ['_missed', '⚠️ فائتة', telemetry.missedCount],
                ['_ended', '✅ منتهية ومكتملة', telemetry.endedCount],
              ] as const
            ).map(([key, label, count]) => (
              <button
                key={key}
                type="button"
                onClick={() => setActiveFilter(key as FilterChannel)}
                style={{
                  border: 'none',
                  background: activeFilter === key ? 'var(--primary)' : 'rgba(0,0,0,0.05)',
                  color: activeFilter === key ? '#fff' : 'inherit',
                  borderRadius: 20,
                  padding: '6px 12px',
                  fontSize: 12,
                  cursor: 'pointer',
                  fontWeight: activeFilter === key ? 700 : 500,
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
                    background: activeFilter === key ? 'rgba(255,255,255,0.25)' : 'rgba(0,0,0,0.1)',
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
        <div className="recv360-filter-grid">
          {/* حقل البحث */}
          <div style={{ position: 'relative' }}>
            <input
              type="text"
              placeholder="بحث بالمرجع، اسم العميل، المستشار، الموضوع..."
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
            {lawyersList.map((law) => (
              <option key={law} value={law}>
                {law}
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* ── 4. طرق العرض (View Modes) ── */}

      {/* ── View A: صالة الاستقبال التفاعلية (Reception Floor Grid) ── */}
      {viewMode === 'grid' && (
        <div className="recv360-cards-grid">
          {filteredItems.length > 0 ? (
            filteredItems.map((c) => {
              const isLive = c.session === 'جلسة جارية';
              const isEnded = c.session === 'منتهية';
              const isMissed = c.missed;

              return (
                <div
                  key={c.id}
                  className="card"
                  style={{
                    margin: 0,
                    padding: 16,
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'space-between',
                    gap: 12,
                    boxShadow: isLive ? '0 4px 20px rgba(30, 157, 107, 0.15)' : '0 2px 10px rgba(0,0,0,0.03)',
                    border: isLive ? '1.5px solid #1E9D6B' : isMissed ? '1.5px solid #dc2626' : '1px solid rgba(0,0,0,0.08)',
                    cursor: 'pointer',
                    transition: 'transform 0.15s, box-shadow 0.15s',
                  }}
                  onClick={() => openDrawer(c.ref, 'actions')}
                  onMouseEnter={(e) => {
                    e.currentTarget.style.transform = 'translateY(-2px)';
                    e.currentTarget.style.boxShadow = '0 8px 20px rgba(0,0,0,0.08)';
                  }}
                  onMouseLeave={(e) => {
                    e.currentTarget.style.transform = 'translateY(0)';
                    e.currentTarget.style.boxShadow = isLive ? '0 4px 20px rgba(30, 157, 107, 0.15)' : '0 2px 10px rgba(0,0,0,0.03)';
                  }}
                >
                  {/* رأس البطاقة */}
                  <div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <div
                          style={{
                            width: 32,
                            height: 32,
                            borderRadius: 8,
                            background: isLive ? '#1E9D6B' : 'rgba(14, 92, 156, 0.1)',
                            color: isLive ? '#fff' : 'var(--primary)',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                          }}
                        >
                          <Icon name={crChannelIcon(c.channel)} />
                        </div>
                        <b style={{ color: 'var(--primary)', fontSize: 14 }}>{c.ref}</b>
                      </div>

                      <div style={{ display: 'flex', gap: 4 }}>
                        <Badge text={c.channel} tone={crChannelTone(c.channel)} />
                        {/*
                          * **الحالةُ الواحدة بلونٍ ونصٍّ واحدين في الشاشات كلّها.**
                          *
                          * كانت هذه الشاشة تكتب تسمياتها بيدها: «جارية الآن» **خضراء**
                          * بينما الكتالوج كهرمانيّ، و«منتهية» **رماديّة** بينما هي خضراء
                          * في كلّ شاشةٍ أخرى. فالمدير يرى الحالة بلونٍ، والموظّف يراها
                          * بلونٍ آخر، ولا أحد يعلم أيّهما المقصود.
                          */}
                        {!isMissed && c.session ? <Badge text={c.session} tone={sessTone(c.session)} /> : null}
                        {isMissed && <Badge text="فائتة" tone="b-red" />}
                      </div>
                    </div>

                    <div style={{ fontSize: 13.5, fontWeight: 700, marginTop: 8, color: '#13314F' }}>
                      {maskClient(c.client)}
                    </div>

                    <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4, lineHeight: 1.5 }}>
                      {c.subject}
                    </div>

                    <div
                      style={{
                        background: isLive ? 'rgba(30, 157, 107, 0.08)' : 'rgba(0,0,0,0.02)',
                        padding: '8px 10px',
                        borderRadius: 8,
                        margin: '10px 0',
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        fontSize: 11.5,
                      }}
                    >
                      <span>المستشار: <b>{c.lawyer}</b></span>
                      <span>المدة: <b>{c.duration || '—'}</b></span>
                    </div>

                    <div style={{ fontSize: 11, color: 'var(--muted)' }}>
                      الموعد: <b>{c.when}</b>
                    </div>
                  </div>

                  {/* شريط الإجراءات والتحكم السريع */}
                  <div
                    style={{
                      paddingTop: 12,
                      borderTop: '1px solid rgba(0,0,0,0.06)',
                      display: 'flex',
                      flexDirection: 'column',
                      gap: 8,
                    }}
                    onClick={(e) => e.stopPropagation()}
                  >
                    {/* حالة 1: الجلسة جارية الآن */}
                    {isLive && (
                      <div style={{ display: 'flex', gap: 6 }}>
                        {c.channel === 'مرئية' && (
                          <button
                            className="btn primary sm"
                            style={{ flex: 1, justifyContent: 'center' }}
                            type="button"
                            onClick={(e) => handleEnterRoom(c, e)}
                          >
                            <Icon name="video" /> دخول غرفة البث
                          </button>
                        )}
                        <button
                          className="btn soft sm"
                          style={{ flex: 1, justifyContent: 'center', color: '#1E9D6B' }}
                          type="button"
                          onClick={(e) => handleEnd(c, e)}
                        >
                          <Icon name="check" /> إنهاء وتوليد الملخص
                        </button>
                      </div>
                    )}

                    {/* حالة 2: بانتظار الجلسة */}
                    {!isLive && !isEnded && !isMissed && (
                      <div style={{ display: 'flex', gap: 6 }}>
                        {c.channel === 'مرئية' ? (
                          <button
                            className="btn primary sm"
                            style={{ flex: 1, justifyContent: 'center' }}
                            type="button"
                            onClick={(e) => handleEnterRoom(c, e)}
                          >
                            <Icon name="video" /> بدء / دخول الغرفة
                          </button>
                        ) : (
                          <button
                            className="btn primary sm"
                            style={{ flex: 1, justifyContent: 'center' }}
                            type="button"
                            onClick={(e) => handleStart(c, e)}
                          >
                            <Icon name="check" /> بدء الجلسة الآن
                          </button>
                        )}

                        {c.slink && (
                          <button
                            className="btn soft sm"
                            type="button"
                            onClick={(e) => handleCopyLink(c, e)}
                            title="نسخ رابط الاجتماع"
                          >
                            <Icon name="reply" />
                          </button>
                        )}
                      </div>
                    )}

                    {/* حالة 3: فائتة */}
                    {isMissed && (
                      <div style={{ display: 'flex', gap: 6 }}>
                        <button
                          className="btn soft sm"
                          style={{ flex: 1, justifyContent: 'center', color: '#dc2626' }}
                          type="button"
                          onClick={(e) => handleNoShow(c, e)}
                        >
                          وسم لم يحضر
                        </button>
                        <button
                          className="btn primary sm"
                          style={{ flex: 1, justifyContent: 'center' }}
                          type="button"
                          onClick={(e) => handleReschedule(c, e)}
                        >
                          إعادة الجدولة
                        </button>
                      </div>
                    )}

                    {/* حالة 4: منتهية */}
                    {isEnded && (
                      <button
                        className="btn soft sm"
                        type="button"
                        style={{ width: '100%', justifyContent: 'center' }}
                        onClick={() => openDrawer(c.ref, 'summary')}
                      >
                        <Icon name="out" /> {c.summary ? 'عرض الملخص والقرارات' : 'عرض السجل'}
                      </button>
                    )}
                  </div>
                </div>
              );
            })
          ) : (
            <div className="empty card" style={{ gridColumn: '1 / -1', padding: 40, textAlign: 'center' }}>
              <Icon name="compass" />
              <b style={{ display: 'block', marginTop: 8 }}>لا توجد جلسات مطابقة في صالة الاستقبال</b>
            </div>
          )}
        </div>
      )}

      {/* ── View B: غرفة العمليات والجدول الموحد ── */}
      {viewMode === 'table' && (
        <div className="card">
          <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <h3>جدول إدارة ومراقبة الجلسات الحية</h3>
            <span className="sub">{filteredItems.length} جلسة</span>
          </div>

          {/* 1) جدول الديسكتوب والتابلت */}
          <div className="card-b recv360-table-wrapper" style={{ padding: 0 }}>
            {filteredItems.length > 0 ? (
              <table className="recv360-desktop-table">
                <thead>
                  <tr style={{ background: 'rgba(0,0,0,0.03)', borderBottom: '1px solid rgba(0,0,0,0.08)' }}>
                    <th style={{ padding: '12px 16px' }}>المرجع والعميل</th>
                    <th style={{ padding: '12px 14px' }}>القناة والمستشار</th>
                    <th style={{ padding: '12px 14px' }}>الموضوع والتخصص</th>
                    <th style={{ padding: '12px 14px' }}>الموعد المحدد</th>
                    <th style={{ padding: '12px 14px' }}>الحالة</th>
                    <th style={{ padding: '12px 16px', textAlign: 'left' }}>التحكم والعمليات</th>
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
                      onClick={() => openDrawer(c.ref, 'actions')}
                      onMouseEnter={(e) => (e.currentTarget.style.background = 'rgba(14, 92, 156, 0.03)')}
                      onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                    >
                      {/* المرجع والعميل */}
                      <td style={{ padding: '12px 16px' }}>
                        <b style={{ color: 'var(--primary)' }}>{c.ref}</b>
                        <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>
                          {maskClient(c.client)}
                        </div>
                      </td>

                      {/* القناة والمستشار */}
                      <td style={{ padding: '12px 14px' }}>
                        <Badge text={c.channel} tone={crChannelTone(c.channel)} />
                        <div style={{ fontSize: 11.5, marginTop: 4 }}>المستشار: <b>{c.lawyer}</b></div>
                      </td>

                      {/* الموضوع والتخصص */}
                      <td style={{ padding: '12px 14px', maxWidth: 240 }}>
                        <div style={{ fontSize: 12.5, lineHeight: 1.4, color: '#333' }}>
                          {c.subject}
                        </div>
                        <span style={{ fontSize: 11, color: 'var(--muted)' }}>
                          تخصص: {(c as any).specialty || c.type || 'عام'}
                        </span>
                      </td>

                      {/* الموعد */}
                      <td style={{ padding: '12px 14px', fontSize: 12, color: 'var(--muted)' }}>
                        {c.when}
                      </td>

                      {/* الحالة */}
                      <td style={{ padding: '12px 14px' }}>
                        {c.missed ? (
                          <span style={{ color: '#dc2626', fontWeight: 700, fontSize: 12 }}>⚠️ فائتة</span>
                        ) : (
                          // والفرعُ الجامع كان يعرض **«لم تُعقد» بانتظارَ الجلسة** — فجلسةٌ
                          // أُغلقت آلياً تُعرض قادمةً، ويُنتظر عميلٌ لن يأتي.
                          <Badge text={c.session || 'بانتظار الجلسة'} tone={sessTone(c.session)} />
                        )}
                      </td>

                      {/* العمليات */}
                      <td
                        style={{ padding: '12px 16px', textAlign: 'left' }}
                        onClick={(e) => e.stopPropagation()}
                      >
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                          {c.session === 'جلسة جارية' ? (
                            <>
                              {c.channel === 'مرئية' && (
                                <button className="btn primary sm" type="button" onClick={(e) => handleEnterRoom(c, e)}>
                                  <Icon name="video" /> الغرفة
                                </button>
                              )}
                              <button className="btn soft sm" type="button" onClick={(e) => handleEnd(c, e)}>
                                <Icon name="check" /> إنهاء
                              </button>
                            </>
                          ) : c.missed ? (
                            <>
                              <button className="btn soft sm" type="button" onClick={(e) => handleNoShow(c, e)}>
                                لم يحضر
                              </button>
                              <button className="btn primary sm" type="button" onClick={(e) => handleReschedule(c, e)}>
                                جدولة
                              </button>
                            </>
                          ) : c.session === 'منتهية' ? (
                            <button className="btn soft sm" type="button" onClick={() => openDrawer(c.ref, 'summary')}>
                              <Icon name="out" /> الملخص
                            </button>
                          ) : (
                            <button
                              className="btn primary sm"
                              type="button"
                              onClick={(e) => (c.channel === 'مرئية' ? handleEnterRoom(c, e) : handleStart(c, e))}
                            >
                              <Icon name="check" /> بدء الجلسة
                            </button>
                          )}

                          <button
                            className="btn soft sm"
                            type="button"
                            onClick={() => openDrawer(c.ref, 'details')}
                            title="تفاصيل الجلسة"
                          >
                            <Icon name="compass" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <div className="empty" style={{ padding: 40, textAlign: 'center' }}>
                <Icon name="compass" />
                <b style={{ display: 'block', marginTop: 8 }}>لا توجد جلسات مطابقة</b>
              </div>
            )}
          </div>

          {/* 2) كروت الموبايل التفاعلية */}
          <div className="recv360-mobile-cards">
            {filteredItems.length > 0 ? (
              filteredItems.map((c) => (
                <div
                  key={c.id}
                  style={{
                    background: '#fff',
                    border: c.session === 'جلسة جارية' ? '1.5px solid #1E9D6B' : '1px solid rgba(0,0,0,0.08)',
                    borderRadius: 12,
                    padding: 14,
                    boxShadow: '0 2px 8px rgba(0,0,0,0.03)',
                    cursor: 'pointer',
                  }}
                  onClick={() => openDrawer(c.ref, 'actions')}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <div>
                      <b style={{ color: 'var(--primary)', fontSize: 13.5 }}>{c.ref}</b>
                      <div style={{ fontSize: 12, color: 'var(--muted)' }}>{maskClient(c.client)}</div>
                    </div>
                    <Badge text={c.channel} tone={crChannelTone(c.channel)} />
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
                    <span>الموعد: <b>{c.when}</b></span>
                  </div>

                  <div
                    style={{
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                      paddingTop: 8,
                      borderTop: '1px solid rgba(0,0,0,0.06)',
                      gap: 6,
                    }}
                    onClick={(e) => e.stopPropagation()}
                  >
                    <button
                      className="btn primary sm"
                      type="button"
                      onClick={() => openDrawer(c.ref, 'actions')}
                      style={{ fontSize: 11.5, padding: '5px 10px', width: '100%', justifyContent: 'center' }}
                    >
                      <Icon name="compass" /> غرفة التحكم بالجلسة
                    </button>
                  </div>
                </div>
              ))
            ) : (
              <div className="empty" style={{ padding: 30, textAlign: 'center' }}>
                <Icon name="compass" />
                <b style={{ display: 'block', marginTop: 6 }}>لا توجد جلسات مطابقة</b>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── 5. درج التحكم بالجلسة والملخص (Slide-Over Portal Drawer) ── */}
      {drawerItem && typeof document !== 'undefined' && createPortal(
        <div
          className="recv360-portal-backdrop"
          onClick={(e) => {
            if (e.target === e.currentTarget) {
              closeDrawer();
            }
          }}
          aria-modal="true"
          role="dialog"
        >
          <div
            className="recv360-drawer-panel"
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
                  <h3 style={{ margin: 0, color: 'var(--primary)', fontSize: 17 }}>{drawerItem.ref}</h3>
                  <Badge text={drawerItem.channel} tone={crChannelTone(drawerItem.channel)} />
                  {drawerItem.session && <Badge text={drawerItem.session} tone={sessTone(drawerItem.session)} />}
                  {drawerItem.missed && <Badge text="فائتة" tone="b-red" />}
                </div>
                <div style={{ fontSize: 12.5, color: 'var(--muted)', marginTop: 4, textOverflow: 'ellipsis', overflow: 'hidden', whiteSpace: 'nowrap' }}>
                  العميل: {maskClient(drawerItem.client)} · المستشار: {drawerItem.lawyer}
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
            <div className="recv360-drawer-tabs">
              {(
                [
                  ['actions', 'التحكم والإجراءات', 'compass'],
                  ['summary', 'ملخص الجلسة', 'doc'],
                  ['details', 'بيانات الاستشارة', 'info'],
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
              {/* Tab 1: التحكم والإجراءات المباشرة */}
              {drawerTab === 'actions' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  <div className="card" style={{ margin: 0, padding: 16 }}>
                    <b style={{ color: 'var(--primary)', fontSize: 14 }}>لوحة الإجراءات التشغيلية للجلسة:</b>
                    <p style={{ fontSize: 12, color: 'var(--muted)', margin: '4px 0 14px' }}>
                      التحكم في بث الجلسة، تسجيل الحضور، والإنهاء المباشر.
                    </p>

                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                      {/* إجراءات الجلسة الجارية */}
                      {drawerItem.session === 'جلسة جارية' ? (
                        <>
                          {drawerItem.channel === 'مرئية' && (
                            <button
                              className="btn primary"
                              style={{ width: '100%', justifyContent: 'center' }}
                              type="button"
                              onClick={(e) => handleEnterRoom(drawerItem, e)}
                            >
                              <Icon name="video" /> دخول غرفة البث المرئي المباشر
                            </button>
                          )}
                          <button
                            className="btn soft"
                            style={{ width: '100%', justifyContent: 'center', color: '#1E9D6B', fontWeight: 700 }}
                            type="button"
                            onClick={(e) => handleEnd(drawerItem, e)}
                          >
                            <Icon name="check" /> إنهاء الجلسة وتوليد الملخص القانوني
                          </button>
                        </>
                      ) : drawerItem.missed ? (
                        <>
                          <div
                            style={{
                              background: 'rgba(220, 38, 38, 0.05)',
                              border: '1px solid rgba(220, 38, 38, 0.15)',
                              borderRadius: 8,
                              padding: '10px 12px',
                              fontSize: 12,
                              lineHeight: 1.6,
                              color: '#991b1b',
                              marginBottom: 4,
                            }}
                          >
                            <div style={{ fontWeight: 700, display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4 }}>
                              <Icon name="clock" /> الاستشارة فاتت موعدها المحدد
                            </div>
                            <span>
                              <b>إعادة الجدولة:</b> تُلغي الموعد وجلسة Zoom السابقة، وتُعيد الاستشارة لحالة «بانتظار تحديد الموعد»، وتُشعر العميل فورياً لاختيار موعد جديد عبر حسابه.
                            </span>
                          </div>

                          <button
                            className="btn soft"
                            style={{ width: '100%', justifyContent: 'center', color: '#dc2626' }}
                            type="button"
                            onClick={(e) => handleNoShow(drawerItem, e)}
                          >
                            وسم الاستشارة «لم يحضر العميل»
                          </button>
                          <button
                            className="btn primary"
                            style={{ width: '100%', justifyContent: 'center' }}
                            type="button"
                            onClick={(e) => handleReschedule(drawerItem, e)}
                          >
                            إعادة الجدولة وإشعار العميل
                          </button>
                        </>
                      ) : drawerItem.session === 'منتهية' ? (
                        <div style={{ textAlign: 'center', padding: 16, background: 'rgba(0,0,0,0.02)', borderRadius: 8 }}>
                          <Icon name="check" />
                          <b style={{ display: 'block', marginTop: 4, color: '#1E9D6B' }}>الجلسة منتهية وموثقة</b>
                          <p style={{ fontSize: 12, color: 'var(--muted)', margin: '4px 0 10px' }}>
                            تم حفظ الملخص ومخرجات الجلسة في الأرشيف بنجاح.
                          </p>
                          <button
                            className="btn soft sm"
                            type="button"
                            style={{ margin: '0 auto' }}
                            onClick={() => setDrawerTab('summary')}
                          >
                            عرض ملخص الجلسة
                          </button>
                        </div>
                      ) : (
                        <>
                          {drawerItem.channel === 'مرئية' ? (
                            <button
                              className="btn primary"
                              style={{ width: '100%', justifyContent: 'center' }}
                              type="button"
                              onClick={(e) => handleEnterRoom(drawerItem, e)}
                            >
                              <Icon name="video" /> بدء الجلسة ودخول غرفة البث
                            </button>
                          ) : (
                            <button
                              className="btn primary"
                              style={{ width: '100%', justifyContent: 'center' }}
                              type="button"
                              onClick={(e) => handleStart(drawerItem, e)}
                            >
                              <Icon name="check" /> تسجيل الحضور وبدء الجلسة الآن
                            </button>
                          )}

                          {drawerItem.slink && (
                            <button
                              className="btn soft"
                              style={{ width: '100%', justifyContent: 'center' }}
                              type="button"
                              onClick={(e) => handleCopyLink(drawerItem, e)}
                            >
                              <Icon name="reply" /> نسخ رابط الانضمام للعميل
                            </button>
                          )}
                        </>
                      )}
                    </div>
                  </div>
                </div>
              )}

              {/* Tab 2: الملخص القانوني والقرارات */}
              {drawerTab === 'summary' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  <div className="card" style={{ margin: 0, padding: 16 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                      <b style={{ color: 'var(--primary)', fontSize: 14 }}>ملخص الجلسة الاستشارية:</b>
                      <span style={{ fontSize: 12, color: 'var(--muted)' }}>المدة: {drawerItem.duration || '—'}</span>
                    </div>

                    {drawerItem.summary ? (
                      <div
                        style={{
                          fontSize: 13.5,
                          lineHeight: 1.9,
                          whiteSpace: 'pre-wrap',
                          color: '#222',
                          background: 'rgba(0,0,0,0.02)',
                          padding: 14,
                          borderRadius: 8,
                          border: '1px solid rgba(0,0,0,0.06)',
                        }}
                      >
                        {drawerItem.summary}
                      </div>
                    ) : (
                      <div style={{ textAlign: 'center', color: 'var(--muted)', padding: 30 }}>
                        <Icon name="doc" />
                        <div style={{ marginTop: 6 }}>لم يُولد ملخص كتابي لهذه الجلسة بعد</div>
                      </div>
                    )}
                  </div>

                  {/* القرارات */}
                  {drawerItem.decisions && drawerItem.decisions.length > 0 && (
                    <div className="card" style={{ margin: 0, padding: 16 }}>
                      <b style={{ color: 'var(--primary)', fontSize: 14 }}>القرارات المعتمدة:</b>
                      <ul style={{ margin: '10px 0 0', paddingRight: 20, fontSize: 13, lineHeight: 1.9 }}>
                        {drawerItem.decisions.map((dec: any, idx: number) => (
                          <li key={idx}>{typeof dec === 'string' ? dec : JSON.stringify(dec)}</li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              )}

              {/* Tab 3: بيانات وتفاصيل الجلسة */}
              {drawerTab === 'details' && (
                <>
                  <div className="card" style={{ margin: 0, padding: 14 }}>
                    <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', marginBottom: 6 }}>
                      موضوع الاستشارة:
                    </div>
                    <div style={{ fontSize: 14, fontWeight: 600, lineHeight: 1.6 }}>{drawerItem.subject}</div>
                  </div>

                  <div className="card" style={{ margin: 0, padding: 14, display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: 12 }}>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>العميل:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{maskClient(drawerItem.client)}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>المستشار:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerItem.lawyer}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>التخصص:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{(drawerItem as any).specialty || drawerItem.type || 'عام'}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>القناة:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerItem.channel}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>الموعد المحدد:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerItem.when}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>المكان / الرابط:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerItem.place || 'عبر المنصة'}</div>
                    </div>
                  </div>

                  {/* الانتقال لصفحة التفاصيل الكاملة */}
                  <button
                    className="btn soft"
                    style={{ width: '100%', justifyContent: 'center' }}
                    type="button"
                    onClick={() => router.visit(`/admin/consult?ref=${encodeURIComponent(drawerItem.ref)}`)}
                  >
                    <Icon name="out" /> الانتقال لصفحة ملف الاستشارة الكامل
                  </button>
                </>
              )}

              {/* Tab 4: سجل التدقيق الزمني */}
              {drawerTab === 'audit' && (
                <div className="card" style={{ margin: 0, padding: 16 }}>
                  <b style={{ color: 'var(--primary)', fontSize: 14 }}>سجل العمليات والتدقيق:</b>
                  {drawerItem.audit && drawerItem.audit.length > 0 ? (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 12 }}>
                      {drawerItem.audit.map((entry: any, idx: number) => (
                        <div
                          key={idx}
                          style={{
                            background: 'rgba(0,0,0,0.02)',
                            padding: 10,
                            borderRadius: 8,
                            fontSize: 12.5,
                            borderRight: '3px solid var(--primary)',
                          }}
                        >
                          <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--muted)', fontSize: 11 }}>
                            <span>المستخدم: <b>{entry.user}</b></span>
                            <span>{entry.time}</span>
                          </div>
                          <div style={{ marginTop: 4 }}>
                            {entry.field}: <span style={{ color: 'var(--muted)' }}>{entry.before}</span> ← <b style={{ color: 'var(--primary)' }}>{entry.after}</b>
                          </div>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div style={{ textAlign: 'center', color: 'var(--muted)', padding: 30 }}>
                      <Icon name="clock" />
                      <div style={{ marginTop: 6 }}>لا توجد قيود تدقيق مسجلة</div>
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

      {/* نافذة التدوين — مادّة الملخّص الوحيدة (نظير `ConsultRecvPage`) */}
      <Modal
        title={`إنهاء الجلسة وتدوين ما دار — ${endingOf?.ref ?? ''}`}
        open={!!endingOf}
        onClose={() => setEndingOf(null)}
      >
        <div className="field">
          <label>ما دار في الجلسة (وقائع العميل، ما طُلب، ما تقرّر)</label>
          <textarea
            className="input"
            rows={9}
            value={endNotes}
            onChange={(ev) => setEndNotes(ev.target.value)}
          />
        </div>
        <p className="action-hint">
          <Icon name="info" /> الملخّص يُبنى على التدوين وحده — وبلا تدوين لا يُكتب شيء، لأنّ ما يُكتب من عنوان الموضوع وحده محضرٌ مختلَق.
        </p>
        <div style={{ display: 'flex', gap: 9, flexWrap: 'wrap' }}>
          <button className="btn" onClick={submitEnd} disabled={endNotes.trim() === ''} type="button">
            <Icon name="doc" /> إنهاء وحفظ التدوين
          </button>
          <button className="btn soft" onClick={submitEnd} type="button">
            <Icon name="check" /> إنهاء بلا تدوين
          </button>
        </div>
      </Modal>

      {/* ── 6. نافذة تأكيد إعادة الجدولة المنبثقة (Confirmation Modal Portal) ── */}
      {rescheduleTarget && typeof document !== 'undefined' && createPortal(
        <div
          style={{
            position: 'fixed',
            inset: 0,
            zIndex: 99995,
            background: 'rgba(10, 25, 45, 0.65)',
            backdropFilter: 'blur(5px)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            padding: 16,
            direction: 'rtl',
            animation: 'recv360FadeIn 0.2s ease-out',
          }}
          onClick={(e) => {
            if (e.target === e.currentTarget && !isRescheduling) {
              setRescheduleTarget(null);
            }
          }}
          aria-modal="true"
          role="dialog"
        >
          <div
            style={{
              background: '#fff',
              borderRadius: 16,
              maxWidth: 520,
              width: '100%',
              boxShadow: '0 20px 50px rgba(0,0,0,0.3)',
              overflow: 'hidden',
              display: 'flex',
              flexDirection: 'column',
              animation: 'recv360FadeIn 0.25s ease-out',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            {/* رأس النافذة */}
            <div
              style={{
                padding: '18px 22px',
                background: 'linear-gradient(135deg, rgba(220, 38, 38, 0.08), rgba(245, 158, 11, 0.08))',
                borderBottom: '1px solid rgba(0,0,0,0.08)',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <div
                  style={{
                    width: 38,
                    height: 38,
                    borderRadius: 10,
                    background: 'rgba(220, 38, 38, 0.12)',
                    color: '#dc2626',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: 18,
                  }}
                >
                  <Icon name="clock" />
                </div>
                <div>
                  <h3 style={{ margin: 0, fontSize: 16, color: '#13314F' }}>
                    تأكيد إعادة جدولة الاستشارة ({rescheduleTarget.ref})
                  </h3>
                  <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                    العميل: {maskClient(rescheduleTarget.client)}
                  </span>
                </div>
              </div>

              <button
                type="button"
                onClick={() => !isRescheduling && setRescheduleTarget(null)}
                style={{
                  background: 'none',
                  border: 'none',
                  fontSize: 18,
                  cursor: isRescheduling ? 'not-allowed' : 'pointer',
                  color: 'var(--muted)',
                  padding: 4,
                }}
              >
                ✕
              </button>
            </div>

            {/* محتوى النافذة والنص التوضيحي الشامل */}
            <div style={{ padding: 22, display: 'flex', flexDirection: 'column', gap: 14 }}>
              {/* بطاقة معلومات الجلسة الحالية */}
              <div
                style={{
                  background: 'rgba(0,0,0,0.02)',
                  border: '1px solid rgba(0,0,0,0.06)',
                  borderRadius: 10,
                  padding: '12px 14px',
                  display: 'grid',
                  gridTemplateColumns: 'repeat(2, 1fr)',
                  gap: 10,
                  fontSize: 12.5,
                }}
              >
                <div>
                  <span style={{ color: 'var(--muted)', fontSize: 11 }}>الموعد الحالي:</span>
                  <div style={{ fontWeight: 600, marginTop: 2 }}>{rescheduleTarget.when}</div>
                </div>
                <div>
                  <span style={{ color: 'var(--muted)', fontSize: 11 }}>المستشار المسند:</span>
                  <div style={{ fontWeight: 600, marginTop: 2 }}>{rescheduleTarget.lawyer}</div>
                </div>
                <div>
                  <span style={{ color: 'var(--muted)', fontSize: 11 }}>القناة:</span>
                  <div style={{ fontWeight: 600, marginTop: 2 }}>{rescheduleTarget.channel}</div>
                </div>
                <div>
                  <span style={{ color: 'var(--muted)', fontSize: 11 }}>الحالة:</span>
                  <div style={{ fontWeight: 600, marginTop: 2, color: '#dc2626' }}>
                    {rescheduleTarget.missed ? 'فائتة / لم تنعقد' : rescheduleTarget.session}
                  </div>
                </div>
              </div>

              {/* ما الذي سيحدث عند إعادة الجدولة؟ */}
              <div
                style={{
                  background: 'rgba(14, 92, 156, 0.04)',
                  borderRight: '4px solid var(--primary)',
                  borderRadius: 8,
                  padding: '12px 14px',
                  fontSize: 12.5,
                  lineHeight: 1.7,
                }}
              >
                <b style={{ color: 'var(--primary)', display: 'block', marginBottom: 6 }}>
                  ما الذي سينفذه النظام عند تأكيد إعادة الجدولة؟
                </b>
                <ul style={{ margin: 0, paddingRight: 18, color: '#333' }}>
                  <li>
                    <b>إلغاء الموعد القديم:</b> سيتم وسم الموعد السابق كـ «ملغي» وتصفير رابط وبيانات اجتماع Zoom.
                  </li>
                  <li>
                    <b>إعادة فتح حجز الموعد:</b> ستتحول حالة الاستشارة إلى «بانتظار تحديد الموعد».
                  </li>
                  <li>
                    <b>إشعار فوري للعميل:</b> سيصل العميل إشعار تنبيه على حسابه فورياً لاختيار موعد جديد عبر صفحة «استشاراتي».
                  </li>
                </ul>
              </div>

              <p style={{ margin: 0, fontSize: 12, color: 'var(--muted)', lineHeight: 1.5 }}>
                ⚠️ هل أنت متأكد من رغبتك في إعادة الجدولة الآن وإلغاء الموعد الحالي؟
              </p>
            </div>

            {/* أزرار الإجراء */}
            <div
              style={{
                padding: '14px 22px',
                borderTop: '1px solid rgba(0,0,0,0.08)',
                background: '#fafafa',
                display: 'flex',
                justifyContent: 'flex-end',
                gap: 10,
              }}
            >
              <button
                type="button"
                className="btn soft sm"
                disabled={isRescheduling}
                onClick={() => setRescheduleTarget(null)}
                style={{ padding: '8px 18px', fontSize: 13 }}
              >
                تراجع / إلغاء
              </button>

              <button
                type="button"
                className="btn primary sm"
                disabled={isRescheduling}
                onClick={confirmReschedule}
                style={{
                  padding: '8px 20px',
                  fontSize: 13,
                  fontWeight: 700,
                  background: '#dc2626',
                  borderColor: '#dc2626',
                }}
              >
                {isRescheduling ? (
                  <>جاري المعالجة وإشعار العميل…</>
                ) : (
                  <>
                    <Icon name="check" /> تأكيد إعادة الجدولة وإشعار العميل
                  </>
                )}
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}
    </div>
  );
};

export default AdminConsultRecv;
