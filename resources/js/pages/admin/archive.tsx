import { router } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '@/components/babylon/Badge';
import { useBodyScrollLock } from '@/components/babylon/Modal';
import { maskClient } from '@/lib/admin-data';
import { crChannelIcon, crChannelTone } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { InlinePlayer, MediaButton } from '@/lib/recording-ui';

interface ArchiveRow {
  id: number;
  ref: string;
  channel: string;
  ctype: string;
  client: string;
  lawyer: string;
  specialty: string;
  subject: string;
  date: string;
  dur: string;
  total: number;
  status: string;
  summary: string | null;
  hasSummary: boolean;
  decisions: string[];
  /** تشغيل الفيديو/الصوت داخل الصفحة عبر الخادم — بديل رابط سحابة Zoom الذي كان يُرسل هنا. */
  stream: string | null;
  audioStream: string | null;
  zip: string | null;
  audioZip: string | null;
  transcript: string | null;
  /** هل الملفّ مبنيٌّ على القرص؟ `false` تعني «يُحضَّر عند الطلب» لا «غير متاح». */
  videoReady: boolean;
  audioReady: boolean;
  transcriptReady: boolean;
}

interface AdminArchiveProps {
  rows: ArchiveRow[];
}

type ViewMode = 'grid' | 'table';
type MediaFilter = 'all' | 'video' | 'audio' | 'transcript' | 'summary';
type DrawerTab = 'summary' | 'decisions' | 'media' | 'details';

// `MediaButton` (زرُّ وسيطٍ يقول ما سيفعله: تنزيل أو تحضير) انتقل إلى recording-ui — مصدرٌ واحد لكلّ الشاشات

export const AdminArchive: React.FC<AdminArchiveProps> = ({ rows = [] }) => {
  // State Management
  const [viewMode, setViewMode] = useState<ViewMode>('grid');
  const [mediaFilter, setMediaFilter] = useState<MediaFilter>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [channelFilter, setChannelFilter] = useState<string>('all');
  const [specialtyFilter, setSpecialtyFilter] = useState<string>('all');
  const [lawyerFilter, setLawyerFilter] = useState<string>('all');

  // Quick Action Drawer (Controlled by Ref String)
  const [drawerRef, setDrawerRef] = useState<string | null>(null);
  const [drawerTab, setDrawerTab] = useState<DrawerTab>('summary');

  // Currently active consult in the drawer
  const drawerItem = useMemo(() => {
    if (!drawerRef) {
return null;
}

    return rows.find((r) => r.ref === drawerRef) || null;
  }, [rows, drawerRef]);

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

  // Open & Close Drawer Actions
  const openDrawer = (ref: string, initialTab: DrawerTab = 'summary') => {
    setDrawerRef(ref);
    setDrawerTab(initialTab);
  };

  const closeDrawer = () => {
    setDrawerRef(null);
  };

  // Telemetry & KPI Computations
  const telemetry = useMemo(() => {
    const total = rows.length;
    const videoCount = rows.filter((r) => r.zip).length;
    const audioCount = rows.filter((r) => r.audioZip).length;
    const transcriptCount = rows.filter((r) => r.transcript).length;
    const summaryCount = rows.filter((r) => r.hasSummary).length;

    return {
      total,
      videoCount,
      audioCount,
      transcriptCount,
      summaryCount,
    };
  }, [rows]);

  // Unique lists for filter dropdowns
  const specialtiesList = useMemo(() => {
    const set = new Set<string>();
    rows.forEach((r) => {
      if (r.specialty && r.specialty.trim() !== '') {
set.add(r.specialty.trim());
}
    });

    return Array.from(set);
  }, [rows]);

  const assignedLawyersList = useMemo(() => {
    const set = new Set<string>();
    rows.forEach((r) => {
      if (r.lawyer && r.lawyer !== '—') {
set.add(r.lawyer.trim());
}
    });

    return Array.from(set);
  }, [rows]);

  // Filtered dataset
  const filteredRows = useMemo(() => {
    return rows.filter((r) => {
      if (mediaFilter === 'video' && !r.zip) {
return false;
}

      if (mediaFilter === 'audio' && !r.audioZip) {
return false;
}

      if (mediaFilter === 'transcript' && !r.transcript) {
return false;
}

      if (mediaFilter === 'summary' && !r.hasSummary) {
return false;
}

      if (channelFilter !== 'all' && r.channel !== channelFilter) {
return false;
}

      if (specialtyFilter !== 'all' && r.specialty !== specialtyFilter) {
return false;
}

      if (lawyerFilter !== 'all' && r.lawyer !== lawyerFilter) {
return false;
}

      if (searchQuery.trim() !== '') {
        const q = searchQuery.toLowerCase().trim();
        const refMatch = (r.ref || '').toLowerCase().includes(q);
        const clientMatch = (r.client || '').toLowerCase().includes(q);
        const lawyerMatch = (r.lawyer || '').toLowerCase().includes(q);
        const subjectMatch = (r.subject || '').toLowerCase().includes(q);

        if (!refMatch && !clientMatch && !lawyerMatch && !subjectMatch) {
return false;
}
      }

      return true;
    });
  }, [rows, mediaFilter, channelFilter, specialtyFilter, lawyerFilter, searchQuery]);

  return (
    <div className="admin-archive-360-root" style={{ paddingBottom: 60, width: '100%' }}>
      {/* ── CSS المخصص للاستجابة والشاشة الكاملة والأنيميشن ── */}
      <style>{`
        .admin-archive-360-root {
          box-sizing: border-box;
          width: 100%;
        }

        /* الهيدر ومبدل طرق العرض */
        .arc360-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          flex-wrap: wrap;
          gap: 14px;
          margin-bottom: 16px;
        }
        .arc360-view-switcher {
          display: flex;
          background: rgba(0,0,0,0.06);
          padding: 4px;
          border-radius: 10px;
          gap: 4px;
        }

        /* شبكة بطاقات الإحصائيات (KPI Ribbon) */
        .arc360-kpi-grid {
          display: grid;
          grid-template-columns: repeat(5, 1fr);
          gap: 12px;
          margin: 16px 0 20px;
        }

        /* شريط فلترة المراحل */
        .arc360-cat-scroll {
          display: flex;
          gap: 8px;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
          padding-bottom: 4px;
          white-space: nowrap;
        }
        .arc360-cat-scroll::-webkit-scrollbar {
          height: 4px;
        }
        .arc360-cat-scroll::-webkit-scrollbar-thumb {
          background: rgba(0,0,0,0.15);
          border-radius: 4px;
        }

        /* شبكة حقول البحث والقوائم المنسدلة */
        .arc360-filter-grid {
          display: grid;
          grid-template-columns: 2fr repeat(3, 1fr);
          gap: 10px;
        }

        /* شبكة بطاقات الوسائط */
        .arc360-cards-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
          gap: 16px;
        }

        /* عروض الجدول: الديسكتوب مقابل كروت الموبايل */
        .arc360-table-wrapper {
          display: block;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
        }
        .arc360-desktop-table {
          width: 100%;
          border-collapse: collapse;
          text-align: right;
          font-size: 13px;
          min-width: 780px;
        }
        .arc360-mobile-cards {
          display: none;
        }

        /* أنيميشن الدرج المنبثق والخلفية الحرة على مستوى الشاشة */
        @keyframes arc360FadeIn {
          from { opacity: 0; }
          to { opacity: 1; }
        }
        @keyframes arc360SlideInRight {
          from { transform: translateX(100%); }
          to { transform: translateX(0); }
        }

        .arc360-portal-backdrop {
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
          animation: arc360FadeIn 0.2s ease-out;
        }

        .arc360-drawer-panel {
          width: 100% !important;
          max-width: 580px !important;
          height: 100vh !important;
          background: #fff !important;
          box-shadow: -10px 0 35px rgba(0,0,0,0.35) !important;
          display: flex !important;
          flex-direction: column !important;
          box-sizing: border-box !important;
          animation: arc360SlideInRight 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .arc360-drawer-tabs {
          display: flex;
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
          border-bottom: 1px solid rgba(0,0,0,0.08);
          background: #fafafa;
          white-space: nowrap;
        }

        /* ── استجابة الشاشات المتوسطة والتابلت (Max 1180px) ── */
        @media (max-width: 1180px) {
          .arc360-kpi-grid {
            grid-template-columns: repeat(3, 1fr);
          }
          .arc360-filter-grid {
            grid-template-columns: repeat(2, 1fr);
          }
        }

        /* ── استجابة التابلت والموبايل (Max 768px) ── */
        @media (max-width: 768px) {
          .arc360-header {
            flex-direction: column;
            align-items: stretch;
          }
          .arc360-view-switcher {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            width: 100%;
          }
          .arc360-kpi-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
          }
          .arc360-filter-grid {
            grid-template-columns: 1fr;
          }

          /* تحويل الجدول إلى كروت لمس ذكية وتفاعلية على الشاشات الصغيرة */
          .arc360-table-wrapper {
            display: none;
          }
          .arc360-mobile-cards {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 12px;
          }
          .arc360-drawer-panel {
            max-width: 100% !important;
          }
        }

        /* ── استجابة الشاشات الصغيرة جداً (Max 420px) ── */
        @media (max-width: 420px) {
          .arc360-view-switcher {
            grid-template-columns: 1fr;
          }
          .arc360-kpi-grid {
            grid-template-columns: 1fr;
          }
        }
      `}</style>

      {/* ── 1. الهيدر والترحيب ومبدل طرق العرض ── */}
      <div className="greet arc360-header">
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0, fontSize: 'clamp(17px, 2.5vw, 22px)' }}>
            <Icon name="video" cls="ic" />
            أرشيف ومستودع وسائط الاستشارات 360° — الإدارة العليا
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--muted)', fontSize: 13 }}>
            المستودع السحابي الموثق لتسجيلات الفيديو والصوتيات، التفريغات النصية، وملخصات وقرارات الجلسات المنتهية.
          </p>
        </div>

        {/* مبدل العرض المتكيف */}
        <div className="arc360-view-switcher">
          <button
            type="button"
            className={`btn sm ${viewMode === 'grid' ? 'primary' : 'soft'}`}
            style={{ borderRadius: 8, padding: '7px 14px', fontSize: 12.5, justifyContent: 'center' }}
            onClick={() => setViewMode('grid')}
          >
            <Icon name="compass" /> شبكة الوسائط
          </button>
          <button
            type="button"
            className={`btn sm ${viewMode === 'table' ? 'primary' : 'soft'}`}
            style={{ borderRadius: 8, padding: '7px 14px', fontSize: 12.5, justifyContent: 'center' }}
            onClick={() => setViewMode('table')}
          >
            <Icon name="doc" /> الجدول الأرشيفي
          </button>
        </div>
      </div>

      {/* ── 2. شريط المؤشرات الأرشيفية اللحظي (KPI Ribbon) ── */}
      <div className="arc360-kpi-grid">
        {/* إجمالي الاستشارات المؤرشفة */}
        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid var(--primary)' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>إجمالي الجلسات المؤرشفة</span>
            <Icon name="folder" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: 'var(--primary)', marginTop: 4 }}>
            {telemetry.total}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>جلسات مكتملة ومنعقدة</div>
        </div>

        {/* تسجيلات الفيديو MP4 */}
        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #11A0C8' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>تسجيلات مرئية (MP4)</span>
            <Icon name="video" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#11A0C8', marginTop: 4 }}>
            {telemetry.videoCount}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>سحابة Zoom والملفات</div>
        </div>

        {/* التسجيلات الصوتية M4A */}
        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #1E9D6B' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>تسجيلات صوتية (M4A)</span>
            <Icon name="mic" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#1E9D6B', marginTop: 4 }}>
            {telemetry.audioCount}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>حزم صوتية نقية مضغوطة</div>
        </div>

        {/* التفريغات النصية TXT */}
        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #0E5C9C' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>تفريغات نصية (TXT)</span>
            <Icon name="doc" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#0E5C9C', marginTop: 4 }}>
            {telemetry.transcriptCount}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>تفريغ الحوار الذكي</div>
        </div>

        {/* الملخصات والقرارات */}
        <div className="card" style={{ padding: '12px 14px', margin: 0, borderRight: '4px solid #C0832B' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>ملخصات معتمدة</span>
            <Icon name="check" />
          </div>
          <div style={{ fontSize: 'clamp(20px, 3vw, 24px)', fontWeight: 800, color: '#C0832B', marginTop: 4 }}>
            {telemetry.summaryCount}
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>قرارات وتوصيات قانونية</div>
        </div>
      </div>

      {/* ── 3. شريط الفلترة والبحث الذكي ── */}
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
        {/* شريط تمرير أزرار الوسائط (Category Pills) */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', flexShrink: 0 }}>نوع الوسائط:</span>
          <div className="arc360-cat-scroll" style={{ flex: 1 }}>
            {(
              [
                ['all', 'جميع السجلات المؤرشفة', rows.length],
                ['video', 'جلسات بفيديو MP4', telemetry.videoCount],
                ['audio', 'جلسات بتسجيل صوتي M4A', telemetry.audioCount],
                ['transcript', 'جلسات بتفريغ نصي TXT', telemetry.transcriptCount],
                ['summary', 'جلسات بملخص وقرارات', telemetry.summaryCount],
              ] as const
            ).map(([key, label, count]) => (
              <button
                key={key}
                type="button"
                onClick={() => setMediaFilter(key)}
                style={{
                  border: 'none',
                  background: mediaFilter === key ? 'var(--primary)' : 'rgba(0,0,0,0.05)',
                  color: mediaFilter === key ? '#fff' : 'inherit',
                  borderRadius: 20,
                  padding: '6px 12px',
                  fontSize: 12,
                  cursor: 'pointer',
                  fontWeight: mediaFilter === key ? 700 : 500,
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
                    background: mediaFilter === key ? 'rgba(255,255,255,0.25)' : 'rgba(0,0,0,0.1)',
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
        <div className="arc360-filter-grid">
          {/* حقل البحث */}
          <div style={{ position: 'relative' }}>
            <input
              type="text"
              placeholder="بحث بالمرجع، العميل، المستشار، الموضوع..."
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
        </div>
      </div>

      {/* ── 4. طرق العرض (View Modes) ── */}

      {/* ── View A: شبكة بطاقات الوسائط والمستودع ── */}
      {viewMode === 'grid' && (
        <div className="arc360-cards-grid">
          {filteredRows.length > 0 ? (
            filteredRows.map((a) => (
              <div
                key={a.ref}
                className="card"
                style={{
                  margin: 0,
                  padding: 16,
                  display: 'flex',
                  flexDirection: 'column',
                  justifyContent: 'space-between',
                  gap: 12,
                  boxShadow: '0 2px 10px rgba(0,0,0,0.03)',
                  border: '1px solid rgba(0,0,0,0.08)',
                  cursor: 'pointer',
                  transition: 'transform 0.15s, box-shadow 0.15s',
                }}
                onClick={() => openDrawer(a.ref, 'summary')}
                onMouseEnter={(e) => {
                  e.currentTarget.style.transform = 'translateY(-2px)';
                  e.currentTarget.style.boxShadow = '0 8px 20px rgba(0,0,0,0.08)';
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.transform = 'translateY(0)';
                  e.currentTarget.style.boxShadow = '0 2px 10px rgba(0,0,0,0.03)';
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
                          background: 'rgba(14, 92, 156, 0.1)',
                          color: 'var(--primary)',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                        }}
                      >
                        <Icon name={crChannelIcon(a.channel)} />
                      </div>
                      <b style={{ color: 'var(--primary)', fontSize: 14 }}>{a.ref}</b>
                    </div>
                    <Badge text={a.ctype} tone={crChannelTone(a.channel)} />
                  </div>

                  <div style={{ fontSize: 13.5, fontWeight: 700, marginTop: 8, color: '#13314F' }}>
                    {maskClient(a.client)}
                  </div>

                  <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4, lineHeight: 1.5 }}>
                    {a.subject}
                  </div>

                  <div
                    style={{
                      background: 'rgba(0,0,0,0.02)',
                      padding: '8px 10px',
                      borderRadius: 8,
                      margin: '10px 0',
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                      fontSize: 11.5,
                    }}
                  >
                    <span>المستشار: <b>{a.lawyer}</b></span>
                    <span>المدة: <b>{a.dur}</b></span>
                  </div>

                  <div style={{ fontSize: 11, color: 'var(--muted)' }}>
                    التاريخ: {a.date}
                  </div>
                </div>

                {/* شريط أزرار تنزيل ومشاهدة الوسائط */}
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
                  <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                    {/*
                      * **زرُّ التنزيل ينزّل، وزرُّ التحضير يُحضّر.**
                      *
                      * كانا زرّاً واحداً: `<a href>` عاديّ يعِد بملفٍّ قد لا يكون مبنيّاً،
                      * فيردّ الخادم `back()` وتُعيد النقرةُ تحميلَ الصفحة بلا تنزيل.
                      */}
                    {a.zip && (
                      <MediaButton href={a.zip} ready={a.videoReady} icon="video" label="فيديو MP4" grow />
                    )}
                    {a.audioZip && (
                      <MediaButton href={a.audioZip} ready={a.audioReady} icon="mic" label="صوت M4A" grow />
                    )}
                    {a.transcript && (
                      <MediaButton href={a.transcript} ready={a.transcriptReady} icon="doc" label="النص TXT" grow />
                    )}
                  </div>

                  <div style={{ display: 'flex', gap: 6 }}>
                    {/* المشاهدة داخل النظام من تبويب «الوسائط» — كان هنا رابطٌ يفتح سحابة Zoom خارجه */}
                    {a.videoReady && (
                      <button
                        className="btn primary sm"
                        type="button"
                        style={{ flex: 1, justifyContent: 'center' }}
                        onClick={() => openDrawer(a.ref, 'media')}
                      >
                        <Icon name="video" /> مشاهدة
                      </button>
                    )}

                    {/* قراءة الملخص */}
                    <button
                      className="btn soft sm"
                      type="button"
                      style={{ flex: a.videoReady ? 1 : 2, justifyContent: 'center' }}
                      onClick={() => openDrawer(a.ref, 'summary')}
                    >
                      <Icon name="out" /> {a.hasSummary ? 'الملخص والقرارات' : 'عرض السجل'}
                    </button>
                  </div>
                </div>
              </div>
            ))
          ) : (
            <div className="empty card" style={{ gridColumn: '1 / -1', padding: 40, textAlign: 'center' }}>
              <Icon name="video" />
              <b style={{ display: 'block', marginTop: 8 }}>لا توجد جلسات مطابقة في الأرشيف</b>
            </div>
          )}
        </div>
      )}

      {/* ── View B: الجدول الأرشيفي الشامل ── */}
      {viewMode === 'table' && (
        <div className="card">
          <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <h3>سجل الأرشيف والوسائط المكتملة</h3>
            <span className="sub">{filteredRows.length} استشارة</span>
          </div>

          {/* 1) جدول الديسكتوب والتابلت */}
          <div className="card-b arc360-table-wrapper" style={{ padding: 0 }}>
            {filteredRows.length > 0 ? (
              <table className="arc360-desktop-table">
                <thead>
                  <tr style={{ background: 'rgba(0,0,0,0.03)', borderBottom: '1px solid rgba(0,0,0,0.08)' }}>
                    <th style={{ padding: '12px 16px' }}>المرجع والعميل</th>
                    <th style={{ padding: '12px 14px' }}>القناة والمستشار</th>
                    <th style={{ padding: '12px 14px' }}>الموضوع والتخصص</th>
                    <th style={{ padding: '12px 14px' }}>تاريخ الانعقاد</th>
                    <th style={{ padding: '12px 14px' }}>المدة</th>
                    <th style={{ padding: '12px 16px', textAlign: 'left' }}>الوسائط والتفريغ 360°</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredRows.map((a) => (
                    <tr
                      key={a.ref}
                      style={{
                        borderBottom: '1px solid rgba(0,0,0,0.05)',
                        transition: 'background 0.15s',
                        cursor: 'pointer',
                      }}
                      onClick={() => openDrawer(a.ref, 'summary')}
                      onMouseEnter={(e) => (e.currentTarget.style.background = 'rgba(14, 92, 156, 0.03)')}
                      onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                    >
                      {/* المرجع والعميل */}
                      <td style={{ padding: '12px 16px' }}>
                        <b style={{ color: 'var(--primary)' }}>{a.ref}</b>
                        <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>
                          {maskClient(a.client)}
                        </div>
                      </td>

                      {/* القناة والمستشار */}
                      <td style={{ padding: '12px 14px' }}>
                        <Badge text={a.ctype} tone={crChannelTone(a.channel)} />
                        <div style={{ fontSize: 11.5, marginTop: 4 }}>المستشار: <b>{a.lawyer}</b></div>
                      </td>

                      {/* الموضوع والتخصص */}
                      <td style={{ padding: '12px 14px', maxWidth: 260 }}>
                        <div style={{ fontSize: 12.5, lineHeight: 1.4, color: '#333' }}>
                          {a.subject}
                        </div>
                        <span style={{ fontSize: 11, color: 'var(--muted)' }}>
                          تخصص: {a.specialty}
                        </span>
                      </td>

                      {/* تاريخ الانعقاد */}
                      <td style={{ padding: '12px 14px', fontSize: 12, color: 'var(--muted)' }}>
                        {a.date}
                      </td>

                      {/* المدة */}
                      <td style={{ padding: '12px 14px', fontWeight: 600 }}>
                        {a.dur}
                      </td>

                      {/* الوسائط والتنزيلات */}
                      <td
                        style={{ padding: '12px 16px', textAlign: 'left' }}
                        onClick={(e) => e.stopPropagation()}
                      >
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                          {a.zip && <MediaButton href={a.zip} ready={a.videoReady} icon="video" label="MP4" />}
                          {a.audioZip && <MediaButton href={a.audioZip} ready={a.audioReady} icon="mic" label="M4A" />}
                          {a.transcript && (
                            <MediaButton href={a.transcript} ready={a.transcriptReady} icon="doc" label="نص" />
                          )}
                          {a.videoReady && (
                            <button
                              className="btn primary sm"
                              type="button"
                              onClick={() => openDrawer(a.ref, 'media')}
                              title="مشاهدة التسجيل داخل النظام"
                            >
                              <Icon name="video" /> مشاهدة
                            </button>
                          )}
                          <button
                            className="btn soft sm"
                            type="button"
                            onClick={() => openDrawer(a.ref, 'summary')}
                            title="الملخص والقرارات"
                          >
                            <Icon name="out" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <div className="empty" style={{ padding: 40, textAlign: 'center' }}>
                <Icon name="video" />
                <b style={{ display: 'block', marginTop: 8 }}>لا توجد استشارات مطابقة</b>
              </div>
            )}
          </div>

          {/* 2) كروت الموبايل الذكية */}
          <div className="arc360-mobile-cards">
            {filteredRows.length > 0 ? (
              filteredRows.map((a) => (
                <div
                  key={a.ref}
                  style={{
                    background: '#fff',
                    border: '1px solid rgba(0,0,0,0.08)',
                    borderRadius: 12,
                    padding: 14,
                    boxShadow: '0 2px 8px rgba(0,0,0,0.03)',
                    cursor: 'pointer',
                  }}
                  onClick={() => openDrawer(a.ref, 'summary')}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <div>
                      <b style={{ color: 'var(--primary)', fontSize: 13.5 }}>{a.ref}</b>
                      <div style={{ fontSize: 12, color: 'var(--muted)' }}>{maskClient(a.client)}</div>
                    </div>
                    <Badge text={a.ctype} tone={crChannelTone(a.channel)} />
                  </div>

                  <div style={{ fontSize: 12.5, margin: '8px 0', lineHeight: 1.5, color: '#333' }}>
                    {a.subject}
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
                    <span>مستشار: <b>{a.lawyer}</b></span>
                    <span>المدة: <b>{a.dur}</b></span>
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
                    <div style={{ display: 'flex', gap: 4 }}>
                      {a.zip && <a className="btn soft sm" href={a.zip} style={{ padding: '4px 8px', fontSize: 11 }}>MP4</a>}
                      {a.audioZip && <a className="btn soft sm" href={a.audioZip} style={{ padding: '4px 8px', fontSize: 11 }}>صوت</a>}
                      {a.transcript && <a className="btn soft sm" href={a.transcript} style={{ padding: '4px 8px', fontSize: 11 }}>نص</a>}
                    </div>
                    <button
                      className="btn primary sm"
                      type="button"
                      onClick={() => openDrawer(a.ref, 'summary')}
                      style={{ fontSize: 11.5, padding: '5px 10px' }}
                    >
                      <Icon name="out" /> عرض الملخص
                    </button>
                  </div>
                </div>
              ))
            ) : (
              <div className="empty" style={{ padding: 30, textAlign: 'center' }}>
                <Icon name="video" />
                <b style={{ display: 'block', marginTop: 6 }}>لا توجد سجلات مطابقة</b>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── 5. درج قراءة الملخص والوسائط والقرارات (Slide-over Portal Drawer) ── */}
      {drawerItem && typeof document !== 'undefined' && createPortal(
        <div
          className="arc360-portal-backdrop"
          onClick={(e) => {
            if (e.target === e.currentTarget) {
              closeDrawer();
            }
          }}
          aria-modal="true"
          role="dialog"
        >
          <div
            className="arc360-drawer-panel"
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
                  <Badge text={drawerItem.ctype} tone={crChannelTone(drawerItem.channel)} />
                  <Badge text="جلسة منتهية" tone="b-green" />
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
            <div className="arc360-drawer-tabs">
              {(
                [
                  ['summary', 'الملخص والتوصيات', 'doc'],
                  ['decisions', 'القرارات المستخرجة', 'check'],
                  ['media', 'مستودع الوسائط', 'video'],
                  ['details', 'بيانات الجلسة', 'info'],
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
              {/* Tab 1: الملخص القانوني والتوصيات */}
              {drawerTab === 'summary' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  <div className="card" style={{ margin: 0, padding: 16 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                      <b style={{ color: 'var(--primary)', fontSize: 14 }}>الملخص النهائي للجلسة الاستشارية</b>
                      <span style={{ fontSize: 12, color: 'var(--muted)' }}>المدة: {drawerItem.dur}</span>
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
                        <div style={{ marginTop: 6 }}>لم يُسجل ملخص كتابي لهذه الجلسة بعد</div>
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* Tab 2: القرارات والمهام المستخرجة */}
              {drawerTab === 'decisions' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  <div className="card" style={{ margin: 0, padding: 16 }}>
                    <b style={{ color: 'var(--primary)', fontSize: 14 }}>القرارات والإجراءات الناتجة عن الجلسة:</b>
                    {drawerItem.decisions && drawerItem.decisions.length > 0 ? (
                      <ul style={{ margin: '12px 0 0', paddingRight: 20, fontSize: 13, lineHeight: 1.9 }}>
                        {drawerItem.decisions.map((dec, idx) => (
                          <li key={idx} style={{ marginBottom: 6 }}>
                            {typeof dec === 'string' ? dec : JSON.stringify(dec)}
                          </li>
                        ))}
                      </ul>
                    ) : (
                      <div style={{ textAlign: 'center', color: 'var(--muted)', padding: 30 }}>
                        <Icon name="check" />
                        <div style={{ marginTop: 6 }}>لا توجد قرارات مستخرجة مسجلة</div>
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* Tab 3: مستودع الوسائط والتنزيلات */}
              {drawerTab === 'media' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                  {/* الفيديو MP4 */}
                  <div
                    className="card"
                    style={{
                      margin: 0,
                      padding: 14,
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                      <div className="iico"><Icon name="video" /></div>
                      <div>
                        <b>تسجيل الفيديو المرئي الكامل (MP4)</b>
                        <div style={{ fontSize: 11.5, color: 'var(--muted)', marginTop: 2 }}>
                          {drawerItem.zip ? 'متاح للتنزيل بجودة عالية' : 'غير متوفر'}
                        </div>
                      </div>
                    </div>
                    {drawerItem.zip ? (
                      <a className="btn primary sm" href={drawerItem.zip}>
                        <Icon name="download" /> تنزيل MP4
                      </a>
                    ) : (
                      <span className="chip muted">غير متاح</span>
                    )}
                  </div>

                  {/* الصوت M4A */}
                  <div
                    className="card"
                    style={{
                      margin: 0,
                      padding: 14,
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                      <div className="iico"><Icon name="mic" /></div>
                      <div>
                        <b>التسجيل الصوتي النقي (M4A)</b>
                        <div style={{ fontSize: 11.5, color: 'var(--muted)', marginTop: 2 }}>
                          {drawerItem.audioZip ? 'حزمة صوتية مضغوطة' : 'غير متوفر'}
                        </div>
                      </div>
                    </div>
                    {drawerItem.audioZip ? (
                      <a className="btn soft sm" href={drawerItem.audioZip}>
                        <Icon name="download" /> تنزيل M4A
                      </a>
                    ) : (
                      <span className="chip muted">غير متاح</span>
                    )}
                  </div>

                  {/* النص التفريغي TXT */}
                  <div
                    className="card"
                    style={{
                      margin: 0,
                      padding: 14,
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                      <div className="iico"><Icon name="doc" /></div>
                      <div>
                        <b>النص التفريغي للحوار (TXT)</b>
                        <div style={{ fontSize: 11.5, color: 'var(--muted)', marginTop: 2 }}>
                          {drawerItem.transcript ? 'تفريغ نصوص المتحدثين' : 'غير متوفر'}
                        </div>
                      </div>
                    </div>
                    {drawerItem.transcript ? (
                      <a className="btn soft sm" href={drawerItem.transcript}>
                        <Icon name="download" /> تنزيل TXT
                      </a>
                    ) : (
                      <span className="chip muted">غير متاح</span>
                    )}
                  </div>

                  {/* المشاهدة والاستماع داخل النظام — من الملفّ المحفوظ على الخادم، لا سحابة Zoom */}
                  {((drawerItem.stream && drawerItem.videoReady) || (drawerItem.audioStream && drawerItem.audioReady)) && (
                    <div className="card" style={{ margin: 0, padding: 14, display: 'flex', flexDirection: 'column', gap: 10 }}>
                      <b>تشغيل الجلسة داخل النظام:</b>
                      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        {drawerItem.stream && drawerItem.videoReady && (
                          <InlinePlayer key={`v-${drawerItem.ref}`} src={drawerItem.stream} kind="video" label="تشغيل التسجيل المرئيّ" />
                        )}
                        {drawerItem.audioStream && drawerItem.audioReady && (
                          <InlinePlayer key={`a-${drawerItem.ref}`} src={drawerItem.audioStream} kind="audio" label="تشغيل الصوت" />
                        )}
                      </div>
                    </div>
                  )}
                </div>
              )}

              {/* Tab 4: بيانات وتفاصيل الجلسة */}
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
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerItem.specialty}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>القناة:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerItem.channel}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>تاريخ الانعقاد:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerItem.date}</div>
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>مدة الجلسة:</span>
                      <div style={{ fontWeight: 600, fontSize: 13, marginTop: 2 }}>{drawerItem.dur}</div>
                    </div>
                  </div>

                  {/* الانتقال لصفحة التفاصيل الكاملة */}
                  <button
                    className="btn soft"
                    style={{ width: '100%', justifyContent: 'center' }}
                    type="button"
                    onClick={() => router.visit(`/admin/consult?ref=${encodeURIComponent(drawerItem.ref)}`)}
                  >
                    <Icon name="out" /> الانتقال لصفحة التفاصيل الكاملة
                  </button>
                </>
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
    </div>
  );
};

export default AdminArchive;
