import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import Icon from '@/lib/icons';

// ============================================================
// مركز متابعة التذاكر والطلبات القانونية 360° (Client Legal Tickets Hub)
// بوابة شاملة لمتابعة سير الطلبات، الرأي القانوني، والمحادثات الحية
// ============================================================

const JOURNEY_STEPS = [
  'استلام الطلب',
  'التحليل والفرز',
  'الإحالة للقسم',
  'الرأي القانوني',
  'حجز الاستشارة',
  'انعقاد الجلسة',
  'النتيجة والاعتماد',
];

export interface TicketCard {
  no: string;
  type: string;
  subject?: string;
  priority?: string;
  dept?: string;
  status: string;
  tone: string;
  last?: string;
  date: string;
  lawyer?: string;
  step?: number;
  needsDoc?: boolean;
  needsBooking?: boolean;
  hasCase?: boolean;
  courtName?: string;
  claimAmount?: number;
  opponentName?: string;
  documentsCount?: number;
  messagesCount?: number;
  createdAt?: string;
}

interface Props {
  tickets: TicketCard[];
  availableStatuses?: string[];
  counts?: {
    total: number;
    active: number;
    needsAction: number;
    inAnalysis: number;
    completed: number;
  };
}

const Tickets: React.FC<Props> = ({ tickets = [], availableStatuses = [], counts }) => {
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [specificStatus, setSpecificStatus] = useState<string>('all');
  const [deptFilter, setDeptFilter] = useState<string>('all');
  const [startDate, setStartDate] = useState<string>('');
  const [endDate, setEndDate] = useState<string>('');
  const [viewMode, setViewMode] = useState<'cards' | 'table'>('cards');

  // إحصائيات لوحة التذاكر
  const statsList: StatItem[] = [
    [
      't-blue',
      'folder',
      counts?.active ?? tickets.filter((t) => !['مكتملة', 'مغلقة'].includes(t.status)).length,
      'تذاكر جارية ونشطة',
    ],
    [
      't-amber',
      'alert',
      counts?.needsAction ?? tickets.filter((t) => ['بانتظار مستندات', 'بانتظار حجز الاستشارة', 'بانتظار الدفع'].includes(t.status)).length,
      'تتطلب إجراءً منك',
    ],
    [
      't-cyan',
      'sparkles',
      counts?.inAnalysis ?? tickets.filter((t) => ['جديدة', 'قيد التحليل', 'محالة للقسم القانوني'].includes(t.status)).length,
      'قيد الفرز والدراسة',
    ],
    [
      't-green',
      'check',
      counts?.completed ?? tickets.filter((t) => ['مكتملة', 'مغلقة'].includes(t.status)).length,
      'تذاكر مكتملة ومنجزة',
    ],
  ];

  // التذاكر العاجلة التي تتطلب تدخل العميل
  const actionRequiredTickets = useMemo(() => {
    return tickets.filter((t) => t.needsDoc || t.needsBooking || t.status === 'بانتظار الدفع');
  }, [tickets]);

  // استخراج الأقسام الفريدة
  const departments = useMemo(() => {
    const set = new Set<string>();
    tickets.forEach((t) => {
      if (t.dept) set.add(t.dept);
    });
    return Array.from(set);
  }, [tickets]);

  // الحالات المتاحة للقائمة المنسدلة
  const allStatuses = useMemo(() => {
    if (availableStatuses.length > 0) return availableStatuses;
    const set = new Set<string>();
    tickets.forEach((t) => set.add(t.status));
    return Array.from(set);
  }, [availableStatuses, tickets]);

  const hasActiveFilters = search || statusFilter !== 'all' || specificStatus !== 'all' || deptFilter !== 'all' || startDate || endDate;

  const resetFilters = () => {
    setSearch('');
    setStatusFilter('all');
    setSpecificStatus('all');
    setDeptFilter('all');
    setStartDate('');
    setEndDate('');
  };

  // تعيين تواريخ سريعة
  const setQuickDate = (preset: 'today' | 'week' | 'month' | 'clear') => {
    const now = new Date();
    const todayStr = now.toISOString().split('T')[0];

    if (preset === 'clear') {
      setStartDate('');
      setEndDate('');
      return;
    }

    if (preset === 'today') {
      setStartDate(todayStr);
      setEndDate(todayStr);
    } else if (preset === 'week') {
      const past7 = new Date();
      past7.setDate(now.getDate() - 7);
      setStartDate(past7.toISOString().split('T')[0]);
      setEndDate(todayStr);
    } else if (preset === 'month') {
      const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
      setStartDate(firstDay.toISOString().split('T')[0]);
      setEndDate(todayStr);
    }
  };

  // تصفية التذاكر
  const filteredTickets = useMemo(() => {
    return tickets.filter((t) => {
      const q = search.trim().toLowerCase();
      const matchQuery =
        !q ||
        t.no.toLowerCase().includes(q) ||
        t.type.toLowerCase().includes(q) ||
        (t.subject && t.subject.toLowerCase().includes(q)) ||
        (t.lawyer && t.lawyer.toLowerCase().includes(q)) ||
        (t.dept && t.dept.toLowerCase().includes(q)) ||
        (t.opponentName && t.opponentName.toLowerCase().includes(q));

      if (!matchQuery) return false;

      // تصفية الحالة المحددة بالاسم
      if (specificStatus !== 'all' && t.status !== specificStatus) {
        return false;
      }

      // تصفية التبويب العام
      if (statusFilter === 'active') {
        if (['مكتملة', 'مغلقة'].includes(t.status)) return false;
      } else if (statusFilter === 'action') {
        if (!t.needsDoc && !t.needsBooking && t.status !== 'بانتظار الدفع') return false;
      } else if (statusFilter === 'analysis') {
        if (!['جديدة', 'قيد التحليل', 'محالة للقسم القانوني'].includes(t.status)) return false;
      } else if (statusFilter === 'opinion') {
        if (!['الرأي القانوني', 'بانتظار حجز الاستشارة', 'موعد مؤكد'].includes(t.status)) return false;
      } else if (statusFilter === 'completed') {
        if (!['مكتملة', 'مغلقة'].includes(t.status)) return false;
      }

      // تصفية القسم
      if (deptFilter !== 'all' && t.dept !== deptFilter) {
        return false;
      }

      // تصفية نطاق التاريخ (من - إلى)
      if (startDate && t.createdAt) {
        if (t.createdAt < startDate) return false;
      }
      if (endDate && t.createdAt) {
        if (t.createdAt > endDate) return false;
      }

      return true;
    });
  }, [tickets, search, statusFilter, specificStatus, deptFilter, startDate, endDate]);

  return (
    <>
      {/* ── الترويسة الرئيسية ── */}
      <div className="hero">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 14 }}>
          <div style={{ minWidth: 260, flex: '1 1 auto' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6, flexWrap: 'wrap' }}>
              <h2 style={{ margin: 0, fontWeight: 800 }}>متابعة التذاكر والطلبات القانونية 📁</h2>
              <Badge text={`${tickets.length} تذكرة مسجلة`} tone="b-blue" />
            </div>
            <p style={{ margin: 0, opacity: 0.9 }}>
              بوابة متابعة الاستشارات المكتوبة، طلبات العقود واللوائح، والردود القانونية المعتمدة.
            </p>
          </div>

          <div className="hero-cta" style={{ margin: 0 }}>
            <button className="hero-b" onClick={() => router.visit('/tickets/new')} type="button">
              <Icon name="plus" /> فتح تذكرة جديدة
            </button>
            <button className="hero-b ghost" onClick={() => router.visit('/book')} type="button">
              <Icon name="calplus" /> حجز استشارة
            </button>
          </div>
        </div>
      </div>

      {/* ── شريط مؤشرات التذاكر ── */}
      <StatRow items={statsList} onSelect={(idx) => {
        setSpecificStatus('all');
        if (idx === 0) setStatusFilter('active');
        if (idx === 1) setStatusFilter('action');
        if (idx === 2) setStatusFilter('analysis');
        if (idx === 3) setStatusFilter('completed');
      }} />

      {/* ── رادار التذاكر التي تتطلب إجراء العميل ── */}
      {actionRequiredTickets.length > 0 && statusFilter !== 'completed' && specificStatus === 'all' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 18 }}>
          {actionRequiredTickets.map((t) => (
            <div
              key={t.no}
              style={{
                background: 'rgba(245, 158, 11, 0.08)',
                border: '1.5px solid rgba(245, 158, 11, 0.3)',
                borderRadius: 12,
                padding: '12px 16px',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                flexWrap: 'wrap',
                gap: 12,
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 12, flex: '1 1 240px' }}>
                <div
                  style={{
                    width: 36,
                    height: 36,
                    borderRadius: 8,
                    background: 'var(--paper-2)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: 16,
                    flexShrink: 0,
                  }}
                >
                  <Icon name={t.needsDoc ? 'alert' : 'cal'} />
                </div>
                <div style={{ minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <span className="mono" style={{ fontWeight: 800, fontSize: 13, color: 'var(--ink)' }}>{t.no}</span>
                    <Badge text={t.status} tone={t.tone} />
                  </div>
                  <div style={{ fontSize: 12.5, color: 'var(--ink)', fontWeight: 600, marginTop: 2 }}>
                    {t.needsDoc
                      ? 'مطلوب تزويد المستشار بالوثائق والمستندات لاستكمال الدراسة'
                      : t.needsBooking
                      ? 'تمت الدراسة المبدئية، يرجى حجز موعد الاستشارة لمناقشة الرأي القانوني'
                      : 'بانتظار استكمال سداد رسوم الاستشارة'}
                  </div>
                </div>
              </div>

              <button
                className="btn sm"
                style={{ flexShrink: 0 }}
                onClick={() => router.visit(t.needsBooking ? '/book' : `/tickets/${encodeURIComponent(t.no)}`)}
                type="button"
              >
                {t.needsDoc ? '📎 إرفاق المستندات' : t.needsBooking ? '📅 حجز الموعد الآن' : 'سداد الرسوم'}
              </button>
            </div>
          ))}
        </div>
      )}

      {/* ── شريط البحث والفلترة الشامل (بما في ذلك الحالة والتاريخ من - إلى) ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          
          {/* الصف الأول: البحث + القوائم المنسدلة + طريقة العرض */}
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}>
            
            {/* حقل البحث السريع */}
            <div style={{ flex: '1 1 240px', position: 'relative' }}>
              <input
                type="text"
                className="input"
                placeholder="ابحث برقم التذكرة، الموضوع، المستشار، أو القسم..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                style={{ width: '100%', paddingRight: 36 }}
              />
              <div style={{ position: 'absolute', right: 12, top: '50%', transform: 'translateY(-50%)', opacity: 0.5, pointerEvents: 'none' }}>
                <Icon name="search" />
              </div>
            </div>

            {/* قائمة فلترة الحالة بالتحديد */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <select
                className="input"
                value={specificStatus}
                onChange={(e) => setSpecificStatus(e.target.value)}
                style={{ minWidth: 150, padding: '6px 12px' }}
                title="تصفية حسب الحالة الدقيقة"
              >
                <option value="all">🔍 كافة الحالات</option>
                {allStatuses.map((s) => (
                  <option key={s} value={s}>
                    {s}
                  </option>
                ))}
              </select>

              {/* قائمة فلترة الأقسام */}
              {departments.length > 0 && (
                <select
                  className="input"
                  value={deptFilter}
                  onChange={(e) => setDeptFilter(e.target.value)}
                  style={{ minWidth: 140, padding: '6px 12px' }}
                >
                  <option value="all">📂 كافة الأقسام</option>
                  {departments.map((d) => (
                    <option key={d} value={d}>{d}</option>
                  ))}
                </select>
              )}

              {/* التبديل بين عرض البطاقات والجدول */}
              <div style={{ display: 'flex', background: 'var(--paper-2)', padding: 3, borderRadius: 8, border: '1px solid var(--line-soft)' }}>
                <button
                  type="button"
                  className={`btn sm ${viewMode === 'cards' ? '' : 'ghost'}`}
                  style={{ padding: '5px 10px', boxShadow: viewMode === 'cards' ? undefined : 'none' }}
                  onClick={() => setViewMode('cards')}
                  title="عرض البطاقات"
                >
                  ▤ بطاقات
                </button>
                <button
                  type="button"
                  className={`btn sm ${viewMode === 'table' ? '' : 'ghost'}`}
                  style={{ padding: '5px 10px', boxShadow: viewMode === 'table' ? undefined : 'none' }}
                  onClick={() => setViewMode('table')}
                  title="عرض الجدول"
                >
                  ☰ جدول
                </button>
              </div>
            </div>

          </div>

          {/* الصف الثاني: فلترة التاريخ (من تاريخ - إلى تاريخ) مع اختصارات سريعة */}
          <div
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              flexWrap: 'wrap',
              gap: 12,
              marginTop: 12,
              paddingTop: 12,
              borderTop: '1px solid var(--line-soft)',
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--muted)', display: 'flex', alignItems: 'center', gap: 4 }}>
                <Icon name="cal" /> التاريخ:
              </span>

              <div style={{ display: 'flex', alignItems: 'center', gap: 6, background: 'var(--paper-2)', padding: '4px 8px', borderRadius: 8, border: '1px solid var(--line-soft)' }}>
                <span style={{ fontSize: 11, color: 'var(--faint)' }}>من:</span>
                <input
                  type="date"
                  className="input"
                  value={startDate}
                  onChange={(e) => setStartDate(e.target.value)}
                  style={{ padding: '3px 6px', fontSize: 12, border: 'none', background: 'transparent' }}
                />
              </div>

              <div style={{ display: 'flex', alignItems: 'center', gap: 6, background: 'var(--paper-2)', padding: '4px 8px', borderRadius: 8, border: '1px solid var(--line-soft)' }}>
                <span style={{ fontSize: 11, color: 'var(--faint)' }}>إلى:</span>
                <input
                  type="date"
                  className="input"
                  value={endDate}
                  onChange={(e) => setEndDate(e.target.value)}
                  style={{ padding: '3px 6px', fontSize: 12, border: 'none', background: 'transparent' }}
                />
              </div>

              {/* اختصارات التاريخ السريعة */}
              <div style={{ display: 'flex', gap: 4 }}>
                <button
                  type="button"
                  className="btn ghost sm"
                  style={{ fontSize: 11, padding: '3px 8px' }}
                  onClick={() => setQuickDate('today')}
                >
                  اليوم
                </button>
                <button
                  type="button"
                  className="btn ghost sm"
                  style={{ fontSize: 11, padding: '3px 8px' }}
                  onClick={() => setQuickDate('week')}
                >
                  آخر 7 أيام
                </button>
                <button
                  type="button"
                  className="btn ghost sm"
                  style={{ fontSize: 11, padding: '3px 8px' }}
                  onClick={() => setQuickDate('month')}
                >
                  هذا الشهر
                </button>
                {(startDate || endDate) && (
                  <button
                    type="button"
                    className="btn ghost sm"
                    style={{ fontSize: 11, padding: '3px 8px', color: 'var(--red, #ef4444)' }}
                    onClick={() => setQuickDate('clear')}
                  >
                    ✕ مسح التاريخ
                  </button>
                )}
              </div>
            </div>

            {hasActiveFilters && (
              <button
                type="button"
                className="btn soft sm"
                style={{ fontSize: 11.5 }}
                onClick={resetFilters}
              >
                إعادة ضبط الفلاتر ✕
              </button>
            )}
          </div>

          {/* الصف الثالث: تبويبات الحالات العامة السريعة */}
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--line-soft)' }}>
            <button
              type="button"
              className={`chip ${statusFilter === 'all' && specificStatus === 'all' ? 'active' : ''}`}
              style={{
                cursor: 'pointer',
                background: statusFilter === 'all' && specificStatus === 'all' ? 'var(--primary)' : 'var(--paper-2)',
                color: statusFilter === 'all' && specificStatus === 'all' ? '#fff' : 'var(--ink)',
                border: '1px solid var(--line-soft)',
              }}
              onClick={() => { setStatusFilter('all'); setSpecificStatus('all'); }}
            >
              كافة التذاكر ({tickets.length})
            </button>
            <button
              type="button"
              className={`chip ${statusFilter === 'active' && specificStatus === 'all' ? 'active' : ''}`}
              style={{
                cursor: 'pointer',
                background: statusFilter === 'active' && specificStatus === 'all' ? 'var(--primary)' : 'var(--paper-2)',
                color: statusFilter === 'active' && specificStatus === 'all' ? '#fff' : 'var(--ink)',
                border: '1px solid var(--line-soft)',
              }}
              onClick={() => { setStatusFilter('active'); setSpecificStatus('all'); }}
            >
              نشطة وقيد المعالجة
            </button>
            <button
              type="button"
              className={`chip ${statusFilter === 'action' && specificStatus === 'all' ? 'active' : ''}`}
              style={{
                cursor: 'pointer',
                background: statusFilter === 'action' && specificStatus === 'all' ? 'var(--primary)' : 'var(--paper-2)',
                color: statusFilter === 'action' && specificStatus === 'all' ? '#fff' : 'var(--ink)',
                border: '1px solid var(--line-soft)',
              }}
              onClick={() => { setStatusFilter('action'); setSpecificStatus('all'); }}
            >
              ⚠️ تتطلب إجراء مني ({actionRequiredTickets.length})
            </button>
            <button
              type="button"
              className={`chip ${statusFilter === 'opinion' && specificStatus === 'all' ? 'active' : ''}`}
              style={{
                cursor: 'pointer',
                background: statusFilter === 'opinion' && specificStatus === 'all' ? 'var(--primary)' : 'var(--paper-2)',
                color: statusFilter === 'opinion' && specificStatus === 'all' ? '#fff' : 'var(--ink)',
                border: '1px solid var(--line-soft)',
              }}
              onClick={() => { setStatusFilter('opinion'); setSpecificStatus('all'); }}
            >
              📜 الرأي والاستشارة
            </button>
            <button
              type="button"
              className={`chip ${statusFilter === 'completed' && specificStatus === 'all' ? 'active' : ''}`}
              style={{
                cursor: 'pointer',
                background: statusFilter === 'completed' && specificStatus === 'all' ? 'var(--primary)' : 'var(--paper-2)',
                color: statusFilter === 'completed' && specificStatus === 'all' ? '#fff' : 'var(--ink)',
                border: '1px solid var(--line-soft)',
              }}
              onClick={() => { setStatusFilter('completed'); setSpecificStatus('all'); }}
            >
              ✔ مكتملة ومغلقة
            </button>
          </div>
        </div>
      </div>

      {/* ── محتوى التذاكر (بطاقات أو جدول) ── */}
      {filteredTickets.length === 0 ? (
        <div className="card">
          <div className="card-b" style={{ padding: '48px 16px', textAlign: 'center' }}>
            <Icon name="folder" />
            <b style={{ display: 'block', margin: '12px 0 6px', fontSize: 16 }}>لا توجد تذاكر مطابقة لمعايير البحث المحددة</b>
            <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 16 }}>
              جرب تغيير معايير البحث أو فتح تذكرة جديدة.
            </p>
            <button className="btn sm" onClick={resetFilters} type="button">
              إعادة ضبط الفلاتر
            </button>
          </div>
        </div>
      ) : viewMode === 'cards' ? (
        /* ── نمط شبكة البطاقات الفاخرة (360° Luxury Cards) ── */
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(330px, 1fr))', gap: 16, marginBottom: 24 }}>
          {filteredTickets.map((t) => {
            const stepIdx = t.step ?? 0;
            return (
              <div
                key={t.no}
                className="card"
                style={{
                  display: 'flex',
                  flexDirection: 'column',
                  justifyContent: 'space-between',
                  transition: 'transform 0.15s ease, box-shadow 0.15s ease',
                }}
              >
                {/* رأس بطاقة التذكرة */}
                <div className="card-h" style={{ padding: '12px 16px', borderBottom: '1px solid var(--line-soft)' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <span className="mono" style={{ fontWeight: 800, fontSize: 14 }}>{t.no}</span>
                    <Badge text={t.status} tone={t.tone} />
                  </div>
                  <span className="chip" style={{ fontSize: 11 }}>{t.type}</span>
                </div>

                {/* متن بطاقة التذكرة */}
                <div className="card-b" style={{ padding: '14px 16px', flex: 1, display: 'flex', flexDirection: 'column', gap: 10 }}>
                  {t.subject && (
                    <b style={{ fontSize: 13.5, color: 'var(--ink)', display: 'block', wordBreak: 'break-word' }}>
                      {t.subject}
                    </b>
                  )}

                  {t.dept && (
                    <div style={{ fontSize: 12, color: 'var(--muted)' }}>
                      📂 القسم: <b style={{ color: 'var(--ink)' }}>{t.dept}</b>
                    </div>
                  )}

                  {t.lawyer && (
                    <div style={{ fontSize: 12, color: 'var(--muted)' }}>
                      👨‍⚖️ المستشار المسند: <b style={{ color: 'var(--primary)' }}>{t.lawyer}</b>
                    </div>
                  )}

                  {/* شريط مسار الرحلة المصغر */}
                  <div
                    style={{
                      background: 'var(--paper-2)',
                      border: '1px solid var(--line-soft)',
                      borderRadius: 8,
                      padding: '8px 12px',
                    }}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: 'var(--muted)', marginBottom: 4 }}>
                      <span>المرحلة الحالية:</span>
                      <b style={{ color: 'var(--primary)' }}>{JOURNEY_STEPS[Math.min(stepIdx, JOURNEY_STEPS.length - 1)]}</b>
                    </div>
                    {/* شريط التقدم */}
                    <div style={{ width: '100%', height: 4, background: 'var(--line-soft)', borderRadius: 2, overflow: 'hidden' }}>
                      <div
                        style={{
                          width: `${Math.max(14, ((stepIdx + 1) / JOURNEY_STEPS.length) * 100)}%`,
                          height: '100%',
                          background: t.tone === 'b-green' ? 'var(--success, #10b981)' : t.tone === 'b-amber' ? 'var(--amber, #f59e0b)' : 'var(--primary, #0ea5e9)',
                          transition: 'width 0.3s ease',
                        }}
                      />
                    </div>
                  </div>

                  {/* آخر رسالة أو تحديث */}
                  {t.last && (
                    <div style={{ fontSize: 12, color: 'var(--faint)', background: 'var(--paper-2)', padding: '6px 10px', borderRadius: 6 }}>
                      💬 آخر رد: <span style={{ color: 'var(--ink)' }}>{t.last}</span>
                    </div>
                  )}

                  {/* عدادات الوثائق والرسائل */}
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 11.5, color: 'var(--faint)', marginTop: 4 }}>
                    <div style={{ display: 'flex', gap: 10 }}>
                      <span>📎 {t.documentsCount ?? 0} مرفقات</span>
                      <span>💬 {t.messagesCount ?? 0} ردود</span>
                      {t.hasCase && <span style={{ color: 'var(--primary)', fontWeight: 600 }}>⚖️ قضية مرتبطة</span>}
                    </div>
                    <span>{t.date}</span>
                  </div>
                </div>

                {/* أزرار الإجراء */}
                <div
                  style={{
                    padding: '12px 16px',
                    background: 'var(--paper-2)',
                    borderTop: '1px solid var(--line-soft)',
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    gap: 10,
                    flexWrap: 'wrap',
                  }}
                >
                  {t.needsBooking ? (
                    <button
                      className="btn sm"
                      style={{ flex: 1 }}
                      type="button"
                      onClick={() => router.visit('/book')}
                    >
                      <Icon name="calplus" /> حجز جلسة الاستشارة
                    </button>
                  ) : t.needsDoc ? (
                    <button
                      className="btn sm"
                      style={{ flex: 1 }}
                      type="button"
                      onClick={() => router.visit(`/tickets/${encodeURIComponent(t.no)}`)}
                    >
                      <Icon name="reply" /> إرفاق المستندات المطلوبة
                    </button>
                  ) : (
                    <button
                      className="btn soft sm"
                      style={{ flex: 1 }}
                      type="button"
                      onClick={() => router.visit(`/tickets/${encodeURIComponent(t.no)}`)}
                    >
                      فتح المحادثة والمتابعة ←
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      ) : (
        /* ── نمط الجدول المنظم (Table View) ── */
        <div className="card" style={{ marginBottom: 24 }}>
          <div className="card-b t-wrap" style={{ padding: 0 }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>رقم التذكرة</th>
                  <th>النوع والموضوع</th>
                  <th>القسم</th>
                  <th>المستشار</th>
                  <th>الحالة</th>
                  <th>آخر تحديث</th>
                  <th>الإجراء</th>
                </tr>
              </thead>
              <tbody>
                {filteredTickets.map((t) => (
                  <tr
                    key={t.no}
                    className="click"
                    onClick={() => router.visit(`/tickets/${encodeURIComponent(t.no)}`)}
                  >
                    <td className="mono" style={{ fontWeight: 800 }}>{t.no}</td>
                    <td>
                      <b>{t.type}</b>
                      {t.subject && <span className="muted" style={{ display: 'block', fontSize: 11 }}>{t.subject}</span>}
                    </td>
                    <td>{t.dept || '—'}</td>
                    <td>{t.lawyer || '—'}</td>
                    <td><Badge text={t.status} tone={t.tone} /></td>
                    <td className="muted" style={{ fontSize: 11.5 }}>{t.date}</td>
                    <td>
                      <button className="btn soft sm" type="button">
                        المحادثة ←
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </>
  );
};

export default Tickets;

