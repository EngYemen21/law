import { router } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import ChatThread from '@/components/babylon/ChatThread';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import { EXEC_FLOW, EXEC_SANADS, EXEC_FEE_MODES, EXEC_CLOSE_REASONS, EXEC_DOC_ACCEPT, EXEC_DOC_HINT, EXEC_REQ_DOC_ACCEPT, EXEC_REQ_DOC_HINT, execTone, execMoney, procTone, execVatLabel, execAiPresentation, execStudyBasis, execUnassigned    } from '@/lib/exec-flow';
import type { ExecFeeMode, ExecInvoice } from '@/lib/exec-flow';
import type {ExecDoc, ExecLawyerOpt, ExecReq, Role} from '@/lib/exec-flow';
import { ExecNajizCard } from '@/lib/exec-najiz';
import Icon from '@/lib/icons';
import { useCan } from '@/lib/permissions';

// ─────────────────────────────────────────────────────────────────────────────
// تدفّق طلب التنفيذ (المرحلة 2) — مربوط بالخادم. الحالة كلّها props من Inertia،
// والانتقالات POST إلى App\Http\Controllers\ExecFlowController (App\Support\ExecService).
// role يُمرَّر من مسار كلّ لوحة (عميل/محامي/إدارة/موظف).
// ─────────────────────────────────────────────────────────────────────────────

const STAGE_COLOR = (s: number) => (s >= 9 ? '#607689' : s >= 7 ? '#1E9D6B' : s >= 5 ? '#C0832B' : '#0E5C9C');

type View = 'list' | 'new' | 'detail';

// صفّ بيانات (تطابق cellRow → .lwf-cells)
const CellRow: React.FC<{ cells: [string, string][] }> = ({ cells }) => (
  <div className="lwf-cells">
    {cells.map((c, i) => (
      <div className="cell" key={i}>
        <div className="cl">{c[0]}</div>
        <div className="cv">{c[1]}</div>
      </div>
    ))}
  </div>
);

// ── القائمة (تطابق execList) — البيانات مُصفّاة ومُقنّعة من الخادم حسب الدور ──
// الإجراء التالي المطلوب على البطاقة حسب الدور (تطابق execNextAction)
const nextAction = (role: Role, r: ExecReq, canCourt = false): string => {
  // الطلب المرفوض لا إجراء عليه — كان يُطبع «اقبل الطلب أو اطلب مستندات» لطلب رُفض فعلاً
  if (r.decision === 'مرفوض') {
return 'مرفوض بعد الدراسة';
}

  // ملفٌّ أُغلق وأُرشف لا إجراء عليه — وكان الموظّف يُؤمَر بمتابعة ملفٍّ انتهى
  if (r.closed) {
return '';
}

  // ملفٌّ بلا محامٍ بعد الإحالة عالقٌ فعلاً: التسعير يردّه الخادم بلا إسناد.
  // يُقال ذلك لمن يملك الإسناد وحده — ومن لا يملكه لا يُؤمَر بما لا يقدر عليه.
  if (r.canAssign && execUnassigned(r) && r.stage >= 2) {
return 'بانتظار إسناد محامٍ';
}

  if (role === 'admin') {
    if (r.offerStatus === 'مرفوض' && r.stage === 5) {
      return 'رفض العميل العرض — أعد التسعير أو أنهِ الملف';
    }
    return r.stage < 2 ? 'أحِل لقسم التنفيذ' : r.stage === 4 ? 'اعتمد الأتعاب' : '';
  }

  if (role === 'lawyer') {
    if (r.stage <= 1) {
return 'بانتظار البدء بالدراسة';
}

    if (r.stage === 2) {
return 'اقبل الطلب أو اطلب مستندات';
}

    // الخادم يرفض التسعير على ملفٍّ ليس مسنَداً — فالإشارة تقول الشرط بدل أمرٍ يُردّ
    if (r.stage === 3) {
return execUnassigned(r) ? 'التسعير يلزمه إسناد الملفّ إليك' : 'حدّد الأتعاب';
}

    if (r.stage === 4) {
return 'بانتظار اعتماد الإدارة';
}

    // المرحلة 7 مطلبها الرفع في ناجز لا إضافة الإجراءات — الإجراءات لا تُسجَّل قبل القيد
    if (r.stage === 7) {
return 'ارفع الطلب في ناجز وسجّل رقمه';
}

    if (r.stage >= 8) {
return 'أضف إجراءات التنفيذ';
}
  }

  if (role === 'employee') {
    if (r.stage <= 1) {
return r.aiMissing.length ? 'اطلب المستندات الناقصة' : 'راجع وأحِل للمحامي';
}

    // من يملك «إجراءات المحكمة والجلسات» يسجّل خطوات ناجز بنفسه، فلا يُقال له «بيد المحامي»
    if (canCourt && r.stage === 7) {
return 'ارفع الطلب في ناجز وسجّل رقمه';
}

    if (canCourt && r.stage === 8) {
return 'سجّل خطوة التنفيذ في ناجز';
}

    return 'مُحال — بيد المحامي';
  }

  return '';
};

const ExecList: React.FC<{ role: Role; execs: ExecReq[]; onNew: () => void; onOpen: (id: string) => void }> = ({ role, execs, onNew, onOpen }) => {
  // الإجراء التالي للموظّف يتبع صلاحيّته لا دوره وحده
  const canCourt = useCan()('إجراءات المحكمة والجلسات');
  const neu = execs.filter((r) => r.stage <= 1).length;
  const study = execs.filter((r) => r.stage >= 2 && r.stage <= 4).length;
  const offer = execs.filter((r) => r.stage >= 5 && r.stage <= 6 && !r.paid).length;
  const active = execs.filter((r) => r.stage >= 7 && !r.closed).length;
  const closed = execs.filter((r) => r.closed).length;

  return (
    <>
      <div className="greet">
        <h2>{role === 'client' ? 'طلبات التنفيذ' : 'ملفات التنفيذ'}</h2>
        <p>إدارة طلبات التنفيذ إلكترونياً من التقديم حتى إغلاق الملف، مع تحديد الأتعاب واعتمادها قبل بدء العمل.</p>
      </div>

      <div className="stat-strip">
        <span className="stat-pill"><span className="pd" style={{ background: '#607689' }} /><b>{neu}</b> جديدة/تحليل</span>
        {role !== 'client' && <span className="stat-pill"><span className="pd" style={{ background: '#0E5C9C' }} /><b>{study}</b> دراسة/أتعاب</span>}
        <span className="stat-pill"><span className="pd" style={{ background: '#C0832B' }} /><b>{offer}</b> عروض/سداد</span>
        <span className="stat-pill"><span className="pd" style={{ background: '#1E9D6B' }} /><b>{active}</b> قيد التنفيذ</span>
        <span className="stat-pill"><span className="pd" style={{ background: '#8895a7' }} /><b>{closed}</b> مغلقة</span>
      </div>

      {role === 'client' && (
        <div style={{ display: 'flex', justifyContent: 'flex-end', margin: '4px 0 12px' }}>
          <button className="btn" type="button" onClick={onNew}><Icon name="plus" /> طلب تنفيذ جديد</button>
        </div>
      )}

      <div className="card">
        <div className="card-h"><h3>الطلبات</h3><span className="sub">{execs.length}</span></div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          {execs.length ? execs.map((r) => (
            <div key={r.id} className="agd-c" style={{ borderRightColor: STAGE_COLOR(r.stage), marginBottom: 10, cursor: 'pointer' }} onClick={() => onOpen(r.id)}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, alignItems: 'flex-start' }}>
                <div style={{ minWidth: 0 }}>
                  <div className="mtg-t">{r.id} — {r.subject}</div>
                  <div className="mtg-m">
                    <Badge text={r.sanad || '—'} tone="b-blue" /> · {role === 'client' ? '' : r.client + ' · '}
                    مطالبة {execMoney(r.amount)} ريال{r.execNo ? ` · تنفيذ ${r.execNo}` : ''}
                  </div>
                </div>
                <Badge text={EXEC_FLOW[r.stage]} tone={execTone(r.stage)} />
              </div>
              <div className="agd-meta">
                <span><Icon name="scale" /> {r.defendant || '—'}</span>
                {r.feeApproved ? <span><Icon name="card" /> {r.feeMode === 'percent' ? `أتعاب ${r.collectionFeePct ?? 0}% من المحصّل` : `أتعاب ${execMoney(r.fee)} ريال`}</span> : null}
                {r.lawyer && role !== 'client' ? <span><Icon name="user" /> {r.lawyer}</span> : null}
                {role !== 'client' && nextAction(role, r, canCourt) ? <span style={{ color: STAGE_COLOR(r.stage), fontWeight: 700 }}><Icon name="info" /> {nextAction(role, r, canCourt)}</span> : null}
              </div>
            </div>
          )) : <div className="empty"><Icon name="exec" /><b>لا طلبات تنفيذ</b></div>}
        </div>
      </div>
    </>
  );
};

// ── نموذج التقديم (تطابق execNew مع إرفاق المستندات للتحليل الذكي) ──
interface ExecNewPayload {
  sanad: string;
  subject: string;
  defendant: string;
  amount: number;
  notes: string;
  files?: File[];
}

const ExecNew: React.FC<{ onSubmit: (d: ExecNewPayload) => void; onBack: () => void; busy: boolean }> = ({ onSubmit, onBack, busy }) => {
  const [sanad, setSanad] = useState(EXEC_SANADS[0]);
  const [amount, setAmount] = useState('');
  const [subject, setSubject] = useState('');
  const [defendant, setDefendant] = useState('');
  const [notes, setNotes] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const onFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const chosen = e.target.files;

    if (!chosen || chosen.length === 0) {
return;
}

    const list = Array.from(chosen);
    // حد أقصى 10 ملفات وحجم 10 ميجابايت للملف
    const valid = list.filter((f) => f.size <= 10 * 1024 * 1024);
    setFiles((prev) => [...prev, ...valid].slice(0, 10));

    if (fileInputRef.current) {
fileInputRef.current.value = '';
}
  };

  const removeFile = (idx: number) => {
    setFiles((prev) => prev.filter((_, i) => i !== idx));
  };

  const submit = () => {
    if (!subject.trim()) {
return;
}

    onSubmit({
      sanad,
      subject: subject.trim(),
      defendant: defendant.trim(),
      amount: parseInt(amount || '0', 10) || 0,
      notes: notes.trim(),
      files,
    });
  };

  return (
    <>
      <div className="greet">
        <h2>طلب تنفيذ جديد</h2>
        <p>أدخل بيانات السند والمطالبة وأرفق المستندات، وسيحلّلها الفريق القانوني الذكي بالكامل قبل الإحالة.</p>
      </div>
      <div className="card">
        <div className="card-b" style={{ padding: 18 }}>
          <div className="picker-grid">
            <div className="field">
              <label>نوع السند التنفيذي</label>
              <select className="input" value={sanad} onChange={(e) => setSanad(e.target.value)}>
                {EXEC_SANADS.map((s) => <option key={s} value={s}>{s}</option>)}
              </select>
            </div>
            <div className="field">
              <label>قيمة المطالبة (ريال)</label>
              <input className="input" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="مثال: 85000" />
            </div>
          </div>
          <div className="field">
            <label>موضوع التنفيذ</label>
            <input className="input" value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="مثال: تحصيل قيمة شيك مرتجع" />
          </div>
          <div className="field">
            <label>بيانات المنفَّذ ضده (إن وجدت)</label>
            <input className="input" value={defendant} onChange={(e) => setDefendant(e.target.value)} placeholder="اسم الطرف الآخر" />
          </div>
          <div className="field">
            <label>معلومات إضافية</label>
            <textarea className="input" value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} placeholder="أي إيضاحات أو تواريخ إضافية…" />
          </div>

          <div className="field">
            <label style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span>إرفاق المستندات والسندات التنفيذية</span>
              <span style={{ fontSize: 11.5, color: 'var(--muted)', fontWeight: 'normal' }}>
                (PDF، صور، Word، Excel — حتى 10 ملفات)
              </span>
            </label>
            <div
              style={{
                border: '1.5px dashed #C0D2E6',
                borderRadius: 14,
                padding: '16px 14px',
                background: '#F8FAFC',
                textAlign: 'center',
                cursor: 'pointer',
                transition: 'border-color 0.2s',
              }}
              onClick={() => fileInputRef.current?.click()}
            >
              <div style={{ color: 'var(--primary)', marginBottom: 6 }}>
                <Icon name="upload" />
              </div>
              <b style={{ color: 'var(--ink)', fontSize: 13.5, display: 'block' }}>
                انقر لاختيار المستندات أو اسحبها هنا
              </b>
              <span style={{ color: 'var(--muted)', fontSize: 11.5, display: 'block', marginTop: 4 }}>
                الحكم القضائي، الشيك، السند لأمر، العقد الموثق، أو الهوية
              </span>
              <input
                ref={fileInputRef}
                type="file"
                multiple
                accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"
                style={{ display: 'none' }}
                onChange={onFileChange}
              />
            </div>

            {files.length > 0 && (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginTop: 10 }}>
                {files.map((file, idx) => (
                  <div
                    key={idx}
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'space-between',
                      background: '#fff',
                      border: '1px solid var(--line)',
                      borderRadius: 10,
                      padding: '7px 12px',
                      fontSize: 12.5,
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 }}>
                      <span style={{ color: 'var(--primary)', flexShrink: 0 }}><Icon name="doc" /></span>
                      <span style={{ fontWeight: 600, color: 'var(--ink)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                        {file.name}
                      </span>
                      <span style={{ color: 'var(--muted)', fontSize: 11, flexShrink: 0 }}>
                        ({(file.size / 1024).toFixed(0)} KB)
                      </span>
                    </div>
                    <button
                      type="button"
                      onClick={(e) => {
 e.stopPropagation(); removeFile(idx); 
}}
                      style={{
                        background: 'transparent',
                        border: 'none',
                        color: 'var(--red)',
                        cursor: 'pointer',
                        fontWeight: 800,
                        padding: '2px 6px',
                        fontSize: 13,
                      }}
                      title="حذف المستند"
                    >
                      ✕
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>

         

          <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
            <button className="btn" type="button" onClick={submit} disabled={busy}><Icon name="send" /> {busy ? 'جارٍ الإرسال والتحليل…' : 'إرسال الطلب'}</button>
            <button className="btn soft" type="button" onClick={onBack}><Icon name="out" /> رجوع</button>
          </div>
        </div>
      </div>
    </>
  );
};

type ActFn = (action: string, payload?: Record<string, unknown>) => void;

// ── بطاقة الإجراء المقيّدة بالدور (تطابق actions 1965‑1967) ──
const ActionCard: React.FC<{ role: Role; r: ExecReq; act: ActFn }> = ({ role, r, act }) => {
  const [fee, setFee] = useState('');
  const [dur, setDur] = useState('');
  const [feeMode, setFeeMode] = useState<ExecFeeMode>('fixed');
  const [collectPct, setCollectPct] = useState('');
  const [proc, setProc] = useState('');
  const [feeAdj, setFeeAdj] = useState('');
  // سبب أرشفة الملفّ المرفوض — «أخرى» افتراضاً، والقائمة تُرسَل كما يقبلها الخادم
  const [rejectedReason, setRejectedReason] = useState('أخرى');
  const total = r.fee + r.vat;
  const basis = execStudyBasis(r.study);

  let body: React.ReactNode = null;

  if (role === 'client') {
    if (r.stage === 5 && r.feeApproved) {
      body = (<>
        <button className="btn" type="button" onClick={() => act('acceptOffer')}><Icon name="check" /> قبول العرض</button>
        <button className="btn soft" type="button" onClick={() => act('inquire')}><Icon name="info" /> طلب استفسار</button>
        <button className="btn soft" type="button" onClick={() => act('rejectOffer')}><Icon name="out" /> رفض العرض</button>
      </>);
    } else if (r.stage === 6 && !r.paid) {
      const onPlan = r.payPlan === 'install';
      body = (<>
        <div className="action-hint" style={{ marginBottom: 8 }}><Icon name="card" /> الفاتورة {r.invoiceNo} — {onPlan ? `الدفعة الأولى ${execMoney(r.invoices?.[0]?.amount ?? 0)} ريال` : `الإجمالي ${execMoney(total)} ريال`}</div>
        <button className="btn" type="button" onClick={() => act('pay', { plan: onPlan ? 'install' : 'full' })}><Icon name="card" /> دفع الآن</button>
        {/* خطّة التقسيط قرار العميل، وتُفتح مرّةً واحدة — بعدها الأزرار تسدّد دفعاتها */}
        {!onPlan && <button className="btn soft" type="button" onClick={() => act('pay', { plan: 'install' })}><Icon name="card" /> تقسيط على 3 دفعات</button>}
        <button className="btn soft" type="button" onClick={() => act('inquire')}><Icon name="info" /> طلب استفسار</button>
        <button className="btn soft" type="button" onClick={() => act('rejectOffer')}><Icon name="out" /> رفض العرض</button>
      </>);
    }
  } else if (role === 'lawyer') {
    if (r.decision === 'مرفوض') {
      body = <div className="action-hint"><Icon name="info" /> رُفض هذا الطلب — لا مزيد من الإجراءات عليه.</div>;
    } else if (r.stage === 2) {
      body = (<>
        <button className="btn" type="button" onClick={() => act('accept')}><Icon name="check" /> قبول الطلب</button>
        <button className="btn soft" type="button" onClick={() => act('requestDocs')}><Icon name="upload" /> طلب مستندات</button>
        <button className="btn soft" type="button" onClick={() => act('reject')}><Icon name="out" /> رفض</button>
      </>);
    } else if (r.stage === 3) {
      body = (<>
        {/* أساس التسعير من الدراسة أمام المحامي وهو يكتب الرقم — لا أتعاب في الفراغ */}
        <div className="action-hint" style={{ marginBottom: 8 }}>
          <Icon name="info" /> {basis ? `أساس التسعير من الدراسة — ${basis}` : 'لا دراسة تنفيذٍ بعد — الأتعاب على بيانات الطلب وحدها.'}
        </div>
        {/* الخادم يرفض التسعير على ملفٍّ غير مسنَد؛ الصمت عنه كان يُعيد 422 بعد ملء النموذج */}
        {execUnassigned(r) && <div className="mtg-pend" style={{ marginBottom: 8 }}><Icon name="info" /> التسعير يلزمه إسناد الملفّ إليك — اطلب من الإدارة إسناده أولاً.</div>}
        <div className="field"><label>نموذج الأتعاب</label>
          <select className="input" value={feeMode} onChange={(e) => setFeeMode(e.target.value as ExecFeeMode)}>
            {EXEC_FEE_MODES.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </select>
        </div>
        <div className="picker-grid">
          {feeMode === 'percent'
            ? <div className="field"><label>نسبة الأتعاب من كل مبلغ محصَّل (%)</label><input className="input" inputMode="decimal" value={collectPct} onChange={(e) => setCollectPct(e.target.value)} placeholder="مثال: 10" /></div>
            : <div className="field"><label>أتعاب التنفيذ (ريال)</label><input className="input" value={fee} onChange={(e) => setFee(e.target.value)} placeholder="مثال: 6000" /></div>}
          <div className="field"><label>مدة التنفيذ</label><input className="input" value={dur} onChange={(e) => setDur(e.target.value)} placeholder="30-45 يوم" /></div>
        </div>
        {feeMode === 'percent' && <div className="action-hint" style={{ marginBottom: 8 }}><Icon name="info" /> لا مبلغ مقدَّم — تُصدَر فاتورة أتعاب بهذه النسبة مع كل مبلغ يُحصَّل.</div>}
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 8 }}>
          <button className="btn" type="button" onClick={() => act('saveFee', feeMode === 'percent'
            ? { feeMode: 'percent', feePct: parseFloat(collectPct || '0') || 0, duration: dur }
            : { feeMode: 'fixed', fee: parseInt(fee || '0', 10) || 0, duration: dur })}><Icon name="send" /> إرسال الأتعاب للإدارة</button>
          <button className="btn soft" type="button" onClick={() => act('requestDocs')}><Icon name="upload" /> طلب مستندات</button>
        </div>
      </>);
    } else if (r.stage >= 7 && !r.closed) {
      body = (<>
        <div className="field"><label>إضافة إجراء تنفيذ</label><input className="input" value={proc} onChange={(e) => setProc(e.target.value)} placeholder="مثال: تم الحجز على الحساب البنكي" /></div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button className="btn" type="button" onClick={() => {
 act('addProcedure', { title: proc }); setProc(''); 
}}><Icon name="plus" /> إضافة إجراء</button>
        </div>
      </>);
    }
  } else if (role === 'admin') {
    /* **مخرج الملفّ المرفوض — للإدارة وحدها**: الرفض (سواء بعد الدراسة في 2-3 أو برفض العميل للعرض في 5)
       لا ينقل المرحلة تلقائيّاً، فالمرفوض يبقى مفتوحاً بلا إجراءٍ ولا إغلاقٍ تلقائيّ. والشرط هنا
       يطابق حارس `ExecService::close` وحارس `CloseExecution` حرفاً بحرف. */
    const isRejectedStudy = r.decision === 'مرفوض' && (r.stage === 2 || r.stage === 3);
    const isRejectedOffer = r.stage === 5 && r.offerStatus === 'مرفوض';

    if (isRejectedStudy || isRejectedOffer) {
      body = (<>
        <div className="mtg-pend" style={{ marginBottom: 8 }}>
          <Icon name="info" />
          {isRejectedOffer
            ? 'رفض العميل عرض الخدمة — يمكن إعادة التسعير من تبويب «الأتعاب»، أو إنهاء الملف وأرشفته بقرار الإدارة.'
            : 'رُفض هذا الطلب بعد الدراسة — لا يُسعَّر ولا يُحال ولا يُسنَد. وإنهاؤه وأرشفته قرار الإدارة.'}
        </div>
        <div className="field"><label>سبب الإنهاء</label>
          <select className="input" value={rejectedReason} onChange={(e) => setRejectedReason(e.target.value)}>
            {EXEC_CLOSE_REASONS.map((reason) => <option key={reason} value={reason}>{reason}</option>)}
          </select>
        </div>
        <button className="btn soft" type="button" onClick={() => act('close', { reason: rejectedReason })}>
          <Icon name="folder" /> إنهاء الملفّ المرفوض وأرشفته
        </button>
      </>);
    } else if (r.stage < 2) {
      body = (<>
        <button className="btn" type="button" onClick={() => act('refer')}><Icon name="reply" /> إحالة لقسم التنفيذ</button>
        {/* الخادم يسمح للإدارة بطلب المستندات — الزرّ كان للمحامي والموظف فقط */}
        <button className="btn soft" type="button" onClick={() => act('requestDocs')}><Icon name="upload" /> طلب مستندات</button>
      </>);
    } else if (r.stage === 3) {
      body = (<>
        <div className="action-hint" style={{ marginBottom: 8 }}>
          <Icon name="info" /> الطلب في مرحلة تحديد الأتعاب — يمكن مراجعة المستندات أو طلب مستندات إضافية لاستكمال التسعير.
        </div>
        <button className="btn soft" type="button" onClick={() => act('requestDocs')}><Icon name="upload" /> طلب مستندات</button>
      </>);
    } else if (r.stage === 4) {
      // **النموذج النسبيّ لا مبلغ فيه يُعدَّل.** حقلُ ريالاتٍ بـ`placeholder="0"` كان يوهم
      // المعتمِد أن العرض بصفر، والخادم يتجاهله ويعتمد النسبة (`ExecService::approveFee`).
      body = r.feeMode === 'percent' ? (<>
        <div className="action-hint" style={{ marginBottom: 8 }}>
          <Icon name="info" /> نموذج الأتعاب: نسبة من المحصّل — <b>{r.collectionFeePct ?? 0}%</b> من كل مبلغ يُحصَّل، بلا مبلغ مقدَّم. لتغيير النسبة أعد التسعير من بطاقة التسعير.
        </div>
        <button className="btn" type="button" onClick={() => act('approveFee')}><Icon name="check" /> اعتماد وإرسال العرض</button>
      </>) : (<>
        <div className="action-hint" style={{ marginBottom: 8 }}><Icon name="info" /> مراجعة الأتعاب واعتمادها قبل إرسال العرض. بعد الاعتماد لا تُعدَّل إلا بصلاحية الإدارة.</div>
        <div className="field"><label>تعديل الأتعاب (اختياري)</label><input className="input" value={feeAdj} onChange={(e) => setFeeAdj(e.target.value)} placeholder={String(r.fee)} /></div>
        <button className="btn" type="button" onClick={() => act('approveFee', { fee: parseInt(feeAdj || '0', 10) || 0 })}><Icon name="check" /> اعتماد وإرسال العرض</button>
      </>);
    } else if (r.stage >= 7 && !r.closed) {
      body = (<>
        <div className="field"><label>إضافة إجراء تنفيذ</label><input className="input" value={proc} onChange={(e) => setProc(e.target.value)} /></div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button className="btn" type="button" onClick={() => {
 act('addProcedure', { title: proc }); setProc(''); 
}}><Icon name="plus" /> إضافة إجراء</button>
        </div>
      </>);
    }
  } else if (role === 'employee') {
    // بوّابة الاستقبال: طلب المستندات الناقصة ثمّ الإحالة لقسم التنفيذ (مرحلة الاستقبال فقط)
    if (r.stage <= 1) {
      body = (<>
        <div className="action-hint" style={{ marginBottom: 8 }}><Icon name="info" /> تحقّق من اكتمال المستندات، اطلب أيّ ناقص من العميل، ثمّ أحِل الطلب لقسم التنفيذ.</div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button className="btn soft" type="button" onClick={() => act('requestDocs')}><Icon name="upload" /> طلب مستندات من العميل</button>
          <button className="btn" type="button" onClick={() => act('refer')}><Icon name="reply" /> إحالة لقسم التنفيذ</button>
        </div>
      </>);
    } else {
      body = <div className="action-hint"><Icon name="info" /> أُحيل الطلب لقسم التنفيذ — متابعته الآن بيد المحامي والإدارة.</div>;
    }
  }

  if (!body) {
return null;
}

  return (
    <div className="card">
      <div className="card-h"><h3>الإجراء</h3></div>
      <div className="card-b" style={{ padding: 16 }}>
        <div className="mtg-a" style={{ flexDirection: 'column', alignItems: 'stretch', gap: 8 }}>{body}</div>
      </div>
    </div>
  );
};

// رابط طباعة عرض/فاتورة التنفيذ — PDF حقيقي عبر الخادم (ExecFlowController::offerPdf)
function offerPdfHref(r: ExecReq): string {
  return `/exec-flow/${encodeURIComponent(r.id)}/offer.pdf`;
}

// ── بطاقة الإجراء الديناميكيّة للعميل (تطابق execClientFlow) ──
const KpiRow: React.FC<{ t: React.ReactNode; v: React.ReactNode; total?: boolean }> = ({ t, v, total }) => (
  <div className="kpi-row" style={total ? { borderTop: '2px solid var(--primary)' } : undefined}>
    <span className="t">{t}</span><span className="v">{v}</span>
  </div>
);

const ClientFlowCard: React.FC<{ r: ExecReq; act: ActFn }> = ({ r, act }) => {
  const total = r.fee + r.vat;

  // رفض المكتب للطلب أصلاً (قبل مرحلة العرض) — رسالة صريحة بدل «قيد الدراسة» المضلِّلة
  if (r.decision === 'مرفوض') {
    return (
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><h3>طلب التنفيذ</h3><Badge text="تعذّر قبول الطلب" tone="b-red" /></div>
        <div className="card-b" style={{ padding: 16 }}>
          <div className="mtg-pend"><Icon name="info" /> تعذّر قبول طلبك بعد الدراسة. راجع محادثة الملف أدناه للتفاصيل أو تواصل مع المكتب.</div>
        </div>
      </div>
    );
  }

  // عرض خدمة التنفيذ — بانتظار قبول العميل
  if (r.stage === 5 && r.feeApproved && !r.paid && !['مقبول', 'مرفوض'].includes(r.offerStatus)) {
    return (
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><h3>عرض خدمة التنفيذ</h3><Badge text={r.offerStatus === 'استفسار' ? 'بانتظار الرد على استفسارك' : 'بانتظار قبولك'} tone="b-amber" /></div>
        <div className="card-b" style={{ padding: 16 }}>
          {/* **النموذج النسبيّ بلا أرقام** (قرار المالك): لا مبلغ اليوم ولا تقديرَ لما سيُحصَّل،
              فطبعُ إجماليٍّ مقدَّر يُقرأ التزاماً. النسبة وحدها هي العرض. */}
          {r.feeMode === 'percent' ? (<>
            <KpiRow t="نموذج الأتعاب" v="نسبة من المحصّل" />
            <KpiRow t="أتعاب التنفيذ" v={`${r.collectionFeePct ?? 0}% من كل مبلغ يُحصَّل`} />
            <KpiRow t={execVatLabel(r.vatRate)} v="تُضاف على كل فاتورة أتعاب" />
            <KpiRow t="مدة التنفيذ المتوقعة" v={r.duration || '—'} />
            <KpiRow total t={<b>المستحق الآن</b>} v={<b>لا مبلغ مقدَّم</b>} />
            <div className="action-hint" style={{ marginTop: 8 }}><Icon name="info" /> يُفتح ملفّ التنفيذ فور قبولك، وتصلك فاتورة أتعاب بنسبتها مع كل مبلغ يُحصَّل.</div>
          </>) : (<>
            <KpiRow t="أتعاب التنفيذ" v={`${execMoney(r.fee)} ريال`} />
            {/* نسبة الإعدادات لا 15% ثابتة — بطاقة المكتب تستعملها وكان عرض العميل يخالف فاتورته */}
            <KpiRow t={execVatLabel(r.vatRate)} v={`${execMoney(r.vat)} ريال`} />
            <KpiRow t="مدة التنفيذ المتوقعة" v={r.duration || '—'} />
            <KpiRow total t={<b>الإجمالي</b>} v={<b>{execMoney(total)} ريال</b>} />
            <div className="action-hint" style={{ marginTop: 8 }}><Icon name="info" /> عند السداد تختار: دفعة واحدة، أو تقسيطاً على ثلاث دفعات.</div>
          </>)}
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 14 }}>
            <button className="btn" type="button" onClick={() => act('acceptOffer')}><Icon name="check" /> {r.feeMode === 'percent' ? 'قبول العرض وفتح ملفّ التنفيذ' : 'قبول العرض وإصدار الفاتورة'}</button>
            <button className="btn soft" type="button" onClick={() => act('inquire')}><Icon name="info" /> استفسار</button>
            <button className="btn soft" type="button" onClick={() => act('rejectOffer')}><Icon name="out" /> رفض</button>
          </div>
        </div>
      </div>
    );
  }

  // العميل رفض العرض — بانتظار مراجعة المكتب وإعادة عرض جديد (لا تكرار لنفس الأزرار)
  if (r.stage === 5 && r.offerStatus === 'مرفوض') {
    return (
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><h3>عرض خدمة التنفيذ</h3><Badge text="رفضتَ هذا العرض" tone="b-red" /></div>
        <div className="card-b" style={{ padding: 16 }}>
          <div className="mtg-pend"><Icon name="info" /> سيتواصل معك المكتب لمراجعة العرض. يمكنك متابعة الردّ من محادثة الملف أدناه.</div>
        </div>
      </div>
    );
  }

  // فاتورة التنفيذ — بانتظار السداد
  if (r.stage === 6 && !r.paid) {
    return (
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><h3>فاتورة التنفيذ</h3><Badge text="بانتظار السداد" tone="b-amber" /></div>
        <div className="card-b" style={{ padding: 16 }}>
          <KpiRow t="رقم الفاتورة" v={r.invoiceNo || '—'} />
          {r.payPlan === 'install' ? (<>
            <KpiRow t="خطة السداد" v={`${r.installmentsTotal || 3} دفعات — يُفتح الملفّ بالدفعة الأولى`} />
            <KpiRow total t={<b>الدفعة الأولى</b>} v={<b>{execMoney(r.invoices?.[0]?.amount ?? 0)} ريال</b>} />
            <button className="btn block" style={{ marginTop: 14 }} type="button" onClick={() => act('pay', { plan: 'install' })}>
              <Icon name="card" /> سداد الدفعة الأولى وفتح ملف التنفيذ
            </button>
          </>) : (<>
            <KpiRow total t={<b>الإجمالي المستحق</b>} v={<b>{execMoney(total)} ريال</b>} />
            <button className="btn block" style={{ marginTop: 14 }} type="button" onClick={() => act('pay', { plan: 'full' })}>
              <Icon name="card" /> سداد الفاتورة وفتح ملف التنفيذ
            </button>
            {/* التقسيط خيارٌ حقيقيّ خلفه ثلاث فواتير — لا وعدَ في قائمةٍ لا يقرؤها كود */}
            <button className="btn soft block" style={{ marginTop: 8 }} type="button" onClick={() => act('pay', { plan: 'install' })}>
              <Icon name="card" /> تقسيط على 3 دفعات
            </button>
          </>)}
        </div>
      </div>
    );
  }

  // بعد فتح الملف — سير إجراءات التنفيذ
  if (r.stage >= 7) {
    return (
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><h3>سير إجراءات التنفيذ</h3><span className="sub">{r.procedures.length}</span></div>
        <div className="card-b">
          {r.procedures.length ? r.procedures.map((pr, i) => (
            <div className="item" key={i}>
              <div className="iico"><Icon name="check" /></div>
              <div className="imeta"><b>{pr.a}</b><span>{pr.t}</span></div>
              {pr.status && <div className="iact"><Badge text={pr.status} tone={procTone(pr.status)} /></div>}
            </div>
          )) : <div className="empty"><Icon name="exec" /><b>فُتح الملف — بانتظار أول إجراء</b></div>}
        </div>
      </div>
    );
  }

  // المراحل المبكّرة — ملاحظة
  return (
    <div className="card" style={{ marginBottom: 14 }}>
      <div className="card-b" style={{ padding: 16 }}>
        <div className="mtg-pend"><Icon name="info" />
          {r.stage <= 1
            ? ' طلبك قيد الدراسة الأوليّة ومراجعة المستندات. سنوافيك بأي نواقص أو بالعرض فور جاهزيته.'
            : ' طلبك قيد الدراسة وتحديد الأتعاب لدى القسم. سيظهر لك العرض هنا فور اعتماده.'}
        </div>
      </div>
    </div>
  );
};

// ── لوحة المستندات المطلوبة من العميل (تطابق exDocPanel) ──
const DocsPanel: React.FC<{ execId: string; docs: ExecDoc[] }> = ({ execId, docs }) => {
  const toast = useToast();
  const fileRefs = useRef<Record<number, HTMLInputElement | null>>({});

  if (!docs.length) {
return null;
}

  const done = docs.filter((d) => d.status === 'مقبول' || d.status === 'مرفوع').length;

  const pick = (id: number) => fileRefs.current[id]?.click();
  const upload = (id: number, e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = '';

    if (!file) {
return;
}

    router.post(`/exec-flow/${encodeURIComponent(execId)}/documents/${id}`, { file }, {
      forceFormData: true, preserveScroll: true,
      onError: (errs) => toast(Object.values(errs)[0] ?? 'تعذّر رفع المستند'),
    });
  };

  return (
    <div className="card" style={{ marginBottom: 14 }}>
      <div className="card-h"><h3>مستندات مطلوبة منك</h3><span className="sub">{done}/{docs.length}</span></div>
      <div className="card-b">
        {/* حدُّ هذا الرفع أضيق من إرفاق المحادثة، وكان مسكوتاً عنه حتى يردّ الخادم الملفّ بعد رفعه */}
        <div className="action-hint" style={{ margin: '10px 14px 0' }}><Icon name="info" /> {EXEC_REQ_DOC_HINT}</div>
        {docs.map((d) => (
          <div className="item" key={d.id}>
            <div className="iico"><Icon name="file" /></div>
            <div className="imeta">
              <b>{d.label}</b>
              {d.fileName && <span>{d.canDownload === false ? d.fileName : <a href={`/exec-flow/${encodeURIComponent(execId)}/documents/${d.id}/download`} target="_blank" rel="noopener noreferrer">{d.fileName}</a>}{d.docType ? ` · ${d.docType}` : ''}</span>}
              {d.summary && <span style={{ display: 'block', marginTop: 3, fontSize: 11.5, color: 'var(--muted)' }}>{d.summary}</span>}
            </div>
            <div className="iact" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Badge text={d.status} tone={d.tone} />
              {d.canUpload && (
                <>
                  <button className="btn soft sm" type="button" onClick={() => pick(d.id)}><Icon name="upload" /> رفع المستند</button>
                  <input ref={(el) => {
 fileRefs.current[d.id] = el; 
}} type="file" accept={EXEC_REQ_DOC_ACCEPT} hidden onChange={(e) => upload(d.id, e)} />
                </>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

// ── تفاصيل العميل الموحّدة (تطابق execClientDetail): بيانات + إجراء ديناميكيّ + مستندات + محادثة ──
/**
 * **فواتير ملفّ التنفيذ** — الموضع الوحيد الذي تُسدَّد منه الدفعتان الثانية والثالثة
 * وفواتيرُ الأتعاب عن التحصيل، وكلُّها تستحقّ **بعد** المرحلة 6 حيث لا زرّ سدادٍ آخر.
 * والمسار مسار الفواتير العامّ: ردُّه يطابق الفاتورة المعنيّة بمرجعها.
 */
const ExecInvoicesCard: React.FC<{ invoices: ExecInvoice[]; canPay: boolean; act: ActFn }> = ({ invoices, canPay, act }) => {
  if (!invoices.length) {
return null;
}

  const due = invoices.filter((v) => !v.paid).length;

  return (
    <div className="card" style={{ marginBottom: 12 }}>
      <div className="card-h"><h3>فواتير ملفّ التنفيذ</h3>{due ? <Badge text={`${due} بانتظار السداد`} tone="b-amber" /> : <Badge text="مسدَّدة بالكامل" tone="b-green" />}</div>
      <div className="card-b">
        {invoices.map((v) => (
          <div className="item" key={v.no}>
            <div className="iico"><Icon name="card" /></div>
            <div className="imeta">
              <b>{v.installmentNo ? `الدفعة ${v.installmentNo} — ${v.no}` : v.no}</b>
              <span>{v.desc} · {execMoney(v.amount)} ريال · {v.due}</span>
            </div>
            <div className="iact" style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
              <Badge text={v.status} tone={v.tone} />
              {canPay && !v.paid && <button className="btn soft" type="button" onClick={() => act('payInvoice', { no: v.no })}><Icon name="card" /> سداد</button>}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

const ClientExecDetail: React.FC<{ r: ExecReq; onBack: () => void; act: ActFn }> = ({ r, onBack, act }) => {
  const total = r.fee + r.vat;
  const sendMsg = (text: string) => axios.post(`/exec-flow/${encodeURIComponent(r.id)}/messages`, { body: text });
  // رفع مستند فعلي من محادثة التنفيذ — يظهر رسالة في المحادثة ويُدرَج ضمن مستندات الملف
  const attachDoc = (file?: File) => {
    if (!file) {
return;
}

    const fd = new FormData();
    fd.append('file', file);

    return axios.post(`/exec-flow/${encodeURIComponent(r.id)}/attach`, fd).then(() => router.reload({ only: ['execs'] }));
  };

  return (
    <>
      <div style={{ marginBottom: 14 }}>
        <button className="btn soft sm" type="button" onClick={onBack}><Icon name="reply" /> رجوع لقائمة التنفيذ</button>
      </div>

      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><h3>طلب التنفيذ {r.id}</h3><Badge text={EXEC_FLOW[r.stage]} tone={execTone(r.stage)} /></div>
        <div className="card-b" style={{ padding: 16 }}>
          <FlowLine steps={EXEC_FLOW} cur={r.stage} />
        </div>
        <div className="card-b" style={{ padding: 16 }}>
          <KpiRow t="الموضوع" v={r.subject} />
          <KpiRow t="نوع السند" v={r.sanad || '—'} />
          <KpiRow t="قيمة المطالبة" v={`${execMoney(r.amount)} ريال`} />
          {r.defendant && <KpiRow t="المنفَّذ ضده" v={r.defendant} />}
          {r.execNo && <KpiRow t="رقم ملف التنفيذ" v={r.execNo} />}
        </div>
      </div>

      {(() => {
        // شاشة العميل: القالب الاحتياطيّ كان يُعرض هنا تحت «الملخّص الذكيّ» فيبدو
        // تحليلاً وقع وهو لم يفحص مستنداً. وبسياسة المكتب لا يُعرَض للعميل أصلاً.
        // محجوبٌ بانتظار اعتماد محامٍ: الخادم يُفرّغ الحقول، والصمت وحده
        // يترك صاحب الطلب يظنّ ملفّه مهمَلاً — فيُقال له ما يقع فعلاً.
        if (r.aiPending) {
          return (
            <div className="card" style={{ marginBottom: 14 }}>
              <div className="card-b" style={{ padding: '14px 16px' }}>
                <div className="mtg-pend">
                  <Icon name="info" /> دراسة طلبك قيد مراجعة المستشار، وستصلك فور اعتمادها.
                </div>
              </div>
            </div>
          );
        }

        const ai = execAiPresentation(r, true);

        if (!ai) return null;

        return (
          <div className="card" style={{ marginBottom: 14, borderInlineStart: `3px solid ${ai.accent}` }}>
            <div className="card-h"><h3>{ai.title}</h3></div>
            <div className="card-b" style={{ padding: '14px 16px' }}>
              {ai.notice && <div className="mtg-pend" style={{ marginBottom: 8 }}><Icon name="info" /> {ai.notice}</div>}
              <p style={{ margin: '0 0 8px' }}>{r.aiSummary}</p>
              {r.aiMissing.length > 0 && <div className="mtg-pend"><Icon name="info" /> نواقص مطلوبة: {r.aiMissing.join(' · ')}</div>}
            </div>
          </div>
        );
      })()}

      <ClientFlowCard r={r} act={act} />

      {/* **فواتير الملفّ عند صاحبها.** الدفعتان 2 و3 تستحقّان في المرحلتين 7 و8، وفواتير
          الأتعاب عن التحصيل في 8 — وكلّها بعد أن يختفي زرّ السداد في المرحلة 6، فلا موضع
          آخر يسدّدها العميل منه في هذه الشاشة. */}
      <ExecInvoicesCard invoices={r.invoices ?? []} canPay={!r.closed} act={act} />

      {/* الشرط يطابق حارس الخادم (`ExecFlowController::offerPdf`): العرض النسبيّ بلا مبلغ
          وله ما يُطبع — نسبتُه ومدّته ونموذجه. و`fee > 0` وحدها كانت تخفيه عنه. */}
      {r.feeApproved && (r.fee > 0 || r.feeMode === 'percent') && (
        <div style={{ margin: '0 0 14px' }}>
          <a className="btn soft sm" href={offerPdfHref(r)}><Icon name="download" /> طباعة العرض/الفاتورة (PDF)</a>
        </div>
      )}

      {/* مسار ناجز كما يراه صاحب الملفّ: رقم الطلب والمحكمة والدائرة ومهلة الوفاء والمحصَّل — للاطّلاع */}
      {r.najiz && (
        <ExecNajizCard
          base={`/exec-flow/${encodeURIComponent(r.id)}`}
          najiz={r.najiz}
          stage={r.stage}
          canAct={false}
        />
      )}

      <DocsPanel execId={r.id} docs={r.docItems} />

      <div className="card">
        <div className="card-h"><h3>محادثة ملف التنفيذ</h3><span className="sub">{r.messages.length} رسالة</span></div>
        <div className="card-b" style={{ padding: 16 }}>
          <ChatThread
            initial={r.messages}
            channel={r.channel}
            onSend={sendMsg}
            onAttach={attachDoc}
            accept={EXEC_DOC_ACCEPT}
            hint={EXEC_DOC_HINT}
            onStatus={() => router.reload({ only: ['execs'] })}
            readOnly={r.closed}
            placeholder="اكتب رسالتك للمكتب…"
          />
        </div>
      </div>
    </>
  );
};

// ── دراسة التنفيذ (قرار المالك 2026-09-12) ──
// مخرَجٌ موسَّع يسبق التسعير: جاهزيّة السند وصعوبته وعدد إجراءاته ومدّته ومؤشّرات تحصيله ومخاطره.
// للمكتب وحده — والعميل يبقى على مسار `execAiPresentation(r, true)` الذي يحجب غير المعتمد.

// قائمة وسوم داخل بطاقة الدراسة — تُخفى كلّها حين لا عناصر، فلا عناوين فوق فراغ
const StudyChips: React.FC<{ label: string; items?: string[] }> = ({ label, items }) => {
  if (!items?.length) {
    return null;
  }

  return (
    <div style={{ marginTop: 8 }}>
      <div className="cl" style={{ marginBottom: 4 }}>{label}</div>
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
        {items.map((t) => <span key={t} className="chip">{t}</span>)}
      </div>
    </div>
  );
};

const ExecStudyCard: React.FC<{ r: ExecReq }> = ({ r }) => {
  const s = r.study;

  if (!s) {
    // الدراسة تجري في الخلفيّة ولا تحجز الملفّ (قرار المالك): غيابها حالةٌ هادئة لا خطأ،
    // وتُقال ما دام الملفّ في مراحل الاستقبال/الدراسة/التسعير — وبعدها لا معنى لانتظارها.
    if (r.stage > 4) {
      return null;
    }

    // **التعذّر ليس انتظاراً.** حين يُسجَّل مخرجٌ احتياطيّ (`fallback`) تكون المحاولة قد
    // فشلت ولم يُفحص مستند، ولا قالب يملأ الفراغ — فتُعاد الجدولة وتقول البطاقة ما وقع
    // بدل «قيد الإعداد» أبديّة يقرؤها المسعِّر انتظاراً قريباً.
    const failed = r.aiSource === 'fallback';

    return (
      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h">
          <h3>دراسة التنفيذ</h3>
          <Badge text={failed ? 'تعذّرت — أُعيدت جدولتها' : 'قيد الإعداد'} tone={failed ? 'b-amber' : 'b-grey'} />
        </div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          <div className="mtg-pend">
            <Icon name="info" />{' '}
            {failed
              ? 'تعذّرت الدراسة الذكيّة ولم يُفحص أيّ مستند — أُعيدت جدولة المحاولة تلقائياً، ويلزم فحص المستندات يدوياً إن تأخّرت.'
              : 'الدراسة قيد الإعداد — تجري في الخلفيّة ولا توقف سير الملفّ.'}
          </div>
        </div>
      </div>
    );
  }

  const count = Number(s.expectedProceduresCount ?? 0);

  return (
    <div className="card" style={{ marginBottom: 12, borderInlineStart: '3px solid var(--cyan)' }}>
      <div className="card-h">
        <h3>دراسة التنفيذ</h3>
        {s.pending
          ? <Badge text="بانتظار اعتماد المستشار" tone="b-amber" />
          : s.approved ? <Badge text="معتمدة" tone="b-green" /> : null}
      </div>
      {(s.pending || s.summary) && (
        <div className="card-b" style={{ padding: '14px 16px 0' }}>
          {/* المكتب يقرأها قبل الاعتماد، والعميل لا — الخادم يفرض ذلك، والشاشة لا توهم بخلافه */}
          {s.pending && <div className="mtg-pend" style={{ marginBottom: 8 }}><Icon name="info" /> دراسةٌ أُنتجت ولم يعتمدها المستشار بعد — للمكتب اطّلاعاً، ولا تصل العميل قبل الاعتماد.</div>}
          {s.summary && <p style={{ margin: '0 0 4px' }}>{s.summary}</p>}
        </div>
      )}
      <div className="card-b">
        <CellRow cells={[['جاهزية السند', s.readiness || '—'], ['درجة الصعوبة', s.difficulty || '—']]} />
        <CellRow cells={[['الإجراءات المتوقّعة', count > 0 ? String(count) : '—'], ['المدة المتوقعة', s.durationEstimate || '—']]} />
      </div>
      <div className="card-b" style={{ padding: '0 16px 14px' }}>
        <StudyChips label="الإجراءات المقترحة" items={s.procedures} />
        <StudyChips label="مؤشّرات التحصيل" items={s.recovery} />
        <StudyChips label="المخاطر" items={s.risks} />
        <StudyChips label="مستندات ناقصة" items={s.missing} />
      </div>
    </div>
  );
};

// ── إسناد ملفّ التنفيذ إلى محامٍ (قرار المالك 2026-09-12) ──
// ملفٌّ بلا محامٍ حالةٌ يصلحها المكتب لا يتعايش معها: الخادم يردّ التسعير عليه.
// يُعرض لمن يملك `canAssign` وحده (إدارةٌ دائماً، وموظّفٌ بصلاحيّة «إجراءات المحكمة والجلسات»).
const ExecAssignCard: React.FC<{ r: ExecReq; lawyers: ExecLawyerOpt[]; act: ActFn }> = ({ r, lawyers, act }) => {
  const unassigned = execUnassigned(r);
  const [sel, setSel] = useState<string>(r.lawyerId ? String(r.lawyerId) : '');

  return (
    <div className="card" style={{ marginBottom: 12, borderInlineStart: unassigned ? '3px solid var(--amber)' : undefined }}>
      <div className="card-h">
        <h3>{unassigned ? 'إسناد إلى محامٍ' : 'إعادة الإسناد'}</h3>
        {unassigned ? <Badge text="ملفّ بلا محامٍ" tone="b-amber" /> : <Badge text={r.lawyer || '—'} tone="b-blue" />}
      </div>
      <div className="card-b" style={{ padding: 16 }}>
        {unassigned && <div className="mtg-pend" style={{ marginBottom: 8 }}><Icon name="info" /> لا محامي لهذا الملفّ — التسعير موقوفٌ حتى يُسنَد.</div>}
        {lawyers.length ? (
          <div style={{ display: 'flex', gap: 6 }}>
            <select className="input" value={sel} onChange={(e) => setSel(e.target.value)} style={{ flex: 1 }}>
              <option value="">— اختر محامياً —</option>
              {lawyers.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
            </select>
            <button className="btn sm" type="button" disabled={!sel} onClick={() => act('assignLawyer', { lawyer_id: Number(sel) })}>
              <Icon name="check" /> {unassigned ? 'إسناد' : 'إعادة الإسناد'}
            </button>
          </div>
        ) : <div className="action-hint"><Icon name="info" /> لم تصل قائمة المحامين — راجع الإدارة.</div>}
      </div>
    </div>
  );
};

// ── بطاقة تسعير الإدارة (تطابق execfeeset): ثابت/نسبة/محصّل + سداد + مدّة → اعتماد وإرسال العرض ──
const PricingCard: React.FC<{ r: ExecReq; act: ActFn }> = ({ r, act }) => {
  const [basisMode, setBasisMode] = useState<'fixed' | 'pct'>('fixed');
  const [fixed, setFixed] = useState('');
  const [pct, setPct] = useState('');
  const [dur, setDur] = useState('');
  // **نموذج الأتعاب** — قرارٌ له محرّك خلفه، غير «أساس التسعير» أعلاه الذي يحسب مبلغاً في المتصفّح
  // تُهيَّأ من الملفّ لا من الفراغ: عرضٌ سعّره المحامي نسبيّاً ثمّ فتحته الإدارة لإعادة
  // التسعير كان يعود إلى «مبلغ ثابت» صامتاً، و`writeFee` يمسح النسبة المخزَّنة معه.
  const [feeMode, setFeeMode] = useState<ExecFeeMode>(r.feeMode === 'percent' ? 'percent' : 'fixed');
  const [collectPct, setCollectPct] = useState(r.collectionFeePct ? String(r.collectionFeePct) : '');

  const percent = feeMode === 'percent';
  const collectPctNum = parseFloat(collectPct || '0') || 0;
  const fee = basisMode === 'fixed' ? (parseInt(fixed || '0', 10) || 0) : Math.round((r.amount * (parseFloat(pct || '0') || 0)) / 100);
  // نسبة الإدارة لا 15% ثابتة — الخادم يحسب الضريبة بها (Setting::vatOn)، فكان المعروض يخالف الفاتورة
  const vatRate = r.vatRate ?? 15;
  const vat = Math.round((fee * vatRate) / 100);
  const basis = execStudyBasis(r.study);
  const ready = percent ? collectPctNum >= 0.01 && collectPctNum <= 50 : fee >= 1;
  const submit = () => {
 if (ready) {
act('setFee', percent
      ? { feeMode: 'percent', feePct: collectPctNum, duration: dur }
      : { feeMode: 'fixed', fee, duration: dur });
} 
};

  return (
    <div className="card" style={{ marginBottom: 12 }}>
      <div className="card-h"><h3>تحديد أتعاب التنفيذ</h3><Badge text="بانتظار التحديد" tone="b-amber" /></div>
      <div className="card-b" style={{ padding: 16 }}>
        <KpiRow t="قيمة المطالبة" v={`${execMoney(r.amount)} ريال`} />
        {/* الأتعاب تُحدَّد مقابل أساسٍ من الدراسة؛ وحين لا دراسة يُقال ذلك صراحةً لا تُعرض أصفار */}
        <div className="action-hint" style={{ margin: '4px 0 10px' }}>
          <Icon name="info" /> {basis ? `أساس التسعير من الدراسة — ${basis}` : 'لا دراسة تنفيذٍ بعد — قدّر الأتعاب على بيانات الطلب، وستظهر أسس الدراسة فور جاهزيتها.'}
        </div>
        <div className="field"><label>نموذج الأتعاب</label>
          <select className="input" value={feeMode} onChange={(e) => setFeeMode(e.target.value as ExecFeeMode)}>
            {EXEC_FEE_MODES.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </select>
        </div>
        {percent ? (<>
          <div className="picker-grid">
            <div className="field"><label>نسبة الأتعاب من كل مبلغ محصَّل (%)</label><input className="input" inputMode="decimal" value={collectPct} onChange={(e) => setCollectPct(e.target.value)} placeholder="مثال: 10" /></div>
            <div className="field"><label>مدة التنفيذ المتوقعة</label><input className="input" value={dur} onChange={(e) => setDur(e.target.value)} placeholder="30-45 يوم" /></div>
          </div>
          <div className="action-hint" style={{ margin: '8px 0' }}>
            <Icon name="info" /> لا مبلغ مقدَّم على العميل — تُصدَر فاتورة أتعاب بهذه النسبة مع كل مبلغ يُحصَّل، ويُفتح ملفّ التنفيذ فور قبول العرض.
          </div>
        </>) : (<>
          {/* **أساس التسعير حسابيّ لا نموذج سداد**: كلا الخيارين ينتهي إلى مبلغٍ ثابت بالريال */}
          <div className="field"><label>أساس احتساب المبلغ</label>
            <select className="input" value={basisMode} onChange={(e) => setBasisMode(e.target.value as 'fixed' | 'pct')}>
              <option value="fixed">مبلغ ثابت</option>
              <option value="pct">نسبة من قيمة المطالبة</option>
            </select>
          </div>
          {basisMode === 'fixed'
            ? <div className="field"><label>أتعاب التنفيذ (ريال)</label><input className="input" value={fixed} onChange={(e) => setFixed(e.target.value)} placeholder="مثال: 6000" /></div>
            : <div className="field"><label>النسبة من قيمة المطالبة (%)</label><input className="input" value={pct} onChange={(e) => setPct(e.target.value)} placeholder="مثال: 10" /></div>}
          <div className="field"><label>مدة التنفيذ المتوقعة</label><input className="input" value={dur} onChange={(e) => setDur(e.target.value)} placeholder="30-45 يوم" /></div>
          <KpiRow total t={<b>الإجمالي بعد الضريبة ({vatRate}%)</b>} v={<b>{execMoney(fee + vat)} ريال</b>} />
          <div className="action-hint" style={{ margin: '8px 0' }}><Icon name="info" /> يختار العميل عند السداد: كاملاً أو على ثلاث دفعات.</div>
        </>)}
        <div className="action-hint" style={{ margin: '8px 0' }}><Icon name="info" /> تحديد الأتعاب واعتمادها من صلاحيات الإدارة العليا؛ بعد الاعتماد يُرسَل العرض للعميل.</div>
        <button className="btn block" type="button" disabled={!ready} onClick={submit}><Icon name="check" /> اعتماد الأتعاب وإرسال العرض للعميل</button>
      </div>
    </div>
  );
};

// ── مراجعة المكتب لمستندات العميل المرفوعة (اعتماد/إعادة) — تطابق exDocPanel لغير العميل ──
const ExecDocReview: React.FC<{ execId: string; docs: ExecDoc[]; onReview: (docId: number, decision: 'accept' | 'reject') => void }> = ({ execId, docs, onReview }) => {
  if (!docs.length) {
return null;
}

  return (
    <div className="card" style={{ marginBottom: 12 }}>
      <div className="card-h"><h3>مستندات العميل</h3><span className="sub">{docs.length}</span></div>
      <div className="card-b">
        {docs.map((d) => (
          <div className="item" key={d.id}>
            <div className="iico"><Icon name="file" /></div>
            <div className="imeta">
              <b>{d.label}</b>
              {d.fileName && <span>{d.canDownload === false ? d.fileName : <a href={`/exec-flow/${encodeURIComponent(execId)}/documents/${d.id}/download`} target="_blank" rel="noopener noreferrer">{d.fileName}</a>}{d.docType ? ` · ${d.docType}` : ''}</span>}
              {d.summary && <span style={{ display: 'block', marginTop: 3, fontSize: 11.5, color: 'var(--muted)' }}>{d.summary}</span>}
            </div>
            <div className="iact" style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              <Badge text={d.status} tone={d.tone} />
              {d.status === 'مرفوع' && (
                <>
                  <button className="btn sm" type="button" onClick={() => onReview(d.id, 'accept')}><Icon name="check" /> اعتماد</button>
                  <button className="btn soft sm" type="button" onClick={() => onReview(d.id, 'reject')}><Icon name="reply" /> إعادة</button>
                </>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

// ── التفاصيل (تطابق execDetail، بنفس ترتيب 1970) — للأدوار غير العميل ──
interface ExecDetailProps {
  role: Role;
  r: ExecReq;
  lawyers: ExecLawyerOpt[];
  onBack: () => void;
  act: ActFn;
  initialTab?: string | null;
  onTabChange?: (tab: 'overview' | 'docs' | 'finance' | 'chat') => void;
}

const EXEC_DETAIL_TABS = ['overview', 'docs', 'finance', 'chat'] as const;
type ExecDetailTab = (typeof EXEC_DETAIL_TABS)[number];
const isExecDetailTab = (t: unknown): t is ExecDetailTab => typeof t === 'string' && (EXEC_DETAIL_TABS as readonly string[]).includes(t);

const ExecDetail: React.FC<ExecDetailProps> = ({ role, r, lawyers, onBack, act, initialTab, onTabChange }) => {
  const [activeTab, setActiveTabState] = useState<ExecDetailTab>(() => {
    if (isExecDetailTab(initialTab)) return initialTab;
    if (typeof window !== 'undefined') {
      const sp = new URLSearchParams(window.location.search).get('tab');
      if (isExecDetailTab(sp)) return sp;
    }
    return 'overview';
  });

  useEffect(() => {
    if (isExecDetailTab(initialTab) && initialTab !== activeTab) {
      setActiveTabState(initialTab);
    }
  }, [initialTab]);

  const setActiveTab = (tab: ExecDetailTab) => {
    setActiveTabState(tab);
    onTabChange?.(tab);
  };
  const total = r.fee + r.vat;
  // خطوات ناجز صارت للموظّف الحامل «إجراءات المحكمة والجلسات» (قرار المالك 2026-09-12)؛
  // وكان الشرط `role !== 'client'` يعرض لموظّفٍ بلا صلاحيّة أزراراً يردّها الخادم بـ403.
  const canCourt = useCan()('إجراءات المحكمة والجلسات');
  const canNajiz = (role === 'employee' ? canCourt : role !== 'client') && !r.closed;
  // الإنهاء بقي للمحامي والإدارة وحدهما — لا يُعرض للموظّف أصلاً
  const canCloseFile = (role === 'lawyer' || role === 'admin') && !r.closed;
  const sendMsg = (text: string) => axios.post(`/exec-flow/${encodeURIComponent(r.id)}/messages`, { body: text });
  const reviewDoc = (docId: number, decision: 'accept' | 'reject') => {
    router.post(`/exec-flow/${encodeURIComponent(r.id)}/documents/${docId}/review`, { decision }, { preserveScroll: true });
  };

  const next = nextAction(role, r, canCourt);
  const dueInvoices = r.invoices?.filter((v) => !v.paid).length ?? 0;

  return (
    <>
      {/* شريط الإجراءات والترويسة التنفيذية المتطورة */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12, marginBottom: 14 }}>
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
            <button className="btn soft sm" type="button" onClick={onBack} title="رجوع للقائمة">
              <Icon name="reply" /> رجوع
            </button>
            <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)' }}>طلب تنفيذ #{r.id}</span>
            <Badge text={EXEC_FLOW[r.stage]} tone={execTone(r.stage)} />
            {r.closed && <Badge text="مغلق" tone="b-grey" />}
            {r.execNo && <span className="chip" style={{ fontSize: 11.5 }}>رقم التنفيذ: {r.execNo}</span>}
          </div>
          <h2 style={{ margin: 0, fontSize: 19, fontWeight: 800, color: 'var(--ink)' }}>{r.subject}</h2>
          <div style={{ fontSize: 12.5, color: 'var(--muted)', marginTop: 4 }}>
            طالب التنفيذ: <b style={{ color: 'var(--ink)' }}>{r.client}</b> · السند: <b style={{ color: 'var(--ink)' }}>{r.sanad || '—'}</b> · المطالبة: <b style={{ color: 'var(--primary)' }}>{execMoney(r.amount)} ريال</b>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          {r.feeApproved && (r.fee > 0 || r.feeMode === 'percent') && (
            <a className="btn soft sm" href={offerPdfHref(r)} target="_blank" rel="noopener noreferrer">
              <Icon name="download" /> طباعة العرض (PDF)
            </a>
          )}
          <button className="btn soft sm" type="button" onClick={onBack}>
            <Icon name="out" /> إغلاق
          </button>
        </div>
      </div>

      {/* خط سير الإجراءات العام */}
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-b" style={{ padding: '14px 16px' }}><FlowLine steps={EXEC_FLOW} cur={r.stage} /></div>
      </div>

      {/* إشعار الإجراء التالي الذكي إن وجد */}
      {next && (
        <div className="action-hint" style={{ marginBottom: 14, padding: '10px 14px', background: 'linear-gradient(135deg, rgba(14,92,156,.06), rgba(17,160,200,.08))', borderColor: 'rgba(17,160,200,.3)' }}>
          <Icon name="info" />
          <div style={{ flex: 1, fontSize: 12.5 }}>
            <b style={{ color: 'var(--primary)', marginInlineEnd: 6 }}>الإجراء التالي المطلوب:</b>
            <span style={{ color: 'var(--ink)' }}>{next}</span>
          </div>
        </div>
      )}

      {/* بطاقة الإجراء المباشر: تظهر فور طلب أي قرار (إحالة، تسعير، اعتماد) */}
      <ActionCard role={role} r={r} act={act} />

      {/* مساحة العمل التنفيذية (Grid ثنائي الأعمدة) */}
      <div className="exec-workspace-grid">
        {/* العمود الرئيسي (محتوى التبويبات) */}
        <div style={{ minWidth: 0 }}>
          {/* شريط التبويبات الفوري */}
          <div className="exec-tabs">
            <button
              type="button"
              className={`exec-tab ${activeTab === 'overview' ? 'active' : ''}`}
              onClick={() => setActiveTab('overview')}
            >
              <Icon name="exec" />
              <span>نظرة عامة وناجز</span>
              {r.procedures.length > 0 && <span className="count">{r.procedures.length}</span>}
            </button>
            <button
              type="button"
              className={`exec-tab ${activeTab === 'docs' ? 'active' : ''}`}
              onClick={() => setActiveTab('docs')}
            >
              <Icon name="file" />
              <span>المستندات والدراسة</span>
              {r.docs.length > 0 && <span className="count">{r.docs.length}</span>}
            </button>
            <button
              type="button"
              className={`exec-tab ${activeTab === 'finance' ? 'active' : ''}`}
              onClick={() => setActiveTab('finance')}
            >
              <Icon name="card" />
              <span>الأتعاب والفواتير</span>
              {dueInvoices > 0 && <span className="count" style={{ background: 'var(--amber)', color: '#fff' }}>{dueInvoices}</span>}
            </button>
            {role !== 'client' && (
              <button
                type="button"
                className={`exec-tab ${activeTab === 'chat' ? 'active' : ''}`}
                onClick={() => setActiveTab('chat')}
              >
                <Icon name="chat" />
                <span>المحادثة</span>
                {r.messages.length > 0 && <span className="count">{r.messages.length}</span>}
              </button>
            )}
          </div>

          {/* تبويب: نظرة عامة وإجراءات ناجز */}
          {activeTab === 'overview' && (
            <>
              {/* مسار ناجز (المرحلتان 7 و8): العميل يطّلع على رقم طلبه ومحكمته ومهلته، والمكتب يسجّل الخطوات */}
              {r.najiz && (
                <ExecNajizCard
                  base={`/exec-flow/${encodeURIComponent(r.id)}`}
                  najiz={r.najiz}
                  stage={r.stage}
                  canAct={canNajiz}
                  canClose={canCloseFile}
                />
              )}

              {r.stage >= 7 ? (
                <div className="card" style={{ marginBottom: 12 }}>
                  <div className="card-h"><h3>سجل إجراءات التنفيذ</h3><span className="sub">{r.procedures.length}</span></div>
                  <div className="card-b">
                    {r.procedures.length ? r.procedures.map((pr, i) => (
                      <div className="item" key={i}>
                        <div className="iico"><Icon name="check" /></div>
                        <div className="imeta"><b>{pr.a}</b><span>{pr.t}</span></div>
                        {pr.status && <div className="iact"><Badge text={pr.status} tone={procTone(pr.status)} /></div>}
                      </div>
                    )) : <div className="empty"><Icon name="doc" /><b>لا إجراءات بعد</b></div>}
                  </div>
                </div>
              ) : (
                <div className="card" style={{ marginBottom: 12 }}>
                  <div className="card-h"><h3>موقف طلب التنفيذ</h3><Badge text={EXEC_FLOW[r.stage]} tone={execTone(r.stage)} /></div>
                  <div className="card-b" style={{ padding: 16 }}>
                    <div className="mtg-pend">
                      <Icon name="info" />
                      {r.stage <= 1
                        ? 'الطلب قيد الاستقبال ومراجعة السند والمستندات قبل الإحالة لقسم التنفيذ.'
                        : r.stage <= 3
                        ? 'الطلب محال لقسم التنفيذ وجارٍ فحص السند وتحديد الجاهزية وإعداد الدراسة.'
                        : r.stage <= 5
                        ? 'تم إعداد مسودة الأتعاب وبانتظار الاعتماد وقبول العميل.'
                        : 'بانتظار سداد الفاتورة لبدء قيد الملف في محكمة التنفيذ عبر ناجز.'}
                    </div>
                  </div>
                </div>
              )}
            </>
          )}

          {/* تبويب: المستندات والدراسة */}
          {activeTab === 'docs' && (
            <>
              {(() => {
                const ai = execAiPresentation(r);
                if (!ai) return null;

                return (
                  <div className="card" style={{ marginBottom: 12, borderInlineStart: `3px solid ${ai.accent}` }}>
                    <div className="card-h"><h3>{ai.title}</h3></div>
                    <div className="card-b" style={{ padding: '14px 16px' }}>
                      {ai.notice && <div className="mtg-pend" style={{ marginBottom: 8 }}><Icon name="info" /> {ai.notice}</div>}
                      <p style={{ margin: '0 0 8px' }}>{r.aiSummary}</p>
                      {r.aiMissing.length > 0 && <div className="mtg-pend"><Icon name="info" /> نواقص مطلوبة: {r.aiMissing.join(' · ')}</div>}
                      {r.aiProcedures.length > 0 && (
                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 6 }}>
                          {r.aiProcedures.map((p) => <span key={p} className="chip">{p}</span>)}
                        </div>
                      )}
                    </div>
                  </div>
                );
              })()}

              {/* دراسة التنفيذ فوق التسعير: أدوار المكتب وحدها — ولا تُعرض للعميل بحال */}
              {role !== 'client' && <ExecStudyCard r={r} />}

              <div className="card" style={{ marginBottom: 12 }}>
                <div className="card-h"><h3>المستندات المرفقة</h3><span className="sub">{r.docs.length}</span></div>
                <div className="card-b">
                  {r.docs.length ? r.docs.map((d) => (
                    <div className="item" key={d}><div className="iico"><Icon name="file" /></div><div className="imeta"><b>{d}</b></div></div>
                  )) : <div className="empty"><Icon name="doc" /><b>لا مستندات</b></div>}
                </div>
              </div>

              <ExecDocReview execId={r.id} docs={r.docItems} onReview={reviewDoc} />
            </>
          )}

          {/* تبويب: الأتعاب والفواتير */}
          {activeTab === 'finance' && (
            <>
              {/* إعادة التسعير مسموحة خادمياً في المرحلة 5 حين يرفض العميل العرض أو يستفسر، والمرحلة 6 إن كانت غير مدفوعة
                  (ExecService::feeStages) — وبلا هذا الشرط كان الطلب المرفوض يتجمّد بلا زرّ لأي دور */}
              {/* المرفوض بعد الدراسة لا يُسعَّر — والخادم يرفضه بالرسالة نفسها (ExecService::guardNotRejected) */}
              {role === 'admin' && r.decision !== 'مرفوض' && r.stage >= 2 && r.stage <= 6 && !r.paid
                && (!r.feeApproved || ['مرفوض', 'استفسار'].includes(r.offerStatus || '') || r.stage === 6)
                && <PricingCard r={r} act={act} />}

              {r.stage >= 4 && (r.fee > 0 || r.feeMode === 'percent') && (
                <div className="card" style={{ marginBottom: 12 }}>
                  <div className="card-h"><h3>عرض خدمة التنفيذ</h3>{r.feeApproved ? <Badge text="معتمد" tone="b-green" /> : <Badge text="بانتظار الاعتماد" tone="b-amber" />}</div>
                  <div className="card-b">
                    {r.feeMode === 'percent' ? (<>
                      <CellRow cells={[['نموذج الأتعاب', 'نسبة من المحصّل'], ['النسبة', `${r.collectionFeePct ?? 0}% من كل مبلغ محصَّل`]]} />
                      <CellRow cells={[['المستحق مقدَّماً', 'لا مبلغ مقدَّم'], ['مدة التنفيذ', r.duration || '—']]} />
                    </>) : (<>
                      <CellRow cells={[['أتعاب التنفيذ', execMoney(r.fee) + ' ريال'], [execVatLabel(r.vatRate), execMoney(r.vat) + ' ريال']]} />
                      <CellRow cells={[['الإجمالي', execMoney(total) + ' ريال'], ['مدة التنفيذ', r.duration || '—']]} />
                    </>)}
                    <CellRow cells={[['طريقة السداد', r.payMethod || '—'], ['حالة العرض', r.offerStatus || '—']]} />
                    {r.payPlan === 'install' && <CellRow cells={[['خطة السداد', `دفعة ${r.installmentsPaid ?? 0} من ${r.installmentsTotal ?? 3} مسدَّدة`], ['فاتورة فتح الملفّ', r.invoiceNo || '—']]} />}
                  </div>
                </div>
              )}

              {/* يطابق حارس الخادم: العرض النسبيّ بلا مبلغ ثابت وله ما يُطبع */}
              {r.feeApproved && (r.fee > 0 || r.feeMode === 'percent') && (
                <div style={{ margin: '0 0 12px' }}>
                  <a className="btn soft sm" href={offerPdfHref(r)}><Icon name="download" /> طباعة العرض/الفاتورة (PDF)</a>
                </div>
              )}

              {/* فواتير الملفّ للمكتب — **للاطّلاع وحده**: السداد فعلُ العميل، وبطاقتُه في شاشته */}
              <ExecInvoicesCard invoices={r.invoices ?? []} canPay={false} act={act} />
            </>
          )}

          {/* تبويب: محادثة ملف التنفيذ */}
          {activeTab === 'chat' && role !== 'client' && r.stage >= 1 && (
            <div className="card" style={{ marginBottom: 12 }}>
              <div className="card-h"><h3>محادثة ملف التنفيذ</h3><span className="sub">{r.messages.length} رسالة</span></div>
              <div className="card-b" style={{ padding: 16 }}>
                <ChatThread
                  initial={r.messages}
                  channel={r.channel}
                  onSend={sendMsg}
                  onStatus={() => router.reload({ only: ['execs'] })}
                  readOnly={r.closed}
                  placeholder="اكتب ردّك للعميل…"
                />
              </div>
            </div>
          )}
        </div>

        {/* العمود الجانبي (Side Rail) */}
        <div style={{ minWidth: 0 }}>
          {/* بطاقة بيانات السند والطلب الأساسية */}
          <div className="card" style={{ marginBottom: 14 }}>
            <div className="card-h"><h3>بيانات السند والأطراف</h3></div>
            <div className="card-b">
              <CellRow cells={[['نوع السند', r.sanad || '—'], ['قيمة المطالبة', execMoney(r.amount) + ' ريال']]} />
              <CellRow cells={[['طالب التنفيذ', r.client], ['المنفَّذ ضده', r.defendant || '—']]} />
              {role !== 'client' && r.lawyer && <CellRow cells={[['محامي التنفيذ', r.lawyer], ['حالة القرار', r.decision || 'قيد الدراسة']]} />}
              {r.execNo && <CellRow cells={[['رقم ملف التنفيذ', r.execNo], ['المرحلة', EXEC_FLOW[r.stage]]]} />}
            </div>
          </div>

          {/* الإسناد: للمكتب المصرَّح له وحده، ولا يُعرض على ملفٍّ أُغلق (الخادم يردّ الإجراء عليه) */}
          {role !== 'client' && r.canAssign && !r.closed && <ExecAssignCard r={r} lawyers={lawyers} act={act} />}

          {/* ملخص الأتعاب وطباعة PDF السريعة */}
          {r.feeApproved && (
            <div className="card" style={{ marginBottom: 14 }}>
              <div className="card-h"><h3>ملخص الأتعاب</h3><Badge text={r.feeMode === 'percent' ? 'نسبة' : 'مبلغ مقطوع'} tone="b-blue" /></div>
              <div className="card-b" style={{ padding: 14 }}>
                {r.feeMode === 'percent' ? (
                  <div style={{ fontSize: 13, color: 'var(--ink)' }}>
                    <b>النسبة:</b> {r.collectionFeePct ?? 0}% من المحصّل
                  </div>
                ) : (
                  <div style={{ fontSize: 13, color: 'var(--ink)' }}>
                    <div><b>المجموع مع الضريبة:</b> {execMoney(total)} ريال</div>
                    {dueInvoices > 0 && <div style={{ color: 'var(--amber)', fontSize: 12, marginTop: 4 }}><b>المتبقي للسداد:</b> {dueInvoices} فاتورة</div>}
                  </div>
                )}
                <a className="btn soft sm" href={offerPdfHref(r)} target="_blank" rel="noopener noreferrer" style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 6, marginTop: 10, width: '100%' }}>
                  <Icon name="download" /> تحميل العرض / الفاتورة (PDF)
                </a>
              </div>
            </div>
          )}
        </div>
      </div>

      <div style={{ marginTop: 16 }}>
        <button className="btn soft sm" type="button" onClick={onBack}><Icon name="out" /> رجوع لقائمة التنفيذ</button>
      </div>
    </>
  );
};

// ── الصفحة ──
// `lawyers` اختياريّة: تصل لمن يملك الإسناد وحده، وغيابها لا يكسر باقي الشاشة
const ExecFlow: React.FC<{
  role: Role;
  execs: ExecReq[];
  lawyers?: ExecLawyerOpt[];
  initialId?: string | number | null;
  initialTab?: string | null;
}> = ({ role, execs, lawyers = [], initialId, initialTab }) => {
  const toast = useToast();

  const resolveTarget = (idVal?: string | number | null, tabVal?: string | null) => {
    let targetId: string | null = idVal !== undefined && idVal !== null ? String(idVal) : null;
    let targetTab: string | null = tabVal !== undefined && tabVal !== null ? String(tabVal) : null;

    if (typeof window !== 'undefined') {
      const sp = new URLSearchParams(window.location.search);
      if (!targetId) targetId = sp.get('id');
      if (!targetTab) targetTab = sp.get('tab');
    }

    const matched = targetId
      ? execs.find((e) => String(e.id) === targetId || (e.rawId !== undefined && String(e.rawId) === targetId) || (e.execNo && String(e.execNo) === targetId)) ?? null
      : null;

    return { matched, targetTab };
  };

  const initialTarget = useMemo(() => resolveTarget(initialId, initialTab), []);
  const [view, setView] = useState<View>(initialTarget.matched ? 'detail' : 'list');
  const [currentId, setCurrentId] = useState<string | null>(initialTarget.matched ? initialTarget.matched.id : null);
  const [currentTab, setCurrentTab] = useState<string | null>(initialTarget.targetTab);
  const [busy, setBusy] = useState(false);

  const current = useMemo(() => execs.find((e) => e.id === currentId) ?? null, [execs, currentId]);

  const syncUrl = (id: string | null, tab?: string | null, push = false) => {
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    if (id) {
      url.searchParams.set('id', id);
      if (tab && tab !== 'overview') {
        url.searchParams.set('tab', tab);
      } else {
        url.searchParams.delete('tab');
      }
    } else {
      url.searchParams.delete('id');
      url.searchParams.delete('tab');
    }
    const nextPath = url.pathname + (url.search ? url.search : '');
    if (push) {
      window.history.pushState({}, '', nextPath);
    } else {
      window.history.replaceState({}, '', nextPath);
    }
  };

  useEffect(() => {
    const onPopState = () => {
      const sp = new URLSearchParams(window.location.search);
      const urlId = sp.get('id');
      const urlTab = sp.get('tab');
      if (urlId) {
        const matched = execs.find((e) => String(e.id) === urlId || (e.rawId !== undefined && String(e.rawId) === urlId) || (e.execNo && String(e.execNo) === urlId));
        if (matched) {
          setCurrentId(matched.id);
          setView('detail');
          setCurrentTab(urlTab);
          return;
        }
      }
      setCurrentId(null);
      setView('list');
      setCurrentTab(null);
    };
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, [execs]);

  useEffect(() => {
    if (initialId) {
      const { matched, targetTab } = resolveTarget(initialId, initialTab);
      if (matched) {
        setCurrentId(matched.id);
        setView('detail');
        if (targetTab) setCurrentTab(targetTab);
      }
    }
  }, [initialId, initialTab, execs]);

  const open = (id: string) => {
    setCurrentId(id);
    setView('detail');
    setCurrentTab('overview');
    syncUrl(id, 'overview', true);
  };

  const backToList = () => {
    setCurrentId(null);
    setView('list');
    setCurrentTab(null);
    syncUrl(null, null, true);
  };

  const handleTabChange = (tab: 'overview' | 'docs' | 'finance' | 'chat') => {
    setCurrentTab(tab);
    if (currentId) {
      syncUrl(currentId, tab, false);
    }
  };

  const act: ActFn = (action, payload = {}) => {
    if (!currentId) {
      return;
    }

    const id = encodeURIComponent(currentId);

    // السداد يمرّ ببوّابة ميسّر (يوجّه المتصفّح لصفحة الدفع)؛ باقي الإجراءات تُحدّث الحالة محليّاً.
    // و`plan` قرار العميل: كاملاً أو ثلاث دفعات — يقرؤه الخادم فيقسّم الفاتورة قبل التوجيه.
    if (action === 'pay') {
      router.post(`/exec-flow/${id}/pay`, { plan: String(payload.plan ?? 'full') }, { onError: (errors) => toast(Object.values(errors)[0] ?? 'تعذّر بدء الدفع') });

      return;
    }

    // فواتير ما بعد فتح الملفّ (الدفعتان 2 و3، وفواتير الأتعاب عن التحصيل) تمرّ بمسار
    // الفواتير العامّ: ردُّه يطابق **الفاتورة المعنيّة** بمرجعها، لا أحدث فاتورة على الملفّ.
    if (action === 'payInvoice') {
      router.post(`/invoices/${encodeURIComponent(String(payload.no ?? ''))}/checkout`, {}, {
        onError: (errors) => toast(Object.values(errors)[0] ?? 'تعذّر بدء الدفع'),
      });

      return;
    }

    router.post(`/exec-flow/${id}/action`, { action, ...payload }, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => toast('تم تنفيذ الإجراء'),
      onError: (errors) => toast(Object.values(errors)[0] ?? 'تعذّر تنفيذ الإجراء'),
    });
  };

  const submitNew = (d: ExecNewPayload) => {
    setBusy(true);
    const formData = new FormData();
    formData.append('sanad', d.sanad);
    formData.append('subject', d.subject);
    formData.append('defendant', d.defendant);
    formData.append('amount', String(d.amount));
    formData.append('notes', d.notes);

    if (d.files && d.files.length > 0) {
      d.files.forEach((f) => formData.append('files[]', f));
    }

    router.post('/exec-flow', formData, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        backToList();
        toast('تم إرسال طلب التنفيذ وبدء التحليل الذكي للمستندات');
      },
      onError: (errors) => toast(Object.values(errors)[0] ?? 'تعذّر إرسال الطلب'),
      onFinish: () => setBusy(false),
    });
  };

  return (
    <div className="tflow">
      {view === 'list' && <ExecList role={role} execs={execs} onNew={() => { setView('new'); syncUrl(null, null, true); }} onOpen={open} />}
      {view === 'new' && <ExecNew onSubmit={submitNew} onBack={backToList} busy={busy} />}
      {view === 'detail' && current && (role === 'client'
        ? <ClientExecDetail r={current} onBack={backToList} act={act} />
        : <ExecDetail role={role} r={current} lawyers={lawyers} onBack={backToList} act={act} initialTab={currentTab} onTabChange={handleTabChange} />)}
      {view === 'detail' && !current && <div className="empty"><Icon name="exec" /><b>لم يُحدَّد طلب</b></div>}
    </div>
  );
};

export default ExecFlow;
