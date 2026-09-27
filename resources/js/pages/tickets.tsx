import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { foldSearch } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { truncateWords } from '@/lib/utils';
// بطاقة العميل من النوع المشترك (`Ticket::toCard`) — كانت مُعرَّفةً هنا وفي الصفحة الأخرى
import type { ClientTicketCard as TicketCard } from '@/types';

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


interface Props {
  tickets: TicketCard[];
  availableStatuses?: string[];
  counts?: {
    total: number;
    active: number;
    needsAction: number;
    inAnalysis: number;
    inOpinion?: number;
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
  const [datePreset, setDatePreset] = useState<'all' | 'today' | 'week' | 'month' | 'custom'>('all');
  const [viewMode, setViewMode] = useState<'cards' | 'table'>('table');

  // إحصائيات لوحة التذاكر
  const statsList: StatItem[] = [
    [
      't-blue',
      'folder',
      counts?.active ?? tickets.filter((t) => !t.isTerminal).length,
      'تذاكر جارية ونشطة',
    ],
    [
      't-amber',
      'alert',
      counts?.needsAction ?? tickets.filter((t) => t.needsDoc || t.needsBooking).length,
      'تتطلب إجراءً منك',
    ],
    [
      't-cyan',
      'sparkles',
      counts?.inAnalysis ?? tickets.filter((t) => t.phase === 'analysis').length,
      'قيد الفرز والدراسة',
    ],
    [
      't-green',
      'check',
      counts?.completed ?? tickets.filter((t) => Boolean(t.isTerminal)).length,
      'تذاكر مكتملة ومنجزة',
    ],
  ];

  // التذاكر العاجلة التي تتطلب تدخل العميل
  const actionRequiredTickets = useMemo(() => {
    return tickets.filter((t) => t.needsDoc || t.needsBooking);
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

  const hasActiveFilters = Boolean(search || statusFilter !== 'all' || specificStatus !== 'all' || deptFilter !== 'all' || startDate || endDate || datePreset !== 'all');

  const resetFilters = () => {
    setSearch('');
    setStatusFilter('all');
    setSpecificStatus('all');
    setDeptFilter('all');
    setDatePreset('all');
    setStartDate('');
    setEndDate('');
  };

  // التعامل مع اختيار فترة التاريخ من القائمة المنسدلة
  const handleDatePresetChange = (preset: 'all' | 'today' | 'week' | 'month' | 'custom') => {
    setDatePreset(preset);
    const now = new Date();
    const todayStr = now.toISOString().split('T')[0];

    if (preset === 'all') {
      setStartDate('');
      setEndDate('');
    } else if (preset === 'today') {
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
      const q = foldSearch(search);
      const matchQuery =
        !q ||
        foldSearch(t.no).includes(q) ||
        foldSearch(t.type).includes(q) ||
        (t.subject && foldSearch(t.subject).includes(q)) ||
        (t.lawyer && foldSearch(t.lawyer).includes(q)) ||
        (t.dept && foldSearch(t.dept).includes(q)) ||
        (t.opponentName && foldSearch(t.opponentName).includes(q));

      if (!matchQuery) return false;

      // تصفية الحالة المحددة بالاسم
      if (specificStatus !== 'all' && t.status !== specificStatus) {
        return false;
      }

      // تصفية التبويب العام
      const isTerminal = Boolean(t.isTerminal);
      if (statusFilter === 'active') {
        if (isTerminal) return false;
      } else if (statusFilter === 'action') {
        if (!t.needsDoc && !t.needsBooking) return false;
      } else if (statusFilter === 'analysis') {
        // مجموعة الخادم لا قائمةٌ يدويّة — كانت تُسقط حالتَي الاعتماد فلا تظهر التذكرة إلا في «الكل»
        if (!(t.phase === 'analysis')) return false;
      } else if (statusFilter === 'opinion') {
        if (!(t.phase === 'opinion')) return false;
      } else if (statusFilter === 'completed') {
        if (!isTerminal) return false;
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
              بوابة متابعة الاستشارات المكتوبة، طلبات العقود واللوائح، وردود الفريق القانوني.
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

      {/* ── شريط البحث والفلترة الشامل الموحد ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-b" style={{ padding: '14px 16px' }}>

          {/* صف الفلترة والبحث الموحد في صف واحد على الشاشات الكبيرة */}
          <div className="tickets-toolbar">

            {/* 1. حقل البحث السريع */}
            <div className="search-field">
              <input
                type="text"
                className="input"
                placeholder="ابحث برقم التذكرة، الموضوع، المستشار، أو القسم..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                style={{ width: '100%', paddingRight: 36, paddingLeft: 12, height: 38 }}
              />
              <div style={{ position: 'absolute', right: 12, top: '50%', transform: 'translateY(-50%)', opacity: 0.5, pointerEvents: 'none' }}>
                <Icon name="search" />
              </div>
            </div>

            {/* 2. قائمة فلترة الحالة بالتحديد */}
            <select
              className="input select-field"
              value={specificStatus}
              onChange={(e) => setSpecificStatus(e.target.value)}
              style={{ height: 38, padding: '6px 10px' }}
              title="تصفية حسب الحالة"
            >
              <option value="all">🔍 كافة الحالات</option>
              {allStatuses.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>

            {/* 3. قائمة فلترة الأقسام */}
            {departments.length > 0 && (
              <select
                className="input select-field"
                value={deptFilter}
                onChange={(e) => setDeptFilter(e.target.value)}
                style={{ height: 38, padding: '6px 10px' }}
                title="تصفية حسب القسم"
              >
                <option value="all">📂 كافة الأقسام</option>
                {departments.map((d) => (
                  <option key={d} value={d}>{d}</option>
                ))}
              </select>
            )}

            {/* 4. قائمة فلترة التاريخ الذكية المنسدلة */}
            <select
              className="input select-field"
              value={datePreset}
              onChange={(e) => handleDatePresetChange(e.target.value as any)}
              style={{ height: 38, padding: '6px 10px' }}
              title="تصفية حسب التاريخ"
            >
              <option value="all">📅 كافة الفترات</option>
              <option value="today">اليوم</option>
              <option value="week">آخر 7 أيام</option>
              <option value="month">هذا الشهر</option>
              <option value="custom">🗓️ تاريخ محدد...</option>
            </select>

            {/* 5. التبديل بين عرض الجدول والبطاقات (الافتراضي: جدول) */}
            <div className="view-switcher" style={{ display: 'flex', background: 'var(--paper-2)', padding: 3, borderRadius: 8, border: '1px solid var(--line-soft)', height: 38, alignItems: 'center' }}>
              <button
                type="button"
                className={`btn sm ${viewMode === 'table' ? '' : 'ghost'}`}
                style={{ padding: '5px 12px', height: 30, boxShadow: viewMode === 'table' ? undefined : 'none' }}
                onClick={() => setViewMode('table')}
                title="عرض الجدول"
              >
                ☰ جدول
              </button>
              <button
                type="button"
                className={`btn sm ${viewMode === 'cards' ? '' : 'ghost'}`}
                style={{ padding: '5px 12px', height: 30, boxShadow: viewMode === 'cards' ? undefined : 'none' }}
                onClick={() => setViewMode('cards')}
                title="عرض البطاقات"
              >
                ▤ بطاقات
              </button>
            </div>

            {/* 6. زر إعادة ضبط الفلاتر */}
            {hasActiveFilters && (
              <button
                type="button"
                className="btn soft sm"
                style={{ fontSize: 11.5, height: 38, padding: '0 10px', flexShrink: 0 }}
                onClick={resetFilters}
                title="إلغاء وتفريغ كافة خيارات التصفية"
              >
                إعادة ضبط ✕
              </button>
            )}

          </div>

          {/* تظهر حقول التاريخ المخصص فقط إذا اختار المستخدم 'تاريخ محدد' */}
          {datePreset === 'custom' && (
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                marginTop: 12,
                paddingTop: 12,
                borderTop: '1px solid var(--line-soft)',
                flexWrap: 'wrap',
                animation: 'fadeIn 0.2s ease',
              }}
            >
              <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--muted)', display: 'flex', alignItems: 'center', gap: 4 }}>
                <Icon name="cal" /> تحديد النطاق الزمني:
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

              {(startDate || endDate) && (
                <button
                  type="button"
                  className="btn ghost sm"
                  style={{ fontSize: 11, padding: '3px 8px', color: 'var(--red, #ef4444)' }}
                  onClick={() => { setStartDate(''); setEndDate(''); }}
                >
                  ✕ مسح التاريخ المخصص
                </button>
              )}
            </div>
          )}

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
              📜 الرأي والاستشارة ({counts?.inOpinion ?? tickets.filter((t) => t.phase === 'opinion').length})
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
            <b style={{ display: 'block', margin: '12px 0 6px', fontSize: 16 }}>{tickets.length === 0 ? 'لا توجد تذاكر بعد' : 'لا توجد تذاكر مطابقة لمعايير البحث المحددة'}</b>
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
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(min(100%, 280px), 1fr))', gap: 16, marginBottom: 24 }}>
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
            <table className="tbl" style={{ minWidth: 720 }}>
              <thead>
                <tr>
                  <th style={{ width: 130 }}>رقم التذكرة</th>
                  <th style={{ minWidth: 200, maxWidth: 360 }}>النوع والموضوع</th>
                  <th>القسم</th>
                  <th>المستشار</th>
                  <th>الحالة</th>
                  <th>آخر تحديث</th>
                  <th style={{ width: 95, textAlign: 'center' }}>الإجراء</th>
                </tr>
              </thead>
              <tbody>
                {filteredTickets.map((t) => (
                  <tr
                    key={t.no}
                    className="click"
                    onClick={() => router.visit(`/tickets/${encodeURIComponent(t.no)}`)}
                  >
                    <td className="mono nowrap" style={{ fontWeight: 800 }}>{t.no}</td>
                    <td style={{ minWidth: 200, maxWidth: 360 }}>
                      <b style={{ color: 'var(--ink)' }}>{t.type}</b>
                      {t.subject && (
                        <div
                          className="muted"
                          title={t.subject}
                          style={{
                            fontSize: 11.5,
                            marginTop: 3,
                            lineHeight: 1.4,
                          }}
                        >
                          {truncateWords(t.subject, 10)}
                        </div>
                      )}
                    </td>
                    <td className="nowrap">{t.dept || '—'}</td>
                    <td className="nowrap">{t.lawyer || '—'}</td>
                    <td className="nowrap"><Badge text={t.status} tone={t.tone} /></td>
                    <td className="muted nowrap" style={{ fontSize: 11.5 }}>{t.date}</td>
                    <td className="nowrap" style={{ textAlign: 'center' }}>
                      <button className="btn soft sm" type="button" style={{ whiteSpace: 'nowrap' }}>
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

