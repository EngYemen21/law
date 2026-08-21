import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';

// شريط تنقّل الصفحات — مبنيّ من أنماط المشروع القائمة (btn soft sm) بلا CSS جديد.
// لا يُرسَم إطلاقاً حين تكون صفحة واحدة، فالشاشات الصغيرة تبقى كما هي حرفياً.

export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Paginated<T> {
  data: T[];
  meta: PageMeta;
}

const Pagination: React.FC<{ meta: PageMeta; only?: string[] }> = ({ meta, only }) => {
  if (!meta || meta.last_page <= 1) return null;

  const go = (page: number) => {
    if (page < 1 || page > meta.last_page || page === meta.current_page) return;
    const url = new URL(window.location.href);
    url.searchParams.set('page', String(page));
    router.get(url.pathname + url.search, {}, { preserveScroll: true, preserveState: true, only });
  };

  const from = (meta.current_page - 1) * meta.per_page + 1;
  const to = Math.min(meta.current_page * meta.per_page, meta.total);

  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', marginTop: 12 }}>
      <span className="sub">{from}–{to} من {meta.total}</span>
      <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
        <button className="btn soft sm" type="button" disabled={meta.current_page <= 1} onClick={() => go(meta.current_page - 1)}>
          <Icon name="reply" /> السابق
        </button>
        <span className="sub">صفحة {meta.current_page} من {meta.last_page}</span>
        <button className="btn soft sm" type="button" disabled={meta.current_page >= meta.last_page} onClick={() => go(meta.current_page + 1)}>
          التالي <Icon name="out" />
        </button>
      </div>
    </div>
  );
};

export default Pagination;
