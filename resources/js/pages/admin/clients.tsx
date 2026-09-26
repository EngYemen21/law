import { Link, router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import Pagination, { type Paginated } from '@/components/babylon/Pagination';

interface ClientRow {
  id: number;
  name: string;
  nid: string;
  email: string;
  mobile: string;
  tickets: number;
  cases: number;
  consults: number;
  executions: number;
  status: string;
  /** لون شارة الحالة من الخادم (`ClientController::index`) */
  statusTone: string;
  createdAt: string;
}

interface FilterParams {
  q?: string;
  status?: string;
  activity?: string;
  date_from?: string;
  date_to?: string;
  sort?: string;
}

interface Props {
  clients: Paginated<ClientRow>;
  filters?: FilterParams;
  summaryStats?: {
    total: number;
    active: number;
    suspended: number;
  };
}

const AdminClients: React.FC<Props> = ({ clients, filters = {}, summaryStats }) => {
  // حالات الفلاتر
  const [search, setSearch] = useState(filters.q || '');
  const [status, setStatus] = useState(filters.status || '');
  const [activity, setActivity] = useState(filters.activity || '');
  const [dateFrom, setDateFrom] = useState(filters.date_from || '');
  const [dateTo, setDateTo] = useState(filters.date_to || '');
  const [sort, setSort] = useState(filters.sort || 'latest');
  const [showFilters, setShowFilters] = useState(
    Boolean(filters.status || filters.activity || filters.date_from || filters.date_to || (filters.sort && filters.sort !== 'latest'))
  );

  // عدد الفلاتر النشطة
  const activeFiltersCount = [
    Boolean(filters.status),
    Boolean(filters.activity),
    Boolean(filters.date_from),
    Boolean(filters.date_to),
    Boolean(filters.sort && filters.sort !== 'latest'),
  ].filter(Boolean).length;

  const applyFilters = (overrides: Partial<FilterParams> = {}) => {
    const params: Record<string, string> = {
      q: overrides.q !== undefined ? overrides.q : search.trim(),
      status: overrides.status !== undefined ? overrides.status : status,
      activity: overrides.activity !== undefined ? overrides.activity : activity,
      date_from: overrides.date_from !== undefined ? overrides.date_from : dateFrom,
      date_to: overrides.date_to !== undefined ? overrides.date_to : dateTo,
      sort: overrides.sort !== undefined ? overrides.sort : sort,
    };

    // إزالة الحقول الفارغة
    Object.keys(params).forEach((key) => {
      if (!params[key]) delete params[key];
    });

    router.get('/admin/clients', params, {
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
    setActivity('');
    setDateFrom('');
    setDateTo('');
    setSort('latest');
    router.get('/admin/clients', {}, { preserveState: true, replace: true });
  };

  /**
   * عرض حقل النشاط بطريقة ذكية ونظيفة ومتجاوبة
   */
  const renderActivity = (c: ClientRow) => {
    const hasTickets = (c.tickets || 0) > 0;
    const hasCases = (c.cases || 0) > 0;
    const hasConsults = (c.consults || 0) > 0;
    const hasExecutions = (c.executions || 0) > 0;
    const hasAny = hasTickets || hasCases || hasConsults || hasExecutions;

    if (!hasAny) {
      return (
        <span className="badge-s b-grey" style={{ fontSize: 11, padding: '3px 8px', opacity: 0.85 }}>
          عميل جديد (بلا نشاط)
        </span>
      );
    }

    return (
      <div style={{ display: 'inline-flex', flexWrap: 'wrap', gap: 4, alignItems: 'center', maxWidth: 220 }}>
        {hasTickets && (
          <span
            className="badge-s b-blue"
            style={{ fontSize: 11, padding: '2px 7px' }}
            title={`${c.tickets} تذاكر استفسار`}
          >
            <Icon name="ticket" cls="ic" /> {c.tickets} {c.tickets === 1 ? 'تذكرة' : 'تذاكر'}
          </span>
        )}
        {hasCases && (
          <span
            className="badge-s b-amber"
            style={{ fontSize: 11, padding: '2px 7px' }}
            title={`${c.cases} قضايا محاكم`}
          >
            <Icon name="scale" cls="ic" /> {c.cases} {c.cases === 1 ? 'قضية' : 'قضايا'}
          </span>
        )}
        {hasConsults && (
          <span
            className="badge-s b-cyan"
            style={{ fontSize: 11, padding: '2px 7px' }}
            title={`${c.consults} استشارات قانونية`}
          >
            <Icon name="video" cls="ic" /> {c.consults} {c.consults === 1 ? 'استشارة' : 'استشارات'}
          </span>
        )}
        {hasExecutions && (
          <span
            className="badge-s b-green"
            style={{ fontSize: 11, padding: '2px 7px' }}
            title={`${c.executions} طلبات تنفيذ`}
          >
            <Icon name="exec" cls="ic" /> {c.executions} تنفيذ
          </span>
        )}
      </div>
    );
  };

  return (
    <>
      <div className="hero">
        <h2>دليل العملاء والحسابات 👥</h2>
        <p>إدارة شاملة لملفات العملاء، مراجعة القضايا والاستشارات، الفواتير، ومتابعة حالة الحسابات وتحديث البيانات.</p>
        <div className="hero-cta" style={{ flexWrap: 'wrap', gap: 8 }}>
          <button
            className={`hero-b ${!status ? '' : 'ghost'}`}
            onClick={() => { setStatus(''); applyFilters({ status: '' }); }}
            type="button"
          >
            <Icon name="user" /> كل العملاء ({summaryStats?.total ?? clients.meta.total})
          </button>
          <button
            className={`hero-b ${status === 'active' ? '' : 'ghost'}`}
            onClick={() => { setStatus('active'); applyFilters({ status: 'active' }); }}
            type="button"
          >
            <Icon name="check" /> الحسابات النشطة ({summaryStats?.active ?? '—'})
          </button>
          <button
            className={`hero-b ${status === 'suspended' ? '' : 'ghost'}`}
            onClick={() => { setStatus('suspended'); applyFilters({ status: 'suspended' }); }}
            type="button"
          >
            <Icon name="alert" /> الحسابات الموقوفة ({summaryStats?.suspended ?? '—'})
          </button>
        </div>
      </div>

      <div className="card">
        {/* الترويسة الرئيسية + شريط البحث السريع والأزرار */}
        <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <h3>العملاء</h3>
              <span className="sub">({clients.meta.total} من {summaryStats?.total ?? clients.meta.total})</span>
            </div>

            {/* شارات تصفية الحالة السريعة */}
            <div style={{ display: 'flex', gap: 4, marginRight: 8 }}>
              <button
                type="button"
                className={`btn sm ${!status ? '' : 'soft'}`}
                style={{ padding: '4px 10px', fontSize: 11.5 }}
                onClick={() => { setStatus(''); applyFilters({ status: '' }); }}
              >
                الكل ({summaryStats?.total ?? clients.meta.total})
              </button>
              <button
                type="button"
                className={`btn sm ${status === 'active' ? '' : 'soft'}`}
                style={{ padding: '4px 10px', fontSize: 11.5 }}
                onClick={() => { setStatus('active'); applyFilters({ status: 'active' }); }}
              >
                النشطين ({summaryStats?.active ?? '—'})
              </button>
              <button
                type="button"
                className={`btn sm ${status === 'suspended' ? '' : 'soft'}`}
                style={{ padding: '4px 10px', fontSize: 11.5 }}
                onClick={() => { setStatus('suspended'); applyFilters({ status: 'suspended' }); }}
              >
                الموقوفين ({summaryStats?.suspended ?? '—'})
              </button>
            </div>
          </div>

          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <form onSubmit={handleSearchSubmit} style={{ display: 'flex', gap: 6, minWidth: 220, maxWidth: 320 }}>
              <div style={{ position: 'relative', width: '100%' }}>
                <input
                  type="text"
                  className="input"
                  placeholder="ابحث بالاسم، الهوية، الجوال..."
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
              <Icon name="link" /> الفلاتر المتقدمة
              {activeFiltersCount > 0 && (
                <span className="badge-s b-blue" style={{ fontSize: 10, padding: '1px 5px', color: '#fff', background: 'var(--primary)' }}>
                  {activeFiltersCount}
                </span>
              )}
            </button>

            {(activeFiltersCount > 0 || search) && (
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

        {/* لوحة الفلاتر المتقدمة (قابلة للفتح والإغلاق) */}
        {showFilters && (
          <div
            style={{
              padding: '14px 18px',
              background: 'var(--paper-2)',
              borderBottom: '1px solid var(--line-soft)',
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
              gap: 12,
              alignItems: 'end',
            }}
          >
            {/* فلتر نوع النشاط */}
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--ink)', marginBottom: 4 }}>
                نوع النشاط القانوني
              </label>
              <select
                className="input"
                value={activity}
                onChange={(e) => {
                  setActivity(e.target.value);
                  applyFilters({ activity: e.target.value });
                }}
                style={{ fontSize: 12.5, padding: '8px 10px' }}
              >
                <option value="">جميع الأنشطة (الكل)</option>
                <option value="active_any">عملاء لديهم أي نشاط</option>
                <option value="has_cases">لديهم قضايا محاكم</option>
                <option value="has_tickets">لديهم تذاكر استفسار</option>
                <option value="has_consults">لديهم استشارات قانونية</option>
                <option value="has_executions">لديهم طلبات تنفيذ</option>
                <option value="inactive">عملاء جدد (بلا نشاط)</option>
              </select>
            </div>

            {/* فلتر تاريخ التسجيل من */}
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--ink)', marginBottom: 4 }}>
                تاريخ التسجيل (من)
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

            {/* فلتر تاريخ التسجيل إلى */}
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--ink)', marginBottom: 4 }}>
                تاريخ التسجيل (إلى)
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

            {/* ترتيب النتائج */}
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--ink)', marginBottom: 4 }}>
                ترتيب النتائج بحسب
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
                <option value="latest">الأحدث تسجيلاً أولاً</option>
                <option value="oldest">الأقدم تسجيلاً أولاً</option>
                <option value="most_active">الأكثر نشاطاً (حسب عدد المعاملات)</option>
                <option value="name_asc">الاسم أبجدياً (أ - ي)</option>
              </select>
            </div>
          </div>
        )}

        {/* جدول العملاء */}
        <div className="card-b t-wrap">
          {clients.data.length === 0 ? (
            <div className="empty">
              <Icon name="user" />
              <b>{clients.meta.total === 0 && !(filters.q || filters.status || filters.activity || filters.date_from || filters.date_to) ? 'لا يوجد عملاء بعد' : 'لا يوجد عملاء يطابقون معايير البحث والفلترة'}</b>
              {(activeFiltersCount > 0 || search) && (
                <div style={{ marginTop: 8 }}>
                  <button type="button" onClick={resetAllFilters} className="btn sm soft">
                    إعادة ضبط الفلاتر
                  </button>
                </div>
              )}
            </div>
          ) : (
            <div className="t-wrap">
              <table className="tbl" style={{ minWidth: 720 }}>
                <thead>
                  <tr>
                    <th style={{ minWidth: 160 }}>العميل</th>
                    <th style={{ minWidth: 110 }}>الهوية</th>
                    <th style={{ minWidth: 120 }}>الجوال</th>
                    <th style={{ minWidth: 160 }}>البريد الإلكتروني</th>
                    <th style={{ minWidth: 170 }}>النشاط</th>
                    <th style={{ minWidth: 90 }}>الحالة</th>
                    <th style={{ minWidth: 100 }}>تاريخ التسجيل</th>
                    <th style={{ minWidth: 90, textAlign: 'center' }}></th>
                  </tr>
                </thead>
                <tbody>
                  {clients.data.map((c) => (
                    <tr
                      key={c.id}
                      onClick={() => router.get(`/admin/clients/${c.id}`)}
                      style={{ cursor: 'pointer' }}
                    >
                      <td>
                        <b>{c.name}</b>
                        <div className="mono muted" style={{ fontSize: 11 }}>CL-{String(c.id).padStart(5, '0')}</div>
                      </td>
                      <td className="mono">{c.nid}</td>
                      <td className="mono" style={{ direction: 'ltr', textAlign: 'right' }}>{c.mobile}</td>
                      <td className="muted">{c.email}</td>
                      <td>{renderActivity(c)}</td>
                      <td>
                        <Badge text={c.status} tone={c.statusTone} />
                      </td>
                      <td className="muted" style={{ fontSize: 12 }}>{c.createdAt}</td>
                      <td style={{ textAlign: 'center' }} onClick={(e) => e.stopPropagation()}>
                        <Link
                          href={`/admin/clients/${c.id}`}
                          className="btn sm soft"
                          style={{ whiteSpace: 'nowrap' }}
                        >
                          <Icon name="out" /> فتح وتعديل
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <Pagination meta={clients.meta} only={['clients']} />
        </div>
      </div>
    </>
  );
};

export default AdminClients;
