import { Link, router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '@/components/babylon/Badge';
import Pagination from '@/components/babylon/Pagination';
import type { Paginated } from '@/components/babylon/Pagination';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { truncateWords } from '@/lib/utils';

export interface JourneyTransitionRow {
  id: number;
  entityType: string;
  entityLabel: string;
  entityTone: string;
  entityId: number | string;
  entityRef: string;
  url: string | null;
  transition: string;
  transitionLabel: string;
  fromState: string;
  toState: string;
  fromTone: string;
  toTone: string;
  actor: {
    id: number | null;
    name: string;
    role: string;
    /** اسم الدور بالعربيّة من الخادم */
    roleLabel: string;
  };
  reason: string | null;
  payload: Record<string, any> | null;
  payloadItems?: { label: string; value: string }[];
  createdAt: string;
  since: string;
}

interface Props {
  transitions: Paginated<JourneyTransitionRow>;
  stats: {
    total: number;
    today: number;
    withReason: number;
    byEntity: {
      tickets: number;
      consults: number;
      cases: number;
      executions: number;
      invoices: number;
    };
  };
  filters: {
    search: string;
    entity_type: string;
    transition: string;
    actor_id: string;
    from_date: string;
    to_date: string;
  };
  availableEntities: { key: string; label: string }[];
  availableTransitions: { key: string; label: string }[];
  availableActors: { id: number; name: string; role: string; roleLabel: string }[];
}

export const AdminJourneyTransitions: React.FC<Props> = ({
  transitions,
  stats,
  filters,
  availableEntities = [],
  availableTransitions = [],
  availableActors = [],
}) => {
  const [search, setSearch] = useState(filters.search || '');
  const [entityType, setEntityType] = useState(filters.entity_type || 'all');
  const [transitionName, setTransitionName] = useState(filters.transition || 'all');
  const [actorId, setActorId] = useState(filters.actor_id || 'all');
  const [fromDate, setFromDate] = useState(filters.from_date || '');
  const [toDate, setToDate] = useState(filters.to_date || '');

  // Slide-over Drawer
  const [activeItem, setActiveItem] = useState<JourneyTransitionRow | null>(null);
  const [copied, setCopied] = useState(false);
  const toast = useToast();
  const [showDevLog, setShowDevLog] = useState(false);

  const rows = useMemo(() => transitions?.data ?? [], [transitions]);
  const meta = transitions?.meta ?? { current_page: 1, last_page: 1, per_page: 50, total: 0 };

  const applyFilters = (overrides: Partial<Props['filters']> = {}) => {
    router.get(
      '/admin/journey-transitions',
      {
        search,
        entity_type: entityType,
        transition: transitionName,
        actor_id: actorId,
        from_date: fromDate,
        to_date: toDate,
        ...overrides,
      },
      { preserveState: true, preserveScroll: true, only: ['transitions', 'filters'] }
    );
  };

  const handleReset = () => {
    setSearch('');
    setEntityType('all');
    setTransitionName('all');
    setActorId('all');
    setFromDate('');
    setToDate('');
    router.get('/admin/journey-transitions', {}, { preserveState: true, preserveScroll: true });
  };

  const handleExport = () => {
    const params = new URLSearchParams({
      search,
      entity_type: entityType,
      transition: transitionName,
      actor_id: actorId,
      from_date: fromDate,
      to_date: toDate,
    });
    window.location.href = `/admin/journey-transitions/export?${params.toString()}`;
  };

  // ينسخ ما يُعرض في السجلّ التقنيّ نفسه (الانتقال + الحمولة) — والحافظة قد تُمنع (صفحةٌ غير آمنة)
  const devLogText = (item: JourneyTransitionRow) => JSON.stringify({ transition: item.transition, payload: item.payload }, null, 2);
  const copyPayload = () => {
    if (!activeItem?.payload) return;
    navigator.clipboard?.writeText(devLogText(activeItem))
      .then(() => {
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      })
      .catch(() => toast('⚠️ تعذّر النسخ — المتصفّح منع الوصول إلى الحافظة؛ حدّد النصّ وانسخه يدوياً.'));
  };

  const hasActiveFilters = Boolean(
    filters.search ||
    (filters.entity_type && filters.entity_type !== 'all') ||
    (filters.transition && filters.transition !== 'all') ||
    (filters.actor_id && filters.actor_id !== 'all') ||
    filters.from_date ||
    filters.to_date
  );

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      {/* ── 1. رأس الصفحة والتعريف الإداري ── */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h2 style={{ fontSize: 20, fontWeight: 800, margin: 0, display: 'flex', alignItems: 'center', gap: 10 }}>
            <span style={{ color: 'var(--primary, #0e5c9c)' }}>
              <Icon name="reply" />
            </span>
            سجل انتقالات الحالات والرحلة الموحد (Workflow Engine)
          </h2>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--muted, #64748b)' }}>
            الرقابة اللحظية لكافة حركات وانتقالات الحالات ومسار القرارات عبر النطاقات الخمسة (تذاكر، استشارات، قضايا، تنفيذ، مالية).
          </p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <button
            type="button"
            className="btn sm soft"
            onClick={handleExport}
            style={{ display: 'flex', alignItems: 'center', gap: 6 }}
          >
            <Icon name="doc" /> تصدير السجل (CSV)
          </button>
        </div>
      </div>

      {/* ── 2. بطاقات المؤشرات الرقمية الحية (KPIs) ── */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12 }}>
        <div className="card" style={{ padding: '14px 18px', borderInlineStart: '4px solid #0e5c9c' }}>
          <div style={{ fontSize: 12, color: 'var(--muted)' }}>إجمالي الانتقالات المسجلة</div>
          <div style={{ fontSize: 22, fontWeight: 800, marginTop: 4, color: 'var(--text)' }}>
            {stats.total.toLocaleString()}
          </div>
          <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 4 }}>حركة نظامية موثقة بالمحرك</div>
        </div>

        <div className="card" style={{ padding: '14px 18px', borderInlineStart: '4px solid #10b981' }}>
          <div style={{ fontSize: 12, color: 'var(--muted)' }}>انتقالات اليوم</div>
          <div style={{ fontSize: 22, fontWeight: 800, marginTop: 4, color: '#059669' }}>
            {stats.today.toLocaleString()}
          </div>
          <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 4 }}>حركات خلال الـ 24 ساعة الماضية</div>
        </div>

        <div className="card" style={{ padding: '14px 18px', borderInlineStart: '4px solid #f59e0b' }}>
          <div style={{ fontSize: 12, color: 'var(--muted)' }}>انتقالات مسببة نظامياً</div>
          <div style={{ fontSize: 22, fontWeight: 800, marginTop: 4, color: '#d97706' }}>
            {stats.withReason.toLocaleString()}
          </div>
          <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 4 }}>تحمل سبباً ومبرراً مدوناً</div>
        </div>

        <div className="card" style={{ padding: '14px 18px', borderInlineStart: '4px solid #6366f1' }}>
          <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 6 }}>توزيع النشاط حسب النطاق</div>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
            <span style={{ fontSize: 11, background: '#eff6ff', color: '#1e40af', padding: '2px 6px', borderRadius: 4 }}>
              تذاكر: {stats.byEntity.tickets}
            </span>
            <span style={{ fontSize: 11, background: '#fffbeb', color: '#92400e', padding: '2px 6px', borderRadius: 4 }}>
              استشارات: {stats.byEntity.consults}
            </span>
            <span style={{ fontSize: 11, background: '#ecfdf5', color: '#065f46', padding: '2px 6px', borderRadius: 4 }}>
              قضايا: {stats.byEntity.cases}
            </span>
            <span style={{ fontSize: 11, background: '#f5f3ff', color: '#5b21b6', padding: '2px 6px', borderRadius: 4 }}>
              تنفيذ: {stats.byEntity.executions}
            </span>
            <span style={{ fontSize: 11, background: '#f0fdfa', color: '#115e59', padding: '2px 6px', borderRadius: 4 }}>
              فواتير: {stats.byEntity.invoices}
            </span>
          </div>
        </div>
      </div>

      {/* ── 3. شريط الفلاتر والبحث المتقدم ── */}
      <div className="card" style={{ padding: 14 }}>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            applyFilters();
          }}
          style={{ display: 'flex', flexDirection: 'column', gap: 10 }}
        >
          <div className="filter-selects" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}>
            {/* البحث النصي */}
            <div>
              <label style={{ fontSize: 11.5, fontWeight: 700, display: 'block', marginBottom: 4 }}>البحث بالمرجع أو السبب</label>
              <input
                type="text"
                className="input sm"
                placeholder="رقم المرجع، السبب، الفاعل..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                style={{ width: '100%' }}
              />
            </div>

            {/* فلتر نوع الكيان */}
            <div>
              <label style={{ fontSize: 11.5, fontWeight: 700, display: 'block', marginBottom: 4 }}>نطاق الكيان</label>
              <select
                className="input sm"
                value={entityType}
                onChange={(e) => {
                  setEntityType(e.target.value);
                  applyFilters({ entity_type: e.target.value });
                }}
                style={{ width: '100%' }}
              >
                <option value="all">كل النطاقات</option>
                {availableEntities.map((ent) => (
                  <option key={ent.key} value={ent.key}>
                    {ent.label}
                  </option>
                ))}
              </select>
            </div>

            {/* فلتر نوع الانتقال */}
            <div>
              <label style={{ fontSize: 11.5, fontWeight: 700, display: 'block', marginBottom: 4 }}>العملية النظامية</label>
              <select
                className="input sm"
                value={transitionName}
                onChange={(e) => {
                  setTransitionName(e.target.value);
                  applyFilters({ transition: e.target.value });
                }}
                style={{ width: '100%' }}
              >
                <option value="all">كل العمليات</option>
                {availableTransitions.map((tr) => (
                  <option key={tr.key} value={tr.key}>
                    {tr.label}
                  </option>
                ))}
              </select>
            </div>

            {/* فلتر الفاعل */}
            <div>
              <label style={{ fontSize: 11.5, fontWeight: 700, display: 'block', marginBottom: 4 }}>الفاعل المسؤول</label>
              <select
                className="input sm"
                value={actorId}
                onChange={(e) => {
                  setActorId(e.target.value);
                  applyFilters({ actor_id: e.target.value });
                }}
                style={{ width: '100%' }}
              >
                <option value="all">كل المستخدمين والنظام</option>
                <option value="system">النظام الآلي / الذكاء الاصطناعي</option>
                {availableActors.map((u) => (
                  <option key={u.id} value={String(u.id)}>
                    {u.name} ({u.roleLabel})
                  </option>
                ))}
              </select>
            </div>

            {/* من تاريخ */}
            <div>
              <label style={{ fontSize: 11.5, fontWeight: 700, display: 'block', marginBottom: 4 }}>من تاريخ</label>
              <input
                type="date"
                className="input sm"
                value={fromDate}
                onChange={(e) => {
                  setFromDate(e.target.value);
                  applyFilters({ from_date: e.target.value });
                }}
                style={{ width: '100%' }}
              />
            </div>

            {/* إلى تاريخ */}
            <div>
              <label style={{ fontSize: 11.5, fontWeight: 700, display: 'block', marginBottom: 4 }}>إلى تاريخ</label>
              <input
                type="date"
                className="input sm"
                value={toDate}
                onChange={(e) => {
                  setToDate(e.target.value);
                  applyFilters({ to_date: e.target.value });
                }}
                style={{ width: '100%' }}
              />
            </div>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: 8, marginTop: 4 }}>
            {hasActiveFilters && (
              <button type="button" className="btn sm soft" onClick={handleReset}>
                إلغاء التصفية
              </button>
            )}
            <button type="submit" className="btn sm">
              <Icon name="search" /> تطبيق الفلاتر
            </button>
          </div>
        </form>
      </div>

      {/* ── 4. جدول الحركات والانتقالات ── */}
      <div className="card" style={{ overflow: 'hidden' }}>
        <div
          className="card-h"
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: '12px 16px',
            background: 'var(--paper-2, #f8fafc)',
            borderBottom: '1px solid var(--line, #e2e8f0)',
            flexWrap: 'wrap',
            gap: 10,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon name="list" />
            <h3 style={{ fontSize: 14, fontWeight: 700, margin: 0 }}>
              سجل الحركات (عرض {rows.length} من أصل {meta.total})
            </h3>
          </div>
          <div style={{ fontSize: 11.5, color: 'var(--muted)' }}>
            يمكن سحب الجدول أفقياً ↔ على الشاشات الصغيرة لرؤية كافة التفاصيل
          </div>
        </div>

        <div className="card-b t-wrap" style={{ padding: 0 }}>
          <table className="tbl" style={{ minWidth: 980, margin: 0 }}>
            <thead>
              <tr>
                <th className="nowrap" style={{ minWidth: 110 }}>التوقيت</th>
                <th className="nowrap" style={{ minWidth: 140 }}>الملف والمرجع</th>
                <th style={{ minWidth: 160, maxWidth: 220 }}>الإجراء المنفذ</th>
                <th className="nowrap" style={{ minWidth: 200 }}>انتقال الحالة</th>
                <th className="nowrap" style={{ minWidth: 130 }}>الفاعل المسؤول</th>
                <th style={{ minWidth: 200, maxWidth: 300 }}>المبرر / التسبيب</th>
                <th className="nowrap" style={{ minWidth: 90, textAlign: 'center' }}>التفاصيل</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={7} style={{ textAlign: 'center', padding: '40px 20px', color: 'var(--muted)' }}>
                    <div style={{ fontSize: 24, marginBottom: 8 }}>🔍</div>
                    <b>لا توجد حركات مسجلة تطابق محددات البحث الحالية</b>
                  </td>
                </tr>
              ) : (
                rows.map((row) => (
                  <tr
                    key={row.id}
                    className="click"
                    style={{
                      transition: 'background 0.15s ease',
                    }}
                    onClick={() => setActiveItem(row)}
                  >
                    {/* التوقيت */}
                    <td className="nowrap" style={{ minWidth: 110 }}>
                      <div style={{ fontWeight: 600 }}>{row.since}</div>
                      <div style={{ fontSize: 11, color: 'var(--muted)' }}>{row.createdAt}</div>
                    </td>

                    {/* المرجع ورابط الكيان */}
                    <td className="nowrap" style={{ minWidth: 140 }} onClick={(e) => e.stopPropagation()}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <Badge text={row.entityLabel} tone={row.entityTone} />
                        {row.url ? (
                          <Link
                            href={row.url}
                            style={{
                              fontWeight: 700,
                              color: 'var(--primary, #0e5c9c)',
                              textDecoration: 'none',
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: 3,
                            }}
                          >
                            {row.entityRef}
                            <span style={{ fontSize: 11 }}>↗</span>
                          </Link>
                        ) : (
                          <b style={{ color: 'var(--text)' }}>{row.entityRef}</b>
                        )}
                      </div>
                    </td>

                    {/* الإجراء المنفذ */}
                    <td style={{ minWidth: 160, maxWidth: 240, overflowWrap: 'break-word', wordBreak: 'normal' }}>
                      <div
                        style={{
                          fontWeight: 700,
                          color: 'var(--ink)',
                          fontSize: 13,
                          lineHeight: 1.4,
                        }}
                      >
                        {row.transitionLabel}
                      </div>
                    </td>

                    {/* انتقال الحالة */}
                    <td className="nowrap" style={{ minWidth: 200 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <Badge text={row.fromState} tone={row.fromTone} />
                        <span style={{ color: 'var(--muted)', fontSize: 13, fontWeight: 700 }}>←</span>
                        <Badge text={row.toState} tone={row.toTone} />
                      </div>
                    </td>

                    {/* الفاعل */}
                    <td className="nowrap" style={{ minWidth: 130 }}>
                      <div style={{ fontWeight: 600 }} title={row.actor.name}>
                        {truncateWords(row.actor.name, 3)}
                      </div>
                      <div style={{ fontSize: 11, color: 'var(--muted)' }}>
                        {row.actor.roleLabel}
                      </div>
                    </td>

                    {/* المبرر والتسبيب */}
                    <td style={{ minWidth: 200, maxWidth: 300 }}>
                      {row.reason ? (
                        <div
                          title={`${row.reason} (انقر لعرض النص كاملاً)`}
                          style={{
                            fontSize: 12,
                            color: '#78350f',
                            background: '#fffbeb',
                            padding: '5px 9px',
                            borderRadius: 6,
                            border: '1px solid #fde68a',
                            lineHeight: 1.45,
                            overflowWrap: 'break-word',
                            wordBreak: 'normal',
                            display: 'inline-block',
                            maxWidth: '100%',
                            cursor: 'pointer',
                            transition: 'all 0.15s ease',
                          }}
                          onMouseEnter={(e) => (e.currentTarget.style.background = '#fef3c7')}
                          onMouseLeave={(e) => (e.currentTarget.style.background = '#fffbeb')}
                        >
                          <div style={{ display: 'flex', alignItems: 'flex-start', gap: 5 }}>
                            <span style={{ flex: 1 }}>{truncateWords(row.reason, 8)}</span>
                            {row.reason.trim().split(/\s+/).length > 8 && (
                              <span style={{ fontSize: 10, color: '#b45309', fontWeight: 700, flexShrink: 0 }}>
                                المزيد ↗
                              </span>
                            )}
                          </div>
                        </div>
                      ) : (
                        <span style={{ color: 'var(--muted)', fontSize: 11 }}>—</span>
                      )}
                    </td>

                    {/* زر التفاصيل */}
                    <td className="nowrap" style={{ minWidth: 90, textAlign: 'center' }} onClick={(e) => e.stopPropagation()}>
                      <button
                        type="button"
                        className="btn sm soft"
                        style={{ padding: '3px 10px', fontSize: 11.5 }}
                        onClick={() => setActiveItem(row)}
                      >
                        <Icon name="eye" /> عرض
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>


        {meta.last_page > 1 && (
          <div style={{ padding: '12px 16px', borderTop: '1px solid var(--line, #e2e8f0)' }}>
            <Pagination meta={meta} />
          </div>
        )}
      </div>


      {/* ── 5. الدرج الجانبي لفحص تفاصيل وحمولة الانتقال (Slide-over Payload Inspector) ── */}
      {activeItem &&
        typeof document !== 'undefined' &&
        createPortal(
          <div
            style={{
              position: 'fixed',
              inset: 0,
              zIndex: 9999,
              background: 'rgba(15, 23, 42, 0.5)',
              backdropFilter: 'blur(3px)',
              display: 'flex',
              justifyContent: 'flex-end',
            }}
            onClick={() => setActiveItem(null)}
          >
            <div
              style={{
                width: '100%',
                maxWidth: 520,
                background: 'var(--paper, #ffffff)',
                height: '100%',
                boxShadow: '-4px 0 25px rgba(0, 0, 0, 0.15)',
                display: 'flex',
                flexDirection: 'column',
              }}
              onClick={(e) => e.stopPropagation()}
            >
              {/* ترويسة الدرج */}
              <div
                style={{
                  padding: '16px 20px',
                  borderBottom: '1px solid var(--line, #e2e8f0)',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  background: 'var(--paper-2, #f8fafc)',
                }}
              >
                <div>
                  <h3 style={{ fontSize: 15, fontWeight: 800, margin: 0 }}>تفاصيل العملية #{activeItem.id}</h3>
                  <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>
                    {activeItem.transitionLabel} · {activeItem.since}
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setActiveItem(null)}
                  style={{
                    background: 'transparent',
                    border: 'none',
                    fontSize: 18,
                    cursor: 'pointer',
                    color: 'var(--muted)',
                  }}
                >
                  ✕
                </button>
              </div>

              {/* جسم الدرج */}
              <div style={{ padding: 20, overflowY: 'auto', flex: 1, display: 'flex', flexDirection: 'column', gap: 16 }}>
                {/* بطاقة معلومات الحركة */}
                <div style={{ padding: 14, borderRadius: 8, background: 'var(--paper-2, #f8fafc)', border: '1px solid var(--line)' }}>
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 10, fontSize: 12 }}>
                    <div>
                      <span style={{ color: 'var(--muted)', display: 'block' }}>النطاق:</span>
                      <b style={{ color: 'var(--text)' }}>{activeItem.entityLabel}</b>
                    </div>
                    <div>
                      <span style={{ color: 'var(--muted)', display: 'block' }}>المرجع:</span>
                      {activeItem.url ? (
                        <Link href={activeItem.url} style={{ fontWeight: 700, color: 'var(--primary)' }}>
                          {activeItem.entityRef} ↗
                        </Link>
                      ) : (
                        <b>{activeItem.entityRef}</b>
                      )}
                    </div>
                    <div>
                      <span style={{ color: 'var(--muted)', display: 'block' }}>الحالة السابقة:</span>
                      <Badge text={activeItem.fromState} tone={activeItem.fromTone} />
                    </div>
                    <div>
                      <span style={{ color: 'var(--muted)', display: 'block' }}>الحالة الجديدة:</span>
                      <Badge text={activeItem.toState} tone={activeItem.toTone} />
                    </div>
                    <div>
                      <span style={{ color: 'var(--muted)', display: 'block' }}>الفاعل المسؤول:</span>
                      <b>{activeItem.actor.name}</b>
                    </div>
                    <div>
                      <span style={{ color: 'var(--muted)', display: 'block' }}>التوقيت:</span>
                      <span>{activeItem.createdAt}</span>
                    </div>
                  </div>

                  {activeItem.reason && (
                    <div style={{ marginTop: 12, paddingTop: 10, borderTop: '1px solid var(--line)' }}>
                      <span style={{ color: 'var(--muted)', fontSize: 11.5, display: 'block', marginBottom: 4, fontWeight: 700 }}>
                        المبرر والتسبيب النظامي الكامل:
                      </span>
                      <div
                        style={{
                          fontSize: 12.5,
                          color: '#78350f',
                          background: '#fffbeb',
                          padding: '10px 12px',
                          borderRadius: 8,
                          border: '1px solid #fde68a',
                          lineHeight: 1.5,
                          overflowWrap: 'break-word',
                          wordBreak: 'normal',
                        }}
                      >
                        {activeItem.reason}
                      </div>
                    </div>
                  )}
                </div>

                {/* استعراض بيانات ومعطيات الإجراء */}
                <div>
                  <b style={{ fontSize: 13, display: 'block', marginBottom: 8, color: 'var(--ink)' }}>
                    بيانات ومعطيات الإجراء:
                  </b>

                  {activeItem.payloadItems && activeItem.payloadItems.length > 0 ? (
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 8 }}>
                      {activeItem.payloadItems.map((item, idx) => (
                        <div
                          key={idx}
                          style={{
                            background: 'var(--paper-2, #f8fafc)',
                            padding: '9px 12px',
                            borderRadius: 7,
                            border: '1px solid var(--line, #e2e8f0)',
                          }}
                        >
                          <div style={{ color: 'var(--muted)', fontSize: 11, fontWeight: 600, marginBottom: 3 }}>
                            {item.label}
                          </div>
                          <div style={{ color: 'var(--ink)', fontSize: 12.5, fontWeight: 700, wordBreak: 'break-word' }}>
                            {item.value}
                          </div>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div style={{ padding: 16, background: 'var(--paper-2)', borderRadius: 8, textAlign: 'center', color: 'var(--muted)', fontSize: 12 }}>
                      لا توجد بيانات إضافية مسجلة مع هذا الإجراء
                    </div>
                  )}

                  {activeItem.payload && (
                    <div style={{ marginTop: 14, paddingTop: 10, borderTop: '1px dashed var(--line, #e2e8f0)', textAlign: 'center' }}>
                      <button
                        type="button"
                        onClick={() => setShowDevLog(!showDevLog)}
                        style={{ fontSize: 11, color: 'var(--muted)', cursor: 'pointer', background: 'none', border: 'none', textDecoration: 'underline' }}
                      >
                        {showDevLog ? 'إخفاء السجل التقني الخام' : 'عرض السجل التقني الخام للمطورين'}
                      </button>
                      {showDevLog && (
                        <div style={{ marginTop: 8, textAlign: 'left', direction: 'ltr' }}>
                          <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 6 }}>
                            <button type="button" className="btn soft sm" onClick={copyPayload}>
                              <Icon name={copied ? 'check' : 'doc'} /> {copied ? 'نُسخ' : 'نسخ السجلّ'}
                            </button>
                          </div>
                          <pre style={{ background: '#0f172a', color: '#38bdf8', padding: 12, borderRadius: 6, fontSize: 11, maxHeight: 200, overflowY: 'auto', margin: 0 }}>
                            {devLogText(activeItem)}
                          </pre>
                        </div>
                      )}
                    </div>
                  )}
                </div>

              </div>

              {/* ذيل الدرج */}
              <div
                style={{
                  padding: '14px 20px',
                  borderTop: '1px solid var(--line, #e2e8f0)',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  background: 'var(--paper-2, #f8fafc)',
                }}
              >
                {activeItem.url ? (
                  <Link href={activeItem.url} className="btn sm">
                    فتح الملف مباشرة ({activeItem.entityRef}) ←
                  </Link>
                ) : (
                  <span />
                )}
                <button type="button" className="btn sm soft" onClick={() => setActiveItem(null)}>
                  إغلاق
                </button>
              </div>
            </div>
          </div>,
          document.body
        )}
    </div>
  );
};

export default AdminJourneyTransitions;
