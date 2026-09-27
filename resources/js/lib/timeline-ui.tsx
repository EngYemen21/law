import { router } from '@inertiajs/react';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import Icon from '@/lib/icons';

// شريط ترشيح وبحث وتصفيح للتبويب الزمني — كل حالة الترشيح في رابط الصفحة.
// لماذا في الرابط: الترشيح خادميّ، فالرابط يصير قابلاً للمشاركة وللرجوع بزرّ المتصفّح،
// ولا تتباعد حالة الواجهة عمّا يعرضه الخادم فعلاً.

export interface TimelineEvent {
  kind: string; kindKey: string; tone: string;
  id: string; title: string;
  day: string | null; time: string | null; where: string | null;
  status: string; statusTone: string; when: string;
  joinLink?: string; cardUrl?: string;
  /** جلسة المحكمة وحدها: المدّة المتوقّعة بالدقائق إن أُدخلت — وإلا لا تُعرض مدّة (TimelineCard::hearing). */
  durationMin?: number | null;
}

export interface TimelineFilters {
  q: string; kind: string; status: string; from: string; to: string; sort: string; per: number;
}

export interface TimelineMeta {
  total: number; page: number; lastPage: number; perPage: number;
  from: number | null; to: number | null;
}

const KIND_LABELS: Record<string, string> = {
  all: 'الكل', appointment: 'المواعيد', consult: 'الاستشارات', hearing: 'الجلسات', meeting: 'الاجتماعات',
};

/** يُرسل الترشيح للخادم — replace كي لا يتضخّم سجلّ المتصفّح بكل ضغطة مفتاح */
const push = (next: Partial<TimelineFilters & { page: number }>, current: TimelineFilters, page = 1) => {
  const merged = { ...current, page, ...next };
  const params: Record<string, string> = {};
  if (merged.q) params.q = merged.q;
  if (merged.kind && merged.kind !== 'all') params.kind = merged.kind;
  if (merged.status) params.status = merged.status;
  if (merged.from) params.from = merged.from;
  if (merged.to) params.to = merged.to;
  if (merged.sort && merged.sort !== 'asc') params.sort = merged.sort;
  if (merged.per && merged.per !== 12) params.per = String(merged.per);
  if (merged.page && merged.page > 1) params.page = String(merged.page);

  router.get(window.location.pathname, params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['events', 'meta', 'counts', 'filters'],
  });
};

/** يوم بصيغة YYYY-MM-DD بالتوقيت المحلّي — toISOString يزيح اليوم بفارق المنطقة */
const ymd = (d: Date): string => {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};

/** اختصارات المدى — تغني عن فتح منتقي التاريخ للحالات الشائعة */
const PRESETS: { label: string; range: () => [string, string] }[] = [
  { label: 'اليوم', range: () => { const t = ymd(new Date()); return [t, t]; } },
  {
    label: 'هذا الأسبوع',
    range: () => {
      const now = new Date();
      const start = new Date(now); start.setDate(now.getDate() - now.getDay()); // الأحد
      const end = new Date(start); end.setDate(start.getDate() + 6);
      return [ymd(start), ymd(end)];
    },
  },
  {
    label: 'هذا الشهر',
    range: () => {
      const n = new Date();
      return [ymd(new Date(n.getFullYear(), n.getMonth(), 1)), ymd(new Date(n.getFullYear(), n.getMonth() + 1, 0))];
    },
  },
  {
    label: 'القادم',
    range: () => [ymd(new Date()), ''],
  },
];

export const TimelineToolbar: React.FC<{
  filters: TimelineFilters;
  counts: Record<string, number>;
  statuses: string[];
}> = ({ filters, counts, statuses }) => {
  const [q, setQ] = useState(filters.q);
  const first = useRef(true);

  // تأخير البحث: طلب لكل ضغطة مفتاح يُغرق الخادم ويجعل النتائج تتقافز أمام المستخدم
  useEffect(() => {
    if (first.current) { first.current = false; return; }
    const t = setTimeout(() => { if (q !== filters.q) push({ q }, filters); }, 350);
    return () => clearTimeout(t);
  }, [q]); // eslint-disable-line react-hooks/exhaustive-deps

  const active = Boolean(filters.q || filters.status || filters.from || filters.to || filters.kind !== 'all');

  return (
    <div className="card" style={{ marginBottom: 12 }}>
      <div className="card-b tl-bar" style={{ padding: 14 }}>

        {/* الصفّ الأول: البحث + المدى الزمني */}
        <div className="tl-line">
          <div className="tl-search">
            <span className="tl-ic"><Icon name="search" /></span>
            <input
              className="input"
              type="search"
              value={q}
              placeholder="ابحث بالعنوان أو الرقم المرجعي…"
              onChange={(ev) => setQ(ev.target.value)}
              aria-label="بحث في الأحداث"
            />
          </div>

          <div className="tl-range" role="group" aria-label="المدى الزمني">
            <span className="tl-lbl"><Icon name="cal" /> من</span>
            <input type="date" value={filters.from} aria-label="من تاريخ"
              onChange={(ev) => push({ from: ev.target.value }, filters)} />
            <span className="tl-sep" />
            <span className="tl-lbl">إلى</span>
            <input type="date" value={filters.to} aria-label="إلى تاريخ"
              onChange={(ev) => push({ to: ev.target.value }, filters)} />
          </div>
        </div>

        {/* اختصارات المدى */}
        <div className="tl-line" style={{ gap: 6 }}>
          {PRESETS.map((p) => {
            const [from, to] = p.range();
            const on = filters.from === from && filters.to === to;
            return (
              <button key={p.label} type="button" className={on ? 'btn pri sm' : 'btn soft sm'}
                onClick={() => push({ from, to }, filters)}>{p.label}</button>
            );
          })}
          {active && (
            <button className="btn soft sm" type="button" style={{ marginInlineStart: 'auto' }}
              onClick={() => { setQ(''); push({ q: '', kind: 'all', status: '', from: '', to: '' }, filters); }}>
              <Icon name="close" /> مسح الترشيح
            </button>
          )}
        </div>

        {/* الصفّ الجامع: الصنف · الحالة · الترتيب */}
        <div className="tl-controls">
          <div className="tl-kinds" role="group" aria-label="الصنف">
            {Object.entries(KIND_LABELS).map(([key, label]) => (
              <button
                key={key}
                type="button"
                className="tl-kind"
                aria-pressed={filters.kind === key}
                onClick={() => push({ kind: key }, filters)}
              >
                {label}
                {typeof counts[key] === 'number' && <span className="tl-n">{counts[key]}</span>}
              </button>
            ))}
          </div>

          <div className="tl-picks">
            <select value={filters.status} aria-label="الحالة"
              onChange={(ev) => push({ status: ev.target.value }, filters)}>
              <option value="">كل الحالات</option>
              {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>

            <select value={filters.sort} aria-label="الترتيب"
              onChange={(ev) => push({ sort: ev.target.value }, filters)}>
              <option value="asc">الأقرب إليّ أولاً</option>
              <option value="desc">الأبعد أولاً</option>
            </select>

            <select value={String(filters.per)} aria-label="عدد الصفوف"
              onChange={(ev) => push({ per: Number(ev.target.value) }, filters)}>
              <option value="12">12 صفاً</option>
              <option value="24">24 صفاً</option>
              <option value="48">48 صفاً</option>
            </select>
          </div>
        </div>
      </div>
    </div>
  );
};

export const TimelinePager: React.FC<{ meta: TimelineMeta; filters: TimelineFilters }> = ({ meta, filters }) => {
  const pages = useMemo(() => {
    const out: number[] = [];
    for (let p = Math.max(1, meta.page - 2); p <= Math.min(meta.lastPage, meta.page + 2); p++) out.push(p);
    return out;
  }, [meta.page, meta.lastPage]);

  if (meta.lastPage <= 1) return null;

  return (
    <div style={{ display: 'flex', gap: 6, alignItems: 'center', justifyContent: 'center', flexWrap: 'wrap', padding: '12px 0' }}>
      <button className="btn soft sm" type="button" disabled={meta.page <= 1}
        onClick={() => push({}, filters, meta.page - 1)}>السابق</button>

      {pages[0] > 1 && <span style={{ opacity: 0.5 }}>…</span>}
      {pages.map((p) => (
        <button
          key={p}
          type="button"
          className={p === meta.page ? 'btn pri sm' : 'btn soft sm'}
          onClick={() => push({}, filters, p)}
          aria-current={p === meta.page ? 'page' : undefined}
        >{p}</button>
      ))}
      {pages[pages.length - 1] < meta.lastPage && <span style={{ opacity: 0.5 }}>…</span>}

      <button className="btn soft sm" type="button" disabled={meta.page >= meta.lastPage}
        onClick={() => push({}, filters, meta.page + 1)}>التالي</button>

      <span style={{ marginInlineStart: 10, fontSize: 12, color: 'var(--muted)' }}>
        {meta.from}–{meta.to} من {meta.total}
      </span>
    </div>
  );
};
