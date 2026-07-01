import { Link, usePage } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import {
  type Consult,
  type AuditEntry,
  CONSULTS,
  CONSULT_FLOW,
  cStage,
  cTone,
  maskClient,
} from '@/lib/employee-data';

// يطابق consultView (دور الموظف) + cTake/cRequestDocs/cRunAI/cSaveAI/cApproveAI/cRerun/cRefer

const LAWYER_OPTS = ['أ. سارة القحطاني', 'أ. خالد المالكي', 'أ. ريم الزهراني', 'أ. ماجد العتيبي'];
const SUGG: Record<string, string> = {
  'تجاري': 'أ. سارة القحطاني', 'عمالي': 'أ. سارة القحطاني',
  'تنفيذ': 'أ. خالد المالكي', 'عقاري': 'أ. خالد المالكي',
};

// يطابق aiStructuredSummary
function aiStructuredSummary(c: Consult, notes: string): string {
  const n = notes && notes.trim()
    ? notes.trim()
    : `عرض العميل موضوع «${c.subject}» خلال الجلسة المرئية وقدّم ملابساته والمستندات ذات الصلة.`;
  return `تصنيف الفريق القانوني: استشارة ${c.type} — أولوية ${c.priority || 'متوسطة'}.\n\n` +
    `١) الوقائع: ${n}\n\n` +
    `٢) التكييف القانوني: يندرج الموضوع ضمن النزاعات ${c.type}، ويتوفّر أساس نظامي للمطالبة استناداً إلى الوقائع المعروضة والمستندات المرفقة.\n\n` +
    `٣) الرأي/التوصية: توجيه إنذار رسمي للطرف الآخر، ثم إعداد مذكرة دعوى احتياطية حال عدم الاستجابة خلال المهلة النظامية.\n\n` +
    `٤) المهام المقترحة: (أ) صياغة خطاب المطالبة، (ب) حصر واستكمال المستندات، (ج) تحديد المحامي المختص ومتابعة المهلة.`;
}

const EmployeeConsult: React.FC = () => {
  const toast = useToast();
  const { url } = usePage() as unknown as { url: string };
  const q = new URLSearchParams(url.split('?')[1] || '');
  const ref = q.get('ref') || q.get('id') || q.get('no');
  const base = CONSULTS.find((x) => x.ref === ref) || CONSULTS[0];

  const [c, setC] = useState<Consult>(() => ({ ...base, audit: [...base.audit], missing: [...base.missing] }));

  // الحقول القابلة للتعديل لتحليل الفريق القانوني
  const [aiClass, setAiClass] = useState(c.aiClass);
  const [aiSummary, setAiSummary] = useState(c.aiSummary);
  const [aiLawyer, setAiLawyer] = useState(c.aiLawyer);

  const audit = (field: string, before: string, after: string): AuditEntry => ({
    user: 'منيرة الحربي', field, before, after, time: 'الآن',
  });
  const pushAudit = (entry: AuditEntry) => setC((p) => ({ ...p, audit: [entry, ...p.audit] }));

  // يطابق cTake
  const take = () => {
    setC((p) => ({ ...p, employee: 'منيرة الحربي', status: 'قيد مراجعة الموظف', audit: [audit('الحالة', p.status, 'قيد مراجعة الموظف'), ...p.audit] }));
    toast('تم استلام الاستشارة لدى الموظف');
  };

  // يطابق cRequestDocs
  const requestDocs = () => {
    setC((p) => ({
      ...p,
      missing: p.missing.length ? p.missing : ['مستند إضافي مطلوب'],
      status: 'بانتظار استكمال البيانات',
      audit: [audit('الحالة', p.status, 'بانتظار استكمال البيانات'), ...p.audit],
    }));
    toast('تم طلب استكمال البيانات وإشعار العميل');
  };

  // يطابق cRunAI
  const runAI = () => {
    const newClass = `استشارة ${c.type}`;
    const newSummary = aiStructuredSummary(c, '');
    const newLawyer = SUGG[c.type] || 'أ. سارة القحطاني';
    setAiClass(newClass);
    setAiSummary(newSummary);
    setAiLawyer(newLawyer);
    setC((p) => ({
      ...p,
      aiClass: newClass, aiSummary: newSummary, aiLawyer: newLawyer,
      aiDone: true, missing: [], status: 'بانتظار اعتماد الموظف',
      audit: [
        audit('الحالة', 'قيد معالجة الفريق القانوني', 'بانتظار اعتماد الموظف'),
        audit('تحليل الفريق القانوني', '—', 'اكتمل'),
        audit('الحالة', p.status, 'قيد معالجة الفريق القانوني'),
        ...p.audit,
      ],
    }));
    toast('اكتمل تحليل الفريق القانوني');
  };

  // يطابق cSaveAI
  const saveAI = () => {
    setC((p) => {
      const entries: AuditEntry[] = [];
      if (aiClass !== p.aiClass) entries.unshift(audit('التصنيف', p.aiClass, aiClass));
      if (aiSummary !== p.aiSummary) entries.unshift(audit('الملخص', '(نص سابق)', '(نص محدّث)'));
      if (aiLawyer !== p.aiLawyer) entries.unshift(audit('المحامي المقترح', p.aiLawyer, aiLawyer));
      return { ...p, aiClass, aiSummary, aiLawyer, audit: [...entries, ...p.audit] };
    });
    toast('تم حفظ ملخص الاستشارة في سجل التدقيق');
  };

  // يطابق cApproveAI
  const approveAI = () => {
    setC((p) => ({
      ...p, aiClass, aiSummary, aiLawyer, status: 'جاهزة للمحامي',
      audit: [audit('اعتماد التحليل', 'بانتظار اعتماد الموظف', 'جاهزة للمحامي'), ...p.audit],
    }));
    toast('تم اعتماد التحليل — الاستشارة جاهزة للمحامي');
  };

  // يطابق cRerun ثم cRunAI
  const rerun = () => {
    setC((p) => ({ ...p, aiDone: false, status: 'قيد معالجة الفريق القانوني', audit: [audit('الحالة', p.status, 'قيد معالجة الفريق القانوني'), ...p.audit] }));
    toast('تمت إعادة تشغيل التحليل');
    setTimeout(runAI, 250);
  };

  // يطابق cRefer
  const refer = () => {
    setC((p) => {
      const lw = p.lawyer === '—' || !p.lawyer ? (p.aiLawyer || 'أ. سارة القحطاني') : p.lawyer;
      toast(`تمت إحالة الاستشارة إلى المحامي: ${lw}`);
      return { ...p, lawyer: lw, status: 'محالة للمحامي', audit: [audit('الحالة', p.status, 'محالة للمحامي'), ...p.audit] };
    });
  };

  const showEmpActions = c.status === 'جديدة'
    || c.status === 'قيد مراجعة الموظف'
    || c.status === 'بانتظار استكمال البيانات';
  const showRefer = c.status === 'جاهزة للمحامي';
  const showAiCard = c.aiDone;
  const showApprove = c.status === 'بانتظار اعتماد الموظف';

  return (
    <div className="detail-wrap" style={{ maxWidth: 920 }}>
      <div style={{ marginBottom: 14 }}>
        <Link href="/employee/consults" className="btn soft sm">
          <Icon name="reply" /> رجوع للاستشارات
        </Link>
      </div>

      {/* info */}
      <div className="card">
        <div className="card-h">
          <h3>{c.ref}</h3>
          <Badge text={c.status} tone={cTone(c.status)} />
        </div>
        <div className="card-b" style={{ padding: '14px 18px' }}>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <span className="chip muted">{maskClient(c.client)}</span>
            <span className="chip muted">{c.subject}</span>
            <span className="chip muted">{c.type}</span>
            <span className={`mq-priority ${c.priority}`}>{c.priority}</span>
            <span className="chip muted">استُلمت: {c.received}</span>
            <span className="chip muted">الموظف: {c.employee}</span>
            <span className="chip muted">المحامي: {c.lawyer}</span>
          </div>
        </div>
      </div>

      {/* stage */}
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-b" style={{ padding: '16px 18px' }}>
          <FlowLine steps={CONSULT_FLOW} cur={cStage(c.status)} />
        </div>
      </div>

      {/* action bar */}
      {(showEmpActions || showRefer) && (
        <div style={{ display: 'flex', gap: 9, margin: '0 0 16px', flexWrap: 'wrap' }}>
          {c.status === 'جديدة' && (
            <button className="btn" onClick={take} type="button">
              <Icon name="check" /> استلام الاستشارة
            </button>
          )}
          {(c.status === 'قيد مراجعة الموظف' || c.status === 'بانتظار استكمال البيانات') && (
            <>
              <button className="btn soft" onClick={requestDocs} type="button">
                <Icon name="upload" /> طلب استكمال مستندات
              </button>
              <button className="btn" onClick={runAI} type="button">
                <Icon name="info" /> بدء معالجة الفريق القانوني
              </button>
            </>
          )}
          {showRefer && (
            <button className="btn" onClick={refer} type="button">
              <Icon name="scale" /> إحالة للمحامي
              {(c.lawyer === '—' || !c.lawyer) ? ` (${c.aiLawyer || ''})` : ''}
            </button>
          )}
        </div>
      )}

      {/* AI card */}
      {showAiCard && (
        <>
          <div className="ai-banner">
            <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
            <p>نتائج تحليل الفريق القانوني — يمكن للموظف المخوّل أو الإدارة تعديلها واعتمادها. تُحفظ كل التعديلات في سجل التدقيق.</p>
          </div>
          <div className="card" style={{ marginBottom: 14 }}>
            <div className="card-h"><h3>تحليل الفريق القانوني</h3></div>
            <div className="card-b" style={{ padding: '16px 18px' }}>
              <div className="field">
                <label>تصنيف الاستشارة</label>
                <input className="input" value={aiClass} onChange={(e) => setAiClass(e.target.value)} />
              </div>
              <div className="field">
                <label>الملخص القانوني</label>
                <textarea className="input" rows={3} value={aiSummary} onChange={(e) => setAiSummary(e.target.value)} />
              </div>
              <div className="field">
                <label>المحامي المقترح</label>
                <select value={aiLawyer} onChange={(e) => setAiLawyer(e.target.value)}>
                  {LAWYER_OPTS.map((l) => <option key={l}>{l}</option>)}
                </select>
              </div>
              {c.missing.length > 0 && (
                <div className="action-hint">
                  <Icon name="upload" /> مستندات ناقصة: {c.missing.join('، ')}
                </div>
              )}
              <div style={{ display: 'flex', gap: 9, marginTop: 6, flexWrap: 'wrap' }}>
                <button className="btn soft sm" onClick={saveAI} type="button">
                  <Icon name="check" /> حفظ التعديلات
                </button>
                {showApprove && (
                  <button className="btn sm" onClick={approveAI} type="button">
                    <Icon name="check" /> اعتماد التحليل (جاهزة للمحامي)
                  </button>
                )}
                <button className="btn soft sm" onClick={() => toast('طباعة الملخص (PDF)')} type="button">
                  <Icon name="download" /> طباعة الملخص (PDF)
                </button>
                <button className="btn soft sm" onClick={rerun} type="button">
                  <Icon name="info" /> إعادة التحليل
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* audit log */}
      <div className="card">
        <div className="card-h">
          <h3>سجل التدقيق (Audit Log)</h3>
          <span className="sub">{c.audit.length}</span>
        </div>
        <div className="card-b">
          {c.audit.length ? c.audit.map((a, i) => (
            <div key={i} className="item">
              <div className="iico"><Icon name="info" /></div>
              <div className="imeta">
                <b>{a.field}</b>
                <span style={{ display: 'block', marginTop: 2 }}>{a.user} · {a.before} ← {a.after}</span>
                <span style={{ color: 'var(--muted)', fontSize: 11 }}>{a.time}</span>
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="info" /><b>لا تعديلات بعد</b></div>
          )}
        </div>
      </div>
    </div>
  );
};

export default EmployeeConsult;
