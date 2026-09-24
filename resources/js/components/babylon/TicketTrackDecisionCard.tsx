import { Link, router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { CLOSURE_REASONS } from '@/components/babylon/CloseTicketModal';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';

export interface TrackGovernanceData {
  aiSuggestedTrack?: string | null;
  aiSuggestedReason?: string | null;
  proposedTrack?: string | null;
  proposedTrackReason?: string | null;
  proposedBy?: string | null;
  proposedAt?: string | null;
  approvedTrack?: string | null;
  approvedTrackReason?: string | null;
  approvedBy?: string | null;
  approvedTrackAt?: string | null;
}

export interface TicketTrackProps {
  ticketNo: string;
  status: string;
  role: 'lawyer' | 'employee' | 'admin';
  base?: string;
  governance?: TrackGovernanceData | null;
  isFrozen?: boolean;
  hasCase?: boolean;
  caseNumber?: string | null;
  hasExecution?: boolean;
  executionNumber?: string | null;
  closureReasonCode?: string | null;
  closureNotes?: string | null;
}

const TRACKS = [
  {
    key: 'consultation',
    label: 'طلب استشارة قانونية',
    desc: 'جلسة استشارية متخصصة مع المستشار لتداول الخيارات والبدائل النظامية.',
    icon: 'chat',
    tone: 'b-blue',
    color: '#2563eb',
    bg: '#eff6ff',
    border: '#bfdbfe',
  },
  {
    key: 'case',
    label: 'تحويل إلى قضية رسمية',
    desc: 'نزاع قضائي موضوعي أو مطالبة مالية تستوجب قيد صحيفة دعوى والترافع.',
    icon: 'scale',
    tone: 'b-green',
    color: '#059669',
    bg: '#ecfdf5',
    border: '#a7f3d0',
  },
  {
    key: 'execution',
    label: 'تحويل إلى ملف تنفيذ قضائي',
    desc: 'حيازة سند تنفيذي (سند لأمر / شيك / حكم قطعي / عقد إيجار) لمحكمة التنفيذ.',
    icon: 'card',
    tone: 'b-amber',
    color: '#d97706',
    bg: '#fffbeb',
    border: '#fde68a',
  },
  {
    key: 'close',
    label: 'إلغاء / حفظ مسبّب',
    desc: 'خروج عن الاختصاص أو مانع نظامي يقتضي حفظ الملف بقرار مسبب صريح.',
    icon: 'close',
    tone: 'b-grey',
    color: '#4b5563',
    bg: '#f9fafb',
    border: '#e5e7eb',
  },
];

const TicketTrackDecisionCard: React.FC<TicketTrackProps> = ({
  ticketNo,
  status,
  role,
  base = `/${role}`,
  governance,
  isFrozen = false,
  hasCase = false,
  caseNumber = null,
  hasExecution = false,
  executionNumber = null,
  closureReasonCode = null,
  closureNotes = null,
}) => {
  const toast = useToast();
  const isAdmin = role === 'admin';

  const approved = governance?.approvedTrack || (status === 'محولة إلى تنفيذ' ? 'execution' : status === 'محولة إلى قضية' ? (hasExecution ? 'execution' : 'case') : status === 'مغلقة' ? 'close' : null);
  const proposed = governance?.proposedTrack;

  // Selected track state defaults to proposed or AI suggested or consultation
  const initialTrack = approved || proposed || governance?.aiSuggestedTrack || 'consultation';
  const initialReason = approved
    ? (governance?.approvedTrackReason || '')
    : proposed
    ? (governance?.proposedTrackReason || '')
    : (governance?.aiSuggestedReason || '');

  const [selectedTrack, setSelectedTrack] = useState<string>(initialTrack);
  const [reason, setReason] = useState<string>(initialReason);
  const [closureCode, setClosureCode] = useState<string>(closureReasonCode || 'STATUTORY_INADMISSIBILITY');
  const [busy, setBusy] = useState<boolean>(false);
  const [isEditing, setIsEditing] = useState<boolean>(!approved && (!proposed || isAdmin));

  // Apply AI suggestion helper
  const applyAiSuggestion = () => {
    if (governance?.aiSuggestedTrack) {
      setSelectedTrack(governance.aiSuggestedTrack);
    }
    if (governance?.aiSuggestedReason) {
      setReason(governance.aiSuggestedReason);
    }
    toast('✨ تم تطبيق توصية الذكاء الاصطناعي والتسبيب الحقيقي');
  };

  // Submit proposed track (Lawyer, Employee, or Admin)
  const submitProposal = () => {
    if (!reason || reason.trim().length < 10) {
      toast('⚠️ يُرجى تدوين المبرر والسبب الحقيقي (10 أحرف على الأقل)');
      return;
    }

    setBusy(true);
    const endpoint = `${base}/tickets/${encodeURIComponent(ticketNo)}/track/propose`;

    router.post(
      endpoint,
      {
        track: selectedTrack,
        reason: reason.trim(),
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast('✅ تم رفع مقترح المسار للإدارة العليا للاعتماد بنجاح');
          setIsEditing(false);
        },
        onError: (errs) => {
          const msg = Object.values(errs)[0] || 'تعذّر رفع مقترح المسار';
          toast(`⚠️ ${msg}`);
        },
        onFinish: () => setBusy(false),
      }
    );
  };

  // Admin approval
  const submitApproval = (trackToApprove?: string, reasonToApprove?: string) => {
    const finalTrack = trackToApprove || selectedTrack;
    const finalReason = reasonToApprove || reason;

    if (!finalReason || finalReason.trim().length < 10) {
      toast('⚠️ يُرجى تدوين المبرر والسبب الحقيقي للاعتماد (10 أحرف على الأقل)');
      return;
    }

    setBusy(true);
    const endpoint = `/admin/tickets/${encodeURIComponent(ticketNo)}/track/approve`;

    router.post(
      endpoint,
      {
        track: finalTrack,
        reason: finalReason.trim(),
        closure_reason_code: finalTrack === 'close' ? closureCode : undefined,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast('✅ تم اعتماد المسار ونشر القرار والتسبيب للعميل بنجاح');
          setIsEditing(false);
        },
        onError: (errs) => {
          const msg = Object.values(errs)[0] || 'تعذّر اعتماد المسار';
          toast(`⚠️ ${msg}`);
        },
        onFinish: () => setBusy(false),
      }
    );
  };

  const currentTrackMeta = TRACKS.find((t) => t.key === (approved || proposed || selectedTrack));
  const aiTrackMeta = TRACKS.find((t) => t.key === governance?.aiSuggestedTrack);

  return (
    <div className="card" style={{ border: '1px solid var(--line)', borderRadius: 12, overflow: 'hidden' }}>
      {/* ── عنوان البطاقة ── */}
      <div
        className="card-h"
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          padding: '12px 16px',
          background: 'var(--paper-2, #f8fafc)',
          borderBottom: '1px solid var(--line, #e2e8f0)',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <Icon name="scale" />
          <h3 style={{ fontSize: 13.5, fontWeight: 700, margin: 0 }}>تحديد مسار المآل (القرارات الأربعة)</h3>
        </div>
        {approved ? (
          <Badge text={`معتمد: ${currentTrackMeta?.label || approved}`} tone={currentTrackMeta?.tone || 'b-green'} />
        ) : proposed ? (
          <Badge text="بانتظار اعتماد الإدارة للمسار" tone="b-amber" />
        ) : (
          <Badge text="بانتظار التوجيه" tone="b-grey" />
        )}
      </div>

      <div className="card-b" style={{ padding: 14 }}>
        {/* ── 1. مقترح الذكاء الاصطناعي مع السبب الحقيقي ── */}
        {governance?.aiSuggestedTrack && (
          <div
            style={{
              padding: '10px 12px',
              borderRadius: 8,
              background: 'linear-gradient(135deg, rgba(99, 102, 241, 0.08) 0%, rgba(168, 85, 247, 0.08) 100%)',
              border: '1px solid rgba(139, 92, 246, 0.25)',
              marginBottom: 14,
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <span style={{ fontSize: 13 }}>✨</span>
                <b style={{ fontSize: 12, color: '#4f46e5' }}>تحليل الذكاء الاصطناعي المقترح:</b>
                <span
                  style={{
                    fontSize: 11.5,
                    fontWeight: 700,
                    padding: '2px 7px',
                    borderRadius: 6,
                    background: aiTrackMeta?.bg || '#ede9fe',
                    color: aiTrackMeta?.color || '#5b21b6',
                    border: `1px solid ${aiTrackMeta?.border || '#c4b5fd'}`,
                  }}
                >
                  {aiTrackMeta?.label || governance.aiSuggestedTrack}
                </span>
              </div>
              {!isFrozen && !approved && (
                <button
                  type="button"
                  onClick={applyAiSuggestion}
                  style={{
                    background: '#6366f1',
                    color: '#ffffff',
                    border: 'none',
                    borderRadius: 6,
                    padding: '3px 8px',
                    fontSize: 11,
                    fontWeight: 600,
                    cursor: 'pointer',
                  }}
                >
                  تطبيق المقترح
                </button>
              )}
            </div>
            <div style={{ fontSize: 12, color: 'var(--text-soft, #334155)', lineHeight: 1.5, whiteSpace: 'pre-line', wordBreak: 'break-word', overflowWrap: 'break-word' }}>
              <strong>السبب الحقيقي:</strong> {governance.aiSuggestedReason}
            </div>
          </div>
        )}

        {/* ── 2. حالة القرار المعتمد نهائياً ── */}
        {approved && !isEditing ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            <div
              style={{
                padding: '12px 14px',
                borderRadius: 8,
                background: currentTrackMeta?.bg || '#ecfdf5',
                border: `1px solid ${currentTrackMeta?.border || '#a7f3d0'}`,
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
                <Icon name={currentTrackMeta?.icon || 'check'} />
                <b style={{ fontSize: 13, color: currentTrackMeta?.color || '#065f46' }}>
                  {currentTrackMeta?.label}
                </b>
              </div>
              <div style={{ fontSize: 12.5, color: '#1e293b', marginBottom: 8, lineHeight: 1.5, whiteSpace: 'pre-line', wordBreak: 'break-word', overflowWrap: 'break-word' }}>
                <strong>السبب والمبرر المعتمد:</strong> {governance?.approvedTrackReason || closureNotes || '—'}
              </div>
              {governance?.approvedBy && (
                <div style={{ fontSize: 11, color: '#64748b' }}>
                  معتمد من الإدارة العليا: <b>{governance.approvedBy}</b> {governance.approvedTrackAt ? `في ${governance.approvedTrackAt}` : ''}
                </div>
              )}
            </div>

            {/* أزرار الروابط المباشرة للملف الناتج */}
            {approved === 'case' && (caseNumber || hasCase) && (
              <Link
                href={`${base}/cases`}
                className="btn soft sm block"
                style={{ justifyContent: 'center' }}
              >
                <Icon name="scale" /> عرض ملف القضية ({caseNumber || ticketNo})
              </Link>
            )}

            {approved === 'execution' && (executionNumber || hasExecution) && (
              <Link
                // مسار التنفيذ المسجَّل لكلّ دورٍ هو `<base>/execs`؛ وكان هنا اسمٌ أطول لا وجود له في المسارات ⇒ 404
                href={`${base}/execs`}
                className="btn soft sm block"
                style={{ justifyContent: 'center' }}
              >
                <Icon name="card" /> عرض ملف التنفيذ ({executionNumber || ticketNo})
              </Link>
            )}

            {isAdmin && !isFrozen && (
              <button
                type="button"
                className="btn soft sm"
                style={{ marginTop: 4 }}
                onClick={() => setIsEditing(true)}
              >
                <Icon name="doc" /> تعديل القرار أو المسار
              </button>
            )}
          </div>
        ) : proposed && !isEditing ? (
          /* ── 3. حالة وجود مقترح مرفوع للإدارة وبانتظار الاعتماد ── */
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            <div
              style={{
                padding: '12px 14px',
                borderRadius: 8,
                background: '#fffbeb',
                border: '1px solid #fde68a',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
                <b style={{ fontSize: 12.5, color: '#92400e' }}>مقترح مسار مرفوع للإدارة العليا:</b>
                <span
                  style={{
                    fontSize: 11.5,
                    fontWeight: 700,
                    padding: '2px 7px',
                    borderRadius: 6,
                    background: currentTrackMeta?.bg || '#eff6ff',
                    color: currentTrackMeta?.color || '#1e40af',
                    border: `1px solid ${currentTrackMeta?.border || '#bfdbfe'}`,
                  }}
                >
                  {currentTrackMeta?.label}
                </span>
              </div>
              <div style={{ fontSize: 12.5, color: '#451a03', marginBottom: 8, lineHeight: 1.5, whiteSpace: 'pre-line', wordBreak: 'break-word', overflowWrap: 'break-word' }}>
                <strong>تسبيب المقترح:</strong> {governance?.proposedTrackReason || '—'}
              </div>
              {governance?.proposedBy && (
                <div style={{ fontSize: 11, color: '#b45309' }}>
                  مرفوع بواسطة: <b>{governance.proposedBy}</b> {governance.proposedAt ? `في ${governance.proposedAt}` : ''}
                </div>
              )}
            </div>

            {isAdmin ? (
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <button
                  type="button"
                  className="btn sm"
                  style={{ flex: 1, justifyContent: 'center' }}
                  disabled={busy}
                  onClick={() => submitApproval(governance?.proposedTrack || undefined, governance?.proposedTrackReason || undefined)}
                >
                  <Icon name="check" /> اعتماد ونشر للعميل
                </button>
                <button
                  type="button"
                  className="btn soft sm"
                  disabled={busy}
                  onClick={() => setIsEditing(true)}
                >
                  تعديل المسار
                </button>
              </div>
            ) : (
              <div style={{ fontSize: 12, color: 'var(--muted)', textAlign: 'center', padding: '6px 0' }}>
                المقترح قيد دراسة وتوجيه الإدارة العليا. سيُنشر للعميل فور اعتماده.
              </div>
            )}
          </div>
        ) : (
          /* ── 4. نموذج تحديد المسار (الخيارات الأربعة + التسبيب) ── */
          <div>
            <div style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 10 }}>
              {isAdmin
                ? 'اختر أحد المسارات الأربعة المعتمدة لاعتماده مباشرةً وتوجيهه للعميل:'
                : 'اختر التوصية الإجرائية لرفعها إلى الإدارة العليا للاعتماد النهائي:'}
            </div>

            {/* شبكة المسارات الأربعة */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr', gap: 8, marginBottom: 12 }}>
              {TRACKS.map((t) => {
                const isSelected = selectedTrack === t.key;
                return (
                  <div
                    key={t.key}
                    onClick={() => setSelectedTrack(t.key)}
                    style={{
                      padding: '9px 12px',
                      borderRadius: 8,
                      cursor: 'pointer',
                      border: isSelected ? `2px solid ${t.color}` : '1px solid var(--line)',
                      background: isSelected ? t.bg : 'var(--paper)',
                      transition: 'all 0.15s ease',
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 2 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
                        <Icon name={t.icon} />
                        <span style={{ fontSize: 13, fontWeight: 700, color: isSelected ? t.color : 'var(--text)' }}>
                          {t.label}
                        </span>
                      </div>
                      <input
                        type="radio"
                        name="track"
                        checked={isSelected}
                        onChange={() => setSelectedTrack(t.key)}
                        style={{ cursor: 'pointer' }}
                      />
                    </div>
                    <div style={{ fontSize: 11.5, color: 'var(--muted)', paddingInlineStart: 22 }}>
                      {t.desc}
                    </div>
                  </div>
                );
              })}
            </div>

            {/* كود سبب الإغلاق إن اختير الإلغاء */}
            {selectedTrack === 'close' && (
              <div className="field" style={{ marginBottom: 10 }}>
                <label style={{ fontSize: 12, fontWeight: 700 }}>تصنيف سبب الإغلاق</label>
                <select
                  value={closureCode}
                  onChange={(e) => setClosureCode(e.target.value)}
                  style={{ width: '100%', fontSize: 12.5 }}
                >
                  {CLOSURE_REASONS.map((r) => (
                    <option key={r.code} value={r.code}>
                      {r.label}
                    </option>
                  ))}
                </select>
              </div>
            )}

            {/* حقل السبب الحقيقي والمبرر النظامي */}
            <div className="field" style={{ marginBottom: 12 }}>
              <label style={{ fontSize: 12, fontWeight: 700, display: 'flex', justifyContent: 'space-between' }}>
                <span>السبب الحقيقي والمبرر النظامي:</span>
                <span style={{ fontSize: 11, color: reason.trim().length >= 10 ? 'var(--muted)' : 'var(--red, #ef4444)' }}>
                  {reason.trim().length}/10 أحرف كحد أدنى
                </span>
              </label>
              <textarea
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                rows={3}
                placeholder="اذكر المبرر المهني والواقعي لاختيار هذا المسار (يظهر في القرار)..."
                style={{ width: '100%', fontSize: 12.5, lineHeight: 1.4 }}
              />
            </div>

            {/* أزرار الإجراء */}
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              {isAdmin ? (
                <button
                  type="button"
                  className="btn sm"
                  style={{ flex: 1, justifyContent: 'center' }}
                  disabled={busy || reason.trim().length < 10}
                  onClick={() => submitApproval()}
                >
                  <Icon name="check" /> اعتماد المسار ونشره للعميل
                </button>
              ) : (
                <button
                  type="button"
                  className="btn sm"
                  style={{ flex: 1, justifyContent: 'center' }}
                  disabled={busy || reason.trim().length < 10}
                  onClick={submitProposal}
                >
                  <Icon name="send" /> رفع المقترح للإدارة العليا
                </button>
              )}

              {(approved || proposed) && (
                <button
                  type="button"
                  className="btn soft sm"
                  onClick={() => setIsEditing(false)}
                >
                  إلغاء
                </button>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default TicketTrackDecisionCard;
