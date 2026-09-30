import axios from 'axios';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import Icon from '@/lib/icons';

/** نسخةٌ من `ContentRevisions::history` — الأحدث أوّلاً. */
interface Revision {
  version: number;
  source: string;
  sourceLabel: string;
  actor: string | null;
  role: string | null;
  ip: string | null;
  at: string;
  content: Record<string, unknown>;
}

/** تسميات أعمدة النصوص المراقَبة — ما لا تسمية له يُعرض باسمه. */
const FIELD_LABELS: Record<string, string> = {
  case_summary: 'ملخّص القضية', attachments_summary: 'ملخّص المرفقات', facts: 'الوقائع', key_points: 'النقاط الجوهرية',
  result: 'الرأي القانوني', ai_class: 'التصنيف', ai_summary: 'الملخّص', ai_lawyer: 'المحامي المقترح', summary: 'الملخّص',
  session_notes: 'ملاحظات الجلسة', body: 'النصّ', ai_classification: 'التصنيف الآليّ', ai_study: 'الدراسة',
  ai_missing: 'النواقص', ai_procedures: 'الإجراءات المقترحة', minutes: 'المحضر',
};

const SOURCE_TONE: Record<string, string> = { ai: 'b-cyan', zoom: 'b-blue', template: 'b-grey', human: 'b-green', baseline: 'b-amber' };
const ROLE_LABEL: Record<string, string> = { admin: 'الإدارة', lawyer: 'محامٍ', employee: 'موظّف' };

/** نصٌّ مقروء من قيمة العمود: HTML يُجرَّد من وسومه، والمصفوفات والكائنات تُعرض منسّقة. */
const readable = (v: unknown): string => {
  if (v === null || v === undefined || v === '') {
    return '—';
  }

  if (typeof v === 'string') {
    return v.replace(/<br\s*\/?>/gi, '\n').replace(/<\/(p|div|li)>/gi, '\n').replace(/<[^>]+>/g, '').replace(/\n{3,}/g, '\n\n').trim() || '—';
  }

  return JSON.stringify(v, null, 2);
};

/**
 * **سجلّ النسخ** (طلب المالك 2026-09-29) — زرٌّ بجانب كلّ تحليلٍ أو ملخّص يفتح نسخه كلّها: المصدر (ذكاء
 * اصطناعي · Zoom · قالب · تعديل بشريّ)، ومن كتبها أو أطلقها ودوره وعنوانه ووقتها، والنصّ الكامل مع ما تغيّر
 * عن النسخة السابقة. للطاقم وحده، والخادم يحرس الرؤية (`Staff\RevisionController`).
 */
const RevisionHistoryButton: React.FC<{ kind: string; refKey: string | number; className?: string; label?: string }> = ({ kind, refKey, className = 'btn soft sm', label: buttonLabel = 'سجل النسخ' }) => {
  const [open, setOpen] = useState(false);
  const [label, setLabel] = useState('');
  const [versions, setVersions] = useState<Revision[] | null>(null);
  const [selected, setSelected] = useState(0);
  const [error, setError] = useState<string | null>(null);

  const load = () => {
    setOpen(true);
    setError(null);
    setVersions(null);
    axios.get(`/revisions/${encodeURIComponent(kind)}/${encodeURIComponent(String(refKey))}`)
      .then((r) => {
        setLabel(r.data.label);
        setVersions(r.data.versions);
        setSelected(0);
      })
      .catch(() => setError('تعذّر تحميل سجلّ النسخ'));
  };

  const current = versions?.[selected] ?? null;
  const previous = versions?.[selected + 1] ?? null;

  return (
    <>
      <button type="button" className={className} onClick={load} title="كلّ نسخ هذا النصّ ومن كتبها">
        <Icon name="clock" /> {buttonLabel}
      </button>
      <Modal title={`سجل النسخ — ${label}`} open={open} onClose={() => setOpen(false)} maxWidth={980}>
        {error && <div className="empty"><Icon name="alert" /><b>{error}</b></div>}
        {!error && versions === null && <div className="sub">جارٍ التحميل…</div>}
        {versions !== null && versions.length === 0 && (
          <div className="empty"><Icon name="doc" /><b>لا نسخ مسجّلة بعد</b></div>
        )}
        {versions !== null && versions.length > 0 && current && (
          <div className="rev-grid">
            <div className="rev-list">
              {versions.map((v, i) => (
                <button key={v.version} type="button" className={`rev-item ${i === selected ? 'on' : ''}`} onClick={() => setSelected(i)}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', gap: 6, alignItems: 'center' }}>
                    <b>النسخة {v.version}</b>
                    <Badge text={v.sourceLabel} tone={SOURCE_TONE[v.source] ?? 'b-grey'} />
                  </div>
                  <div className="sub">{v.at}</div>
                  {v.actor && <div className="sub">{v.source === 'human' ? 'كتبها' : 'أطلقها'}: {v.actor}{v.role ? ` (${ROLE_LABEL[v.role] ?? v.role})` : ''}</div>}
                </button>
              ))}
            </div>
            <div className="rev-body">
              <div className="sub" style={{ marginBottom: 8 }}>
                النسخة {current.version} · {current.sourceLabel} · {current.at}
                {current.actor ? ` · ${current.actor}` : ''}
                {current.ip ? ` · IP ${current.ip}` : ''}
              </div>
              {Object.entries(current.content).map(([field, value]) => {
                const changed = previous !== null && JSON.stringify(previous.content[field] ?? null) !== JSON.stringify(value ?? null);

                return (
                  <div key={field} className={`rev-field ${changed ? 'changed' : ''}`}>
                    <div className="rev-field-h">
                      <b>{FIELD_LABELS[field] ?? field}</b>
                      {changed && <Badge text="تغيّر عن النسخة السابقة" tone="b-amber" />}
                    </div>
                    <div className="rev-text">{readable(value)}</div>
                  </div>
                );
              })}
            </div>
          </div>
        )}
      </Modal>
    </>
  );
};

export default RevisionHistoryButton;
