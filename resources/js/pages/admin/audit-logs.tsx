import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '@/components/babylon/Badge';
import Pagination from '@/components/babylon/Pagination';
import type { Paginated } from '@/components/babylon/Pagination';
// import { useToast } from '@/components/babylon/Toast'; // لا إشعارات توست في الصفحة بعد — يُعاد تفعيله عند الحاجة
import Icon from '@/lib/icons';

export interface AuditLogCard {
  id: number;
  userName: string;
  userRole: string;
  userAvatar?: string | null;
  action: string;
  category: string;
  auditableRef: string;
  auditableType?: string | null;
  description: string;
  beforeState?: Record<string, any> | null;
  afterState?: Record<string, any> | null;
  ipAddress: string;
  userAgent: string;
  severity: 'info' | 'warning' | 'critical';
  /** الأسماء والألوان من الخادم (`AuditLog::toCard`) — الشاشة لا تقارن نصّ الفئة ولا الدور */
  severityLabel: string;
  roleLabel: string;
  roleTone: 'b-blue' | 'b-green' | 'b-amber' | 'b-grey';
  categoryColor: string;
  categoryIcon: string;
  time: string;
  timeHuman: string;
}

interface Props {
  logs: Paginated<AuditLogCard>;
  stats: {
    total: number;
    today: number;
    critical: number;
    financial: number;
    activeActors: number;
  };
  categories: string[];
  actors: string[];
  /** خيارات الأهمية بأسمائها العربيّة من `AuditLog::SEVERITY_LABELS` */
  severityOptions: { value: string; label: string }[];
  filters: {
    search: string;
    category: string;
    severity: string;
    user: string;
    from_date: string;
    to_date: string;
  };
}

type ViewMode = 'table' | 'timeline';
type DrawerTab = 'overview' | 'diff' | 'security';

export const AdminAuditLogs: React.FC<Props> = ({
  logs,
  stats,
  categories = [],
  actors = [],
  severityOptions = [],
  filters,
}) => {
  // const toast = useToast(); // غير مستخدم بعد

  // Local state for interactive filtering
  const [searchQuery, setSearchQuery] = useState(filters.search || '');
  const [selectedCategory, setSelectedCategory] = useState(filters.category || 'all');
  const [selectedSeverity, setSelectedSeverity] = useState(filters.severity || 'all');
  const [selectedUser, setSelectedUser] = useState(filters.user || 'all');
  const [fromDate, setFromDate] = useState(filters.from_date || '');
  const [toDate, setToDate] = useState(filters.to_date || '');

  const [viewMode, setViewMode] = useState<ViewMode>('table');

  // Slide-over Drawer State
  const [activeLogId, setActiveLogId] = useState<number | null>(null);
  const [drawerTab, setDrawerTab] = useState<DrawerTab>('overview');

  // الصفحة الحاليّة من الخادم — لا ترشيحَ ثانٍ في المتصفّح
  const rows = useMemo(() => logs?.data ?? [], [logs]);
  const meta = logs?.meta ?? { current_page: 1, last_page: 1, per_page: 50, total: 0 };

  const activeLog = useMemo(
    () => (activeLogId !== null ? rows.find((l) => l.id === activeLogId) ?? null : null),
    [activeLogId, rows]
  );

  /*
   * **مرشِّحٌ واحد في الخادم.** كانت الشاشة تُعيد ترشيح أحدث ٢٠٠ قيدٍ في المتصفّح لحظياً
   * بينما الترشيح الخادميّ ينتظر «تطبيق» — مرشّحان على مجموعتين مختلفتين، والقيد المبحوث
   * عنه قد يكون خارج الـ٢٠٠. الآن كلّ تغييرٍ في القوائم يُطبَّق على القاعدة كلّها، والبحث
   * النصّيّ عند Enter أو «تطبيق»، والترقيم يحمل المرشّحات.
   */
  const applyFilters = (over: Partial<Props['filters']> = {}) => {
    router.get(
      '/admin/audit-logs',
      {
        search: searchQuery,
        category: selectedCategory,
        severity: selectedSeverity,
        user: selectedUser,
        from_date: fromDate,
        to_date: toDate,
        ...over,
      },
      { preserveState: true, preserveScroll: true, only: ['logs', 'filters'] }
    );
  };

  const handleApplyServerFilter = () => applyFilters();

  const hasFilters = (filters.search || '') !== '' || (filters.category || 'all') !== 'all'
    || (filters.severity || 'all') !== 'all' || (filters.user || 'all') !== 'all'
    || (filters.from_date || '') !== '' || (filters.to_date || '') !== '';

  const handleResetFilters = () => {
    setSearchQuery('');
    setSelectedCategory('all');
    setSelectedSeverity('all');
    setSelectedUser('all');
    setFromDate('');
    setToDate('');
    router.get('/admin/audit-logs', {}, { preserveState: true, preserveScroll: true });
  };

  /*
   * **التصدير يحمل المرشّحات المطبَّقة** (`filters` من الخادم لا حقولاً لم تُطبَّق بعد): كان الرابط
   * ثابتاً فيُصدِّر أحدث ألف قيدٍ أيّاً كان الترشيح. والخادم يكتب كلّ المطابق بلا سقف.
   */
  const exportHref = `/admin/audit-logs/export?${new URLSearchParams(
    Object.entries(filters).filter(([, v]) => v !== '' && v !== 'all' && v != null) as [string, string][]
  ).toString()}`;

  return (
    <div className="admin-audit-logs-root" style={{ paddingBottom: 60, width: '100%' }}>
      {/* ── 1. الهيدر وشريط التصدير ── */}
      <div className="greet" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 14 }}>
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0 }}>
            <Icon name="clock" cls="ic" />
            سجل الرقابة والتدقيق الأمني — الإدارة العليا
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--muted)', fontSize: 13 }}>
            تتبع لحظي وشامل لكافة الأنشطة، التعديلات المالية، إجراءات القضايا والاستشارات، وسجلات الدخول لضمان الامتثال والسرية التامة.
          </p>
        </div>

        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          {/* تنزيل ملفّ لا زيارة صفحة — لذا `<a>` لا `<Link>` */}
          <a
            href={exportHref}
            className="btn soft"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontWeight: 700 }}
            title={hasFilters ? `تصدير القيود المطابقة للترشيح (${meta.total})` : `تصدير كلّ القيود (${stats.total})`}
          >
            <Icon name="download" /> {hasFilters ? 'تصدير المطابق CSV' : 'تصدير السجلات CSV'}
          </a>

          {/* تبديل العرض */}
          <div style={{ display: 'flex', background: 'rgba(0,0,0,0.05)', borderRadius: 8, padding: 3 }}>
            <button
              type="button"
              onClick={() => setViewMode('table')}
              style={{
                border: 'none',
                background: viewMode === 'table' ? '#fff' : 'transparent',
                boxShadow: viewMode === 'table' ? '0 1px 4px rgba(0,0,0,0.1)' : 'none',
                padding: '6px 12px',
                borderRadius: 6,
                fontSize: 12.5,
                fontWeight: 700,
                cursor: 'pointer',
                color: viewMode === 'table' ? 'var(--primary)' : 'var(--muted)',
                display: 'flex',
                alignItems: 'center',
                gap: 4,
              }}
            >
              <Icon name="calgrid" /> جدول التدقيق
            </button>
            <button
              type="button"
              onClick={() => setViewMode('timeline')}
              style={{
                border: 'none',
                background: viewMode === 'timeline' ? '#fff' : 'transparent',
                boxShadow: viewMode === 'timeline' ? '0 1px 4px rgba(0,0,0,0.1)' : 'none',
                padding: '6px 12px',
                borderRadius: 6,
                fontSize: 12.5,
                fontWeight: 700,
                cursor: 'pointer',
                color: viewMode === 'timeline' ? 'var(--primary)' : 'var(--muted)',
                display: 'flex',
                alignItems: 'center',
                gap: 4,
              }}
            >
              <Icon name="clock" /> الخط الزمني
            </button>
          </div>
        </div>
      </div>

      {/* ── 2. شريط مؤشرات القيادة الحية (Telemetry Ribbon) ── */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12, margin: '18px 0' }}>
        <div className="card" style={{ margin: 0, padding: '14px 16px', borderTop: '3px solid var(--primary)' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', fontWeight: 600 }}>إجمالي السجلات الموثقة</div>
          <b style={{ fontSize: 22, color: '#13314F', display: 'block', marginTop: 4 }}>{stats.total.toLocaleString()}</b>
          <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>عملية موثقة بالنظام</div>
        </div>

        <div className="card" style={{ margin: 0, padding: '14px 16px', borderTop: '3px solid #1E9D6B' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', fontWeight: 600 }}>عمليات اليوم الحية</div>
          <b style={{ fontSize: 22, color: '#1E9D6B', display: 'block', marginTop: 4 }}>{stats.today.toLocaleString()}</b>
          <div style={{ fontSize: 11, color: '#1E9D6B', marginTop: 2 }}>منذ بداية اليوم</div>
        </div>

        <div className="card" style={{ margin: 0, padding: '14px 16px', borderTop: '3px solid #dc2626' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', fontWeight: 600 }}>عمليات حساسة / تحذيرية</div>
          <b style={{ fontSize: 22, color: '#dc2626', display: 'block', marginTop: 4 }}>{stats.critical.toLocaleString()}</b>
          <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>تعديل أسعار أو أمان</div>
        </div>

        <div className="card" style={{ margin: 0, padding: '14px 16px', borderTop: '3px solid #C0832B' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', fontWeight: 600 }}>العمليات المالية والفواتير</div>
          <b style={{ fontSize: 22, color: '#C0832B', display: 'block', marginTop: 4 }}>{stats.financial.toLocaleString()}</b>
          <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>إصدار ودفع وتعديل</div>
        </div>

        <div className="card" style={{ margin: 0, padding: '14px 16px', borderTop: '3px solid #11A0C8' }}>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', fontWeight: 600 }}>المستخدمون النشطون</div>
          <b style={{ fontSize: 22, color: '#11A0C8', display: 'block', marginTop: 4 }}>{stats.activeActors.toLocaleString()}</b>
          <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>خلال آخر 24 ساعة</div>
        </div>
      </div>

      {/* ── 3. شريط الفلاتر الذكية والبحث المتعدد ── */}
      <div className="card" style={{ padding: 14, marginBottom: 16 }}>
        {/* صف البحث والحقول السريعة */}
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(220px, 2fr) repeat(auto-fit, minmax(140px, 1fr)) auto', gap: 10, alignItems: 'center' }}>
          {/* حقل البحث */}
          <div style={{ position: 'relative' }}>
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleApplyServerFilter()}
              placeholder="بحث بالوصف، المستخدم، الرقم المرجعي أو IP…"
              style={{
                width: '100%',
                padding: '9px 12px 9px 32px',
                borderRadius: 8,
                border: '1px solid rgba(0,0,0,0.15)',
                fontSize: 13,
                boxSizing: 'border-box',
              }}
            />
            <span style={{ position: 'absolute', left: 10, top: 9, color: 'var(--muted)' }}>
              <Icon name="search" />
            </span>
          </div>

          {/* فئة السجل */}
          <select
            value={selectedCategory}
            onChange={(e) => { setSelectedCategory(e.target.value); applyFilters({ category: e.target.value }); }}
            style={{ padding: '9px 10px', borderRadius: 8, border: '1px solid rgba(0,0,0,0.15)', fontSize: 12.5 }}
          >
            <option value="all">جميع الفئات</option>
            {categories.map((cat) => (
              <option key={cat} value={cat}>
                {cat}
              </option>
            ))}
          </select>

          {/* الأهمية */}
          <select
            value={selectedSeverity}
            onChange={(e) => { setSelectedSeverity(e.target.value); applyFilters({ severity: e.target.value }); }}
            style={{ padding: '9px 10px', borderRadius: 8, border: '1px solid rgba(0,0,0,0.15)', fontSize: 12.5 }}
          >
            <option value="all">كافة مستويات الأهمية</option>
            {severityOptions.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>

          {/* المستخدم / الفاعل */}
          <select
            value={selectedUser}
            onChange={(e) => { setSelectedUser(e.target.value); applyFilters({ user: e.target.value }); }}
            style={{ padding: '9px 10px', borderRadius: 8, border: '1px solid rgba(0,0,0,0.15)', fontSize: 12.5 }}
          >
            <option value="all">جميع المستخدمين</option>
            {actors.map((actor) => (
              <option key={actor} value={actor}>
                {actor}
              </option>
            ))}
          </select>

          {/* من تاريخ */}
          <input
            type="date"
            value={fromDate}
            onChange={(e) => { setFromDate(e.target.value); applyFilters({ from_date: e.target.value }); }}
            style={{ padding: '8px 10px', borderRadius: 8, border: '1px solid rgba(0,0,0,0.15)', fontSize: 12 }}
            title="من تاريخ"
          />

          {/* إلى تاريخ */}
          <input
            type="date"
            value={toDate}
            onChange={(e) => { setToDate(e.target.value); applyFilters({ to_date: e.target.value }); }}
            style={{ padding: '8px 10px', borderRadius: 8, border: '1px solid rgba(0,0,0,0.15)', fontSize: 12 }}
            title="إلى تاريخ"
          />

          {/* أزرار التطبيق والمسح */}
          <div style={{ display: 'flex', gap: 6 }}>
            <button
              type="button"
              className="btn primary sm"
              onClick={handleApplyServerFilter}
              style={{ padding: '8px 14px', fontSize: 12 }}
            >
              تطبيق
            </button>
            {(searchQuery || selectedCategory !== 'all' || selectedSeverity !== 'all' || selectedUser !== 'all' || fromDate || toDate) && (
              <button
                type="button"
                className="btn soft sm"
                onClick={handleResetFilters}
                style={{ padding: '8px 10px', fontSize: 12 }}
                title="إعادة ضبط الفلاتر"
              >
                مسح
              </button>
            )}
          </div>
        </div>

        {/* كبسولات الفئات السريعة */}
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 12, paddingTop: 10, borderTop: '1px solid rgba(0,0,0,0.06)' }}>
          <button
            type="button"
            className={`btn sm ${selectedCategory === 'all' ? 'primary' : 'soft'}`}
            style={{ padding: '4px 12px', fontSize: 11.5 }}
            onClick={() => { setSelectedCategory('all'); applyFilters({ category: 'all' }); }}
          >
            الكل ({stats.total})
          </button>
          {/* بلا عدٍّ لكلّ فئة: كان يُحسب على الصفّ المحمَّل فيصير أصفاراً بمجرّد اختيار فئة */}
          {categories.map((cat) => {
            return (
              <button
                key={cat}
                type="button"
                className={`btn sm ${selectedCategory === cat ? 'primary' : 'soft'}`}
                style={{ padding: '4px 12px', fontSize: 11.5 }}
                onClick={() => { setSelectedCategory(cat); applyFilters({ category: cat }); }}
              >
                {cat}
              </button>
            );
          })}
        </div>
      </div>

      {/* ── 4. العرض الرئيسي (جدول الرقابة أو الخط الزمني) ── */}
      {viewMode === 'table' ? (
        <div className="card" style={{ overflow: 'hidden', padding: 0 }}>
          <div className="card-h" style={{ padding: '14px 18px', borderBottom: '1px solid rgba(0,0,0,0.08)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' }}>
              <h3 style={{ margin: 0, fontSize: 15, color: '#13314F' }}>
                مصفوفة سجلات التدقيق الأمني
              </h3>
              <span className="sub" style={{ fontSize: 12 }}>
                {hasFilters ? `${meta.total} قيداً مطابقاً من أصل ${stats.total}` : `${stats.total} قيد مسجّل`}
              </span>
            </div>
          </div>

          <div style={{ overflowX: 'auto' }}>
            {rows.length > 0 ? (
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5, textAlign: 'right' }}>
                <thead>
                  <tr style={{ background: '#f8fafc', borderBottom: '1.5px solid rgba(0,0,0,0.08)', color: '#475569' }}>
                    <th style={{ padding: '12px 16px' }}>المستخدم / الفاعل</th>
                    <th style={{ padding: '12px 14px' }}>الإجراء والفئة</th>
                    <th style={{ padding: '12px 14px' }}>المرجع المرتبط</th>
                    <th style={{ padding: '12px 14px' }}>تفاصيل العملية</th>
                    <th style={{ padding: '12px 14px' }}>عنوان IP</th>
                    <th style={{ padding: '12px 14px' }}>التوقيت</th>
                    <th style={{ padding: '12px 16px', textAlign: 'center' }}>فحص 360°</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((log) => {
                    const isWarning = log.severity === 'warning';
                    const isCritical = log.severity === 'critical';

                    return (
                      <tr
                        key={log.id}
                        style={{
                          borderBottom: '1px solid rgba(0,0,0,0.05)',
                          background: isCritical ? 'rgba(220, 38, 38, 0.03)' : isWarning ? 'rgba(245, 158, 11, 0.03)' : 'transparent',
                          transition: 'background 0.15s ease',
                          cursor: 'pointer',
                        }}
                        onClick={() => setActiveLogId(log.id)}
                      >
                        {/* المستخدم */}
                        <td style={{ padding: '12px 16px' }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <div
                              style={{
                                width: 32,
                                height: 32,
                                borderRadius: '50%',
                                background: 'rgba(0,0,0,0.06)',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                fontWeight: 700,
                                fontSize: 13,
                                color: 'var(--primary)',
                              }}
                            >
                              {log.userName.charAt(0)}
                            </div>
                            <div>
                              <b style={{ color: '#13314F', display: 'block' }}>{log.userName}</b>
                              <Badge text={log.roleLabel} tone={log.roleTone} />
                            </div>
                          </div>
                        </td>

                        {/* الإجراء والفئة */}
                        <td style={{ padding: '12px 14px' }}>
                          <b style={{ display: 'block', color: '#1E293B', marginBottom: 3 }}>{log.action}</b>
                          <span
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: 4,
                              fontSize: 11,
                              fontWeight: 600,
                              color: log.categoryColor,
                              background: `${log.categoryColor}15`,
                              padding: '2px 8px',
                              borderRadius: 6,
                            }}
                          >
                            <Icon name={log.categoryIcon} /> {log.category}
                          </span>
                        </td>

                        {/* المرجع */}
                        <td style={{ padding: '12px 14px' }}>
                          {log.auditableRef && log.auditableRef !== '—' ? (
                            <span
                              style={{
                                fontFamily: 'monospace',
                                fontWeight: 700,
                                color: 'var(--primary)',
                                background: 'rgba(14, 92, 156, 0.08)',
                                padding: '3px 8px',
                                borderRadius: 6,
                                fontSize: 12,
                              }}
                            >
                              {log.auditableRef}
                            </span>
                          ) : (
                            <span style={{ color: 'var(--muted)' }}>—</span>
                          )}
                        </td>

                        {/* الوصف */}
                        <td style={{ padding: '12px 14px', maxWidth: 300 }}>
                          <span
                            style={{
                              display: '-webkit-box',
                              WebkitLineClamp: 2,
                              WebkitBoxOrient: 'vertical',
                              overflow: 'hidden',
                              lineHeight: 1.5,
                              color: '#334155',
                            }}
                          >
                            {log.description}
                          </span>
                        </td>

                        {/* IP */}
                        <td style={{ padding: '12px 14px', fontFamily: 'monospace', fontSize: 12, color: 'var(--muted)' }}>
                          {log.ipAddress}
                        </td>

                        {/* التوقيت */}
                        <td style={{ padding: '12px 14px' }}>
                          <b style={{ display: 'block', fontSize: 12, color: '#1E293B' }}>{log.timeHuman}</b>
                          <span style={{ fontSize: 11, color: 'var(--muted)' }}>{log.time}</span>
                        </td>

                        {/* فحص 360° */}
                        <td style={{ padding: '12px 16px', textAlign: 'center' }}>
                          <button
                            type="button"
                            className="btn soft sm"
                            onClick={(e) => {
                              e.stopPropagation();
                              setActiveLogId(log.id);
                            }}
                            title="فحص التفاصيل ومقارنة التغييرات"
                            style={{ padding: '6px 12px' }}
                          >
                            <Icon name="compass" /> فحص
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            ) : (
              <div style={{ textAlign: 'center', padding: 50, color: 'var(--muted)' }}>
                <Icon name="clock" />
                {hasFilters ? (<>
                  <b style={{ display: 'block', marginTop: 8, fontSize: 15 }}>لا توجد سجلات تدقيق مطابقة للفلاتر</b>
                  <p style={{ margin: '4px 0 12px', fontSize: 12.5 }}>جرّب مسح الفلاتر أو تغيير شروط البحث</p>
                  <button type="button" className="btn soft sm" onClick={handleResetFilters}>
                    إعادة ضبط الفلاتر
                  </button>
                </>) : (
                  <b style={{ display: 'block', marginTop: 8, fontSize: 15 }}>لا توجد سجلات تدقيق بعد</b>
                )}
              </div>
            )}
          </div>
        </div>
      ) : (
        /* ── عرض الخط الزمني (Timeline View) ── */
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {rows.length > 0 ? (
            rows.map((log) => (
              <div
                key={log.id}
                className="card"
                style={{
                  margin: 0,
                  padding: 16,
                  borderRight: `4px solid ${log.categoryColor}`,
                  cursor: 'pointer',
                  transition: 'box-shadow 0.15s ease',
                }}
                onClick={() => setActiveLogId(log.id)}
              >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 8 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <div
                      style={{
                        width: 36,
                        height: 36,
                        borderRadius: 10,
                        background: `${log.categoryColor}15`,
                        color: log.categoryColor,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                      }}
                    >
                      <Icon name={log.categoryIcon} />
                    </div>
                    <div>
                      <b style={{ fontSize: 14, color: '#13314F' }}>{log.action}</b>
                      <div style={{ fontSize: 12, color: 'var(--muted)', display: 'flex', alignItems: 'center', gap: 6, marginTop: 2 }}>
                        <span>بواسطة: <b>{log.userName}</b> ({log.roleLabel})</span>
                        {log.auditableRef && log.auditableRef !== '—' && (
                          <span style={{ fontFamily: 'monospace', fontWeight: 700, color: 'var(--primary)' }}>
                            · {log.auditableRef}
                          </span>
                        )}
                      </div>
                    </div>
                  </div>

                  <div style={{ textAlign: 'left' }}>
                    <span style={{ fontSize: 12, fontWeight: 700, color: '#1E293B' }}>{log.timeHuman}</span>
                    <div style={{ fontSize: 11, color: 'var(--muted)' }}>{log.time}</div>
                  </div>
                </div>

                <p style={{ margin: '10px 0 0', fontSize: 13, lineHeight: 1.6, color: '#334155' }}>
                  {log.description}
                </p>

                {/* مقارنة سريعة إذا وجدت */}
                {(log.beforeState || log.afterState) && (
                  <div
                    style={{
                      marginTop: 10,
                      padding: 10,
                      background: 'rgba(0,0,0,0.02)',
                      borderRadius: 8,
                      display: 'flex',
                      gap: 16,
                      fontSize: 12,
                    }}
                  >
                    {log.beforeState && (
                      <div>
                        <span style={{ color: 'var(--muted)' }}>قبل:</span>{' '}
                        <b style={{ color: '#dc2626' }}>{JSON.stringify(log.beforeState)}</b>
                      </div>
                    )}
                    {log.afterState && (
                      <div>
                        <span style={{ color: 'var(--muted)' }}>بعد:</span>{' '}
                        <b style={{ color: '#1E9D6B' }}>{JSON.stringify(log.afterState)}</b>
                      </div>
                    )}
                  </div>
                )}
              </div>
            ))
          ) : (
            <div className="card" style={{ padding: 40, textAlign: 'center' }}>
              <Icon name="clock" />
              <b style={{ display: 'block', marginTop: 8 }}>{hasFilters ? 'لا توجد أنشطة مطابقة للفلاتر' : 'لا توجد أنشطة مسجّلة بعد'}</b>
            </div>
          )}
        </div>
      )}

      {/* الترقيم يحمل المرشّحات من الرابط — بدل سقف ٢٠٠ صامتٍ بلا صفحات */}
      <Pagination meta={meta} only={['logs', 'filters']} />

      {/* ── 5. الدرج السحابي لفحص السجل 360° (Portal Slide-Over Drawer) ── */}
      {activeLog && typeof document !== 'undefined' && createPortal(
        <div
          style={{
            position: 'fixed',
            inset: 0,
            zIndex: 99990,
            background: 'rgba(10, 25, 45, 0.45)',
            backdropFilter: 'blur(3px)',
            display: 'flex',
            justifyContent: 'flex-start',
            direction: 'rtl',
            animation: 'recv360FadeIn 0.2s ease-out',
          }}
          onClick={(e) => {
            if (e.target === e.currentTarget) {
setActiveLogId(null);
}
          }}
        >
          <div
            style={{
              width: '100%',
              maxWidth: 580,
              height: '100vh',
              background: '#fff',
              boxShadow: '-8px 0 32px rgba(0,0,0,0.2)',
              display: 'flex',
              flexDirection: 'column',
              animation: 'recv360SlideLeft 0.25s cubic-bezier(0.16, 1, 0.3, 1)',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            {/* رأس الدرج */}
            <div
              style={{
                padding: '20px 24px',
                background: 'linear-gradient(135deg, #13314F, #0E5C9C)',
                color: '#fff',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
              }}
            >
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <span style={{ background: 'rgba(255,255,255,0.2)', padding: '2px 8px', borderRadius: 4, fontSize: 11.5 }}>
                    سجل #{activeLog.id}
                  </span>
                  <span style={{ fontSize: 12, opacity: 0.9 }}>{activeLog.time}</span>
                </div>
                <h3 style={{ margin: '6px 0 0', fontSize: 16, color: '#fff' }}>{activeLog.action}</h3>
              </div>

              <button
                type="button"
                onClick={() => setActiveLogId(null)}
                style={{ background: 'rgba(255,255,255,0.15)', border: 'none', color: '#fff', borderRadius: '50%', width: 32, height: 32, cursor: 'pointer', fontSize: 16 }}
              >
                ✕
              </button>
            </div>

            {/* تبويبات الدرج */}
            <div style={{ display: 'flex', borderBottom: '1px solid rgba(0,0,0,0.08)', background: '#f8fafc', padding: '0 16px' }}>
              {[
                { id: 'overview' as DrawerTab, label: 'تفاصيل العملية', icon: 'doc' },
                { id: 'diff' as DrawerTab, label: 'مقارنة التغييرات', icon: 'check' },
                { id: 'security' as DrawerTab, label: 'سجل الأمان و IP', icon: 'clock' },
              ].map((tab) => (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => setDrawerTab(tab.id)}
                  style={{
                    border: 'none',
                    background: 'none',
                    padding: '12px 14px',
                    fontSize: 13,
                    fontWeight: 700,
                    cursor: 'pointer',
                    color: drawerTab === tab.id ? 'var(--primary)' : 'var(--muted)',
                    borderBottom: drawerTab === tab.id ? '3px solid var(--primary)' : '3px solid transparent',
                    display: 'flex',
                    alignItems: 'center',
                    gap: 6,
                  }}
                >
                  <Icon name={tab.icon} /> {tab.label}
                </button>
              ))}
            </div>

            {/* محتوى تبويبات الدرج */}
            <div className="c360-drawer-body" style={{ padding: 22, flex: 1, minHeight: 0, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 16 }}>
              {/* Tab 1: تفاصيل العملية */}
              {drawerTab === 'overview' && (
                <>
                  <div className="card" style={{ margin: 0, padding: 16 }}>
                    <b style={{ color: 'var(--primary)', fontSize: 13.5, display: 'block', marginBottom: 12 }}>
                      بيانات الفاعل والمورد المرتبط:
                    </b>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 12, fontSize: 12.5 }}>
                      <div>
                        <span style={{ color: 'var(--muted)', fontSize: 11 }}>المستخدم:</span>
                        <div style={{ fontWeight: 700, marginTop: 2 }}>{activeLog.userName}</div>
                      </div>
                      <div>
                        <span style={{ color: 'var(--muted)', fontSize: 11 }}>الدور والصلاحية:</span>
                        <div style={{ marginTop: 2 }}><Badge text={activeLog.roleLabel} tone={activeLog.roleTone} /></div>
                      </div>
                      <div>
                        <span style={{ color: 'var(--muted)', fontSize: 11 }}>الفئة:</span>
                        <div style={{ fontWeight: 700, marginTop: 2, color: activeLog.categoryColor }}>{activeLog.category}</div>
                      </div>
                      <div>
                        <span style={{ color: 'var(--muted)', fontSize: 11 }}>المرجع:</span>
                        <div style={{ fontWeight: 700, marginTop: 2, fontFamily: 'monospace', color: 'var(--primary)' }}>
                          {activeLog.auditableRef}
                        </div>
                      </div>
                    </div>
                  </div>

                  <div className="card" style={{ margin: 0, padding: 16 }}>
                    <b style={{ color: 'var(--primary)', fontSize: 13.5, display: 'block', marginBottom: 8 }}>
                      الوصف القانوني للعملية:
                    </b>
                    <p style={{ margin: 0, fontSize: 13, lineHeight: 1.7, color: '#334155' }}>
                      {activeLog.description}
                    </p>
                  </div>
                </>
              )}

              {/* Tab 2: مقارنة التغييرات */}
              {drawerTab === 'diff' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                    {/* قبل */}
                    <div style={{ background: 'rgba(220, 38, 38, 0.04)', border: '1px solid rgba(220, 38, 38, 0.2)', borderRadius: 10, padding: 14 }}>
                      <b style={{ color: '#dc2626', fontSize: 12.5, display: 'block', marginBottom: 8 }}>
                        الحالة السابقة (Before):
                      </b>
                      {activeLog.beforeState ? (
                        <pre style={{ margin: 0, fontSize: 11.5, background: '#fff', padding: 10, borderRadius: 6, overflowX: 'auto', border: '1px solid rgba(0,0,0,0.06)' }}>
                          {JSON.stringify(activeLog.beforeState, null, 2)}
                        </pre>
                      ) : (
                        <span style={{ fontSize: 12, color: 'var(--muted)' }}>لا توجد قيمة سابقة مسجلة</span>
                      )}
                    </div>

                    {/* بعد */}
                    <div style={{ background: 'rgba(30, 157, 107, 0.04)', border: '1px solid rgba(30, 157, 107, 0.2)', borderRadius: 10, padding: 14 }}>
                      <b style={{ color: '#1E9D6B', fontSize: 12.5, display: 'block', marginBottom: 8 }}>
                        الحالة الجديدة (After):
                      </b>
                      {activeLog.afterState ? (
                        <pre style={{ margin: 0, fontSize: 11.5, background: '#fff', padding: 10, borderRadius: 6, overflowX: 'auto', border: '1px solid rgba(0,0,0,0.06)' }}>
                          {JSON.stringify(activeLog.afterState, null, 2)}
                        </pre>
                      ) : (
                        <span style={{ fontSize: 12, color: 'var(--muted)' }}>لا توجد تعديلات هيكلية مسجلة</span>
                      )}
                    </div>
                  </div>
                </div>
              )}

              {/* Tab 3: سجل الأمان و IP */}
              {drawerTab === 'security' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                  <div className="card" style={{ margin: 0, padding: 16 }}>
                    <b style={{ color: 'var(--primary)', fontSize: 13.5, display: 'block', marginBottom: 12 }}>
                      معلومات الاتصال والأمان:
                    </b>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10, fontSize: 12.5 }}>
                      <div>
                        <span style={{ color: 'var(--muted)', fontSize: 11 }}>عنوان IP المستخدم:</span>
                        <div style={{ fontWeight: 700, fontFamily: 'monospace', marginTop: 2 }}>{activeLog.ipAddress}</div>
                      </div>
                      <div>
                        <span style={{ color: 'var(--muted)', fontSize: 11 }}>متصفح وبيئة العميل (User Agent):</span>
                        <div style={{ fontSize: 11.5, color: '#475569', background: '#f8fafc', padding: '8px 10px', borderRadius: 6, marginTop: 4, wordBreak: 'break-all' }}>
                          {activeLog.userAgent}
                        </div>
                      </div>
                      <div>
                        <span style={{ color: 'var(--muted)', fontSize: 11 }}>مستوى الخطورة والحساسية:</span>
                        <div style={{ marginTop: 4 }}>
                          <span
                            style={{
                              display: 'inline-block',
                              padding: '3px 10px',
                              borderRadius: 6,
                              fontSize: 12,
                              fontWeight: 700,
                              color: activeLog.severity === 'critical' ? '#dc2626' : activeLog.severity === 'warning' ? '#b45309' : '#1E9D6B',
                              background: activeLog.severity === 'critical' ? '#fee2e2' : activeLog.severity === 'warning' ? '#fef3c7' : '#dcfce7',
                            }}
                          >
                            {activeLog.severityLabel}
                          </span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* ذيل الدرج */}
            <div style={{ padding: '14px 22px', borderTop: '1px solid rgba(0,0,0,0.08)', background: '#fafafa', display: 'flex', justifyContent: 'flex-end' }}>
              <button
                type="button"
                className="btn soft sm"
                onClick={() => setActiveLogId(null)}
                style={{ padding: '8px 20px', fontSize: 13, fontWeight: 700 }}
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

export default AdminAuditLogs;
