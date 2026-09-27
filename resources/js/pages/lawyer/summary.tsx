import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useState, useMemo } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { type SummaryData } from '@/lib/lawyer-data';

// ============================================================================
// صفحة ملخص الملف والرأي القانوني المبدئي — واجهة نخبوية بمستوى المشاريع الكبرى
// تدعم دور المحامي ودور الإدارة العليا مع الحفاظ 100% على كافة الوظائف ومسارات الخادم
// ============================================================================

interface EmpTicket {
  no: string;
  client: string;
  type: string;
  dept: string;
  lawyer: string;
  status: string;
  tone: string;
  priority?: string;
  subject?: string;
}

interface Props {
  ticket: EmpTicket;
  summary: SummaryData;
  base?: string;
  /** حارس `rerunSummary` نفسه (`Ticket::summaryRerunBlocker`) — غير شرط التعديل. */
  canRerunSummary?: boolean;
}

interface FieldMeta {
  key: keyof SummaryData;
  label: string;
  badge: string;
  subtitle: string;
  icon: string;
  placeholder: string;
}

const LEGAL_SECTIONS: FieldMeta[] = [
  {
    key: 'caseSummary',
    label: 'تلخيص القضية وأصل النزاع',
    badge: 'موضوع النزاع',
    subtitle: 'الموجز الموضوعي لأصل النزاع وأطرافه ومحل الخلاف والطلبات المحددة',
    icon: 'doc',
    placeholder: 'أدخل ملخص القضية وموضوع النزاع بالتفصيل...',
  },
  {
    key: 'attachmentsSummary',
    label: 'فحص الأدلة والمرفقات والوثائق',
    badge: 'البينات والمستندات',
    subtitle: 'تفريغ وفحص حجة العقود، الإشعارات، السندات، والمستندات الثبوتية المرفقة بالملف',
    icon: 'folder',
    placeholder: 'أدخل فحص المستندات والعقود والبينات المقدمة...',
  },
  {
    key: 'facts',
    label: 'تجهيز وتكييف الوقائع النظامية',
    badge: 'الوقائع النظامية',
    subtitle: 'التسلسل الزمني والوقائع المادية الثابتة المؤثرة في المركز القانوني للطرفين',
    icon: 'scale',
    placeholder: 'أدخل تسلسل الوقائع النظامية المؤثرة...',
  },
  {
    key: 'keyPoints',
    label: 'النقاط الجوهرية والرأي والتوصيات',
    badge: 'الرأي والتوصيات',
    subtitle: 'الرأي القانوني المبدئي، عناصر القوة والمخاطر، وخطة العمل المقترحة للملف',
    icon: 'sparkles',
    placeholder: 'أدخل النقاط الجوهرية والتوصيات القانونية...',
  },
];

const LawyerSummary: React.FC<Props> = ({ ticket, summary, base = '/lawyer', canRerunSummary = false }) => {
  const toast = useToast();
  const approved = Boolean(summary.approved);
  const isAdmin = base === '/admin';
  // اعتماد المحامي يُقفل عليه؛ والإدارة تعدّل حتى تعتمد
  const canEdit = !approved && (isAdmin || !summary.lawyerApproved);

  const [form, setForm] = useState({
    case_summary: summary.caseSummary || '',
    attachments_summary: summary.attachmentsSummary || '',
    facts: summary.facts || '',
    key_points: summary.keyPoints || '',
  });

  const [copiedKey, setCopiedKey] = useState<string | null>(null);
  const [copiedTicketNo, setCopiedTicketNo] = useState(false);
  const [copiedNajiz, setCopiedNajiz] = useState(false);
  const [activeTab, setActiveTab] = useState<'all' | 'caseSummary' | 'attachmentsSummary' | 'facts' | 'keyPoints'>('all');

  const [najizDraft, setNajizDraft] = useState<string | null>(null);
  const [najizMeta, setNajizMeta] = useState<{ source?: string; label?: string; verdict?: string | null } | null>(null);
  const [busyNajiz, setBusyNajiz] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isApproving, setIsApproving] = useState(false);
  const [isRerunning, setIsRerunning] = useState(false);

  // حساب عدد الكلمات الإجمالي للملخص
  const totalWords = useMemo(() => {
    const text = `${form.case_summary} ${form.attachments_summary} ${form.facts} ${form.key_points}`.trim();
    return text ? text.split(/\s+/).filter(Boolean).length : 0;
  }, [form]);

  const val = (key: keyof SummaryData): string => {
    switch (key) {
      case 'caseSummary': return form.case_summary;
      case 'attachmentsSummary': return form.attachments_summary;
      case 'facts': return form.facts;
      case 'keyPoints': return form.key_points;
      default: return '';
    }
  };

  const setVal = (key: keyof SummaryData, v: string) => {
    setForm((f) => ({
      ...f,
      ...(key === 'caseSummary' ? { case_summary: v }
        : key === 'attachmentsSummary' ? { attachments_summary: v }
        : key === 'facts' ? { facts: v } : { key_points: v }),
    }));
  };

  // نسخ محتوى حقل معين للحافظة
  const copyText = (text: string, id: string) => {
    if (!text) return;
    navigator.clipboard?.writeText(text);
    setCopiedKey(id);
    toast('تم نسخ النص إلى الحافظة بنجاح');
    setTimeout(() => setCopiedKey(null), 2000);
  };

  // نسخ رقم التذكرة
  const copyTicketNumber = () => {
    navigator.clipboard?.writeText(ticket.no);
    setCopiedTicketNo(true);
    toast(`تم نسخ رقم الملف ${ticket.no}`);
    setTimeout(() => setCopiedTicketNo(false), 2000);
  };

  // حفظ التعديلات كمسودة
  const save = () => {
    setIsSaving(true);
    router.post(`${base}/summary/${encodeURIComponent(ticket.no)}`, form, {
      preserveScroll: true,
      onSuccess: () => toast('تم حفظ تعديلات الملخص بنجاح'),
      onError: (e) => toast(e.message || Object.values(e)[0] || 'تعذر حفظ التعديلات حالياً'),
      onFinish: () => setIsSaving(false),
    });
  };

  // إعادة تشغيل التحليل الذكي للملخّص
  const rerun = () => {
    setIsRerunning(true);
    router.post(`${base}/summary/${encodeURIComponent(ticket.no)}/rerun`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('✨ تمت إعادة تشغيل التحليل الذكي للملخّص وتحديث البنود'),
      onError: (e) => toast(e.message || Object.values(e)[0] || 'تعذّر إعادة تشغيل التحليل حالياً'),
      onFinish: () => setIsRerunning(false),
    });
  };

  // توليد مسودة لائحة ناجز عبر الذكاء الاصطناعي
  const generateNajiz = async () => {
    setBusyNajiz(true);
    setNajizMeta(null);
    try {
      const { data } = await axios.post(`${base}/summary/${encodeURIComponent(ticket.no)}/najiz`);
      setNajizDraft(data.draft);
      setNajizMeta({ source: data.source, label: data.sourceLabel, verdict: data.verdict });
      toast('⚖️ تم توليد مسودة صحيفة دعوى مطابقة لمعايير منصة ناجز');
    } catch {
      toast('تعذّر توليد مسودة ناجز حالياً');
    } finally {
      setBusyNajiz(false);
    }
  };

  // تصدير وطباعة تقرير معتمد كـ PDF
  const exportPdf = () => {
    window.open(`${base}/summary/${encodeURIComponent(ticket.no)}/print`, '_blank');
  };

  // اعتماد الملخص والمصادقة عليه
  const approve = () => {
    setIsApproving(true);
    router.post(`${base}/summary/${encodeURIComponent(ticket.no)}/approve`, form, {
      onSuccess: () => toast(isAdmin ? 'تم اعتماد الملخّص رسمياً ونشر الرأي القانوني للعميل' : 'تم اعتماد الملخّص ورفعه للإدارة العليا للمصادقة'),
      onError: (errors) => {
        const firstError = Object.values(errors)[0];
        toast(typeof firstError === 'string' ? firstError : 'لا يمكن اعتماد ملخّص لم يكتمل تحليله الذكي — حرّره يدوياً أولاً.');
      },
      onFinish: () => setIsApproving(false),
    });
  };

  // قالب مبدئي لم يكتمل تحليله الذكي
  const isTemplate = summary.aiGenerated === false;

  // تحديد مرحلة المسار
  const currentStage = approved ? 3 : summary.lawyerApproved ? 2 : 1;

  const statusText = approved
    ? 'معتمد — أُرسل للعميل'
    : summary.lawyerApproved
    ? 'بانتظار اعتماد الإدارة العليا'
    : isTemplate
    ? 'بانتظار التحليل الذكي'
    : 'بانتظار اعتماد المستشار';

  const statusTone = approved
    ? 'b-green'
    : summary.lawyerApproved
    ? 'b-blue'
    : isTemplate
    ? 'b-red'
    : 'b-amber';

  return (
    <div className="summary-page-container">
      {/* ── 1. شريط التنقل العلوي والمسار (Breadcrumb & Top Toolbar) ── */}
      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          flexWrap: 'wrap',
          gap: 10,
          background: 'var(--paper)',
          padding: '10px 16px',
          borderRadius: 10,
          border: '1px solid var(--border)',
        }}
      >
        {/* مسار الصفحة */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5, color: 'var(--muted)' }}>
          <Link href={isAdmin ? '/admin/dashboard' : '/lawyer/dashboard'} style={{ color: 'var(--muted)', display: 'flex', alignItems: 'center', gap: 4 }}>
            <Icon name="home" /> الرئيسية
          </Link>
          <span>/</span>
          <Link href={`${base}/approvals`} style={{ color: 'var(--muted)' }}>
            مركز الاعتمادات والقرارات
          </Link>
          <span>/</span>
          <span style={{ color: 'var(--primary)', fontWeight: 700 }}>ملخص ملف {ticket.no}</span>
        </div>

        {/* أزرار الإجراءات العلوية السريعة */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <Link
            href={`${base}/approvals`}
            className="btn soft sm"
            style={{ height: 32, fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 5 }}
          >
            <Icon name="reply" /> رجوع للمركز
          </Link>

          <button
            type="button"
            className="btn soft sm"
            onClick={exportPdf}
            style={{ height: 32, fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 5 }}
            title={approved ? 'تصدير وثيقة PDF معتمدة' : 'تصدير مسودة PDF'}
          >
            <Icon name="upload" /> {approved ? 'طباعة تقرير معتمد' : 'تصدير المسودة'}
          </button>

          <Link
            href={`${base}/editor/create?ticket=${encodeURIComponent(ticket.no)}&type=summary`}
            className="btn soft sm"
            style={{ height: 32, fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 5 }}
            title="فتح هذا الملخص في محرر الصياغة لتنسيقه وتصميمه كـ Word"
          >
            <Icon name="doc" /> محرر الصياغة
          </Link>

          <button
            type="button"
            className="btn soft sm"
            onClick={generateNajiz}
            disabled={busyNajiz}
            style={{
              height: 32,
              fontSize: 12,
              display: 'inline-flex',
              alignItems: 'center',
              gap: 5,
              background: busyNajiz ? 'rgba(99, 102, 241, 0.05)' : undefined,
              color: '#4338ca',
              borderColor: 'rgba(99, 102, 241, 0.25)',
            }}
          >
            <Icon name="sparkles" /> {busyNajiz ? 'جارٍ صياغة ناجز…' : 'مسودة لائحة ناجز'}
          </button>
        </div>
      </div>

      {/* ── 2. ترويسة بطاقة القضية والملف (Case Dossier Header) ── */}
      <div
        className="card"
        style={{
          background: 'var(--paper)',
          border: '1px solid var(--border)',
          borderRadius: 12,
          padding: '16px 20px',
          boxShadow: 'none',
          marginBottom: 0,
        }}
      >
        <div className="summary-header-flex">
          {/* الجانب الأيمن: بيانات الملف والعناوين */}
          <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, minWidth: 0, flex: '1 1 300px' }}>
            <div
              style={{
                width: 44,
                height: 44,
                borderRadius: 10,
                background: 'rgba(14, 92, 156, 0.1)',
                color: 'var(--primary)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: 22,
                flexShrink: 0,
              }}
            >
              <Icon name="doc" />
            </div>

            <div style={{ minWidth: 0 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <h1 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: 'var(--ink)' }}>
                  ملخص الملف والرأي القانوني المبدئي
                </h1>
                <button
                  type="button"
                  onClick={copyTicketNumber}
                  style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 4,
                    padding: '2px 8px',
                    borderRadius: 6,
                    background: 'var(--paper-2)',
                    border: '1px solid var(--border)',
                    fontSize: 12,
                    fontWeight: 700,
                    color: 'var(--primary)',
                    cursor: 'pointer',
                  }}
                  title="انقر لنسخ رقم الملف"
                >
                  <span>{ticket.no}</span>
                  <Icon name={copiedTicketNo ? 'check' : 'link'} />
                </button>
                <Badge text={statusText} tone={statusTone} />
                {ticket.priority && (
                  <Badge text={`أولوية: ${ticket.priority}`} tone={ticket.priority === 'عالية' || ticket.priority === 'عاجلة' ? 'b-red' : 'b-grey'} />
                )}
              </div>

              <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginTop: 6, flexWrap: 'wrap', fontSize: 12.5, color: 'var(--muted)' }}>
                <span><b>العميل:</b> {ticket.client || '—'}</span>
                <span>•</span>
                <span><b>النوع:</b> {ticket.type}</span>
                <span>•</span>
                <span><b>القسم:</b> {ticket.dept || 'عام'}</span>
                <span>•</span>
                <span><b>المستشار المسند:</b> {ticket.lawyer || '—'}</span>
                <span>•</span>
                <span><b>إجمالي الكلمات:</b> {totalWords} كلمة</span>
              </div>
            </div>
          </div>

          {/* الجانب الأيسر: الإجراء الرئيسي الفوري */}
          <div className="summary-header-actions">
            {canEdit ? (
              <>
                <Link
                  href={`${isAdmin ? '/admin' : '/lawyer'}/editor/create?importType=ticket_summary&id=${summary.id}`}
                  className="btn soft"
                  style={{
                    height: 36,
                    fontSize: 12.5,
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 6,
                    textDecoration: 'none',
                    background: 'rgba(14, 92, 156, 0.08)',
                    borderColor: 'rgba(14, 92, 156, 0.3)',
                    color: '#0e5c9c',
                    fontWeight: 700,
                  }}
                  title="فتح وتنسيق هذا الملخص كـ Word في محرر المستندات القانونية"
                >
                  <Icon name="edit" />
                  <span>تنسيق في المحرر ⚖️</span>
                </Link>

                <button
                  type="button"
                  className="btn soft"
                  onClick={save}
                  // الحقول تصير للقراءة بعد الاعتماد (`readOnly={!canEdit}`) وكان زرّ الحفظ
                  // يبقى فعّالاً فيعطي 422 — وعدٌ كاذب ثانٍ. يُعطَّل مع الحقول لا بعدها.
                  disabled={isSaving || !canEdit}
                  style={{ height: 36, fontSize: 12.5, display: 'inline-flex', alignItems: 'center', gap: 5 }}
                >
                  <Icon name="check" /> {isSaving ? 'جارٍ الحفظ…' : 'حفظ المسودة'}
                </button>

                <button
                  type="button"
                  className="btn"
                  onClick={approve}
                  disabled={isApproving}
                  style={{
                    height: 36,
                    fontSize: 13,
                    fontWeight: 700,
                    padding: '0 16px',
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 6,
                    background: '#16a34a',
                    color: '#fff',
                    border: 'none',
                  }}
                >
                  <Icon name="check" />
                  <span>{isApproving ? 'جارٍ الاعتماد…' : isAdmin ? 'اعتماد نهائي ونشر للعميل' : 'اعتماد ورفع للإدارة العليا'}</span>
                </button>
              </>
            ) : (
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <span
                  style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 6,
                    padding: '6px 12px',
                    borderRadius: 8,
                    background: 'rgba(22, 163, 74, 0.1)',
                    color: '#16a34a',
                    fontSize: 12.5,
                    fontWeight: 700,
                  }}
                >
                  <Icon name="check" /> معتمد رسمياً
                </span>
                <Link
                  href={`${base}/tickets/${encodeURIComponent(ticket.no)}`}
                  className="btn soft sm"
                  style={{ height: 36, fontSize: 12.5, display: 'inline-flex', alignItems: 'center', gap: 5 }}
                >
                  <Icon name="folder" /> فتح التذكرة والمحادثة
                </Link>
              </div>
            )}
          </div>
        </div>

        {/* ── مسار الحوكمة ومراحل المصادقة (Governance Stepper Track) ── */}
        <div
          className="summary-stepper-grid"
          style={{
            marginTop: 16,
            paddingTop: 14,
            borderTop: '1px solid var(--border)',
          }}
        >
          {[
            { step: 1, title: '١. التحليل الذكي التلقائي', desc: 'استخراج الوقائع والمرفقات من التذكرة', done: true, current: currentStage === 1 },
            { step: 2, title: '٢. مراجعة واعتماد المستشار', desc: 'التدقيق الموضوعي وصياغة التوصيات', done: currentStage >= 2, current: currentStage === 1 && !summary.lawyerApproved },
            { step: 3, title: '٣. مصادقة الإدارة العليا', desc: 'المصادقة الإدارية الرسمية لمركز الاعتمادات', done: currentStage >= 3, current: currentStage === 2 },
            { step: 4, title: '٤. النشر لمحادثة العميل', desc: 'إتاحة الرأي القانوني المبدئي للعميل', done: approved, current: currentStage === 3 },
          ].map((s) => (
            <div
              key={s.step}
              style={{
                background: s.done ? 'rgba(22, 163, 74, 0.05)' : s.current ? 'rgba(14, 92, 156, 0.06)' : 'var(--paper-2)',
                border: s.done ? '1px solid rgba(22, 163, 74, 0.3)' : s.current ? '1px solid var(--primary)' : '1px solid var(--border)',
                borderRadius: 8,
                padding: '8px 10px',
                display: 'flex',
                alignItems: 'center',
                gap: 8,
              }}
            >
              <div
                style={{
                  width: 22,
                  height: 22,
                  borderRadius: '50%',
                  background: s.done ? '#16a34a' : s.current ? 'var(--primary)' : 'var(--muted)',
                  color: '#fff',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  fontSize: 11,
                  fontWeight: 700,
                  flexShrink: 0,
                }}
              >
                {s.done ? '✓' : s.step}
              </div>
              <div style={{ minWidth: 0 }}>
                <div style={{ fontSize: 11.5, fontWeight: 700, color: s.done ? '#15803d' : s.current ? 'var(--primary)' : 'var(--text)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {s.title}
                </div>
                <div style={{ fontSize: 10, color: 'var(--muted)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {s.desc}
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* ── 3. تنبيه حالة الذكاء الاصطناعي والإرشاد المهني ── */}
      <div
        style={{
          background: isTemplate ? 'rgba(239, 68, 68, 0.07)' : approved ? 'rgba(22, 163, 74, 0.07)' : 'rgba(14, 92, 156, 0.06)',
          border: `1px solid ${isTemplate ? 'rgba(239, 68, 68, 0.25)' : approved ? 'rgba(22, 163, 74, 0.25)' : 'rgba(14, 92, 156, 0.2)'}`,
          borderRadius: 10,
          padding: '12px 16px',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          flexWrap: 'wrap',
          gap: 10,
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <div
            style={{
              width: 32,
              height: 32,
              borderRadius: 8,
              background: isTemplate ? 'rgba(239, 68, 68, 0.15)' : 'rgba(14, 92, 156, 0.12)',
              color: isTemplate ? '#dc2626' : 'var(--primary)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: 16,
              flexShrink: 0,
            }}
          >
            <Icon name={isTemplate ? 'alert' : 'sparkles'} />
          </div>
          <div>
            <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--ink)' }}>
              {approved ? (
                'تم اعتماد هذا الملخص رسمياً وإرساله لمحادثة العميل'
              ) : !canEdit ? (
                'تم اعتماد هذا الملخص من المستشار ورُفع للإدارة العليا'
              ) : isTemplate ? (
                'تنبيه: لم يكتمل التحليل الذكي التلقائي لهذا الملف'
              ) : (
                'مراجعة الفريق القانوني الذكي (Legal AI Analysis Engine)'
              )}
            </div>
            <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>
              {approved ? (
                'أصبح الرأي القانوني المبدئي نافذاً ومتاحاً للعميل داخل نافذة المحادثة الخاصة بالتذكرة.'
              ) : !canEdit ? (
                'الملخص قيد المراجعة الإدارية العليا في مركز الاعتمادات والقرارات، وسيصل للعميل فور مصادقة الإدارة.'
              ) : isTemplate ? (
                'هذا قالب أولي بحاجة لتحرير وتعبئة يدوية بناءً على مستندات التذكرة قبل الاعتماد (لن يُقبل اعتماد القالب المفرغ).'
              ) : (
                'قام المساعد القانوني بتلخيص الوقائع والمرفقات ووضع التوصيات الأولية من واقع مستندات التذكرة. راجع البنود وعدّلها عند الحاجة قبل الاعتماد.'
              )}
            </div>
          </div>
        </div>

        {canRerunSummary && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <button
              type="button"
              className="btn soft sm"
              onClick={rerun}
              disabled={isRerunning}
              style={{ fontSize: 12, height: 30, display: 'inline-flex', alignItems: 'center', gap: 5 }}
              title="إعادة قراءة مستندات التذكرة وإعادة توليد الملخص"
            >
              <Icon name="sparkles" /> {isRerunning ? 'جارٍ إعادة التحليل…' : 'إعادة التحليل الذكي'}
            </button>
          </div>
        )}
      </div>

      {/* ── 4. مسودة صحيفة دعوى منصة ناجز (إن تم توليدها) ── */}
      {najizDraft !== null && (
        <div
          className="card"
          style={{
            border: '2px solid var(--primary)',
            borderRadius: 12,
            overflow: 'hidden',
            background: 'var(--paper)',
            boxShadow: 'none',
          }}
        >
          <div
            className="card-h"
            style={{
              background: 'rgba(14, 92, 156, 0.05)',
              borderBottom: '1px solid rgba(14, 92, 156, 0.2)',
              padding: '12px 18px',
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              flexWrap: 'wrap',
              gap: 8,
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ fontSize: 18 }}>⚖️</span>
              <div>
                <h3 style={{ margin: 0, fontSize: 14.5, fontWeight: 800, color: 'var(--deep)' }}>
                  مسودة صحيفة دعوى مطابقة لمعايير منصة ناجز القضائية
                </h3>
                <span style={{ fontSize: 11.5, color: 'var(--muted)' }}>
                  صياغة آلية مهنية تتضمن الأطراف، الوقائع، الأسانيد الشرعية والنظامية، والطلبات الختامية
                </span>
              </div>
            </div>

            <div style={{ display: 'flex', gap: 7, alignItems: 'center' }}>
              <button
                type="button"
                className="btn soft sm"
                onClick={() => {
                  navigator.clipboard?.writeText(najizDraft);
                  setCopiedNajiz(true);
                  toast('تم نسخ مسودة صحيفة الدعوى بالكامل');
                  setTimeout(() => setCopiedNajiz(false), 2000);
                }}
                style={{ height: 30, fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 4 }}
              >
                <Icon name={copiedNajiz ? 'check' : 'doc'} /> {copiedNajiz ? 'تم النسخ!' : 'نسخ الصحيفة'}
              </button>
              <button
                type="button"
                className="btn soft sm"
                onClick={() => setNajizDraft(null)}
                style={{ height: 30, fontSize: 12 }}
              >
                إغلاق
              </button>
            </div>
          </div>

          <div className="card-b" style={{ padding: 16 }}>
            {najizMeta && (najizMeta.verdict === 'unsupported' || najizMeta.verdict === 'insufficient_authority') && (
              <div
                style={{
                  marginBottom: 12,
                  padding: '10px 14px',
                  borderRadius: 8,
                  background: '#FFF7E6',
                  border: '1px solid #E8B14C',
                  color: '#7A5200',
                  fontSize: 12.5,
                  lineHeight: 1.8,
                }}
              >
                <b>⚠️ تنبيه السند النظامي:</b> استشهاد هذه الصحيفة يحتاج مراجعة الأرقام المرجعية للأنظمة قبل تقديمها لدى المحكمة لضمان التطابق التام.
              </div>
            )}

            {najizMeta?.source && najizMeta.source !== 'ai_success' && najizMeta.verdict !== 'unsupported' && najizMeta.verdict !== 'insufficient_authority' && (
              <div style={{ marginBottom: 10, fontSize: 12, color: 'var(--muted)' }}>
                مصدر المخرج: <b>{najizMeta.label || najizMeta.source}</b>
              </div>
            )}

            <textarea
              value={najizDraft}
              onChange={(e) => setNajizDraft(e.target.value)}
              style={{
                width: '100%',
                minHeight: 280,
                fontFamily: 'inherit',
                fontSize: 13.5,
                border: '1px solid var(--border)',
                borderRadius: 8,
                padding: 14,
                lineHeight: 1.9,
                background: '#FAFCFE',
                resize: 'vertical',
              }}
            />
          </div>
        </div>
      )}

      {/* ── 5. هيكل العرض الرئيسي بعمودين (Enterprise 2-Column Split Layout) ── */}
      <div className="summary-layout-grid">
        {/* ── العمود الأول: محررات الأقسام القانونية الأربعة (Legal Brief Workstream) ── */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14, minWidth: 0 }}>
          {/* شريط فلترة الأقسام السريعة */}
          <div
            className="summary-section-tabs"
            style={{
              background: 'var(--paper)',
              borderRadius: 8,
              border: '1px solid var(--border)',
            }}
          >
            <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', marginInlineEnd: 4 }}>
              عرض الأقسام:
            </span>
            <button
              type="button"
              onClick={() => setActiveTab('all')}
              style={{
                height: 28,
                padding: '0 10px',
                borderRadius: 6,
                fontSize: 12,
                fontWeight: 600,
                background: activeTab === 'all' ? 'var(--primary)' : 'transparent',
                color: activeTab === 'all' ? '#fff' : 'var(--text)',
                border: 'none',
                cursor: 'pointer',
              }}
            >
              جميع الأقسام الأربعة ({LEGAL_SECTIONS.length})
            </button>
            {LEGAL_SECTIONS.map((sec) => (
              <button
                key={sec.key}
                type="button"
                onClick={() => setActiveTab(sec.key as any)}
                style={{
                  height: 28,
                  padding: '0 9px',
                  borderRadius: 6,
                  fontSize: 12,
                  fontWeight: 600,
                  background: activeTab === sec.key ? 'var(--primary)' : 'transparent',
                  color: activeTab === sec.key ? '#fff' : 'var(--muted)',
                  border: 'none',
                  cursor: 'pointer',
                }}
              >
                {sec.badge}
              </button>
            ))}
          </div>

          {/* كروت الأقسام القانونية الأربعة */}
          {LEGAL_SECTIONS.filter((s) => activeTab === 'all' || activeTab === s.key).map((f) => {
            const content = val(f.key);
            const wordCount = content.trim() ? content.trim().split(/\s+/).length : 0;
            const charCount = content.length;

            return (
              <div
                key={f.key}
                className="card"
                style={{
                  background: 'var(--paper)',
                  border: '1px solid var(--border)',
                  borderRadius: 10,
                  boxShadow: 'none',
                  overflow: 'hidden',
                  marginBottom: 0,
                  transition: 'border-color 0.15s ease',
                }}
              >
                {/* رأس القسم القانوني */}
                <div
                  style={{
                    padding: '11px 16px',
                    background: 'var(--paper-2)',
                    borderBottom: '1px solid var(--border)',
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    flexWrap: 'wrap',
                    gap: 8,
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <div
                      style={{
                        width: 28,
                        height: 28,
                        borderRadius: 6,
                        background: 'rgba(14, 92, 156, 0.1)',
                        color: 'var(--primary)',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        fontSize: 14,
                        flexShrink: 0,
                      }}
                    >
                      <Icon name={f.icon} />
                    </div>
                    <div>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <h3 style={{ margin: 0, fontSize: 13.5, fontWeight: 700, color: 'var(--ink)' }}>
                          {f.label}
                        </h3>
                        <Badge text={f.badge} tone="b-blue" />
                      </div>
                      <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--muted)' }}>
                        {f.subtitle}
                      </p>
                    </div>
                  </div>

                  <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    <span style={{ fontSize: 11, color: 'var(--muted)', background: 'var(--paper)', padding: '2px 8px', borderRadius: 4, border: '1px solid var(--border)' }}>
                      {wordCount} كلمة · {charCount} حرف
                    </span>

                    <button
                      type="button"
                      onClick={() => copyText(content, String(f.key))}
                      style={{
                        height: 26,
                        padding: '0 8px',
                        borderRadius: 5,
                        background: copiedKey === String(f.key) ? 'rgba(22, 163, 74, 0.1)' : 'var(--paper)',
                        color: copiedKey === String(f.key) ? '#16a34a' : 'var(--muted)',
                        border: '1px solid var(--border)',
                        fontSize: 11.5,
                        fontWeight: 600,
                        cursor: 'pointer',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 4,
                      }}
                      title="نسخ محتوى هذا البند"
                    >
                      <Icon name={copiedKey === String(f.key) ? 'check' : 'link'} />
                      <span>{copiedKey === String(f.key) ? 'تم النسخ' : 'نسخ'}</span>
                    </button>
                  </div>
                </div>

                {/* محرر النص الاحترافي */}
                <div style={{ padding: '12px 16px' }}>
                  <textarea
                    value={content}
                    onChange={(e) => setVal(f.key, e.target.value)}
                    readOnly={!canEdit}
                    placeholder={f.placeholder}
                    style={{
                      width: '100%',
                      minHeight: 110,
                      fontSize: 13.2,
                      lineHeight: 1.8,
                      fontFamily: 'inherit',
                      color: 'var(--ink)',
                      background: canEdit ? 'var(--paper)' : 'var(--paper-2)',
                      border: '1px solid var(--border)',
                      borderRadius: 8,
                      padding: 12,
                      outline: 'none',
                      resize: 'vertical',
                      boxShadow: 'none',
                      transition: 'border-color 0.15s ease',
                    }}
                    onFocus={(e) => {
                      if (canEdit) e.currentTarget.style.borderColor = 'var(--primary)';
                    }}
                    onBlur={(e) => {
                      if (canEdit) e.currentTarget.style.borderColor = 'var(--border)';
                    }}
                  />
                  {!canEdit && (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 5, marginTop: 6, fontSize: 11, color: 'var(--muted)' }}>
                      <Icon name="lock" />
                      <span>هذا البند معتمد ومقفل للقراءة فقط بموجب ضوابط حوكمة القرارات القانونية.</span>
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>

        {/* ── العمود الثاني: الشريط الجانبي الذكي (Dossier & Quick Intelligence) ── */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {/* بطاقة معلومات التذكرة والعميل */}
          <div
            className="card"
            style={{
              background: 'var(--paper)',
              border: '1px solid var(--border)',
              borderRadius: 10,
              padding: 16,
              boxShadow: 'none',
              marginBottom: 0,
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12, borderBottom: '1px solid var(--border)', paddingBottom: 8 }}>
              <Icon name="folder" />
              <h4 style={{ margin: 0, fontSize: 13.5, fontWeight: 700, color: 'var(--ink)' }}>
                ملف المعاملة والعميل
              </h4>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 10, fontSize: 12.5 }}>
              <div>
                <div style={{ color: 'var(--muted)', fontSize: 11 }}>رقم التذكرة:</div>
                <div style={{ fontWeight: 700, color: 'var(--primary)', marginTop: 1 }}>{ticket.no}</div>
              </div>

              <div>
                <div style={{ color: 'var(--muted)', fontSize: 11 }}>العميل:</div>
                <div style={{ fontWeight: 700, color: 'var(--ink)', marginTop: 1 }}>{ticket.client || '—'}</div>
              </div>

              <div>
                <div style={{ color: 'var(--muted)', fontSize: 11 }}>المستشار المكلف:</div>
                <div style={{ fontWeight: 600, color: 'var(--ink)', marginTop: 1 }}>{ticket.lawyer || '—'}</div>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 160px), 1fr))', gap: 8 }}>
                <div>
                  <div style={{ color: 'var(--muted)', fontSize: 11 }}>النوع:</div>
                  <div style={{ fontWeight: 600, color: 'var(--ink)', marginTop: 1 }}>{ticket.type}</div>
                </div>
                <div>
                  <div style={{ color: 'var(--muted)', fontSize: 11 }}>القسم:</div>
                  <div style={{ fontWeight: 600, color: 'var(--ink)', marginTop: 1 }}>{ticket.dept || 'عام'}</div>
                </div>
              </div>

              <div>
                <div style={{ color: 'var(--muted)', fontSize: 11 }}>حالة الملف:</div>
                <div style={{ marginTop: 2 }}>
                  <Badge text={ticket.status || statusText} tone={statusTone} />
                </div>
              </div>

              <div style={{ paddingTop: 8, borderTop: '1px solid var(--border)' }}>
                <Link
                  href={`${base}/tickets/${encodeURIComponent(ticket.no)}`}
                  className="btn soft sm"
                  style={{ width: '100%', height: 32, fontSize: 12, justifyContent: 'center' }}
                >
                  <Icon name="folder" /> فتح ملف التذكرة والمحادثة
                </Link>
              </div>
            </div>
          </div>

          {/* بطاقة الأدوات الذكية ومخرجات الملف */}
          <div
            className="card"
            style={{
              background: 'var(--paper)',
              border: '1px solid var(--border)',
              borderRadius: 10,
              padding: 16,
              boxShadow: 'none',
              marginBottom: 0,
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12, borderBottom: '1px solid var(--border)', paddingBottom: 8 }}>
              <Icon name="sparkles" />
              <h4 style={{ margin: 0, fontSize: 13.5, fontWeight: 700, color: 'var(--ink)' }}>
                أدوات الذكاء الاصطناعي والتصدير
              </h4>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <button
                type="button"
                className="btn soft sm"
                onClick={generateNajiz}
                disabled={busyNajiz}
                style={{
                  width: '100%',
                  height: 34,
                  fontSize: 12,
                  justifyContent: 'flex-start',
                  paddingInline: 12,
                  color: '#4338ca',
                  borderColor: 'rgba(99, 102, 241, 0.25)',
                }}
              >
                <Icon name="sparkles" /> {busyNajiz ? 'جارٍ توليد المسودة…' : 'توليد مسودة لائحة ناجز'}
              </button>

              <button
                type="button"
                className="btn soft sm"
                onClick={exportPdf}
                style={{ width: '100%', height: 34, fontSize: 12, justifyContent: 'flex-start', paddingInline: 12 }}
              >
                <Icon name="upload" /> {approved ? 'طباعة تقرير PDF معتمد' : 'تصدير مسودة تقرير PDF'}
              </button>

              {canRerunSummary && (
                <button
                  type="button"
                  className="btn soft sm"
                  onClick={rerun}
                  disabled={isRerunning}
                  style={{ width: '100%', height: 34, fontSize: 12, justifyContent: 'flex-start', paddingInline: 12 }}
                >
                  <Icon name="sparkles" /> {isRerunning ? 'جارٍ التحليل…' : 'إعادة استخراج وتحليل AI'}
                </button>
              )}
            </div>
          </div>

          {/* بطاقة الحوكمة والاعتماد الرسمي */}
          <div
            className="card"
            style={{
              background: 'var(--paper)',
              border: '1px solid var(--border)',
              borderRadius: 10,
              padding: 16,
              boxShadow: 'none',
              marginBottom: 0,
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12, borderBottom: '1px solid var(--border)', paddingBottom: 8 }}>
              <Icon name="check" />
              <h4 style={{ margin: 0, fontSize: 13.5, fontWeight: 700, color: 'var(--ink)' }}>
                إجراءات المصادقة والحفظ
              </h4>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {canEdit ? (
                <>
                  <button
                    type="button"
                    className="btn"
                    onClick={approve}
                    disabled={isApproving}
                    style={{
                      width: '100%',
                      height: 38,
                      fontSize: 13,
                      fontWeight: 700,
                      justifyContent: 'center',
                      background: '#16a34a',
                      color: '#fff',
                      border: 'none',
                    }}
                  >
                    <Icon name="check" />
                    <span>{isApproving ? 'جارٍ الاعتماد…' : isAdmin ? 'اعتماد نهائي ونشر للعميل' : 'اعتماد ورفع للإدارة العليا'}</span>
                  </button>

                  <button
                    type="button"
                    className="btn soft"
                    onClick={save}
                    // نظير زرّ الحفظ الأعلى — يُعطَّل بعد الاعتماد بدل أن يعطي 422
                    disabled={isSaving || !canEdit}
                    style={{ width: '100%', height: 36, fontSize: 12.5, justifyContent: 'center' }}
                  >
                    <Icon name="check" /> {isSaving ? 'جارٍ الحفظ…' : 'حفظ التعديلات كمسودة'}
                  </button>

                  <p style={{ margin: '4px 0 0', fontSize: 11, color: 'var(--muted)', lineHeight: 1.6 }}>
                    {isAdmin
                      ? 'ملاحظة: الضغط على الاعتماد النهائي ينشر الرأي القانوني المبدئي مباشرة في محادثة العميل ويكمل مرحلة دراسة الملف.'
                      : 'ملاحظة: بعد اعتمادك سيتم رفع الملف إلى مركز الاعتمادات والقرارات الإدارية للمصادقة النهائية.'}
                  </p>
                </>
              ) : (
                <div>
                  <div
                    style={{
                      padding: '10px 12px',
                      borderRadius: 8,
                      background: 'rgba(22, 163, 74, 0.08)',
                      border: '1px solid rgba(22, 163, 74, 0.25)',
                      color: '#15803d',
                      fontSize: 12,
                      fontWeight: 600,
                      lineHeight: 1.6,
                    }}
                  >
                    ✓ اكتملت مراحل مراجعة واعتماد هذا الملخص، ولا يمكن إجراء تعديلات إضافية بعد النشر للعميل.
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default LawyerSummary;
