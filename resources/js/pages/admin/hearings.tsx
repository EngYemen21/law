import { Link, router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import { hearingDurationLabel } from '@/lib/case-ui';
import Icon from '@/lib/icons';
import { truncateWords } from '@/lib/utils';

/* ─────────────────────────────────────────────────────────────
   الجلسات القضائية وتواريخ المحاكم — الإدارة العليا
   منظومة المتابعة والرقابة المركزية لكافة الجلسات في المحاكم السعودية
───────────────────────────────────────────────────────────── */

export interface HearingDocument {
  id: number;
  name: string;
  docType?: string | null;
  size?: string | null;
  date?: string | null;
  downloadUrl?: string | null;
}

export interface PostponedHearingRef {
  id: number;
  title: string;
  date: string;
  outcome?: string | null;
}

export interface HearingCaseRef {
  id: number;
  no: string;
  title: string;
  type: string;
  status: string;
  tone: string;
  client: string;
  realClient: string;
  lawyer: string;
  opponent: string;
  url: string;
}

export interface CourtHearingRow {
  id: number;
  title: string;
  day: string;
  time: string;
  startsAt?: string | null;
  relativeDate: string;
  court: string;
  circuit: string;
  status: string;
  lapsed: boolean;
  /** نصّ الشارة ونغمتها من الخادم (`EventStatus::forHearing` · `HearingStatus::tone`) */
  statusLabel: string;
  tone: string;
  /** موعدها اليوم — علَمٌ لا مقارنةٌ بنصّ `relativeDate` */
  isToday: boolean;
  /** المدّة المتوقّعة بالدقائق كما أُدخلت — null إن تُركت */
  durationMin?: number | null;
  outcome?: string | null;
  postponedFromId?: number | null;
  postponedFrom?: PostponedHearingRef | null;
  postponedTo?: PostponedHearingRef | null;
  documents: HearingDocument[];
  case?: HearingCaseRef | null;
}

export interface HearingKPIs {
  total: number;
  today: number;
  upcoming: number;
  lapsed: number;
  held: number;
  postponed: number;
}

export interface LawyerOption {
  id: number;
  name: string;
}

interface Props {
  hearings: {
    data: CourtHearingRow[];
    meta: {
      currentPage: number;
      lastPage: number;
      perPage: number;
      total: number;
      from: number | null;
      to: number | null;
    };
    links: {
      prev: string | null;
      next: string | null;
    };
  };
  kpis: HearingKPIs;
  options: {
    courts: string[];
    lawyers: LawyerOption[];
  };
  filters: {
    q: string;
    status: string;
    court: string;
    lawyer_id: number | null;
    preset: string;
    from: string;
    to: string;
    sort: string;
  };
}

export default function CourtHearingsPage({
  hearings,
  kpis,
  options,
  filters: initialFilters,
}: Props) {
  // Filters State
  const [q, setQ] = useState(initialFilters.q || '');
  const [status, setStatus] = useState(initialFilters.status || 'all');
  const [court, setCourt] = useState(initialFilters.court || '');
  const [lawyerId, setLawyerId] = useState<number | string>(initialFilters.lawyer_id || '');
  const [preset, setPreset] = useState(initialFilters.preset || 'all');
  const [from, setFrom] = useState(initialFilters.from || '');
  const [to, setTo] = useState(initialFilters.to || '');
  const [sort, setSort] = useState(initialFilters.sort || 'nearest');

  // Drawer Dossier State
  const [activeHearing, setActiveHearing] = useState<CourtHearingRow | null>(null);

  // Apply filters via Inertia router
  const applyFilters = (customOverrides: Record<string, unknown> = {}) => {
    const params: Record<string, unknown> = {
      q: q || undefined,
      status: status !== 'all' ? status : undefined,
      court: court || undefined,
      lawyer_id: lawyerId || undefined,
      preset: preset !== 'all' ? preset : undefined,
      from: preset === 'custom' ? from || undefined : undefined,
      to: preset === 'custom' ? to || undefined : undefined,
      sort: sort !== 'nearest' ? sort : undefined,
      page: 1,
      ...customOverrides,
    };

    router.get('/admin/hearings', params as any, {
      preserveState: true,
      preserveScroll: true,
    });
  };

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    applyFilters();
  };

  const handleStatusTab = (newStatus: string) => {
    setStatus(newStatus);
    applyFilters({ status: newStatus !== 'all' ? newStatus : undefined });
  };

  const handlePresetChange = (newPreset: string) => {
    setPreset(newPreset);
    if (newPreset !== 'custom') {
      applyFilters({ preset: newPreset !== 'all' ? newPreset : undefined, from: undefined, to: undefined });
    }
  };

  const resetFilters = () => {
    setQ('');
    setStatus('all');
    setCourt('');
    setLawyerId('');
    setPreset('all');
    setFrom('');
    setTo('');
    setSort('nearest');
    router.get('/admin/hearings', {}, { preserveState: true, preserveScroll: true });
  };

  // Build CSV export link with current filters
  const exportUrl = React.useMemo(() => {
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (status && status !== 'all') params.set('status', status);
    if (court) params.set('court', court);
    if (lawyerId) params.set('lawyer_id', String(lawyerId));
    if (preset && preset !== 'all') params.set('preset', preset);
    if (preset === 'custom') {
      if (from) params.set('from', from);
      if (to) params.set('to', to);
    }
    if (sort && sort !== 'nearest') params.set('sort', sort);
    const qs = params.toString();
    return `/admin/hearings/export${qs ? `?${qs}` : ''}`;
  }, [q, status, court, lawyerId, preset, from, to, sort]);

  // النصّ والنغمة من الخادم — كان `switch` على النصوص العربيّة هنا بلوحةٍ تخالف بقيّة الشاشات
  const getStatusBadge = (h: CourtHearingRow) => <Badge text={h.statusLabel} tone={h.tone} />;

  return (
    <div className="section-body">
      {/* ── الترويسة القيادية والأزرار ── */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 16, marginBottom: 20 }}>
        <div>
          <h1 style={{ fontSize: 22, fontWeight: 800, margin: '0 0 6px 0', color: 'var(--text)' }}>
            الجلسات القضائية وتواريخ المحاكم
          </h1>
          <p style={{ margin: 0, fontSize: 13, color: 'var(--muted)', maxWidth: 640, lineHeight: 1.6 }}>
            منظومة الرقابة المركزية لمتابعة كافة الجلسات المجدولة في المحاكم السعودية، سلاسل التأجيل، ومتابعة الجلسات الفائتة بانتظار تدوين النتائج.
          </p>
        </div>

        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
          <a
            href={exportUrl}
            className="btn btn-secondary"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 16px', fontSize: 13 }}
            title="تصدير بيانات الجلسات المصفاة بصيغة Excel CSV"
          >
            <Icon name="download" />
            تصدير الجلسات (CSV)
          </a>
        </div>
      </div>

      {/* ── بطاقات مؤشرات الأداء الحية (KPIs) ── */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))',
          gap: 12,
          marginBottom: 20,
        }}
      >
        {/* إجمالي الجلسات */}
        <div
          onClick={() => handleStatusTab('all')}
          style={{
            cursor: 'pointer',
            padding: '14px 16px',
            background: 'var(--card-bg, #fff)',
            borderRadius: 10,
            border: `1px solid ${status === 'all' ? 'var(--gold)' : 'var(--line-soft)'}`,
            boxShadow: 'var(--card-shadow, 0 1px 3px rgba(0,0,0,0.05))',
            transition: 'all 0.2s',
          }}
        >
          <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 4 }}>إجمالي الجلسات</div>
          <div style={{ fontSize: 24, fontWeight: 800, color: 'var(--text)' }}>{kpis.total}</div>
        </div>

        {/* جلسات اليوم */}
        <div
          onClick={() => handleStatusTab('today')}
          style={{
            cursor: 'pointer',
            padding: '14px 16px',
            background: 'var(--card-bg, #fff)',
            borderRadius: 10,
            border: `1px solid ${status === 'today' ? 'var(--green)' : 'var(--line-soft)'}`,
            boxShadow: 'var(--card-shadow, 0 1px 3px rgba(0,0,0,0.05))',
            transition: 'all 0.2s',
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
            <span style={{ fontSize: 12, color: 'var(--muted)' }}>جلسات اليوم</span>
            {kpis.today > 0 && <span style={{ width: 8, height: 8, borderRadius: '50%', background: 'var(--green)' }} />}
          </div>
          <div style={{ fontSize: 24, fontWeight: 800, color: 'var(--green)' }}>{kpis.today}</div>
        </div>

        {/* الجلسات القادمة */}
        <div
          onClick={() => handleStatusTab('upcoming')}
          style={{
            cursor: 'pointer',
            padding: '14px 16px',
            background: 'var(--card-bg, #fff)',
            borderRadius: 10,
            border: `1px solid ${status === 'upcoming' ? 'var(--blue)' : 'var(--line-soft)'}`,
            boxShadow: 'var(--card-shadow, 0 1px 3px rgba(0,0,0,0.05))',
            transition: 'all 0.2s',
          }}
        >
          <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 4 }}>الجلسات القادمة</div>
          <div style={{ fontSize: 24, fontWeight: 800, color: 'var(--blue)' }}>{kpis.upcoming}</div>
        </div>

        {/* فائتة — بانتظار النتيجة */}
        <div
          onClick={() => handleStatusTab('lapsed')}
          style={{
            cursor: 'pointer',
            padding: '14px 16px',
            background: kpis.lapsed > 0 ? 'rgba(245, 158, 11, 0.08)' : 'var(--card-bg, #fff)',
            borderRadius: 10,
            border: `1px solid ${status === 'lapsed' || kpis.lapsed > 0 ? 'var(--amber)' : 'var(--line-soft)'}`,
            boxShadow: 'var(--card-shadow, 0 1px 3px rgba(0,0,0,0.05))',
            transition: 'all 0.2s',
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
            <span style={{ fontSize: 12, color: kpis.lapsed > 0 ? 'var(--amber)' : 'var(--muted)', fontWeight: 600 }}>
              فائتة بانتظار النتيجة ⚠️
            </span>
          </div>
          <div style={{ fontSize: 24, fontWeight: 800, color: 'var(--amber)' }}>{kpis.lapsed}</div>
        </div>

        {/* الجلسات المنعقدة */}
        <div
          onClick={() => handleStatusTab('held')}
          style={{
            cursor: 'pointer',
            padding: '14px 16px',
            background: 'var(--card-bg, #fff)',
            borderRadius: 10,
            border: `1px solid ${status === 'held' ? 'var(--green)' : 'var(--line-soft)'}`,
            boxShadow: 'var(--card-shadow, 0 1px 3px rgba(0,0,0,0.05))',
            transition: 'all 0.2s',
          }}
        >
          <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 4 }}>الجلسات المنعقدة</div>
          <div style={{ fontSize: 24, fontWeight: 800, color: 'var(--text)' }}>{kpis.held}</div>
        </div>

        {/* الجلسات المؤجلة */}
        <div
          onClick={() => handleStatusTab('postponed')}
          style={{
            cursor: 'pointer',
            padding: '14px 16px',
            background: 'var(--card-bg, #fff)',
            borderRadius: 10,
            border: `1px solid ${status === 'postponed' ? 'var(--purple)' : 'var(--line-soft)'}`,
            boxShadow: 'var(--card-shadow, 0 1px 3px rgba(0,0,0,0.05))',
            transition: 'all 0.2s',
          }}
        >
          <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 4 }}>الجلسات المؤجلة</div>
          <div style={{ fontSize: 24, fontWeight: 800, color: 'var(--purple)' }}>{kpis.postponed}</div>
        </div>
      </div>

      {/* ── شريط التصفية والبحث الموحد ── */}
      <div
        className="card"
        style={{
          padding: 16,
          marginBottom: 16,
          background: 'var(--card-bg, #fff)',
          borderRadius: 10,
          border: '1px solid var(--line-soft)',
        }}
      >
        <form onSubmit={handleSearchSubmit}>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
            {/* حقل البحث الشامل */}
            <div style={{ flex: '1 1 240px', position: 'relative' }}>
              <input
                type="text"
                placeholder="بحث برقم القضية، العنوان، المحكمة، المحامي، العميل، الخصم..."
                value={q}
                onChange={(e) => setQ(e.target.value)}
                style={{
                  width: '100%',
                  padding: '9px 12px',
                  borderRadius: 8,
                  border: '1px solid var(--line-soft)',
                  background: 'var(--paper-2)',
                  fontSize: 13,
                  outline: 'none',
                }}
              />
            </div>

            {/* فلتر المحكمة */}
            <div style={{ flex: '0 1 180px' }}>
              <select
                value={court}
                onChange={(e) => {
                  setCourt(e.target.value);
                  applyFilters({ court: e.target.value || undefined });
                }}
                style={{
                  width: '100%',
                  padding: '9px 10px',
                  borderRadius: 8,
                  border: '1px solid var(--line-soft)',
                  background: 'var(--paper-2)',
                  fontSize: 13,
                }}
              >
                <option value="">كافة المحاكم</option>
                {options.courts.map((c) => (
                  <option key={c} value={c}>
                    {c}
                  </option>
                ))}
              </select>
            </div>

            {/* فلتر المحامي */}
            <div style={{ flex: '0 1 170px' }}>
              <select
                value={lawyerId}
                onChange={(e) => {
                  setLawyerId(e.target.value);
                  applyFilters({ lawyer_id: e.target.value || undefined });
                }}
                style={{
                  width: '100%',
                  padding: '9px 10px',
                  borderRadius: 8,
                  border: '1px solid var(--line-soft)',
                  background: 'var(--paper-2)',
                  fontSize: 13,
                }}
              >
                <option value="">كافة المحامين</option>
                {options.lawyers.map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.name}
                  </option>
                ))}
              </select>
            </div>

            {/* فلتر النطاق الزمني السريع */}
            <div style={{ flex: '0 1 140px' }}>
              <select
                value={preset}
                onChange={(e) => handlePresetChange(e.target.value)}
                style={{
                  width: '100%',
                  padding: '9px 10px',
                  borderRadius: 8,
                  border: '1px solid var(--line-soft)',
                  background: 'var(--paper-2)',
                  fontSize: 13,
                }}
              >
                <option value="all">كل الأوقات</option>
                <option value="today">جلسات اليوم</option>
                <option value="week">هذا الأسبوع</option>
                <option value="month">هذا الشهر</option>
                <option value="custom">تاريخ مخصص...</option>
              </select>
            </div>

            {/* فلتر الترتيب */}
            <div style={{ flex: '0 1 140px' }}>
              <select
                value={sort}
                onChange={(e) => {
                  setSort(e.target.value);
                  applyFilters({ sort: e.target.value });
                }}
                style={{
                  width: '100%',
                  padding: '9px 10px',
                  borderRadius: 8,
                  border: '1px solid var(--line-soft)',
                  background: 'var(--paper-2)',
                  fontSize: 13,
                }}
              >
                <option value="nearest">الأقرب موعداً</option>
                <option value="furthest">الأبعد موعداً</option>
                <option value="newest">الأحدث تسجيلاً</option>
              </select>
            </div>

            {/* أزرار الإجراء */}
            <div style={{ display: 'flex', gap: 6 }}>
              <button type="submit" className="btn btn-primary" style={{ padding: '8px 16px', fontSize: 13 }}>
                بحث
              </button>
              {(q || status !== 'all' || court || lawyerId || preset !== 'all' || sort !== 'nearest') && (
                <button
                  type="button"
                  onClick={resetFilters}
                  className="btn btn-ghost"
                  style={{ padding: '8px 12px', fontSize: 13 }}
                  title="تفريغ كافة الفلاتر"
                >
                  إعادة ضبط
                </button>
              )}
            </div>
          </div>

          {/* حقول التاريخ المخصص عند اختيار custom */}
          {preset === 'custom' && (
            <div
              style={{
                display: 'flex',
                gap: 12,
                marginTop: 12,
                paddingTop: 12,
                borderTop: '1px dashed var(--line-soft)',
                alignItems: 'center',
                flexWrap: 'wrap',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ fontSize: 12, color: 'var(--muted)' }}>من تاريخ:</span>
                <input
                  type="date"
                  value={from}
                  onChange={(e) => setFrom(e.target.value)}
                  style={{ padding: '6px 10px', borderRadius: 6, border: '1px solid var(--line-soft)', fontSize: 13 }}
                />
              </div>

              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ fontSize: 12, color: 'var(--muted)' }}>إلى تاريخ:</span>
                <input
                  type="date"
                  value={to}
                  onChange={(e) => setTo(e.target.value)}
                  style={{ padding: '6px 10px', borderRadius: 6, border: '1px solid var(--line-soft)', fontSize: 13 }}
                />
              </div>

              <button
                type="button"
                onClick={() => applyFilters({ from, to })}
                className="btn btn-secondary"
                style={{ padding: '6px 14px', fontSize: 12 }}
              >
                تطبيق التاريخ
              </button>
            </div>
          )}
        </form>
      </div>

      {/* ── ألسنة الحالة السريعة ── */}
      <div
        style={{
          display: 'flex',
          gap: 6,
          marginBottom: 16,
          overflowX: 'auto',
          paddingBottom: 4,
          scrollbarWidth: 'none',
        }}
      >
        {[
          { key: 'all', label: 'كافة الجلسات' },
          { key: 'today', label: `اليوم (${kpis.today})` },
          { key: 'upcoming', label: `القادمة (${kpis.upcoming})` },
          { key: 'lapsed', label: `بانتظار النتيجة (${kpis.lapsed})` },
          { key: 'held', label: `منعقدة (${kpis.held})` },
          { key: 'postponed', label: `مؤجلة (${kpis.postponed})` },
          { key: 'cancelled', label: 'ملغاة' },
        ].map((tab) => (
          <button
            key={tab.key}
            type="button"
            onClick={() => handleStatusTab(tab.key)}
            style={{
              padding: '6px 14px',
              borderRadius: 20,
              fontSize: 12,
              fontWeight: status === tab.key ? 700 : 500,
              border: `1px solid ${status === tab.key ? 'var(--gold)' : 'var(--line-soft)'}`,
              background: status === tab.key ? 'var(--gold)' : 'var(--card-bg, #fff)',
              color: status === tab.key ? '#fff' : 'var(--text)',
              cursor: 'pointer',
              whiteSpace: 'nowrap',
              transition: 'all 0.15s ease',
            }}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* ── جدول الجلسات التنفيذي المحصن ── */}
      <div
        className="t-wrap"
        style={{
          background: 'var(--card-bg, #fff)',
          borderRadius: 10,
          border: '1px solid var(--line-soft)',
          overflowX: 'auto',
          WebkitOverflowScrolling: 'touch',
          marginBottom: 16,
        }}
      >
        <table className="tbl" style={{ width: '100%', minWidth: 1050, borderCollapse: 'collapse', textAlign: 'right' }}>
          <thead>
            <tr style={{ background: 'var(--paper-2)', borderBottom: '1px solid var(--line-soft)' }}>
              <th style={{ padding: '12px 14px', fontSize: 12, fontWeight: 700 }}>القضية والمرجع</th>
              <th style={{ padding: '12px 14px', fontSize: 12, fontWeight: 700 }}>عنوان الجلسة</th>
              <th style={{ padding: '12px 14px', fontSize: 12, fontWeight: 700 }}>المحكمة والدائرة</th>
              <th style={{ padding: '12px 14px', fontSize: 12, fontWeight: 700 }}>الموعد والتوقيت</th>
              <th style={{ padding: '12px 14px', fontSize: 12, fontWeight: 700 }}>المحامي والعميل</th>
              <th style={{ padding: '12px 14px', fontSize: 12, fontWeight: 700 }}>حالة الجلسة</th>
              <th style={{ padding: '12px 14px', fontSize: 12, fontWeight: 700 }}>نتيجة وقرار الجلسة</th>
              <th style={{ padding: '12px 14px', fontSize: 12, fontWeight: 700, textAlign: 'center' }}>التفاصيل</th>
            </tr>
          </thead>
          <tbody>
            {hearings.data.length > 0 ? (
              hearings.data.map((h) => {
                const titleSnippet = truncateWords(h.title, 6);
                const outcomeSnippet = h.outcome ? truncateWords(h.outcome, 8) : '—';

                return (
                  <tr
                    key={h.id}
                    style={{
                      borderBottom: '1px solid var(--line-soft)',
                      background: h.lapsed ? 'rgba(245, 158, 11, 0.03)' : 'transparent',
                    }}
                  >
                    {/* القضية والمرجع */}
                    <td style={{ padding: '12px 14px', verticalAlign: 'middle' }}>
                      {h.case ? (
                        <div>
                          <Link
                            href={h.case.url}
                            style={{
                              fontSize: 13,
                              fontWeight: 700,
                              color: 'var(--blue)',
                              textDecoration: 'none',
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: 4,
                            }}
                          >
                            <Icon name="scale" />
                            {h.case.no}
                          </Link>
                          <div
                            style={{
                              fontSize: 11,
                              color: 'var(--muted)',
                              marginTop: 2,
                              overflowWrap: 'break-word',
                              wordBreak: 'normal',
                            }}
                            title={h.case.title}
                          >
                            {truncateWords(h.case.title, 5)}
                          </div>
                        </div>
                      ) : (
                        <span style={{ color: 'var(--muted)', fontSize: 12 }}>—</span>
                      )}
                    </td>

                    {/* عنوان الجلسة */}
                    <td style={{ padding: '12px 14px', verticalAlign: 'middle' }}>
                      <div
                        style={{
                          fontSize: 13,
                          fontWeight: 600,
                          color: 'var(--text)',
                          overflowWrap: 'break-word',
                          wordBreak: 'normal',
                        }}
                        title={h.title}
                      >
                        {titleSnippet}
                      </div>
                      {h.postponedFrom && (
                        <div style={{ fontSize: 10, color: 'var(--purple)', marginTop: 2, display: 'flex', alignItems: 'center', gap: 3 }}>
                          <span>↩️</span> مؤجلة من جلسة سابقة
                        </div>
                      )}
                      {h.postponedTo && (
                        <div style={{ fontSize: 10, color: 'var(--muted)', marginTop: 2, display: 'flex', alignItems: 'center', gap: 3 }}>
                          <span>↪️</span> أُجّلت إلى موعد لاحق
                        </div>
                      )}
                    </td>

                    {/* المحكمة والدائرة */}
                    <td style={{ padding: '12px 14px', verticalAlign: 'middle' }}>
                      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text)' }} title={h.court}>
                        {truncateWords(h.court, 5)}
                      </div>
                      <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>
                        {h.circuit !== '—' ? `الدائرة: ${h.circuit}` : 'الدائرة غير محددة'}
                      </div>
                    </td>

                    {/* الموعد والتوقيت */}
                    <td style={{ padding: '12px 14px', verticalAlign: 'middle', whiteSpace: 'nowrap' }}>
                      <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text)' }}>
                        {h.day}
                      </div>
                      <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2, display: 'flex', alignItems: 'center', gap: 6 }}>
                        <span>{h.time}</span>
                        <span
                          style={{
                            fontSize: 10,
                            padding: '1px 6px',
                            borderRadius: 4,
                            background: h.isToday ? 'var(--green-soft, #e8f5e9)' : 'var(--paper-2)',
                            color: h.isToday ? 'var(--green)' : 'var(--muted)',
                            fontWeight: 600,
                          }}
                        >
                          {h.relativeDate}
                        </span>
                      </div>
                      {/* المدّة المتوقّعة بالصيغة المشتركة (`hearingDurationLabel`) — كانت لا تصل الشاشة */}
                      {hearingDurationLabel(h.durationMin) && (
                        <div style={{ fontSize: 10.5, color: 'var(--muted)', marginTop: 2 }}>{hearingDurationLabel(h.durationMin)}</div>
                      )}
                    </td>

                    {/* المحامي والعميل */}
                    <td style={{ padding: '12px 14px', verticalAlign: 'middle' }}>
                      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text)' }}>
                        {h.case?.lawyer || 'غير مسند'}
                      </div>
                      <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>
                        العميل: {h.case?.client || '—'}
                      </div>
                    </td>

                    {/* حالة الجلسة */}
                    <td style={{ padding: '12px 14px', verticalAlign: 'middle', whiteSpace: 'nowrap' }}>
                      {getStatusBadge(h)}
                    </td>

                    {/* نتيجة وقرار الجلسة */}
                    <td style={{ padding: '12px 14px', verticalAlign: 'middle' }}>
                      <div
                        style={{
                          fontSize: 12,
                          color: h.outcome ? 'var(--text)' : 'var(--muted)',
                          fontStyle: h.outcome ? 'normal' : 'italic',
                          maxWidth: 240,
                          overflowWrap: 'break-word',
                          wordBreak: 'normal',
                        }}
                        title={h.outcome || undefined}
                      >
                        {outcomeSnippet}
                      </div>
                    </td>

                    {/* الإجراءات */}
                    <td style={{ padding: '12px 14px', verticalAlign: 'middle', textAlign: 'center' }}>
                      <button
                        type="button"
                        onClick={() => setActiveHearing(h)}
                        className="btn btn-secondary"
                        style={{
                          padding: '5px 12px',
                          fontSize: 12,
                          borderRadius: 6,
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: 4,
                        }}
                      >
                        <Icon name="eye" />
                        عرض
                      </button>
                    </td>
                  </tr>
                );
              })
            ) : (
              <tr>
                <td colSpan={8} style={{ padding: '40px 20px', textAlign: 'center', color: 'var(--muted)' }}>
                  <div style={{ display: 'inline-block', marginBottom: 10 }}>
                    <Icon name="calgrid" cls="ic big" />
                  </div>
                  <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--text)' }}>لا توجد جلسات قضائية مطابقة للبحث أو الفلتر</div>
                  <p style={{ margin: '6px 0 0', fontSize: 12 }}>جرّب تغيير حالة الفلتر أو النطاق الزمني لعرض الجلسات المسجلة.</p>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {/* ── شريط التصفيح (Pagination) ── */}
      {hearings.meta.total > 0 && (
        <div
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            flexWrap: 'wrap',
            gap: 12,
            padding: '12px 16px',
            background: 'var(--card-bg, #fff)',
            borderRadius: 10,
            border: '1px solid var(--line-soft)',
          }}
        >
          <div style={{ fontSize: 12, color: 'var(--muted)' }}>
            عرض السجلات من <b>{hearings.meta.from || 0}</b> إلى <b>{hearings.meta.to || 0}</b> من إجمالي{' '}
            <b>{hearings.meta.total}</b> جلسة
          </div>

          <div style={{ display: 'flex', gap: 6 }}>
            {hearings.links.prev ? (
              <Link
                href={hearings.links.prev}
                className="btn btn-secondary"
                style={{ padding: '6px 14px', fontSize: 12 }}
                preserveState
                preserveScroll
              >
                السابق
              </Link>
            ) : (
              <button disabled className="btn btn-secondary" style={{ padding: '6px 14px', fontSize: 12, opacity: 0.5 }}>
                السابق
              </button>
            )}

            <div style={{ display: 'flex', alignItems: 'center', padding: '0 8px', fontSize: 12, color: 'var(--text)', fontWeight: 600 }}>
              صفحة {hearings.meta.currentPage} من {hearings.meta.lastPage}
            </div>

            {hearings.links.next ? (
              <Link
                href={hearings.links.next}
                className="btn btn-secondary"
                style={{ padding: '6px 14px', fontSize: 12 }}
                preserveState
                preserveScroll
              >
                التالي
              </Link>
            ) : (
              <button disabled className="btn btn-secondary" style={{ padding: '6px 14px', fontSize: 12, opacity: 0.5 }}>
                التالي
              </button>
            )}
          </div>
        </div>
      )}

      {/* ── درج ملف تفاصيل الجلسة المنبثق (Slide-over Dossier) ── */}
      {activeHearing && (
        <Modal
          open={!!activeHearing}
          onClose={() => setActiveHearing(null)}
          title={`ملف الجلسة: ${activeHearing.title}`}
          maxWidth={640}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            {/* بطاقة التنبيه إن كانت الجلسة فائتة */}
            {activeHearing.lapsed && (
              <div
                style={{
                  padding: 12,
                  borderRadius: 8,
                  background: 'rgba(245, 158, 11, 0.1)',
                  border: '1px solid var(--amber)',
                  display: 'flex',
                  alignItems: 'center',
                  gap: 10,
                }}
              >
                <Icon name="alert" />
                <div style={{ fontSize: 12, color: 'var(--amber)', fontWeight: 600 }}>
                  تنبيه إداري: فات موعد هذه الجلسة ولم يقم المحامي المسند بتسجيل نتيجتها أو تحرير محضرها حتى الآن.
                </div>
              </div>
            )}

            {/* شبكة معلومات الجلسة الأساسية */}
            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
                gap: 10,
                background: 'var(--paper-2)',
                padding: 14,
                borderRadius: 8,
                border: '1px solid var(--line-soft)',
              }}
            >
              <div>
                <span style={{ fontSize: 11, color: 'var(--muted)', display: 'block' }}>المحكمة المختصة</span>
                <b style={{ fontSize: 13, color: 'var(--text)' }}>{activeHearing.court}</b>
              </div>

              <div>
                <span style={{ fontSize: 11, color: 'var(--muted)', display: 'block' }}>الدائرة القضائية</span>
                <b style={{ fontSize: 13, color: 'var(--text)' }}>{activeHearing.circuit}</b>
              </div>

              <div>
                <span style={{ fontSize: 11, color: 'var(--muted)', display: 'block' }}>تاريخ الجلسة</span>
                <b style={{ fontSize: 13, color: 'var(--text)' }}>{activeHearing.day}</b>
              </div>

              <div>
                <span style={{ fontSize: 11, color: 'var(--muted)', display: 'block' }}>توقيت الانعقاد</span>
                <b style={{ fontSize: 13, color: 'var(--text)' }}>{activeHearing.time}</b>
              </div>

              <div>
                <span style={{ fontSize: 11, color: 'var(--muted)', display: 'block' }}>حالة الجلسة</span>
                <div style={{ marginTop: 2 }}>{getStatusBadge(activeHearing)}</div>
              </div>

              <div>
                <span style={{ fontSize: 11, color: 'var(--muted)', display: 'block' }}>الموعد النسبي</span>
                <b style={{ fontSize: 13, color: 'var(--text)' }}>{activeHearing.relativeDate}</b>
              </div>
            </div>

            {/* بطاقة القضية والعميل المرتبطين */}
            {activeHearing.case && (
              <div
                style={{
                  padding: 14,
                  borderRadius: 8,
                  border: '1px solid var(--line-soft)',
                  background: 'var(--card-bg, #fff)',
                }}
              >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                  <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text)' }}>بيانات ملف القضية</span>
                  <Link
                    href={activeHearing.case.url}
                    className="btn btn-secondary"
                    style={{ fontSize: 11, padding: '4px 10px' }}
                  >
                    فتح ملف القضية كاملةً ↗
                  </Link>
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 8, fontSize: 12 }}>
                  <div>
                    <span style={{ color: 'var(--muted)' }}>رقم القضية: </span>
                    <b>{activeHearing.case.no}</b>
                  </div>
                  <div>
                    <span style={{ color: 'var(--muted)' }}>نوع القضية: </span>
                    <b>{activeHearing.case.type}</b>
                  </div>
                  <div>
                    <span style={{ color: 'var(--muted)' }}>العميل: </span>
                    <b>{activeHearing.case.realClient}</b>
                  </div>
                  <div>
                    <span style={{ color: 'var(--muted)' }}>الخصم: </span>
                    <b>{activeHearing.case.opponent}</b>
                  </div>
                  <div>
                    <span style={{ color: 'var(--muted)' }}>المحامي الموكل: </span>
                    <b>{activeHearing.case.lawyer}</b>
                  </div>
                  <div>
                    <span style={{ color: 'var(--muted)' }}>حالة القضية: </span>
                    <Badge text={activeHearing.case.status} tone={activeHearing.case.tone} />
                  </div>
                </div>
              </div>
            )}

            {/* نتيجة ومضبطة الجلسة */}
            <div
              style={{
                padding: 14,
                borderRadius: 8,
                border: '1px solid var(--line-soft)',
                background: 'var(--card-bg, #fff)',
              }}
            >
              <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text)', marginBottom: 8 }}>
                مضبطة وقرار الجلسة:
              </div>
              {activeHearing.outcome ? (
                <div
                  style={{
                    fontSize: 13,
                    lineHeight: 1.7,
                    color: 'var(--text)',
                    whiteSpace: 'pre-wrap',
                    background: 'var(--paper-2)',
                    padding: 12,
                    borderRadius: 6,
                    border: '1px solid var(--line-soft)',
                  }}
                >
                  {activeHearing.outcome}
                </div>
              ) : (
                <div style={{ fontSize: 12, color: 'var(--muted)', fontStyle: 'italic' }}>
                  لم يتم تدوين نتيجة أو قرار لهذه الجلسة بعد.
                </div>
              )}
            </div>

            {/* سلسلة التأجيلات إن وجدت */}
            {(activeHearing.postponedFrom || activeHearing.postponedTo) && (
              <div
                style={{
                  padding: 14,
                  borderRadius: 8,
                  border: '1px solid var(--line-soft)',
                  background: 'var(--card-bg, #fff)',
                }}
              >
                <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text)', marginBottom: 8 }}>
                  سلسلة ومسار التأجيلات القضائية:
                </div>

                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {activeHearing.postponedFrom && (
                    <div
                      style={{
                        padding: 10,
                        background: 'rgba(168, 85, 247, 0.06)',
                        borderRadius: 6,
                        border: '1px solid var(--purple)',
                        fontSize: 12,
                      }}
                    >
                      <b style={{ color: 'var(--purple)' }}>↩️ مؤجلة من جلسة سابقة:</b>
                      <div style={{ marginTop: 4, color: 'var(--text)' }}>
                        {activeHearing.postponedFrom.title} · {activeHearing.postponedFrom.date}
                      </div>
                      {activeHearing.postponedFrom.outcome && (
                        <div style={{ marginTop: 2, color: 'var(--muted)', fontSize: 11 }}>
                          سبب التأجيل: {activeHearing.postponedFrom.outcome}
                        </div>
                      )}
                    </div>
                  )}

                  {activeHearing.postponedTo && (
                    <div
                      style={{
                        padding: 10,
                        background: 'var(--paper-2)',
                        borderRadius: 6,
                        border: '1px solid var(--line-soft)',
                        fontSize: 12,
                      }}
                    >
                      <b style={{ color: 'var(--text)' }}>↪️ أُجّلت إلى موعد لاحق:</b>
                      <div style={{ marginTop: 4, color: 'var(--text)' }}>
                        {activeHearing.postponedTo.title} · {activeHearing.postponedTo.date}
                      </div>
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* المستندات والمذكرات المرفوعة للجلسة */}
            <div
              style={{
                padding: 14,
                borderRadius: 8,
                border: '1px solid var(--line-soft)',
                background: 'var(--card-bg, #fff)',
              }}
            >
              <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text)', marginBottom: 8 }}>
                المذكرات والمستندات المقدمة بالجلسة:
              </div>

              {activeHearing.documents.length > 0 ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                  {activeHearing.documents.map((doc) => (
                    <div
                      key={doc.id}
                      style={{
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        padding: '8px 12px',
                        background: 'var(--paper-2)',
                        borderRadius: 6,
                        border: '1px solid var(--line-soft)',
                      }}
                    >
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <Icon name="doc" />
                        <div>
                          <b style={{ fontSize: 12, color: 'var(--text)' }}>{doc.name}</b>
                          <div style={{ fontSize: 10, color: 'var(--muted)' }}>
                            {doc.docType || 'مستند قضائي'} {doc.size ? `· ${doc.size}` : ''} {doc.date ? `· ${doc.date}` : ''}
                          </div>
                        </div>
                      </div>

                      {doc.downloadUrl && (
                        <a
                          href={doc.downloadUrl}
                          className="btn btn-secondary"
                          style={{ fontSize: 11, padding: '4px 10px' }}
                          download
                        >
                          تنزيل
                        </a>
                      )}
                    </div>
                  ))}
                </div>
              ) : (
                <div style={{ fontSize: 12, color: 'var(--muted)', fontStyle: 'italic' }}>
                  لا توجد مذكرات أو ملفات مرفقة بهذه الجلسة.
                </div>
              )}
            </div>

            {/* زر الإغلاق */}
            <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 8 }}>
              <button
                type="button"
                onClick={() => setActiveHearing(null)}
                className="btn btn-secondary"
                style={{ padding: '8px 20px', fontSize: 13 }}
              >
                إغلاق
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
