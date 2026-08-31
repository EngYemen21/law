import { router, usePage } from '@inertiajs/react';
import React, { useState } from 'react';
import { panelBase } from '@/lib/data';
import Icon from '@/lib/icons';

/** مصدر قانونيّ ببياناته الحاكمة — يُعرض نصّه كاملاً كي يعتمد المحامي ما قرأه. */
interface Source {
  id: number;
  ref: string;
  systemName: string;
  articleNo: string | null;
  title: string | null;
  text: string;
  citation: string;
  jurisdiction: string;
  domain: string;
  version: string | null;
  effectiveFrom: string | null;
  effectiveTo: string | null;
  inForce: boolean;
  sourceOwner: string;
  sourceUrl: string | null;
  usageScope: string | null;
  status: string;
  reviewedBy: string | null;
  legalReviewAt: string | null;
}

interface SystemRow {
  name: string;
  total: number;
  draft: number;
  approved: number;
  suspended: number;
  effectiveFrom: string | null;
  inForce: boolean;
}

interface Stats { draft: number; approved: number; suspended: number }
interface Filters { system: string; status: string; q: string }
interface Pagination { page: number; lastPage: number; total: number }

interface Props {
  sources: Source[];
  systems: SystemRow[];
  stats: Stats;
  filters: Filters;
  pagination: Pagination;
}

const STATUS_TONE: Record<string, string> = {
  'معتمد': 'b-green',
  'مسودة': 'b-amber',
  'موقوف': 'b-grey',
};

const LegalSources: React.FC<Props> = ({ sources, systems, stats, filters, pagination }) => {
  // بادئة لوحة الدور: الشاشة مشتركة بين الإدارة والمحامي، وتثبيت `/admin` في
  // الإرسال يجعلها تُعرض للمحامي ثم تُمنع عند الحفظ بـ403 — شاشةٌ لا تعمل.
  const base = panelBase((usePage().url as string).split('?')[0]);
  const [openId, setOpenId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [q, setQ] = useState(filters.q);
  const [confirmSystem, setConfirmSystem] = useState<string | null>(null);
  const [typed, setTyped] = useState('');

  const act = (id: number, action: 'approve' | 'suspend') => {
    setBusy(true);
    router.post(`${base}/legal-sources/${id}/${action}`, {}, {
      preserveScroll: true,
      onFinish: () => setBusy(false),
    });
  };

  const browse = (next: Partial<Filters & { page: number }>) => {
    router.get(`${base}/legal-sources`, { ...filters, q, ...next }, { preserveState: true, preserveScroll: true });
  };

  const approveSystem = (system: string) => {
    setBusy(true);
    router.post(`${base}/legal-sources/approve-system`, { system, confirm: typed }, {
      preserveScroll: true,
      onFinish: () => { setBusy(false); setConfirmSystem(null); setTyped(''); },
    });
  };

  return (
    <div className="admin-legal-sources-root" style={{ paddingBottom: 60, width: '100%' }}>
      <div className="greet">
        <h1>المصادر القانونيّة المعتمدة</h1>
        <p>
          لا يُستشهد بمصدر إلا بعد اعتماد محامٍ. والمعتمَد وحده يُمرَّر للنموذج، ويُطابَق
          معرّفه خادمياً — فادّعاءٌ بمادّة خارج هذه القائمة يسقط تلقائياً.
        </p>
      </div>

      <div className="kpi-row" style={{ marginBottom: 14 }}>
        <div className="kpi"><span>بانتظار الاعتماد</span><b>{stats.draft}</b></div>
        <div className="kpi"><span>معتمدة</span><b>{stats.approved}</b></div>
        <div className="kpi"><span>موقوفة</span><b>{stats.suspended}</b></div>
      </div>

      {stats.approved === 0 && (
        <div className="card" style={{ marginBottom: 14, borderInlineStart: '3px solid var(--amber)' }}>
          <div className="card-b" style={{ padding: '12px 16px' }}>
            <div className="mtg-pend">
              <Icon name="info" /> لا مصدر معتمد بعد — المسودات القانونيّة تُنتَج بلا استشهاد،
              ويُسجَّل ذلك في بياناتها صراحةً.
            </div>
          </div>
        </div>
      )}

      {/* ── الأنظمة: المحامي يقرّر على مستوى النظام قبل أن يفتح مادّة ── */}
      {systems.length > 0 && (
        <div className="card" style={{ marginBottom: 12 }}>
          <div className="card-h"><h3>الأنظمة في القاعدة</h3></div>
          <div className="card-b" style={{ padding: '14px 16px' }}>
            {systems.map((sys) => (
              <div key={sys.name} style={{ borderBottom: '1px solid var(--line)', padding: '8px 0' }}>
                <div className="cell-row">
                  <span>
                    <b>{sys.name}</b>
                    {!sys.inForce && (
                      <span className="badge b-amber" style={{ marginInlineStart: 6 }}>
                        لم يبدأ سريانه — {sys.effectiveFrom}
                      </span>
                    )}
                  </span>
                  <span>
                    {sys.total} مادّة · معتمدة {sys.approved} · مسودة {sys.draft}
                    {sys.suspended > 0 && ` · موقوفة ${sys.suspended}`}
                  </span>
                </div>

                <div style={{ marginTop: 6, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                  <button className="btn soft sm" type="button" onClick={() => browse({ system: sys.name, page: 1 })}>
                    استعراض موادّه
                  </button>
                  {sys.draft > 0 && confirmSystem !== sys.name && (
                    <button className="btn soft sm" type="button" onClick={() => { setConfirmSystem(sys.name); setTyped(''); }}>
                      اعتماد النظام كاملاً ({sys.draft})
                    </button>
                  )}
                </div>

                {confirmSystem === sys.name && (
                  <div style={{ marginTop: 8, padding: 10, borderInlineStart: '3px solid var(--amber)' }}>
                    <p style={{ fontSize: 12.5, margin: 0 }}>
                      باعتمادك النظام كاملاً تشهد أن نصّ <b>{sys.draft}</b> مادّة المنشور في الجريدة
                      الرسميّة صالحٌ للاستشهاد في مخرجات المكتب. يُسجَّل اسمك وتاريخ مراجعتك على كل
                      مادّة، ويُقيَّد الفعل في سجلّ التدقيق.
                      {!sys.inForce && ' وهذا النظام لم يبدأ سريانه بعد، فلن يُسترجَع في وقائع اليوم حتى لو اعتُمد.'}
                    </p>
                    <div className="field" style={{ maxWidth: 340, marginTop: 8 }}>
                      <label>اكتب اسم النظام حرفياً للتأكيد</label>
                      <input className="input" value={typed} onChange={(e) => setTyped(e.target.value)} placeholder={sys.name} />
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                      <button className="btn sm" type="button" disabled={busy || typed !== sys.name} onClick={() => approveSystem(sys.name)}>
                        <Icon name="check" /> أعتمد النظام كاملاً
                      </button>
                      <button className="btn soft sm" type="button" onClick={() => { setConfirmSystem(null); setTyped(''); }}>
                        تراجع
                      </button>
                    </div>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ── الترشيح ── */}
      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-b" style={{ padding: '12px 16px', display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <div className="field" style={{ margin: 0, minWidth: 220 }}>
            <label>بحث في نصّ المواد</label>
            <input
              className="input"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') browse({ page: 1 }); }}
              placeholder="مثل: التعويض، الحجز، الموطن"
            />
          </div>
          <div className="field" style={{ margin: 0, minWidth: 200 }}>
            <label>النظام</label>
            <select className="input" value={filters.system} onChange={(e) => browse({ system: e.target.value, page: 1 })}>
              <option value="">كل الأنظمة</option>
              {systems.map((s) => <option key={s.name} value={s.name}>{s.name}</option>)}
            </select>
          </div>
          <div className="field" style={{ margin: 0, minWidth: 150 }}>
            <label>الحالة</label>
            <select className="input" value={filters.status} onChange={(e) => browse({ status: e.target.value, page: 1 })}>
              <option value="">الكل</option>
              <option value="مسودة">مسودة</option>
              <option value="معتمد">معتمد</option>
              <option value="موقوف">موقوف</option>
            </select>
          </div>
          <button className="btn sm" type="button" onClick={() => browse({ page: 1 })}>
            <Icon name="search" /> ترشيح
          </button>
        </div>
      </div>

      {sources.length === 0 ? (
        <div className="card">
          <div className="card-b" style={{ padding: 18 }}>
            {pagination.total === 0 && filters.q === '' && filters.system === '' && filters.status === ''
              ? <>لا مصادر بعد. أدخِلها بـ<code>php artisan ai:import-sources &lt;ملف.json&gt;</code></>
              : 'لا مواد مطابقة للترشيح الحاليّ.'}
          </div>
        </div>
      ) : (
        <>
          <p style={{ color: 'var(--muted)', fontSize: 12.5 }}>
            {pagination.total} مادّة مطابقة — صفحة {pagination.page} من {pagination.lastPage}
          </p>

          {sources.map((s) => (
            <div key={s.id} className="card" style={{ marginBottom: 12 }}>
              <div className="card-h">
                <h3>{s.citation}</h3>
                <span className={`badge ${STATUS_TONE[s.status] ?? 'b-grey'}`}>{s.status}</span>
                {!s.inForce && <span className="badge b-amber">لم يبدأ سريانه</span>}
              </div>
              <div className="card-b" style={{ padding: '14px 16px' }}>
                <div className="cell-row">
                  <span>المعرّف: {s.ref}</span>
                  <span>المجال: {s.domain}</span>
                  <span>الولاية: {s.jurisdiction}</span>
                  <span>السريان: {s.effectiveFrom ?? '—'}{s.effectiveTo ? ` حتى ${s.effectiveTo}` : ''}</span>
                </div>
                {s.title && <div className="cell-row" style={{ marginTop: 4 }}><span>الموضع: {s.title}</span></div>}
                <div className="cell-row" style={{ marginTop: 4 }}>
                  <span>المالك: {s.sourceOwner}</span>
                  {s.sourceUrl && <a href={s.sourceUrl} target="_blank" rel="noreferrer">المصدر</a>}
                </div>
                {s.usageScope && (
                  <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>نطاق الاستعمال: {s.usageScope}</p>
                )}

                {s.reviewedBy && (
                  <div className="cell-row" style={{ marginTop: 4 }}>
                    <span>راجعه: {s.reviewedBy}</span>
                    <span>بتاريخ: {s.legalReviewAt ?? '—'}</span>
                  </div>
                )}

                {/* النصّ كاملاً: الاعتماد على ما قُرئ لا على ما أُخبِر عنه */}
                <details open={openId === s.id} style={{ marginTop: 10 }}>
                  <summary onClick={() => setOpenId(openId === s.id ? null : s.id)}>
                    نصّ المادّة
                  </summary>
                  <p style={{ whiteSpace: 'pre-wrap', marginTop: 8, lineHeight: 1.9 }}>{s.text}</p>
                </details>

                <div style={{ marginTop: 12, display: 'flex', gap: 8 }}>
                  {s.status !== 'معتمد' && (
                    <button className="btn sm" disabled={busy} onClick={() => act(s.id, 'approve')} type="button">
                      <Icon name="check" /> اعتماد للاستشهاد
                    </button>
                  )}
                  {s.status === 'معتمد' && (
                    <button className="btn soft sm" disabled={busy} onClick={() => act(s.id, 'suspend')} type="button">
                      <Icon name="info" /> إيقاف الاستشهاد
                    </button>
                  )}
                </div>
              </div>
            </div>
          ))}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'center', marginTop: 12 }}>
            <button className="btn soft sm" type="button" disabled={pagination.page <= 1} onClick={() => browse({ page: pagination.page - 1 })}>
              السابق
            </button>
            <button className="btn soft sm" type="button" disabled={pagination.page >= pagination.lastPage} onClick={() => browse({ page: pagination.page + 1 })}>
              التالي
            </button>
          </div>
        </>
      )}
    </div>
  );
};

export default LegalSources;
