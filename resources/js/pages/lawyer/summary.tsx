import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import { SUM_FLOW, type SummaryData, sumStage } from '@/lib/lawyer-data';

// مراجعة المحامي لملخص الملف الذي جهّزه الفريق القانوني (الذكاء الاصطناعي) واعتماده.
// الاعتماد يرسل الرأي القانوني + إشعاراً مباشرةً إلى محادثة العميل.

interface EmpTicket { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; }
interface Props { ticket: EmpTicket; summary: SummaryData; base?: string; }

const FIELDS: { key: keyof SummaryData; label: string }[] = [
  { key: 'caseSummary', label: 'تلخيص القضية' },
  { key: 'attachmentsSummary', label: 'تلخيص المرفقات' },
  { key: 'facts', label: 'تجهيز الوقائع' },
  { key: 'keyPoints', label: 'النقاط المهمة والتوصيات' },
];

const LawyerSummary: React.FC<Props> = ({ ticket, summary, base = '/lawyer' }) => {
  const toast = useToast();
  const approved = summary.approved;
  const canEdit = !approved;

  const [form, setForm] = useState({
    case_summary: summary.caseSummary || '',
    attachments_summary: summary.attachmentsSummary || '',
    facts: summary.facts || '',
    key_points: summary.keyPoints || '',
  });

  const [najizDraft, setNajizDraft] = useState<string | null>(null);
  const [busyNajiz, setBusyNajiz] = useState(false);

  const val = (key: keyof SummaryData) =>
    key === 'caseSummary' ? form.case_summary
      : key === 'attachmentsSummary' ? form.attachments_summary
      : key === 'facts' ? form.facts : form.key_points;

  const setVal = (key: keyof SummaryData, v: string) => setForm((f) => ({
    ...f,
    ...(key === 'caseSummary' ? { case_summary: v }
      : key === 'attachmentsSummary' ? { attachments_summary: v }
      : key === 'facts' ? { facts: v } : { key_points: v }),
  }));

  const save = () =>
    router.post(`${base}/summary/${encodeURIComponent(ticket.no)}`, form, {
      preserveScroll: true, onSuccess: () => toast('تم حفظ تعديلات الملخص'),
    });

  // إعادة تشغيل التحليل الذكي للملخّص
  const rerun = () =>
    router.post(`${base}/summary/${encodeURIComponent(ticket.no)}/rerun`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('تمت إعادة تشغيل التحليل الذكي للملخّص'),
      onError: () => toast('تعذّر إعادة تشغيل التحليل حالياً'),
    });

  // توليد مسودة لائحة ناجز عبر الذكاء الاصطناعي
  const generateNajiz = async () => {
    setBusyNajiz(true);
    try {
      const { data } = await axios.post(`${base}/summary/${encodeURIComponent(ticket.no)}/najiz`);
      setNajizDraft(data.draft);
      toast('✨ تم توليد مسودة صحيفة دعوى مطابقة لمعايير ناجز');
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

  // قالب مبدئي لم يكتمل تحليله الذكي
  const isTemplate = summary.aiGenerated === false;

  const approve = () =>
    router.post(`${base}/summary/${encodeURIComponent(ticket.no)}/approve`, form, {
      onSuccess: () => toast('تم اعتماد الملخص وإرساله لمحادثة العميل'),
      onError: (errors) => {
        const firstError = Object.values(errors)[0];
        toast(typeof firstError === 'string' ? firstError : 'لا يمكن اعتماد ملخّص لم يكتمل تحليله الذكي — حرّره يدوياً أولاً.');
      },
    });

  const statusText = approved ? 'معتمد — أُرسل للعميل' : 'بانتظار اعتماد المستشار';
  const statusTone = approved ? 'b-green' : 'b-amber';

  return (
    <div className="detail-wrap">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14, flexWrap: 'wrap', gap: 10 }}>
        <Link href={`${base}/summaries`} className="btn soft sm">
          <Icon name="reply" /> رجوع للملخصات
        </Link>
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="btn soft sm" onClick={exportPdf} type="button">
            <Icon name="upload" /> تصدير وطباعة PDF معتمد
          </button>
          <button className="btn soft sm" onClick={generateNajiz} type="button" disabled={busyNajiz}>
            <Icon name="sparkles" /> {busyNajiz ? 'جارٍ توليد مسودة ناجز…' : '✨ توليد مسودة لائحة ناجز'}
          </button>
        </div>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>ملخص ملف — {ticket.no} · {ticket.type}</h3>
          {!approved && isTemplate
            ? <Badge text="بانتظار التحليل الذكي" tone="b-red" />
            : <Badge text={statusText} tone={statusTone} />}
        </div>
        <div className="card-b" style={{ padding: '16px 18px' }}>
          <FlowLine steps={SUM_FLOW} cur={sumStage(summary.resultStatus)} />
        </div>
      </div>

      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>
          {!canEdit
            ? 'تم اعتماد هذا الملخص وإرساله إلى محادثة العميل.'
            : isTemplate
              ? <><b>لم يكتمل التحليل الذكي بعد</b> — هذا قالب مبدئي لا يعكس محتوى الملف/المرفقات. حرّره يدوياً بناءً على المستندات قبل الاعتماد (لن يُقبل اعتماد القالب كما هو).</>
              : <><b>الفريق القانوني</b> جهّز هذا الملخص آلياً من ملف التذكرة. راجِعه وعدّله عند الحاجة ثم اعتمده.</>}
        </p>
      </div>

      {FIELDS.map((f) => (
        <div className="doc-edit" key={f.key} style={{ marginBottom: 12 }}>
          <div className="doc-head">
            <span className="di"><Icon name="doc" /></span>
            <b>{f.label}</b>
          </div>
          <textarea
            value={val(f.key)}
            onChange={(e) => setVal(f.key, e.target.value)}
            readOnly={!canEdit}
            style={{ minHeight: 90 }}
          />
        </div>
      ))}

      {/* قسم مسودة صحيفة دعوى ناجز إن تم توليدها */}
      {najizDraft !== null && (
        <div className="card" style={{ marginTop: 18, marginBottom: 18, border: '2px solid var(--primary)' }}>
          <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <h3 style={{ margin: 0, color: 'var(--deep)' }}>⚖️ مسودة صحيفة دعوى (معايير منصة ناجز)</h3>
            <div style={{ display: 'flex', gap: 7 }}>
              <button
                className="btn soft sm"
                onClick={() => {
                  navigator.clipboard?.writeText(najizDraft);
                  toast('تم نسخ مسودة صحيفة الدعوى');
                }}
                type="button"
              >
                <Icon name="check" /> نسخ المسودة
              </button>
              <button className="btn soft sm" onClick={() => setNajizDraft(null)} type="button">
                إخفاء
              </button>
            </div>
          </div>
          <div className="card-b" style={{ padding: 16 }}>
            <textarea
              value={najizDraft}
              onChange={(e) => setNajizDraft(e.target.value)}
              style={{
                width: '100%',
                minHeight: 280,
                fontFamily: 'inherit',
                fontSize: '13.8px',
                border: '1.4px solid var(--line)',
                borderRadius: 12,
                padding: 14,
                lineHeight: 2,
                background: '#FAFCFE',
              }}
            />
          </div>
        </div>
      )}

      <div style={{ display: 'flex', gap: 9, marginTop: 16, flexWrap: 'wrap', alignItems: 'center' }}>
        {canEdit ? (
          <>
            <button className="btn" onClick={approve} type="button">
              <Icon name="check" /> اعتماد الملخص وإرساله للعميل
            </button>
            <button className="btn soft" onClick={save} type="button">
              حفظ التعديلات
            </button>
            <button className="btn soft" onClick={rerun} type="button">
              <Icon name="sparkles" /> إعادة التحليل الذكي
            </button>
            <button className="btn soft" onClick={generateNajiz} type="button" disabled={busyNajiz}>
              <Icon name="doc" /> {busyNajiz ? 'جارٍ توليد المسودة…' : 'مسودة لائحة ناجز'}
            </button>
            <button className="btn soft" onClick={exportPdf} type="button">
              <Icon name="upload" /> معاينة PDF للطباعة
            </button>
          </>
        ) : (
          <>
            <span className="chip muted">تم إرسال الملخص والرأي القانوني للعميل</span>
            <button className="btn soft sm" onClick={exportPdf} type="button">
              <Icon name="upload" /> طباعة تقرير الملف المعتمد
            </button>
            <button className="btn soft sm" onClick={generateNajiz} type="button" disabled={busyNajiz}>
              <Icon name="sparkles" /> مسودة لائحة ناجز
            </button>
          </>
        )}
      </div>
    </div>
  );
};

export default LawyerSummary;
