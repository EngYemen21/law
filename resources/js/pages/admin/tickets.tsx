import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Pagination, { type Paginated } from '@/components/babylon/Pagination';
import Badge from '@/components/babylon/Badge';
import { TICKET_PRIORITIES, isUrgentTicket } from '@/lib/employee-data';
import { truncateWords } from '@/lib/utils';

/** صفّ قائمة التذاكر للإدارة — `Admin\TicketController::listRow` (حمولةٌ خفيفة، لا `toEmployeeCard`). */
interface AdminTicketRow {
  no: string;
  client: string;
  clientId?: number;
  type: string;
  subject?: string;
  priority?: string;
  dept: string;
  lawyer: string;
  lawyerId?: number | null;
  status: string;
  tone: string;
  date?: string;
}

interface FilterParams {
  q?: string;
  status?: string;
  dept?: string;
  lawyer_id?: string;
  priority?: string;
  date_from?: string;
  date_to?: string;
  sort?: string;
}

interface Props {
  tickets: Paginated<AdminTicketRow>;
  filters?: FilterParams;
  departments?: string[];
  lawyers?: { id: number; name: string }[];
  summaryStats?: {
    total: number;
    open: number;
    pending_admin: number;
    completed: number;
  };
}

const openTicket = (no: string) => router.visit(`/admin/tickets/${encodeURIComponent(no)}`);

const AdminTickets: React.FC<Props> = ({
  tickets,
  filters = {},
  departments = [],
  lawyers = [],
  summaryStats,
}) => {
  const [search, setSearch] = useState(filters.q || '');
  const [status, setStatus] = useState(filters.status || '');
  const [dept, setDept] = useState(filters.dept || '');
  const [lawyerId, setLawyerId] = useState(filters.lawyer_id || '');
  const [priority, setPriority] = useState(filters.priority || '');
  const [dateFrom, setDateFrom] = useState(filters.date_from || '');
  const [dateTo, setDateTo] = useState(filters.date_to || '');
  const [sort, setSort] = useState(filters.sort || 'latest');
  const [showFilters, setShowFilters] = useState(
    Boolean(
      filters.dept ||
      filters.lawyer_id ||
      filters.priority ||
      filters.date_from ||
      filters.date_to ||
      (filters.sort && filters.sort !== 'latest')
    )
  );

  const activeFiltersCount = [
    Boolean(filters.status && !['open', 'pending_admin', 'completed'].includes(filters.status)),
    Boolean(filters.dept),
    Boolean(filters.lawyer_id),
    Boolean(filters.priority),
    Boolean(filters.date_from),
    Boolean(filters.date_to),
    Boolean(filters.sort && filters.sort !== 'latest'),
  ].filter(Boolean).length;

  const applyFilters = (overrides: Partial<FilterParams> = {}) => {
    const params: Record<string, string> = {
      q: overrides.q !== undefined ? overrides.q : search.trim(),
      status: overrides.status !== undefined ? overrides.status : status,
      dept: overrides.dept !== undefined ? overrides.dept : dept,
      lawyer_id: overrides.lawyer_id !== undefined ? overrides.lawyer_id : lawyerId,
      priority: overrides.priority !== undefined ? overrides.priority : priority,
      date_from: overrides.date_from !== undefined ? overrides.date_from : dateFrom,
      date_to: overrides.date_to !== undefined ? overrides.date_to : dateTo,
      sort: overrides.sort !== undefined ? overrides.sort : sort,
    };

    Object.keys(params).forEach((key) => {
      if (!params[key]) delete params[key];
    });

    router.get('/admin/tickets', params, {
      preserveState: true,
      replace: true,
    });
  };

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    applyFilters();
  };

  const resetAllFilters = () => {
    setSearch('');
    setStatus('');
    setDept('');
    setLawyerId('');
    setPriority('');
    setDateFrom('');
    setDateTo('');
    setSort('latest');
    router.get('/admin/tickets', {}, { preserveState: true, replace: true });
  };

  /**
   * عرض القسم المختص بشكل أنيق ومتناسق مع هوية الجدول
   */
  const renderDepartment = (department?: string) => {
    const deptName = department?.trim();
    if (!deptName || deptName === '—' || deptName === 'عام') {
      return (
        <span
          className="badge-s b-grey"
          style={{ fontSize: 11.5, padding: '3px 8px', opacity: 0.85 }}
        >
          القسم العام
        </span>
      );
    }

    let toneClass = 'b-blue';
    if (deptName.includes('عمال') || deptName.includes('تنفيذ')) {
      toneClass = 'b-cyan';
    } else if (deptName.includes('أحوال') || deptName.includes('عقار') || deptName.includes('تركات') || deptName.includes('أسرة')) {
      toneClass = 'b-amber';
    } else if (deptName.includes('جزائ') || deptName.includes('جنائ')) {
      toneClass = 'b-red';
    }

    return (
      <span
        className={`badge-s ${toneClass}`}
        style={{
          fontSize: 11.5,
          padding: '3px 9px',
          borderRadius: 6,
          display: 'inline-flex',
          alignItems: 'center',
          gap: 5,
          whiteSpace: 'nowrap',
        }}
      >
        <Icon name="folder" cls="ic sm" />
        <span>{deptName}</span>
      </span>
    );
  };

  return (
    <>
      <div className="hero">
        <h2>إشراف التذاكر والاستشارات 🎫</h2>
        <p>متابعة التذاكر القانونية الواردة، مسارات المعالجة، توزيع المهام على المحامين، والاعتماد الإداري النهائي.</p>
        <div className="hero-cta" style={{ flexWrap: 'wrap', gap: 8 }}>
          <button
            className={`hero-b ${!status ? '' : 'ghost'}`}
            onClick={() => { setStatus(''); applyFilters({ status: '' }); }}
            type="button"
          >
            <Icon name="ticket" /> كل التذاكر ({summaryStats?.total ?? tickets.meta.total})
          </button>
          <button
            className={`hero-b ${status === 'open' ? '' : 'ghost'}`}
            onClick={() => { setStatus('open'); applyFilters({ status: 'open' }); }}
            type="button"
          >
            <Icon name="folder" /> التذاكر المفتوحة ({summaryStats?.open ?? '—'})
          </button>
          <button
            className={`hero-b ${status === 'pending_admin' ? '' : 'ghost'}`}
            onClick={() => { setStatus('pending_admin'); applyFilters({ status: 'pending_admin' }); }}
            type="button"
          >
            <Icon name="check" /> بانتظار الاعتماد ({summaryStats?.pending_admin ?? '—'})
          </button>
          <button
            className={`hero-b ${status === 'completed' ? '' : 'ghost'}`}
            onClick={() => { setStatus('completed'); applyFilters({ status: 'completed' }); }}
            type="button"
          >
            <Icon name="card" /> التذاكر المكتملة ({summaryStats?.completed ?? '—'})
          </button>
        </div>
      </div>

      <div className="card">
        {/* الترويسة الرئيسية + شريط البحث والفلترة السريعة */}
        <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <h3>التذاكر</h3>
              <span className="sub">({tickets.meta.total} من {summaryStats?.total ?? tickets.meta.total})</span>
            </div>

            {/* أزرار الحالة السريعة */}
            <div style={{ display: 'flex', gap: 4, marginRight: 8, flexWrap: 'wrap' }}>
              <button
                type="button"
                className={`btn sm ${!status ? '' : 'soft'}`}
                style={{ padding: '4px 10px', fontSize: 11.5 }}
                onClick={() => { setStatus(''); applyFilters({ status: '' }); }}
              >
                الكل ({summaryStats?.total ?? tickets.meta.total})
              </button>
              <button
                type="button"
                className={`btn sm ${status === 'open' ? '' : 'soft'}`}
                style={{ padding: '4px 10px', fontSize: 11.5 }}
                onClick={() => { setStatus('open'); applyFilters({ status: 'open' }); }}
              >
                مفتوحة ({summaryStats?.open ?? '—'})
              </button>
              <button
                type="button"
                className={`btn sm ${status === 'pending_admin' ? '' : 'soft'}`}
                style={{ padding: '4px 10px', fontSize: 11.5 }}
                onClick={() => { setStatus('pending_admin'); applyFilters({ status: 'pending_admin' }); }}
              >
                بانتظار الاعتماد ({summaryStats?.pending_admin ?? '—'})
              </button>
              <button
                type="button"
                className={`btn sm ${status === 'completed' ? '' : 'soft'}`}
                style={{ padding: '4px 10px', fontSize: 11.5 }}
                onClick={() => { setStatus('completed'); applyFilters({ status: 'completed' }); }}
              >
                مكتملة ({summaryStats?.completed ?? '—'})
              </button>
            </div>
          </div>

          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <form onSubmit={handleSearchSubmit} style={{ display: 'flex', gap: 6, minWidth: 220, maxWidth: 320 }}>
              <div style={{ position: 'relative', width: '100%' }}>
                <input
                  type="text"
                  className="input"
                  placeholder="ابحث بالرقم، الموضوع، العميل..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  style={{ paddingInlineStart: 28, fontSize: 12.5, padding: '7px 10px 7px 28px' }}
                />
                {search && (
                  <button
                    type="button"
                    onClick={() => { setSearch(''); applyFilters({ q: '' }); }}
                    style={{
                      position: 'absolute',
                      left: 8,
                      top: '50%',
                      transform: 'translateY(-50%)',
                      background: 'none',
                      border: 'none',
                      cursor: 'pointer',
                      color: 'var(--muted)',
                      fontSize: 11,
                    }}
                    title="مسح البحث"
                  >
                    ✕
                  </button>
                )}
              </div>
              <button type="submit" className="btn sm">
                <Icon name="search" />
              </button>
            </form>

            <button
              type="button"
              className={`btn sm ${showFilters || activeFiltersCount > 0 ? '' : 'soft'}`}
              onClick={() => setShowFilters(!showFilters)}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
            >
              <Icon name="link" /> الفلاتر
              {activeFiltersCount > 0 && (
                <span className="badge-s b-blue" style={{ fontSize: 10, padding: '1px 5px', color: '#fff', background: 'var(--primary)' }}>
                  {activeFiltersCount}
                </span>
              )}
            </button>

            {(activeFiltersCount > 0 || search || status) && (
              <button
                type="button"
                onClick={resetAllFilters}
                className="btn sm ghost"
                style={{ fontSize: 12, padding: '7px 10px' }}
                title="إلغاء جميع الفلاتر"
              >
                إلغاء الفلاتر
              </button>
            )}
          </div>
        </div>

        {/* لوحة الفلاتر المتقدمة القابلة للطي */}
        {showFilters && (
          <div
            style={{
              padding: '14px 18px',
              background: 'var(--paper-2)',
              borderBottom: '1px solid var(--line-soft)',
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
              gap: 12,
              alignItems: 'end',
            }}
          >
            {/* فلتر القسم */}
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--ink)', marginBottom: 4 }}>
                القسم المختص
              </label>
              <select
                className="input"
                value={dept}
                onChange={(e) => {
                  setDept(e.target.value);
                  applyFilters({ dept: e.target.value });
                }}
                style={{ fontSize: 12.5, padding: '8px 10px' }}
              >
                <option value="">جميع الأقسام</option>
                {departments.map((d) => (
                  <option key={d} value={d}>{d}</option>
                ))}
              </select>
            </div>

            {/* فلتر المحامي المسند */}
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--ink)', marginBottom: 4 }}>
                المحامي المسؤول
              </label>
              <select
                className="input"
                value={lawyerId}
                onChange={(e) => {
                  setLawyerId(e.target.value);
                  applyFilters({ lawyer_id: e.target.value });
                }}
                style={{ fontSize: 12.5, padding: '8px 10px' }}
              >
                <option value="">الكل (مسندين وغير مسندين)</option>
                <option value="unassigned">غير مسند لمحامٍ بعد</option>
                {lawyers.map((l) => (
                  <option key={l.id} value={l.id}>{l.name}</option>
                ))}
              </select>
            </div>

            {/* فلتر الأولوية */}
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--ink)', marginBottom: 4 }}>
                مستوى الأولوية
              </label>
              <select
                className="input"
                value={priority}
                onChange={(e) => {
                  setPriority(e.target.value);
                  applyFilters({ priority: e.target.value });
                }}
                style={{ fontSize: 12.5, padding: '8px 10px' }}
              >
                <option value="">جميع الأولويات</option>
                {/* من الكتالوج: كانت «عاجلة» أوّلَ خيارٍ ولا وجود لها في القاعدة، و«عالية» مفقودة */}
                {TICKET_PRIORITIES.map((p) => <option key={p} value={p}>{p}</option>)}
              </select>
            </div>

            {/* تاريخ الإنشاء من */}
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--ink)', marginBottom: 4 }}>
                تاريخ الإنشاء (من)
              </label>
              <input
                type="date"
                className="input"
                value={dateFrom}
                onChange={(e) => {
                  setDateFrom(e.target.value);
                  applyFilters({ date_from: e.target.value });
                }}
                style={{ fontSize: 12.5, padding: '7px 10px' }}
              />
            </div>

            {/* تاريخ الإنشاء إلى */}
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--ink)', marginBottom: 4 }}>
                تاريخ الإنشاء (إلى)
              </label>
              <input
                type="date"
                className="input"
                value={dateTo}
                onChange={(e) => {
                  setDateTo(e.target.value);
                  applyFilters({ date_to: e.target.value });
                }}
                style={{ fontSize: 12.5, padding: '7px 10px' }}
              />
            </div>

            {/* الترتيب */}
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--ink)', marginBottom: 4 }}>
                الترتيب
              </label>
              <select
                className="input"
                value={sort}
                onChange={(e) => {
                  setSort(e.target.value);
                  applyFilters({ sort: e.target.value });
                }}
                style={{ fontSize: 12.5, padding: '8px 10px' }}
              >
                <option value="latest">الأحدث أولاً</option>
                <option value="oldest">الأقدم أولاً</option>
                <option value="priority">حسب الأولوية (الأعلى أولاً)</option>
              </select>
            </div>
          </div>
        )}

        {/* جدول التذاكر */}
        <div className="card-b t-wrap">
          {tickets.data.length ? (
            <table className="tbl" style={{ minWidth: 760 }}>
              <thead>
                <tr>
                  <th style={{ minWidth: 120 }}>رقم التذكرة</th>
                  <th style={{ minWidth: 150 }}>العميل</th>
                  <th style={{ minWidth: 140 }}>النوع والموضوع</th>
                  <th style={{ minWidth: 140 }}>القسم المختص</th>
                  <th style={{ minWidth: 130 }}>المحامي المسند</th>
                  <th style={{ minWidth: 100 }}>الحالة</th>
                  <th style={{ minWidth: 90 }}>التاريخ</th>
                  <th style={{ minWidth: 80, textAlign: 'center' }}>الإجراء</th>
                </tr>
              </thead>
              <tbody>
                {tickets.data.map((t) => (
                  <tr key={t.no} className="click" onClick={() => openTicket(t.no)}>
                    <td className="nowrap">
                      <b className="mono">{t.no}</b>
                      {isUrgentTicket(t.priority) && (
                        <span className="badge-s b-red" style={{ fontSize: 10, padding: '1px 5px', marginRight: 6 }}>
                          عاجلة
                        </span>
                      )}
                    </td>
                    <td style={{ minWidth: 150, maxWidth: 220 }}>
                      <b title={t.client}>{truncateWords(t.client, 4)}</b>
                    </td>
                    <td style={{ minWidth: 160, maxWidth: 280 }}>
                      <div style={{ fontWeight: 600 }}>{t.type}</div>
                      {t.subject && (
                        <div
                          className="muted"
                          title={t.subject}
                          style={{ fontSize: 11.5, marginTop: 2, lineHeight: 1.4 }}
                        >
                          {truncateWords(t.subject, 8)}
                        </div>
                      )}
                    </td>
                    <td>{renderDepartment(t.dept)}</td>
                    <td className="muted nowrap" title={t.lawyer}>{truncateWords(t.lawyer, 4)}</td>
                    <td className="nowrap"><Badge text={t.status} tone={t.tone} /></td>
                    <td className="muted nowrap" style={{ fontSize: 12 }}>{t.date}</td>
                    <td className="nowrap" style={{ textAlign: 'center' }} onClick={(e) => e.stopPropagation()}>
                      <button className="btn sm soft" onClick={() => openTicket(t.no)} type="button" style={{ whiteSpace: 'nowrap' }}>
                        <Icon name="out" /> عرض
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="empty">
              <Icon name="ticket" />
              <b>{tickets.meta.total === 0 && !(filters.q || filters.status || filters.dept || filters.lawyer_id || filters.priority || filters.date_from || filters.date_to) ? 'لا توجد تذاكر بعد' : 'لا توجد تذاكر تطابق معايير البحث والفلترة'}</b>
              {(activeFiltersCount > 0 || search || status) && (
                <div style={{ marginTop: 8 }}>
                  <button type="button" onClick={resetAllFilters} className="btn sm soft">
                    إعادة ضبط الفلاتر
                  </button>
                </div>
              )}
            </div>
          )}
          <Pagination meta={tickets.meta} only={['tickets']} />
        </div>
      </div>
    </>
  );
};

export default AdminTickets;
