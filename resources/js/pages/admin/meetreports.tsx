import { Link, router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Icon from '@/lib/icons';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { Bars, BarChart } from '@/components/babylon/admin-charts';
import type { BarDatum } from '@/lib/admin-data';
import { fmtActualDuration, meetStatusTone, type FullMeetingCard } from '@/lib/meeting-ui';
import { truncateWords } from '@/lib/utils';

// «—» لا صفر: القياس الغائب لا يُعرض رقماً يدّعي أنّه وقع
const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v}%`);

// تحليلات وتغطية Zoom وقاعدة البيانات لصفحة تقارير الاجتماعات
interface Analytics {
  monthlyTrend: (BarDatum & { hours?: number })[];
  statusCounts?: Record<string, number>;
  attendanceByLawyer: [string, number][];
  // null = لم يُقَس (لا مدد فعلية / لا مهامّ) — يُعرض «—»
  avgActualMinutes: number | null;
  decisionRate: number | null;
  tasksFromDecisions: number;
  doneTasksCount?: number;
  totalZoomHours?: number;
  aiSummaryCount?: number;
  aiCoverageRate?: number | null;
  recordingCount?: number;
  recordingCoverageRate?: number | null;
  audioCount?: number;
}

const MEET_NAV_TABS = [
  { label: 'إدارة الاجتماعات', route: '/admin/meetmgmt', icon: 'calgrid' },
  { label: 'طلبات الاجتماعات', route: '/admin/meetreqs', icon: 'video' },
  { label: 'اعتماد الاجتماعات', route: '/admin/meetings', icon: 'check' },
  { label: 'أرشيف الاجتماعات', route: '/admin/meetlog', icon: 'folder' },
  { label: 'تقارير وإحصاءات الاجتماعات', route: '/admin/meetreports', icon: 'cal', active: true },
];

const AdminMeetReports: React.FC<{ meetings: FullMeetingCard[]; analytics: Analytics }> = ({
  meetings = [],
  analytics,
}) => {
  // تصفية وبحث في جدول السجل التحليلي
  const [searchQ, setSearchQ] = useState('');
  const [filterType, setFilterType] = useState('all');
  const [filterStatus, setFilterStatus] = useState('all');
  const [showTable, setShowTable] = useState(true);

  // توزيع الاجتماعات حسب النوع والخدمة
  const byType: Record<string, number> = {};
  meetings.forEach((m) => {
    byType[m.type] = (byType[m.type] || 0) + 1;
  });
  const typeData: [string, number][] = Object.keys(byType).length
    ? Object.keys(byType).map((k) => [k, byType[k]])
    : [['—', 0]];

  // توزيع الاجتماعات حسب العميل/الجهة
  const byClient: Record<string, number> = {};
  meetings.forEach((m) => {
    byClient[m.client] = (byClient[m.client] || 0) + 1;
  });
  const clientData: [string, number][] = Object.keys(byClient).length
    ? Object.keys(byClient).map((k) => [k, byClient[k]])
    : [['—', 0]];

  // توزيع الاجتماعات حسب المحامي المسؤول
  const byLawyer: Record<string, number> = {};
  meetings.forEach((m) => {
    if (m.lawyer && m.lawyer !== '—') {
      byLawyer[m.lawyer] = (byLawyer[m.lawyer] || 0) + 1;
    }
  });
  const lawyerData: [string, number][] = Object.keys(byLawyer).length
    ? Object.keys(byLawyer).map((k) => [k, byLawyer[k]])
    : [['—', 0]];

  // العدّ بمفتاح الحالة من الخادم (`statusKey`) — النصّ العربيّ للعرض وحده
  const done = meetings.filter((m) => m.statusKey === 'ended');
  const measured = done.filter((m) => m.presenceRate !== null);
  const att = measured.length
    ? Math.round(measured.reduce((a, m) => a + (m.presenceRate ?? 0), 0) / measured.length)
    : null;
  const cancelled = meetings.filter((m) => m.statusKey === 'cancelled').length;
  const missed = meetings.filter((m) => m.statusKey === 'missed').length;
  const postponed = meetings.filter((m) => m.statusKey === 'postponed').length;
  const up = meetings.filter((m) => m.statusKey === 'upcoming').length;
  const approved = meetings.filter((m) => m.approved).length;

  const stats: StatItem[] = [
    ['t-blue', 'video', meetings.length, 'إجمالي الاجتماعات'],
    ['t-cyan', 'video', up, 'قادمة'],
    ['t-green', 'user', att !== null ? `${att}%` : '—', 'متوسّط البقاء'],
    ['t-green', 'check', approved, 'معتمدة'],
    ['t-grey', 'clock', done.length, 'منتهية'],
    ['t-blue', 'doc', done.filter((m) => m.minutes).length, 'بمحضر موثّق'],
    ['t-red', 'out', cancelled + missed, 'ملغاة / لم تنعقد'],
    ['t-amber', 'clock', postponed, 'مؤجلة'],
  ];

  // تصفية سجل الاجتماعات التحليلي
  const filteredMeetings = useMemo(() => {
    return meetings.filter((m) => {
      if (filterType !== 'all' && m.type !== filterType) return false;
      if (filterStatus !== 'all' && m.statusKey !== filterStatus) return false;
      if (searchQ.trim()) {
        const q = searchQ.trim().toLowerCase();
        const hay = `${m.title} ${m.client} ${m.lawyer} ${m.caseRef || ''} ${m.id}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }, [meetings, searchQ, filterType, filterStatus]);

  // أنواع الاجتماعات الفريدة للفلترة
  const availableTypes = useMemo(() => {
    return Array.from(new Set(meetings.map((m) => m.type).filter(Boolean)));
  }, [meetings]);

  // إجمالي الساعات والاجتماعات في آخر 6 أشهر
  const totalTrendMeetings = useMemo(() => {
    return (analytics.monthlyTrend || []).reduce((acc, curr) => acc + (curr.v || 0), 0);
  }, [analytics.monthlyTrend]);

  // مجموع أعشارٍ عشريّة يُخرج ضجيج الفاصلة العائمة (0.1 + 0.2 = 0.30000000000000004) —
  // فيُقرَّب إلى منزلةٍ واحدة كما يقرّب الخادم ساعات كلّ شهر
  const totalTrendHours = useMemo(() => {
    const sum = (analytics.monthlyTrend || []).reduce((acc, curr) => acc + (curr.hours || 0), 0);

    return Math.round(sum * 10) / 10;
  }, [analytics.monthlyTrend]);

  return (
    <>
      <style>{`
        @media print {
          .no-print, .mtabs, .greet button, .btn {
            display: none !important;
          }
          .card {
            box-shadow: none !important;
            border: 1px solid #ccc !important;
            break-inside: avoid;
          }
        }
      `}</style>

      {/* ── شريط التنقل المشترك لمنظومة الاجتماعات ── */}
      <div className="mtabs no-print" style={{ marginBottom: 16, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {MEET_NAV_TABS.map((tab) => (
          <Link
            key={tab.route}
            href={tab.route}
            className={`mtab ${tab.active ? 'on' : ''}`}
            style={{
              textDecoration: 'none',
              display: 'inline-flex',
              alignItems: 'center',
              gap: 6,
              fontWeight: tab.active ? 800 : 600,
            }}
          >
            <Icon name={tab.icon} />
            <span>{tab.label}</span>
          </Link>
        ))}
      </div>

      {/* ── ترويسة الصفحة وإجراءات التقرير ── */}
      <div
        className="greet"
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'flex-start',
          flexWrap: 'wrap',
          gap: 14,
          marginBottom: 18,
        }}
      >
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div
              style={{
                width: 36,
                height: 36,
                borderRadius: 10,
                background: 'rgba(14, 92, 156, 0.1)',
                color: 'var(--primary)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: 18,
              }}
            >
              <Icon name="cal" />
            </div>
            <h2 style={{ margin: 0 }}>تقارير وإحصاءات الاجتماعات</h2>
          </div>
          <p style={{ marginTop: 6 }}>
            مؤشرات أداء تفاعلية شاملة: بيانات Zoom الفعلية، تغطية الذكاء الاصطناعي (AI Companion)، تسجيلات الصوت والفيديو، ومعدلات تنفيذ القرارات.
          </p>
        </div>

        {/* أزرار العمليات والتصدير السريع */}
        <div className="no-print" style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
          <button
            type="button"
            className="btn soft sm"
            onClick={() => router.reload({ only: ['analytics', 'meetings'] })}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
            title="تحديث البيانات اللحظية من الخادم وZoom"
          >
            <Icon name="reply" /> تحديث المؤشرات
          </button>

          <button
            type="button"
            className="btn sm"
            onClick={() => window.print()}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontWeight: 700 }}
            title="طباعة التقرير الشامل بصيغة PDF أو ورقياً"
          >
            <Icon name="doc" /> طباعة التقرير / PDF
          </button>
        </div>
      </div>

      {/* ── شريط مؤشرات الحالة والأرقام العامة ── */}
      <StatRow items={stats} />

      {/* ── لوحة مؤشرات الأداء الاستراتيجية (4 محاور متطورة) ── */}
      <div className="card" style={{ marginBottom: 20, overflow: 'hidden' }}>
        <div
          className="card-h"
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            flexWrap: 'wrap',
            gap: 10,
            background: 'linear-gradient(135deg, rgba(14, 92, 156, 0.03) 0%, rgba(17, 160, 200, 0.05) 100%)',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <span style={{ fontSize: 18 }}>🤖</span>
            <div>
              <h3 style={{ margin: 0, fontSize: 15 }}>تغطية Zoom وأتمتة الذكاء الاصطناعي (AI Companion)</h3>
              <span className="sub" style={{ fontSize: 11.5 }}>
                مؤشرات الجلسات المشفوعة بالسجلات السحابية الحقيقية وقاعدة البيانات
              </span>
            </div>
          </div>
          <span
            style={{
              fontSize: 11,
              fontWeight: 700,
              background: 'rgba(14, 92, 156, 0.08)',
              color: 'var(--primary)',
              padding: '3px 10px',
              borderRadius: 12,
            }}
          >
            سجلات حقيقية 100%
          </span>
        </div>

        <div className="card-b" style={{ padding: 18 }}>
          <div
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))',
              gap: 16,
            }}
          >
            {/* المحور 1: ملخصات الذكاء الاصطناعي */}
            <div
              style={{
                background: 'linear-gradient(135deg, rgba(14, 92, 156, 0.04) 0%, #ffffff 100%)',
                padding: '16px 18px',
                borderRadius: 12,
                border: '1px solid rgba(14, 92, 156, 0.15)',
                boxShadow: '0 2px 8px rgba(0,0,0,0.02)',
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                <span style={{ fontSize: 13, color: 'var(--deep)', fontWeight: 700, display: 'flex', alignItems: 'center', gap: 6 }}>
                  <Icon name="doc" /> ملخصات AI Companion
                </span>
                <span style={{ fontSize: 11, background: 'rgba(14, 92, 156, 0.1)', color: 'var(--primary)', padding: '2px 8px', borderRadius: 8, fontWeight: 700 }}>
                  أتمتة
                </span>
              </div>
              <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--primary)' }}>
                {pct(analytics.aiCoverageRate)}
              </div>
              {/* شريط تقدم */}
              <div style={{ width: '100%', height: 6, background: '#e2e8f0', borderRadius: 3, margin: '8px 0', overflow: 'hidden' }}>
                <div
                  style={{
                    width: `${analytics.aiCoverageRate ?? 0}%`,
                    height: '100%',
                    background: 'linear-gradient(90deg, var(--primary) 0%, #06b6d4 100%)',
                    borderRadius: 3,
                  }}
                />
              </div>
              <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between' }}>
                <span>تم استخراج {analytics.aiSummaryCount ?? 0} ملخص ذكي تلقائيًا</span>
              </div>
            </div>

            {/* المحور 2: التسجيلات السحابية والأرشيف */}
            <div
              style={{
                background: 'linear-gradient(135deg, rgba(16, 185, 129, 0.04) 0%, #ffffff 100%)',
                padding: '16px 18px',
                borderRadius: 12,
                border: '1px solid rgba(16, 185, 129, 0.2)',
                boxShadow: '0 2px 8px rgba(0,0,0,0.02)',
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                <span style={{ fontSize: 13, color: 'var(--deep)', fontWeight: 700, display: 'flex', alignItems: 'center', gap: 6 }}>
                  <Icon name="video" /> أرشيف الفيديو والمرئيات
                </span>
                <span style={{ fontSize: 11, background: 'rgba(16, 185, 129, 0.1)', color: '#047857', padding: '2px 8px', borderRadius: 8, fontWeight: 700 }}>
                  سحابي
                </span>
              </div>
              <div style={{ fontSize: 26, fontWeight: 800, color: '#10B981' }}>
                {pct(analytics.recordingCoverageRate)}
              </div>
              {/* شريط تقدم */}
              <div style={{ width: '100%', height: 6, background: '#e2e8f0', borderRadius: 3, margin: '8px 0', overflow: 'hidden' }}>
                <div
                  style={{
                    width: `${analytics.recordingCoverageRate ?? 0}%`,
                    height: '100%',
                    background: 'linear-gradient(90deg, #10B981 0%, #34d399 100%)',
                    borderRadius: 3,
                  }}
                />
              </div>
              <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between' }}>
                <span>{analytics.recordingCount ?? 0} فيديو</span>
                <span>•</span>
                <span>{analytics.audioCount ?? 0} ملف صوتي M4A</span>
              </div>
            </div>

            {/* المحور 3: ساعات الجلسات ومتوسط الحضور */}
            <div
              style={{
                background: 'linear-gradient(135deg, rgba(139, 92, 246, 0.04) 0%, #ffffff 100%)',
                padding: '16px 18px',
                borderRadius: 12,
                border: '1px solid rgba(139, 92, 246, 0.2)',
                boxShadow: '0 2px 8px rgba(0,0,0,0.02)',
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                <span style={{ fontSize: 13, color: 'var(--deep)', fontWeight: 700, display: 'flex', alignItems: 'center', gap: 6 }}>
                  <Icon name="clock" /> إجمالي ساعات الجلسات
                </span>
                <span style={{ fontSize: 11, background: 'rgba(139, 92, 246, 0.1)', color: '#6d28d9', padding: '2px 8px', borderRadius: 8, fontWeight: 700 }}>
                  Zoom Live
                </span>
              </div>
              <div style={{ fontSize: 26, fontWeight: 800, color: '#8B5CF6' }}>
                {analytics.totalZoomHours ?? 0} <span style={{ fontSize: 16, fontWeight: 600 }}>ساعة</span>
              </div>
              <div style={{ width: '100%', height: 6, background: '#e2e8f0', borderRadius: 3, margin: '8px 0', overflow: 'hidden' }}>
                <div style={{ width: '100%', height: '100%', background: '#8B5CF6', borderRadius: 3 }} />
              </div>
              <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between' }}>
                <span>متوسط مدة الجلسة:</span>
                <b style={{ color: 'var(--deep)' }}>
                  {analytics.avgActualMinutes !== null ? `${analytics.avgActualMinutes} دقيقة` : '—'}
                </b>
              </div>
            </div>

            {/* المحور 4: معدل إنجاز المهام المشتقة من القرارات */}
            <div
              style={{
                background: 'linear-gradient(135deg, rgba(245, 158, 11, 0.04) 0%, #ffffff 100%)',
                padding: '16px 18px',
                borderRadius: 12,
                border: '1px solid rgba(245, 158, 11, 0.25)',
                boxShadow: '0 2px 8px rgba(0,0,0,0.02)',
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                <span style={{ fontSize: 13, color: 'var(--deep)', fontWeight: 700, display: 'flex', alignItems: 'center', gap: 6 }}>
                  <Icon name="check" /> معدل تنفيذ القرارات
                </span>
                <span style={{ fontSize: 11, background: 'rgba(245, 158, 11, 0.1)', color: '#b45309', padding: '2px 8px', borderRadius: 8, fontWeight: 700 }}>
                  حوكمة
                </span>
              </div>
              <div style={{ fontSize: 26, fontWeight: 800, color: '#F59E0B' }}>
                {pct(analytics.decisionRate)}
              </div>
              {/* شريط تقدم */}
              <div style={{ width: '100%', height: 6, background: '#e2e8f0', borderRadius: 3, margin: '8px 0', overflow: 'hidden' }}>
                <div
                  style={{
                    width: `${analytics.decisionRate ?? 0}%`,
                    height: '100%',
                    background: 'linear-gradient(90deg, #F59E0B 0%, #fbbf24 100%)',
                    borderRadius: 3,
                  }}
                />
              </div>
              <div style={{ fontSize: 11.5, color: 'var(--muted)', display: 'flex', justifyContent: 'space-between' }}>
                <span>{analytics.doneTasksCount ?? 0} من {analytics.tasksFromDecisions} مهام مُنجزة</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* ── اتجاه حجم وساعات الاجتماعات شهرياً ── */}
      <div className="card" style={{ marginBottom: 20 }}>
        <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon name="calgrid" />
            <div>
              <h3 style={{ margin: 0 }}>اتجاه حجم وساعات الاجتماعات (آخر 6 أشهر)</h3>
              <span className="sub">تطور عدد الجلسات المرئية وساعات الانعقاد المسجلة</span>
            </div>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 12 }}>
            <span style={{ background: 'var(--paper-2)', padding: '4px 10px', borderRadius: 8, border: '1px solid var(--line-soft)' }}>
              إجمالي الاجتماعات: <b>{totalTrendMeetings}</b>
            </span>
            <span style={{ background: 'var(--paper-2)', padding: '4px 10px', borderRadius: 8, border: '1px solid var(--line-soft)' }}>
              إجمالي الساعات: <b>{totalTrendHours} س</b>
            </span>
          </div>
        </div>

        <div className="card-b" style={{ padding: 18 }}>
          {analytics.monthlyTrend && analytics.monthlyTrend.some((d) => d.v > 0) ? (
            <Bars data={analytics.monthlyTrend} />
          ) : (
            <div className="empty" style={{ padding: 36 }}>
              <Icon name="cal" />
              <b>لا توجد اجتماعات مسجلة في آخر 6 أشهر</b>
              <p style={{ fontSize: 12, color: 'var(--muted)' }}>ستظهر الأعمدة البيانية آلياً بمجرد جدولة وانعقاد الاجتماعات</p>
            </div>
          )}
        </div>
      </div>

      {/* ── شبكة الرسوم البيانية والتوزيعات التفصيلية (2x2 Grid المتوازن) ── */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(440px, 1fr))',
          gap: 16,
          marginBottom: 20,
        }}
      >
        <BarChart title="توزيع الاجتماعات حسب النوع والخدمة" data={typeData} />
        <BarChart title="توزيع الاجتماعات حسب العميل والجهة" data={clientData} />
        <BarChart title="عبء الاجتماعات حسب المحامي المسؤول" data={lawyerData} />
        <BarChart
          title="متوسط مدة الحضور الفعلية حسب المحامي (دقائق)"
          data={analytics.attendanceByLawyer.length ? analytics.attendanceByLawyer : [['—', 0]]}
        />
      </div>

      {/* ── سجل الاجتماعات التحليلي التفصيلي (Analytical Meetings Drill-down) ── */}
      <div className="card" style={{ marginBottom: 24, overflow: 'hidden' }}>
        <div
          className="card-h"
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            flexWrap: 'wrap',
            gap: 10,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon name="ticket" />
            <div>
              <h3 style={{ margin: 0 }}>سجل الاجتماعات التحليلي والفرز اللحظي</h3>
              <span className="sub">
                عرض تفصيلي لـ {filteredMeetings.length} من أصل {meetings.length} اجتماع
              </span>
            </div>
          </div>

          <button
            type="button"
            className="btn soft sm"
            onClick={() => setShowTable(!showTable)}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}
          >
            {showTable ? 'إخفاء الجدول ▲' : 'إظهار الجدول ▼'}
          </button>
        </div>

        {showTable && (
          <div className="card-b" style={{ padding: 0 }}>
            {/* شريط أدوات البحث والفلترة */}
            <div
              className="no-print"
              style={{
                padding: '12px 16px',
                background: 'var(--paper-2)',
                borderBottom: '1px solid var(--line-soft)',
                display: 'flex',
                gap: 10,
                alignItems: 'center',
                flexWrap: 'wrap',
              }}
            >
              <div style={{ flex: 1, minWidth: 200 }}>
                <input
                  className="input"
                  placeholder="بحث بالعنوان، المحامي، العميل، أو رمز الاجتماع…"
                  value={searchQ}
                  onChange={(e) => setSearchQ(e.target.value)}
                  style={{ width: '100%', fontSize: 13 }}
                />
              </div>

              {availableTypes.length > 0 && (
                <select
                  value={filterType}
                  onChange={(e) => setFilterType(e.target.value)}
                  style={{ width: 140, fontSize: 13 }}
                >
                  <option value="all">كل الأنواع</option>
                  {availableTypes.map((t) => (
                    <option key={t} value={t}>
                      {t}
                    </option>
                  ))}
                </select>
              )}

              <select
                value={filterStatus}
                onChange={(e) => setFilterStatus(e.target.value)}
                style={{ width: 140, fontSize: 13 }}
              >
                <option value="all">كل الحالات</option>
                {/* القيم مفاتيح `MeetingStatus::key` — والنصّ المعروض هو الحالة نفسها */}
                <option value="upcoming">قادم</option>
                <option value="ended">منتهٍ</option>
                <option value="live">جارٍ</option>
                <option value="cancelled">ملغى</option>
                <option value="missed">لم ينعقد</option>
                <option value="postponed">مؤجل</option>
              </select>

              {(searchQ || filterType !== 'all' || filterStatus !== 'all') && (
                <button
                  type="button"
                  className="btn soft sm"
                  onClick={() => {
                    setSearchQ('');
                    setFilterType('all');
                    setFilterStatus('all');
                  }}
                  style={{ fontSize: 12 }}
                >
                  مسح الفلاتر
                </button>
              )}
            </div>

            {/* جدول الاجتماعات */}
            <div className="t-wrap" style={{ padding: 0 }}>
              {filteredMeetings.length === 0 ? (
                <div className="empty" style={{ padding: 36 }}>
                  <Icon name="video" />
                  <b>لا توجد اجتماعات مطابقة لشروط الفرز</b>
                </div>
              ) : (
                <table className="tbl" style={{ minWidth: 780 }}>
                  <thead>
                    <tr>
                      <th style={{ width: 90 }}>الرمز</th>
                      <th>عنوان الاجتماع</th>
                      <th>العميل / الجهة</th>
                      <th>المحامي المسؤول</th>
                      <th>النوع</th>
                      <th>التاريخ والوقت</th>
                      <th style={{ textAlign: 'center' }}>المدة الفعلية</th>
                      <th style={{ textAlign: 'center' }}>الأصول الرقمية</th>
                      <th style={{ width: 100, textAlign: 'center' }}>الحالة</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredMeetings.slice(0, 50).map((m) => {
                      const durStr = fmtActualDuration(m.durationSec);
                      const hasRec = Boolean(m.recording);
                      const hasSum = Boolean(m.zoomSummaryAt || m.zoomSummary || m.summary);
                      const hasMin = Boolean(m.minutes);

                      return (
                        <tr key={m.id}>
                          <td className="nowrap" style={{ fontWeight: 700, color: 'var(--primary)', fontSize: 12 }}>
                            {m.id}
                          </td>
                          <td style={{ fontWeight: 600, color: 'var(--deep)' }}>
                            <span title={m.title}>
                              {truncateWords(m.title, 7)}
                            </span>
                            {m.caseRef && (
                              <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>
                                قضية: {m.caseRef}
                              </div>
                            )}
                          </td>
                          <td style={{ fontSize: 12.5 }}>
                            <span title={m.client}>{truncateWords(m.client, 4)}</span>
                          </td>
                          <td style={{ fontSize: 12.5, fontWeight: 500 }}>
                            {m.lawyer || '—'}
                          </td>
                          <td style={{ fontSize: 12 }}>
                            <span style={{ background: 'var(--paper-2)', padding: '2px 6px', borderRadius: 4, border: '1px solid var(--line-soft)' }}>
                              {m.type}
                            </span>
                          </td>
                          <td className="nowrap" style={{ fontSize: 12, color: 'var(--muted)' }}>
                            {m.when || m.startsAt ? (m.startsAt ? m.startsAt.slice(0, 16).replace('T', ' ') : m.when) : '—'}
                          </td>
                          <td style={{ textAlign: 'center', fontSize: 12, fontWeight: 600 }}>
                            {durStr ? (
                              <span style={{ color: 'var(--deep)' }}>{durStr}</span>
                            ) : (
                              <span style={{ color: 'var(--line)' }}>—</span>
                            )}
                          </td>
                          <td style={{ textAlign: 'center' }}>
                            <div style={{ display: 'inline-flex', gap: 4, alignItems: 'center' }}>
                              {hasRec && <span title="يتوفر تسجيل سحابي">🎥</span>}
                              {hasSum && <span title="يتوفر ملخص ذكي">🤖</span>}
                              {hasMin && <span title="يتوفر محضر موثق">📄</span>}
                              {!hasRec && !hasSum && !hasMin && <span style={{ color: 'var(--line)' }}>—</span>}
                            </div>
                          </td>
                          <td style={{ textAlign: 'center' }}>
                            <Badge text={m.status} tone={meetStatusTone(m.status)} />
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              )}
            </div>

            {filteredMeetings.length > 50 && (
              <div
                style={{
                  padding: '10px 16px',
                  background: 'var(--paper-2)',
                  fontSize: 12,
                  color: 'var(--muted)',
                  textAlign: 'center',
                  borderTop: '1px solid var(--line-soft)',
                }}
              >
                يتم عرض أول 50 اجتماعاً من إجمالي {filteredMeetings.length} اجتماعاً مطابقاً.
              </div>
            )}
          </div>
        )}
      </div>
    </>
  );
};

export default AdminMeetReports;
