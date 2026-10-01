import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { foldSearch } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { dateISOAfter, firstOfMonthISO, todayISO } from '@/lib/local-date';
import { useServerAction } from '@/lib/use-server-action';
import { truncateWords } from '@/lib/utils';

// ============================================================
// مركز القضايا والدعاوى القضائية 360° (Client Legal Cases Hub)
// تتبع المرافعات، الجلسات بالمحاكم، الدوائر القضائية، وسداد الأتعاب
// ============================================================

export interface CaseCard {
  no: string;
  type: string;
  status: string;
  tone: string;
  update?: string;
  next?: string;
  fee?: number | null;
  feeStatus?: string;
  /** ما يُسدَّد الآن شاملاً الضريبة — مبلغ الفاتورة التي يفتحها زرّ السداد (`CaseFee::nextPayable`). */
  amountDue?: number | null;
  invoice?: string | null;
  department?: string;
  createdAt?: string;
  assignedLawyer?: string;
  court?: string;
  nextHearing?: {
    id: number;
    title: string;
    court: string;
    day: string;
    startsAt?: string;
    label: string;
  } | null;
  hearingsCount?: number;
  documentsCount?: number;
  installmentsTotal?: number;
  installmentsPaid?: number;
  pleadingStatus?: string;
  ruling?: string;
}

export interface UpcomingHearingItem {
  id: number;
  caseNo: string;
  caseType: string;
  title: string;
  court: string;
  day: string;
  startsAt?: string;
  label: string;
  status: string;
  lawyer: string;
}

interface Props {
  cases: CaseCard[];
  counts?: {
    total: number;
    active: number;
    upcomingHearings: number;
    pendingFees: number;
    completed: number;
  };
  upcomingHearings?: UpcomingHearingItem[];
  /** مجموعات الحالات من `CaseJourney::clientTabs` — لا قوائم باليد في الشاشة */
  tabs?: { active: string[]; fees: string[]; completed: string[] };
}

const NO_TABS = { active: [] as string[], fees: [] as string[], completed: [] as string[] };

const Cases: React.FC<Props> = ({ cases = [], counts, upcomingHearings = [], tabs = NO_TABS }) => {
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [specificStatus, setSpecificStatus] = useState<string>('all');
  const [deptFilter, setDeptFilter] = useState<string>('all');
  const [startDate, setStartDate] = useState<string>('');
  const [endDate, setEndDate] = useState<string>('');
  const [datePreset, setDatePreset] = useState<'all' | 'today' | 'week' | 'month' | 'custom'>('all');
  const [viewMode, setViewMode] = useState<'cards' | 'table'>('table');

  // استخراج كافة الحالات المتوفرة للقائمة المنسدلة
  const allStatuses = useMemo(() => {
    const set = new Set<string>();
    cases.forEach((c) => set.add(c.status));
    return Array.from(set);
  }, [cases]);

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
    // تواريخ الفلتر بالتوقيت المحلّي (`lib/local-date`) — `toISOString` يعطي أمسَ بعد منتصف الليل وآخرَ الشهر السابق لـ«هذا الشهر»
    const todayStr = todayISO();

    if (preset === 'all') {
      setStartDate('');
      setEndDate('');
    } else if (preset === 'today') {
      setStartDate(todayStr);
      setEndDate(todayStr);
    } else if (preset === 'week') {
      setStartDate(dateISOAfter(-7));
      setEndDate(todayStr);
    } else if (preset === 'month') {
      setStartDate(firstOfMonthISO());
      setEndDate(todayStr);
    }
  };

  // قفلٌ موحّد: نقرتان على «ادفع» لا تفتحان جلستَي دفع
  const payment = useServerAction();
  const pay = (no: string) =>
    payment.run(`/cases/${encodeURIComponent(no)}/pay`, { key: no, fallback: 'تعذّر بدء الدفع، حاول بعد قليل' });

  // إحصائيات لوحة القضايا
  const statsList: StatItem[] = [
    [
      't-cyan',
      'scale',
      counts?.active ?? cases.filter((c) => tabs.active.includes(c.status)).length,
      'قضايا نشطة',
    ],
    [
      't-green',
      'cal',
      counts?.upcomingHearings ?? upcomingHearings.length,
      'جلسات مرافعة قادمة',
    ],
    [
      't-amber',
      'card',
      counts?.pendingFees ?? cases.filter((c) => tabs.fees.includes(c.status)).length,
      'بانتظار الأتعاب',
    ],
    [
      't-blue',
      'check',
      counts?.completed ?? cases.filter((c) => tabs.completed.includes(c.status)).length,
      'قضايا مغلقة ومؤرشفة',
    ],
  ];

  // استخراج الأقسام الفريدة للتصفية
  const departments = useMemo(() => {
    const set = new Set<string>();
    cases.forEach((c) => {
      if (c.department) set.add(c.department);
    });
    return Array.from(set);
  }, [cases]);

  // تصفية القضايا
  const filteredCases = useMemo(() => {
    return cases.filter((c) => {
      const q = foldSearch(search);
      const matchQuery =
        !q ||
        foldSearch(c.no).includes(q) ||
        foldSearch(c.type).includes(q) ||
        (c.court && foldSearch(c.court).includes(q)) ||
        (c.assignedLawyer && foldSearch(c.assignedLawyer).includes(q)) ||
        (c.department && foldSearch(c.department).includes(q));

      if (!matchQuery) return false;

      // تصفية الحالة المحددة بالاسم
      if (specificStatus !== 'all' && c.status !== specificStatus) {
        return false;
      }

      // تصفية الحالة
      if (statusFilter === 'active') {
        if (!tabs.active.includes(c.status)) return false;
      } else if (statusFilter === 'hearings') {
        if (!c.next || c.next === '—') return false;
      } else if (statusFilter === 'fees') {
        if (!tabs.fees.includes(c.status)) return false;
      } else if (statusFilter === 'completed') {
        if (!tabs.completed.includes(c.status)) return false;
      }

      // تصفية القسم
      if (deptFilter !== 'all' && c.department !== deptFilter) {
        return false;
      }

      // تصفية التاريخ (تاريخ إنشاء القضية)
      if (startDate && c.createdAt) {
        if (c.createdAt < startDate) return false;
      }
      if (endDate && c.createdAt) {
        if (c.createdAt > endDate) return false;
      }

      return true;
    });
  }, [tabs, cases, search, statusFilter, specificStatus, deptFilter, startDate, endDate]);

  return (
    <>
      {/* ── الترويسة الرئيسية ── */}
      <div className="hero">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 14 }}>
          <div style={{ minWidth: 260, flex: '1 1 auto' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6, flexWrap: 'wrap' }}>
              <h2 style={{ margin: 0, fontWeight: 800 }}>قضاياي والدعاوى القضائية ⚖️</h2>
              <Badge text={`${cases.length} قضايا مسجلة`} tone="b-cyan" />
            </div>
            <p style={{ margin: 0, opacity: 0.9 }}>
              بوابة متابعة الدعاوى المرفوعة بالمحاكم، الدوائر القضائية، مواعيد الجلسات، واللوائح المعتمدة.
            </p>
          </div>

          <div className="hero-cta" style={{ margin: 0 }}>
            <button className="hero-b" onClick={() => router.visit('/tickets/new')} type="button">
              <Icon name="plus" /> فتح طلب قضائي
            </button>
          </div>
        </div>
      </div>

      {/* ── شريط مؤشرات القضايا ── */}
      <StatRow items={statsList} onSelect={(idx) => {
        if (idx === 0) setStatusFilter('active');
        if (idx === 1) setStatusFilter('hearings');
        if (idx === 2) setStatusFilter('fees');
        if (idx === 3) setStatusFilter('completed');
      }} />

      {/* ── رادار الجلسات القضائية القادمة ── */}
      {upcomingHearings.length > 0 && (
        <div className="card" style={{ marginBottom: 18, border: '1.5px solid rgba(16, 185, 129, 0.3)', background: 'linear-gradient(180deg, rgba(16, 185, 129, 0.04) 0%, var(--card-bg, #fff) 100%)' }}>
          <div className="card-h" style={{ padding: '12px 18px' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ fontSize: 16 }}>📅</span>
              <h3 style={{ fontSize: 14.5 }}>أجندة جلسات المرافعة القادمة بالمحاكم</h3>
            </div>
            <Badge text={`${upcomingHearings.length} جلسات مجدولة`} tone="b-green" />
          </div>
          <div className="card-b" style={{ padding: '12px 16px' }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 260px), 1fr))', gap: 12 }}>
              {upcomingHearings.map((h) => (
                <div
                  key={h.id}
                  style={{
                    background: 'var(--paper-2)',
                    border: '1px solid var(--line-soft)',
                    borderRadius: 10,
                    padding: '12px 14px',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'space-between',
                    gap: 10,
                  }}
                >
                  <div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                      <span className="mono" style={{ fontWeight: 800, fontSize: 13, color: 'var(--primary)' }}>
                        {h.caseNo}
                      </span>
                      <Badge text="جلسة مرافعة" tone="b-cyan" />
                    </div>
                    <b style={{ fontSize: 13.5, color: 'var(--ink)', display: 'block' }}>{h.title}</b>
                    <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>
                      🏛️ {h.court}
                    </div>
                    <div style={{ fontSize: 12, color: 'var(--success, #10b981)', marginTop: 4, fontWeight: 700 }}>
                      🕒 {h.label} ({h.day})
                    </div>
                  </div>

                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingTop: 8, borderTop: '1px solid var(--line-soft)' }}>
                    <span style={{ fontSize: 11.5, color: 'var(--faint)' }}>المستشار: {h.lawyer}</span>
                    <button
                      className="btn soft sm"
                      onClick={() => router.visit(`/cases/${encodeURIComponent(h.caseNo)}`)}
                      type="button"
                    >
                      ملف القضية ←
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* ── شريط البحث والفلترة الذكي الموحد ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-b" style={{ padding: '14px 16px' }}>

          {/* صف الفلترة والبحث الموحد في صف واحد على الشاشات الكبيرة */}
          <div className="tickets-toolbar">
            
            {/* 1. حقل البحث السريع */}
            <div className="search-field">
              <input
                type="text"
                className="input"
                placeholder="ابحث برقم القضية، المحكمة، المستشار، أو القسم..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                style={{ width: '100%', paddingRight: 36, paddingLeft: 12, height: 38 }}
              />
              <div style={{ position: 'absolute', right: 12, top: '50%', transform: 'translateY(-50%)', opacity: 0.5, pointerEvents: 'none' }}>
                <Icon name="search" />
              </div>
            </div>

            {/* 2. قائمة فلترة الحالات */}
            <select
              className="input select-field"
              value={specificStatus}
              onChange={(e) => setSpecificStatus(e.target.value)}
              style={{ height: 38, padding: '6px 10px' }}
              title="تصفية حسب الحالة"
            >
              <option value="all">🔍 كافة الحالات</option>
              {allStatuses.map((s) => (
                <option key={s} value={s}>{s}</option>
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

            {/* 4. قائمة فلترة التاريخ الذكية */}
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

            {/* 5. مبدل العرض (الافتراضي: جدول) */}
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

            {/* 6. زر إعادة الضبط */}
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

          {/* تبويبات حالات القضايا */}
          <div className="filter-pills" style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--line-soft)' }}>
            <button
              type="button"
              className={`chip ${statusFilter === 'all' ? 'active' : ''}`}
              style={{
                cursor: 'pointer',
                background: statusFilter === 'all' ? 'var(--primary)' : 'var(--paper-2)',
                color: statusFilter === 'all' ? '#fff' : 'var(--ink)',
                border: '1px solid var(--line-soft)',
              }}
              onClick={() => setStatusFilter('all')}
            >
              كافة القضايا ({cases.length})
            </button>
            <button
              type="button"
              className={`chip ${statusFilter === 'active' ? 'active' : ''}`}
              style={{
                cursor: 'pointer',
                background: statusFilter === 'active' ? 'var(--primary)' : 'var(--paper-2)',
                color: statusFilter === 'active' ? '#fff' : 'var(--ink)',
                border: '1px solid var(--line-soft)',
              }}
              onClick={() => setStatusFilter('active')}
            >
              منظورة وجارية
            </button>
            <button
              type="button"
              className={`chip ${statusFilter === 'hearings' ? 'active' : ''}`}
              style={{
                cursor: 'pointer',
                background: statusFilter === 'hearings' ? 'var(--primary)' : 'var(--paper-2)',
                color: statusFilter === 'hearings' ? '#fff' : 'var(--ink)',
                border: '1px solid var(--line-soft)',
              }}
              onClick={() => setStatusFilter('hearings')}
            >
              📅 لها جلسات مجدولة
            </button>
            <button
              type="button"
              className={`chip ${statusFilter === 'fees' ? 'active' : ''}`}
              style={{
                cursor: 'pointer',
                background: statusFilter === 'fees' ? 'var(--primary)' : 'var(--paper-2)',
                color: statusFilter === 'fees' ? '#fff' : 'var(--ink)',
                border: '1px solid var(--line-soft)',
              }}
              onClick={() => setStatusFilter('fees')}
            >
              💳 بانتظار سداد الأتعاب
            </button>
            <button
              type="button"
              className={`chip ${statusFilter === 'completed' ? 'active' : ''}`}
              style={{
                cursor: 'pointer',
                background: statusFilter === 'completed' ? 'var(--primary)' : 'var(--paper-2)',
                color: statusFilter === 'completed' ? '#fff' : 'var(--ink)',
                border: '1px solid var(--line-soft)',
              }}
              onClick={() => setStatusFilter('completed')}
            >
              ✔ أحكام ومنتهية
            </button>
          </div>
        </div>
      </div>

      {/* ── محتوى القضايا (بطاقات أو جدول) ── */}
      {filteredCases.length === 0 ? (
        <div className="card">
          <div className="card-b" style={{ padding: '48px 16px', textAlign: 'center' }}>
            <Icon name="scale" />
            <b style={{ display: 'block', margin: '12px 0 6px', fontSize: 16 }}>{cases.length === 0 ? 'لا توجد قضايا بعد' : 'لا توجد قضايا مطابقة للبحث أو الفلتر المحدد'}</b>
            <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 16 }}>
              جرب تغيير معايير البحث أو استعراض كافة القضايا المسجلة.
            </p>
            <button className="btn sm" onClick={resetFilters} type="button">
              إعادة ضبط الفلاتر
            </button>
          </div>
        </div>
      ) : viewMode === 'cards' ? (
        /* ── نمط شبكة البطاقات الفاخرة (360° Luxury Cards) ── */
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(min(100%, 280px), 1fr))', gap: 16, marginBottom: 24 }}>
          {filteredCases.map((c) => (
            <div
              key={c.no}
              className="card"
              style={{
                display: 'flex',
                flexDirection: 'column',
                justifyContent: 'space-between',
                transition: 'transform 0.15s ease, box-shadow 0.15s ease',
              }}
            >
              {/* رأس بطاقة القضية */}
              <div className="card-h" style={{ padding: '12px 16px', borderBottom: '1px solid var(--line-soft)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                  <span className="mono" style={{ fontWeight: 800, fontSize: 14 }}>{c.no}</span>
                  <Badge text={c.status} tone={c.tone} />
                </div>
                <span className="chip" style={{ fontSize: 11 }}>{c.type}</span>
              </div>

              {/* متن بطاقة القضية */}
              <div className="card-b" style={{ padding: '14px 16px', flex: 1, display: 'flex', flexDirection: 'column', gap: 10 }}>
                {c.department && (
                  <div style={{ fontSize: 12, color: 'var(--muted)' }}>
                    📂 القسم: <b style={{ color: 'var(--ink)' }}>{c.department}</b>
                  </div>
                )}

                {c.court && (
                  <div style={{ fontSize: 12.5, color: 'var(--ink)' }}>
                    🏛️ <span style={{ fontWeight: 600 }}>{c.court}</span>
                  </div>
                )}

                {c.assignedLawyer && (
                  <div style={{ fontSize: 12, color: 'var(--muted)' }}>
                    👨‍⚖️ المستشار المخصص: <b style={{ color: 'var(--primary)' }}>{c.assignedLawyer}</b>
                  </div>
                )}

                {/* صندوق الجلسة القادمة أو آخر تحديث */}
                <div
                  style={{
                    background: c.next && c.next !== '—' ? 'rgba(14, 165, 233, 0.07)' : 'var(--paper-2)',
                    border: `1px solid ${c.next && c.next !== '—' ? 'rgba(14, 165, 233, 0.25)' : 'var(--line-soft)'}`,
                    borderRadius: 8,
                    padding: '8px 12px',
                    fontSize: 12,
                  }}
                >
                  {c.next && c.next !== '—' ? (
                    <div>
                      <span style={{ color: 'var(--primary)', fontWeight: 700, display: 'block' }}>📅 الجلسة القادمة بالمحكمة:</span>
                      <span style={{ color: 'var(--ink)', fontWeight: 600 }}>{c.next}</span>
                    </div>
                  ) : (
                    <div>
                      <span style={{ color: 'var(--muted)', display: 'block' }}>📌 آخر مستجدات الملف:</span>
                      <span style={{ color: 'var(--ink)' }}>{c.update || 'الملف قيد المتابعة القضائية'}</span>
                    </div>
                  )}
                </div>

                {/* عدادات الوثائق والجلسات */}
                <div style={{ display: 'flex', gap: 12, fontSize: 11.5, color: 'var(--faint)', marginTop: 4 }}>
                  <span>📑 {c.documentsCount ?? 0} مستندات</span>
                  <span>⚖️ {c.hearingsCount ?? 0} جلسات مرافعة</span>
                  {c.ruling && <span style={{ color: 'var(--success)' }}>✔ صدر حكم</span>}
                </div>
              </div>

              {/* أزرار الإجراء وسداد الأتعاب */}
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
                {c.feeStatus === 'pending_payment' ? (
                  <>
                    <button
                      className="btn sm"
                      style={{ flex: 1 }}
                      type="button"
                      disabled={payment.busyKey === c.no}
                      onClick={() => pay(c.no)}
                    >
                      <Icon name="card" /> سداد الأتعاب {c.amountDue ? `(${c.amountDue.toLocaleString()} ريال)` : ''}
                    </button>
                    {/* السداد لا يقفل الملف: بلا هذا الزرّ كانت القضية غير المسدَّدة بلا أي طريق لفتح ملفها في نمط البطاقات */}
                    <button
                      className="btn soft sm"
                      type="button"
                      onClick={() => router.visit(`/cases/${encodeURIComponent(c.no)}`)}
                    >
                      فتح الملف
                    </button>
                  </>
                ) : (
                  <button
                    className="btn soft sm"
                    style={{ flex: 1 }}
                    type="button"
                    onClick={() => router.visit(`/cases/${encodeURIComponent(c.no)}`)}
                  >
                    متابعة ملف القضية والمحادثة ←
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      ) : (
        /* ── نمط الجدول المنظم (Table View) ── */
        <div className="card" style={{ marginBottom: 24 }}>
          <div className="card-b t-wrap" style={{ padding: 0 }}>
            <table className="tbl" style={{ minWidth: 740 }}>
              <thead>
                <tr>
                  <th style={{ width: 130 }}>رقم القضية</th>
                  <th style={{ minWidth: 180, maxWidth: 280 }}>النوع والقسم</th>
                  <th style={{ minWidth: 160, maxWidth: 240 }}>المحكمة</th>
                  <th>المستشار</th>
                  <th>الحالة</th>
                  <th>الجلسة القادمة</th>
                  <th style={{ width: 110, textAlign: 'center' }}>الإجراء</th>
                </tr>
              </thead>
              <tbody>
                {filteredCases.map((c) => (
                  <tr
                    key={c.no}
                    className="click"
                    onClick={() => router.visit(`/cases/${encodeURIComponent(c.no)}`)}
                  >
                    <td className="mono nowrap" style={{ fontWeight: 800 }}>{c.no}</td>
                    <td style={{ minWidth: 180, maxWidth: 280 }}>
                      <b style={{ color: 'var(--ink)' }}>{c.type}</b>
                      {c.department && (
                        <span
                          className="muted"
                          title={c.department}
                          style={{ display: 'block', fontSize: 11, marginTop: 2 }}
                        >
                          {truncateWords(c.department, 5)}
                        </span>
                      )}
                    </td>
                    <td style={{ minWidth: 160, maxWidth: 240 }}>
                      <span title={c.court || 'المحكمة المختصة'}>
                        {truncateWords(c.court || 'المحكمة المختصة', 6)}
                      </span>
                    </td>
                    <td className="nowrap">{c.assignedLawyer || '—'}</td>
                    <td className="nowrap"><Badge text={c.status} tone={c.tone} /></td>
                    <td className="nowrap" style={{ color: c.next && c.next !== '—' ? 'var(--primary)' : 'var(--muted)', fontWeight: c.next && c.next !== '—' ? 700 : 400 }}>
                      <span title={c.next ?? '—'}>{truncateWords(c.next ?? '—', 5)}</span>
                    </td>
                    <td className="nowrap" style={{ textAlign: 'center' }}>
                      {c.feeStatus === 'pending_payment' ? (
                        <button
                          className="btn sm"
                          type="button"
                          style={{ whiteSpace: 'nowrap' }}
                          disabled={payment.busyKey === c.no}
                          onClick={(e) => { e.stopPropagation(); pay(c.no); }}
                        >
                          <Icon name="card" /> سداد {c.amountDue ? `(${c.amountDue.toLocaleString()} ر.س)` : ''}
                        </button>
                      ) : (
                        <button className="btn soft sm" type="button" style={{ whiteSpace: 'nowrap' }}>
                          التفاصيل ←
                        </button>
                      )}
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

export default Cases;

