import { Link, router } from '@inertiajs/react';
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

  const approve = () =>
    router.post(`${base}/summary/${encodeURIComponent(ticket.no)}/approve`, form, {
      onSuccess: () => toast('تم اعتماد الملخص وإرساله لمحادثة العميل'),
    });

  const statusText = approved ? 'معتمد — أُرسل للعميل' : 'بانتظار اعتماد المستشار';
  const statusTone = approved ? 'b-green' : 'b-amber';

  return (
    <div className="detail-wrap">
      <div style={{ marginBottom: 14 }}>
        <Link href={`${base}/summaries`} className="btn soft sm">
          <Icon name="reply" /> رجوع للملخصات
        </Link>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>ملخص ملف — {ticket.no} · {ticket.type}</h3>
          <Badge text={statusText} tone={statusTone} />
        </div>
        <div className="card-b" style={{ padding: '16px 18px' }}>
          <FlowLine steps={SUM_FLOW} cur={sumStage(summary.status)} />
        </div>
      </div>

      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>
          {canEdit
            ? <><b>الفريق القانوني</b> جهّز هذا الملخص آلياً من ملف التذكرة. راجِعه وعدّله عند الحاجة ثم اعتمده.</>
            : 'تم اعتماد هذا الملخص وإرساله إلى محادثة العميل.'}
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

      <div style={{ display: 'flex', gap: 9, marginTop: 16, flexWrap: 'wrap' }}>
        {canEdit ? (
          <>
            <button className="btn" onClick={approve} type="button">
              <Icon name="check" /> اعتماد الملخص وإرساله للعميل
            </button>
            <button className="btn soft" onClick={save} type="button">
              حفظ التعديلات
            </button>
          </>
        ) : (
          <span className="chip muted">تم إرسال الملخص والرأي القانوني للعميل</span>
        )}
      </div>
    </div>
  );
};

export default LawyerSummary;
