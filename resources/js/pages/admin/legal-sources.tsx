import { router } from '@inertiajs/react';
import React, { useState } from 'react';
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
  sourceOwner: string;
  sourceUrl: string | null;
  usageScope: string | null;
  status: string;
  reviewedBy: string | null;
  legalReviewAt: string | null;
}

interface Stats { draft: number; approved: number; suspended: number }

const STATUS_TONE: Record<string, string> = {
  'معتمد': 'b-green',
  'مسودة': 'b-amber',
  'موقوف': 'b-grey',
};

const LegalSources: React.FC<{ sources: Source[]; stats: Stats }> = ({ sources, stats }) => {
  const [openId, setOpenId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);

  const act = (id: number, action: 'approve' | 'suspend') => {
    setBusy(true);
    router.post(`/admin/legal-sources/${id}/${action}`, {}, {
      preserveScroll: true,
      onFinish: () => setBusy(false),
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

      {sources.length === 0 ? (
        <div className="card">
          <div className="card-b" style={{ padding: 18 }}>
            لا مصادر بعد. أدخِلها بـ<code>php artisan ai:import-sources &lt;ملف.json&gt;</code>
          </div>
        </div>
      ) : (
        sources.map((s) => (
          <div key={s.id} className="card" style={{ marginBottom: 12 }}>
            <div className="card-h">
              <h3>{s.citation}</h3>
              <span className={`badge ${STATUS_TONE[s.status] ?? 'b-grey'}`}>{s.status}</span>
            </div>
            <div className="card-b" style={{ padding: '14px 16px' }}>
              <div className="cell-row">
                <span>المعرّف: {s.ref}</span>
                <span>المجال: {s.domain}</span>
                <span>الولاية: {s.jurisdiction}</span>
                <span>السريان: {s.effectiveFrom ?? '—'}{s.effectiveTo ? ` حتى ${s.effectiveTo}` : ''}</span>
              </div>
              <div className="cell-row" style={{ marginTop: 4 }}>
                <span>المالك: {s.sourceOwner}</span>
                {s.usageScope && <span>نطاق الاستعمال: {s.usageScope}</span>}
                {s.sourceUrl && <a href={s.sourceUrl} target="_blank" rel="noreferrer">المصدر</a>}
              </div>

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
        ))
      )}
    </div>
  );
};

export default LegalSources;
