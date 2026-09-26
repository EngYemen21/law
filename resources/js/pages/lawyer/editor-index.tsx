import { Link, router, usePage } from '@inertiajs/react';
import React, { useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { LEGAL_TEMPLATES } from '@/lib/editor-templates';

// ============================================================================
// قائمة المستندات القانونية — محرر الصياغة
// ============================================================================

interface DocCard {
  id: number;
  title: string;
  type: string;
  typeLabel: string;
  status: string;
  statusLabel: string;
  author: string;
  ticketNo: string | null;
  updatedAt: string;
  createdAt: string;
  approved: boolean;
  approvedBy: string | null;
  approvedAt: string | null;
}

interface Props {
  documents: DocCard[];
  types: Record<string, string>;
}

const statusTone = (s: string) => {
  switch (s) {
    case 'approved': return 'b-green';
    case 'review': return 'b-blue';
    case 'archived': return 'b-muted';
    default: return 'b-amber';
  }
};

const typeTone = (t: string) => {
  switch (t) {
    case 'lawsuit': return 'b-red';
    case 'memo': return 'b-blue';
    case 'summary': return 'b-cyan';
    case 'contract': return 'b-green';
    case 'letter': return 'b-amber';
    default: return 'b-muted';
  }
};

const typeIcon = (t: string) => {
  switch (t) {
    case 'lawsuit': return 'scale';
    case 'memo': return 'doc';
    case 'summary': return 'out';
    case 'contract': return 'office';
    case 'letter': return 'reply';
    default: return 'doc';
  }
};

const EditorIndex: React.FC<Props> = ({ documents, types }) => {
  const { url } = usePage();
  const base = (url as string).startsWith('/admin')
    ? '/admin'
    : (url as string).startsWith('/employee')
      ? '/employee'
      : '/lawyer';
  const [filter, setFilter] = useState<string>('all');
  const [search, setSearch] = useState('');
  const [showTemplateModal, setShowTemplateModal] = useState(false);
  const [templateFilter, setTemplateFilter] = useState<string>('all');
  const [templateSearch, setTemplateSearch] = useState('');

  const filtered = documents.filter((d) => {
    if (filter !== 'all' && d.type !== filter) return false;
    if (search && !d.title.includes(search) && !d.typeLabel.includes(search)) return false;
    return true;
  });

  const filteredTemplates = LEGAL_TEMPLATES.filter((t) => {
    if (templateFilter !== 'all' && t.category !== templateFilter) return false;
    if (templateSearch && !t.name.includes(templateSearch) && !t.description.includes(templateSearch)) return false;
    return true;
  });

  return (
    <>
      {/* ── ترويسة الصفحة ── */}
      <div className="hero" style={{ marginBottom: 18 }}>
        <h2>محرر الصياغة القانونية ⚖️</h2>
        <p>
          أنشئ ونسّق وأدِر مستنداتك القانونية — لوائح، مذكرات، ملخصات، عقود، وخطابات رسمية.
          محرر احترافي مدمج بدون الحاجة لتطبيقات خارجية.
        </p>
      </div>

      {/* ── شريط الإجراءات ── */}
      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          gap: 12,
          flexWrap: 'wrap',
          marginBottom: 16,
        }}
      >
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <Link
            href={`${base}/editor/create`}
            className="btn primary"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
          >
            <Icon name="plus" /> مستند فارغ
          </Link>
          <button
            type="button"
            className="btn soft"
            onClick={() => setShowTemplateModal(true)}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
          >
            <span>📚</span> تصفح القوالب الجاهزة (9 قوالب)
          </button>
          <Link
            href={`${base}/editor/create?template=najiz_lawsuit`}
            className="btn soft"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
          >
            <span>⚖️</span> صحيفة دعوى (ناجز)
          </Link>
          <Link
            href={`${base}/editor/create?template=reply_memo`}
            className="btn soft"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
          >
            <span>📝</span> لائحة جوابية
          </Link>
          <Link
            href={`${base}/editor/create?template=appeal_memo`}
            className="btn soft"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
          >
            <span>📜</span> لائحة استئناف
          </Link>
        </div>

        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          {/* فلتر النوع */}
          <select
            className="select sm"
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
            style={{ minWidth: 140 }}
          >
            <option value="all">كل الأنواع</option>
            {Object.entries(types).map(([k, v]) => (
              <option key={k} value={k}>{v}</option>
            ))}
          </select>

          {/* بحث */}
          <input
            type="text"
            className="input sm"
            placeholder="ابحث في المستندات..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            style={{ minWidth: 180 }}
          />
        </div>
      </div>

      {/* ── شبكة المستندات ── */}
      {filtered.length === 0 ? (
        <div
          className="card"
          style={{
            textAlign: 'center',
            padding: '48px 24px',
            color: 'var(--muted)',
          }}
        >
          <div style={{ fontSize: 48, marginBottom: 12, opacity: 0.3 }}>📝</div>
          <p style={{ fontSize: 15, marginBottom: 16 }}>
            {documents.length === 0
              ? 'لم تُنشئ أي مستند بعد. ابدأ بإنشاء أول مستند قانوني!'
              : 'لا توجد مستندات تطابق البحث أو الفلتر المحدد.'}
          </p>
          {documents.length === 0 && (
            <Link
              href={`${base}/editor/create`}
              className="btn primary"
              style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
            >
              <Icon name="plus" /> إنشاء أول مستند
            </Link>
          )}
        </div>
      ) : (
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))',
            gap: 14,
          }}
        >
          {filtered.map((doc) => (
            <Link
              key={doc.id}
              href={`${base}/editor/${doc.id}`}
              className="card"
              style={{
                padding: '16px 18px',
                cursor: 'pointer',
                transition: 'box-shadow .15s, border-color .15s',
                textDecoration: 'none',
                display: 'block',
              }}
            >
              {/* عنوان وأيقونة */}
              <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10, marginBottom: 10 }}>
                <div
                  style={{
                    width: 38,
                    height: 38,
                    borderRadius: 8,
                    background: 'rgba(14, 92, 156, 0.08)',
                    color: 'var(--primary)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: 18,
                    flexShrink: 0,
                  }}
                >
                  <Icon name={typeIcon(doc.type)} />
                </div>
                <div style={{ minWidth: 0, flex: 1 }}>
                  <div
                    style={{
                      fontWeight: 700,
                      fontSize: 14,
                      color: 'var(--text)',
                      marginBottom: 3,
                      overflow: 'hidden',
                      textOverflow: 'ellipsis',
                      whiteSpace: 'nowrap',
                    }}
                  >
                    {doc.title}
                  </div>
                  <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                    <Badge tone={typeTone(doc.type)} text={doc.typeLabel} />
                    <Badge tone={statusTone(doc.status)} text={doc.statusLabel} />
                  </div>
                </div>
              </div>

              {/* تفاصيل */}
              <div style={{ fontSize: 12, color: 'var(--muted)', lineHeight: 1.8 }}>
                {doc.author && <div>المنشئ: {doc.author}</div>}
                {doc.ticketNo && <div>مرتبط بـ: {doc.ticketNo}</div>}
                <div>آخر تعديل: {doc.updatedAt}</div>
                {doc.approved && doc.approvedBy && (
                  <div style={{ color: 'var(--green)' }}>✓ معتمد بواسطة {doc.approvedBy}</div>
                )}
              </div>
            </Link>
          ))}
        </div>
      )}

      {/* ── نافذة معرض القوالب القانونية بالبورتال ── */}
      {showTemplateModal && typeof document !== 'undefined' && createPortal(
        <div className="legal-modal-backdrop" onClick={() => setShowTemplateModal(false)}>
          <div className="legal-modal-card" onClick={(e) => e.stopPropagation()}>
            <div className="legal-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 17, color: 'var(--deep)' }}>📚 مكتبة القوالب القانونية السعودية</h3>
                <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                  اختر قالباً رسمياً معتمداً لإنشاء مستند جديد مباشرة بهيكل قانوني متكامل
                </span>
              </div>
              <button
                type="button"
                onClick={() => setShowTemplateModal(false)}
                style={{ fontSize: 18, color: 'var(--muted)', cursor: 'pointer', padding: 4 }}
              >
                ✕
              </button>
            </div>

            <div style={{ padding: '12px 24px', background: 'var(--paper)', borderBottom: '1px solid var(--line)', display: 'flex', gap: 10, flexWrap: 'wrap' }}>
              <input
                type="text"
                className="input sm"
                placeholder="ابحث في القوالب..."
                value={templateSearch}
                onChange={(e) => setTemplateSearch(e.target.value)}
                style={{ flex: 1, minWidth: 200 }}
              />
              <select
                className="select sm"
                value={templateFilter}
                onChange={(e) => setTemplateFilter(e.target.value)}
                style={{ minWidth: 150 }}
              >
                <option value="all">كل التصنيفات ({LEGAL_TEMPLATES.length})</option>
                <option value="lawsuit">لوائح ودعاوى</option>
                <option value="memo">مذكرات قضائية</option>
                <option value="summary">آراء وملخصات</option>
                <option value="contract">عقود واتفاقيات</option>
                <option value="letter">خطابات ومحاضر</option>
                <option value="free">مستند حر</option>
              </select>
            </div>

            <div className="legal-modal-body">
              <div className="templates-grid">
                {filteredTemplates.map((t) => (
                  <div
                    key={t.id}
                    className="template-card"
                    onClick={() => router.visit(`${base}/editor/create?template=${t.id}`)}
                  >
                    <div>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                        <span style={{ fontSize: 24 }}>⚖️</span>
                        <span className="badge-s b-blue">{t.categoryLabel}</span>
                      </div>
                      <h4 style={{ margin: '0 0 6px', fontSize: 15, color: 'var(--ink)' }}>{t.name}</h4>
                      <p style={{ margin: 0, fontSize: 12.5, color: 'var(--muted)', lineHeight: 1.6 }}>
                        {t.description}
                      </p>
                    </div>

                    <div style={{ marginTop: 14, paddingTop: 10, borderTop: '1px solid var(--line-soft)', display: 'flex', justifyContent: 'flex-end' }}>
                      <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--primary)' }}>
                        إنشاء بهذا القالب ←
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>,
        document.body
      )}
    </>
  );
};

export default EditorIndex;
