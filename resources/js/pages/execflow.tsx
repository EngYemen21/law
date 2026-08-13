import { router } from '@inertiajs/react';
import axios from 'axios';
import React, { useMemo, useRef, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import ChatThread from '@/components/babylon/ChatThread';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { EXEC_FLOW, EXEC_SANADS, EXEC_PAYM, EXEC_DOC_ACCEPT, EXEC_DOC_HINT, execTone, execMoney, procTone, type ExecDoc, type ExecReq, type Role } from '@/lib/exec-flow';

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
const nextAction = (role: Role, r: ExecReq): string => {
  if (role === 'admin') return r.stage < 2 ? 'أحِل لقسم التنفيذ' : r.stage === 4 ? 'اعتمد الأتعاب' : '';
  if (role === 'lawyer') {
    if (r.stage <= 1) return 'بانتظار البدء بالدراسة';
    if (r.stage === 2) return 'اقبل الطلب أو اطلب مستندات';
    if (r.stage === 3) return 'حدّد الأتعاب';
    if (r.stage === 4) return 'بانتظار اعتماد الإدارة';
    if (r.stage >= 7 && !r.closed) return 'أضف إجراءات التنفيذ';
  }
  if (role === 'employee') {
    if (r.stage <= 1) return r.aiMissing.length ? 'اطلب المستندات الناقصة' : 'راجع وأحِل للمحامي';
    return 'مُحال — بيد المحامي';
  }
  return '';
};

const ExecList: React.FC<{ role: Role; execs: ExecReq[]; onNew: () => void; onOpen: (id: string) => void }> = ({ role, execs, onNew, onOpen }) => {
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
                {r.feeApproved ? <span><Icon name="card" /> أتعاب {execMoney(r.fee)} ريال</span> : null}
                {r.lawyer && role !== 'client' ? <span><Icon name="user" /> {r.lawyer}</span> : null}
                {role !== 'client' && nextAction(role, r) ? <span style={{ color: STAGE_COLOR(r.stage), fontWeight: 700 }}><Icon name="info" /> {nextAction(role, r)}</span> : null}
              </div>
            </div>
          )) : <div className="empty"><Icon name="exec" /><b>لا طلبات تنفيذ</b></div>}
        </div>
      </div>
    </>
  );
};

// ── نموذج التقديم (تطابق execNew) ──
const ExecNew: React.FC<{ onSubmit: (d: { sanad: string; subject: string; defendant: string; amount: number; notes: string }) => void; onBack: () => void; busy: boolean }> = ({ onSubmit, onBack, busy }) => {
  const [sanad, setSanad] = useState(EXEC_SANADS[0]);
  const [amount, setAmount] = useState('');
  const [subject, setSubject] = useState('');
  const [defendant, setDefendant] = useState('');
  const [notes, setNotes] = useState('');

  const submit = () => {
    if (!subject.trim()) return;
    onSubmit({ sanad, subject: subject.trim(), defendant: defendant.trim(), amount: parseInt(amount || '0', 10) || 0, notes: notes.trim() });
  };

  return (
    <>
      <div className="greet">
        <h2>طلب تنفيذ جديد</h2>
        <p>أدخل بيانات السند والمطالبة وأرفق المستندات، وسيحلّلها الفريق القانوني الذكي قبل الإحالة.</p>
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
            <textarea className="input" value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} />
          </div>
          <div className="action-hint" style={{ margin: '6px 0' }}>
            <Icon name="upload" /> المرفقات: الحكم/السند التنفيذي/سند الأمر/الشيك/عقد التنفيذ/مستندات داعمة (محاكاة الرفع).
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn" type="button" onClick={submit} disabled={busy}><Icon name="send" /> إرسال الطلب</button>
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
  const [pay, setPay] = useState(EXEC_PAYM[0]);
  const [proc, setProc] = useState('');
  const [feeAdj, setFeeAdj] = useState('');
  const total = r.fee + r.vat;

  let body: React.ReactNode = null;

  if (role === 'client') {
    if (r.stage === 5 && r.feeApproved) {
      body = (<>
        <button className="btn" type="button" onClick={() => act('acceptOffer')}><Icon name="check" /> قبول العرض</button>
        <button className="btn soft" type="button" onClick={() => act('inquire')}><Icon name="info" /> طلب استفسار</button>
        <button className="btn soft" type="button" onClick={() => act('rejectOffer')}><Icon name="out" /> رفض العرض</button>
      </>);
    } else if (r.stage === 6 && !r.paid) {
      body = (<>
        <div className="action-hint" style={{ marginBottom: 8 }}><Icon name="card" /> الفاتورة {r.invoiceNo} — الإجمالي {execMoney(total)} ريال</div>
        <button className="btn" type="button" onClick={() => act('pay')}><Icon name="card" /> دفع الآن</button>
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
        <div className="picker-grid">
          <div className="field"><label>أتعاب التنفيذ (ريال)</label><input className="input" value={fee} onChange={(e) => setFee(e.target.value)} placeholder="مثال: 6000" /></div>
          <div className="field"><label>مدة التنفيذ</label><input className="input" value={dur} onChange={(e) => setDur(e.target.value)} placeholder="30-45 يوم" /></div>
        </div>
        <div className="field"><label>طريقة السداد</label>
          <select className="input" value={pay} onChange={(e) => setPay(e.target.value)}>{EXEC_PAYM.map((p) => <option key={p} value={p}>{p}</option>)}</select>
        </div>
        <button className="btn" type="button" onClick={() => act('saveFee', { fee: parseInt(fee || '0', 10) || 0, duration: dur, payMethod: pay })}><Icon name="send" /> إرسال الأتعاب للإدارة</button>
      </>);
    } else if (r.stage >= 7 && !r.closed) {
      body = (<>
        <div className="field"><label>إضافة إجراء تنفيذ</label><input className="input" value={proc} onChange={(e) => setProc(e.target.value)} placeholder="مثال: تم الحجز على الحساب البنكي" /></div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button className="btn" type="button" onClick={() => { act('addProcedure', { title: proc }); setProc(''); }}><Icon name="plus" /> إضافة إجراء</button>
          <button className="btn soft" type="button" onClick={() => act('requestCorr')}><Icon name="office" /> طلب مخاطبة</button>
          <button className="btn soft" type="button" onClick={() => act('close')}><Icon name="check" /> إغلاق الملف</button>
        </div>
      </>);
    }
  } else if (role === 'admin') {
    if (r.stage < 2) {
      body = <button className="btn" type="button" onClick={() => act('refer')}><Icon name="reply" /> إحالة لقسم التنفيذ</button>;
    } else if (r.stage === 4) {
      body = (<>
        <div className="action-hint" style={{ marginBottom: 8 }}><Icon name="info" /> مراجعة الأتعاب واعتمادها قبل إرسال العرض. بعد الاعتماد لا تُعدَّل إلا بصلاحية الإدارة.</div>
        <div className="field"><label>تعديل الأتعاب (اختياري)</label><input className="input" value={feeAdj} onChange={(e) => setFeeAdj(e.target.value)} placeholder={String(r.fee)} /></div>
        <button className="btn" type="button" onClick={() => act('approveFee', { fee: parseInt(feeAdj || '0', 10) || 0 })}><Icon name="check" /> اعتماد وإرسال العرض</button>
      </>);
    } else if (r.stage >= 7 && !r.closed) {
      body = (<>
        <div className="field"><label>إضافة إجراء تنفيذ</label><input className="input" value={proc} onChange={(e) => setProc(e.target.value)} /></div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button className="btn" type="button" onClick={() => { act('addProcedure', { title: proc }); setProc(''); }}><Icon name="plus" /> إضافة إجراء</button>
          <button className="btn soft" type="button" onClick={() => act('requestCorr')}><Icon name="office" /> طلب مخاطبة</button>
          <button className="btn soft" type="button" onClick={() => act('close')}><Icon name="check" /> إغلاق الملف</button>
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

  if (!body) return null;
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
          <KpiRow t="أتعاب التنفيذ" v={`${execMoney(r.fee)} ريال`} />
          <KpiRow t="ضريبة القيمة المضافة (15%)" v={`${execMoney(r.vat)} ريال`} />
          <KpiRow t="مدة التنفيذ المتوقعة" v={r.duration || '—'} />
          <KpiRow t="طريقة الدفع" v={r.payMethod || '—'} />
          <KpiRow total t={<b>الإجمالي</b>} v={<b>{execMoney(total)} ريال</b>} />
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 14 }}>
            <button className="btn" type="button" onClick={() => act('acceptOffer')}><Icon name="check" /> قبول العرض وإصدار الفاتورة</button>
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
          <KpiRow total t={<b>الإجمالي المستحق</b>} v={<b>{execMoney(total)} ريال</b>} />
          <button className="btn block" style={{ marginTop: 14 }} type="button" onClick={() => act('pay')}>
            <Icon name="card" /> سداد الفاتورة وفتح ملف التنفيذ
          </button>
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
  if (!docs.length) return null;
  const done = docs.filter((d) => d.status === 'مقبول' || d.status === 'مرفوع').length;

  const pick = (id: number) => fileRefs.current[id]?.click();
  const upload = (id: number, e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    router.post(`/exec-flow/${encodeURIComponent(execId)}/documents/${id}`, { file }, {
      forceFormData: true, preserveScroll: true,
      onError: (errs) => toast(Object.values(errs)[0] ?? 'تعذّر رفع المستند'),
    });
  };

  return (
    <div className="card" style={{ marginBottom: 14 }}>
      <div className="card-h"><h3>مستندات مطلوبة منك</h3><span className="sub">{done}/{docs.length}</span></div>
      <div className="card-b">
        {docs.map((d) => (
          <div className="item" key={d.id}>
            <div className="iico"><Icon name="file" /></div>
            <div className="imeta">
              <b>{d.label}</b>
              {d.fileName && <span><a href={`/exec-flow/${encodeURIComponent(execId)}/documents/${d.id}/download`} target="_blank" rel="noopener noreferrer">{d.fileName}</a>{d.docType ? ` · ${d.docType}` : ''}</span>}
              {d.summary && <span style={{ display: 'block', marginTop: 3, fontSize: 11.5, color: 'var(--muted)' }}>{d.summary}</span>}
            </div>
            <div className="iact" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Badge text={d.status} tone={d.tone} />
              {d.canUpload && (
                <>
                  <button className="btn soft sm" type="button" onClick={() => pick(d.id)}><Icon name="upload" /> رفع المستند</button>
                  <input ref={(el) => { fileRefs.current[d.id] = el; }} type="file" hidden onChange={(e) => upload(d.id, e)} />
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
const ClientExecDetail: React.FC<{ r: ExecReq; onBack: () => void; act: ActFn }> = ({ r, onBack, act }) => {
  const total = r.fee + r.vat;
  const sendMsg = (text: string) => { axios.post(`/exec-flow/${encodeURIComponent(r.id)}/messages`, { body: text }); };
  // رفع مستند فعلي من محادثة التنفيذ — يظهر رسالة في المحادثة ويُدرَج ضمن مستندات الملف
  const attachDoc = (file?: File) => {
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    axios.post(`/exec-flow/${encodeURIComponent(r.id)}/attach`, fd).then(() => router.reload({ only: ['execs'] }));
  };

  return (
    <>
      <div style={{ marginBottom: 14 }}>
        <button className="btn soft sm" type="button" onClick={onBack}><Icon name="reply" /> رجوع لقائمة التنفيذ</button>
      </div>

      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><h3>طلب التنفيذ {r.id}</h3><Badge text={EXEC_FLOW[r.stage]} tone={execTone(r.stage)} /></div>
        <div className="card-b" style={{ padding: 16 }}>
          <KpiRow t="الموضوع" v={r.subject} />
          <KpiRow t="نوع السند" v={r.sanad || '—'} />
          <KpiRow t="قيمة المطالبة" v={`${execMoney(r.amount)} ريال`} />
          {r.defendant && <KpiRow t="المنفَّذ ضده" v={r.defendant} />}
          {r.execNo && <KpiRow t="رقم ملف التنفيذ" v={r.execNo} />}
        </div>
      </div>

      {r.aiDone && (
        <div className="card" style={{ marginBottom: 14, borderInlineStart: '3px solid var(--cyan)' }}>
          <div className="card-h"><h3>الملخّص الذكيّ</h3></div>
          <div className="card-b" style={{ padding: '14px 16px' }}>
            <p style={{ margin: '0 0 8px' }}>{r.aiSummary}</p>
            {r.aiMissing.length > 0 && <div className="mtg-pend"><Icon name="info" /> نواقص مطلوبة: {r.aiMissing.join(' · ')}</div>}
          </div>
        </div>
      )}

      <ClientFlowCard r={r} act={act} />

      {r.fee > 0 && r.feeApproved && (
        <div style={{ margin: '0 0 14px' }}>
          <a className="btn soft sm" href={offerPdfHref(r)}><Icon name="download" /> طباعة العرض/الفاتورة (PDF)</a>
        </div>
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

// ── بطاقة تسعير الإدارة (تطابق execfeeset): ثابت/نسبة/محصّل + سداد + مدّة → اعتماد وإرسال العرض ──
const PricingCard: React.FC<{ r: ExecReq; act: ActFn }> = ({ r, act }) => {
  const [mode, setMode] = useState<'fixed' | 'pct'>('fixed');
  const [fixed, setFixed] = useState('');
  const [pct, setPct] = useState('');
  const [dur, setDur] = useState('');
  const [pay, setPay] = useState(EXEC_PAYM[0]);

  const fee = mode === 'fixed' ? (parseInt(fixed || '0', 10) || 0) : Math.round((r.amount * (parseFloat(pct || '0') || 0)) / 100);
  const vat = Math.round(fee * 0.15);
  const submit = () => { if (fee >= 1) act('setFee', { fee, duration: dur, payMethod: pay }); };

  return (
    <div className="card" style={{ marginBottom: 12 }}>
      <div className="card-h"><h3>تحديد أتعاب التنفيذ</h3><Badge text="بانتظار التحديد" tone="b-amber" /></div>
      <div className="card-b" style={{ padding: 16 }}>
        <KpiRow t="قيمة المطالبة" v={`${execMoney(r.amount)} ريال`} />
        <div className="field"><label>نوع التسعير</label>
          <select className="input" value={mode} onChange={(e) => setMode(e.target.value as 'fixed' | 'pct')}>
            <option value="fixed">مبلغ ثابت</option>
            <option value="pct">نسبة من قيمة المطالبة</option>
          </select>
        </div>
        {mode === 'fixed'
          ? <div className="field"><label>أتعاب التنفيذ (ريال)</label><input className="input" value={fixed} onChange={(e) => setFixed(e.target.value)} placeholder="مثال: 6000" /></div>
          : <div className="field"><label>النسبة (%)</label><input className="input" value={pct} onChange={(e) => setPct(e.target.value)} placeholder="مثال: 10" /></div>}
        <div className="picker-grid">
          <div className="field"><label>طريقة السداد</label>
            <select className="input" value={pay} onChange={(e) => setPay(e.target.value)}>{EXEC_PAYM.map((p) => <option key={p} value={p}>{p}</option>)}</select>
          </div>
          <div className="field"><label>مدة التنفيذ المتوقعة</label><input className="input" value={dur} onChange={(e) => setDur(e.target.value)} placeholder="30-45 يوم" /></div>
        </div>
        <KpiRow total t={<b>الإجمالي بعد الضريبة (15%)</b>} v={<b>{execMoney(fee + vat)} ريال</b>} />
        <div className="action-hint" style={{ margin: '8px 0' }}><Icon name="info" /> تحديد الأتعاب واعتمادها من صلاحيات الإدارة العليا؛ بعد الاعتماد يُرسَل العرض للعميل.</div>
        <button className="btn block" type="button" onClick={submit}><Icon name="check" /> اعتماد الأتعاب وإرسال العرض للعميل</button>
      </div>
    </div>
  );
};

// ── مراجعة المكتب لمستندات العميل المرفوعة (اعتماد/إعادة) — تطابق exDocPanel لغير العميل ──
const ExecDocReview: React.FC<{ execId: string; docs: ExecDoc[]; onReview: (docId: number, decision: 'accept' | 'reject') => void }> = ({ execId, docs, onReview }) => {
  if (!docs.length) return null;
  return (
    <div className="card" style={{ marginBottom: 12 }}>
      <div className="card-h"><h3>مستندات العميل</h3><span className="sub">{docs.length}</span></div>
      <div className="card-b">
        {docs.map((d) => (
          <div className="item" key={d.id}>
            <div className="iico"><Icon name="file" /></div>
            <div className="imeta">
              <b>{d.label}</b>
              {d.fileName && <span><a href={`/exec-flow/${encodeURIComponent(execId)}/documents/${d.id}/download`} target="_blank" rel="noopener noreferrer">{d.fileName}</a>{d.docType ? ` · ${d.docType}` : ''}</span>}
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
const ExecDetail: React.FC<{ role: Role; r: ExecReq; onBack: () => void; act: ActFn }> = ({ role, r, onBack, act }) => {
  const total = r.fee + r.vat;
  const sendMsg = (text: string) => { axios.post(`/exec-flow/${encodeURIComponent(r.id)}/messages`, { body: text }); };
  const reviewDoc = (docId: number, decision: 'accept' | 'reject') => {
    router.post(`/exec-flow/${encodeURIComponent(r.id)}/documents/${docId}/review`, { decision }, { preserveScroll: true });
  };
  return (
    <>
      <div className="greet">
        <h2>{r.id} — {r.subject}</h2>
        <p><Badge text={EXEC_FLOW[r.stage]} tone={execTone(r.stage)} />{r.execNo ? ` · رقم التنفيذ: ${r.execNo}` : ''}</p>
      </div>

      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-b" style={{ padding: 16 }}><FlowLine steps={EXEC_FLOW} cur={r.stage} /></div>
      </div>

      {r.aiDone && (
        <div className="card" style={{ marginBottom: 12, borderInlineStart: '3px solid var(--cyan)' }}>
          <div className="card-h"><h3>الملخص الذكي</h3></div>
          <div className="card-b" style={{ padding: '14px 16px' }}>
            <p style={{ margin: '0 0 8px' }}>{r.aiSummary}</p>
            {r.aiMissing.length > 0 && <div className="mtg-pend"><Icon name="info" /> نواقص مطلوبة: {r.aiMissing.join(' · ')}</div>}
            {r.aiProcedures.length > 0 && (
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 6 }}>
                {r.aiProcedures.map((p) => <span key={p} className="chip">{p}</span>)}
              </div>
            )}
          </div>
        </div>
      )}

      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>بيانات الطلب</h3></div>
        <div className="card-b">
          <CellRow cells={[['نوع السند', r.sanad || '—'], ['قيمة المطالبة', execMoney(r.amount) + ' ريال']]} />
          <CellRow cells={[['طالب التنفيذ', r.client], ['المنفَّذ ضده', r.defendant || '—']]} />
          {role !== 'client' && r.lawyer && <CellRow cells={[['محامي التنفيذ', r.lawyer], ['حالة القرار', r.decision || 'قيد الدراسة']]} />}
        </div>
      </div>

      {role === 'admin' && r.stage >= 2 && r.stage <= 4 && !r.feeApproved && <PricingCard r={r} act={act} />}

      {r.stage >= 4 && r.fee > 0 && (
        <div className="card" style={{ marginBottom: 12 }}>
          <div className="card-h"><h3>عرض خدمة التنفيذ</h3>{r.feeApproved ? <Badge text="معتمد" tone="b-green" /> : <Badge text="بانتظار الاعتماد" tone="b-amber" />}</div>
          <div className="card-b">
            <CellRow cells={[['أتعاب التنفيذ', execMoney(r.fee) + ' ريال'], ['ضريبة القيمة المضافة (15%)', execMoney(r.vat) + ' ريال']]} />
            <CellRow cells={[['الإجمالي', execMoney(total) + ' ريال'], ['مدة التنفيذ', r.duration || '—']]} />
            <CellRow cells={[['طريقة السداد', r.payMethod || '—'], ['حالة العرض', r.offerStatus || '—']]} />
          </div>
        </div>
      )}

      {r.fee > 0 && r.feeApproved && (
        <div style={{ margin: '0 0 12px' }}>
          <a className="btn soft sm" href={offerPdfHref(r)}><Icon name="download" /> طباعة العرض/الفاتورة (PDF)</a>
        </div>
      )}

      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>المستندات</h3><span className="sub">{r.docs.length}</span></div>
        <div className="card-b">
          {r.docs.length ? r.docs.map((d) => (
            <div className="item" key={d}><div className="iico"><Icon name="file" /></div><div className="imeta"><b>{d}</b></div></div>
          )) : <div className="empty"><Icon name="doc" /><b>لا مستندات</b></div>}
        </div>
      </div>

      <ExecDocReview execId={r.id} docs={r.docItems} onReview={reviewDoc} />

      {r.stage >= 7 && (role === 'client' ? (
        <div className="card" style={{ marginBottom: 12 }}>
          <div className="card-h"><h3>متابعة التنفيذ</h3></div>
          <div className="card-b">
            <CellRow cells={[['رقم التنفيذ', r.execNo || '—'], ['الحالة', EXEC_FLOW[r.stage]]]} />
            <CellRow cells={[['آخر إجراء', r.procedures[0]?.a || '—'], ['الفاتورة', r.invoiceNo || '—']]} />
            <div className="action-hint" style={{ marginTop: 8 }}><Icon name="info" /> الملاحظات الداخلية والمراسلات الإدارية غير ظاهرة للعميل.</div>
          </div>
        </div>
      ) : (
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
      ))}

      {r.linkedCorr.length > 0 && (
        <div className="card" style={{ marginBottom: 12 }}>
          <div className="card-h"><h3>المخاطبات المرتبطة بالملفّ</h3><span className="sub">{r.linkedCorr.length}</span></div>
          <div className="card-b">
            {r.linkedCorr.map((lc) => {
              const corrBase = role === 'admin' ? '/admin/correspondences' : role === 'lawyer' ? '/lawyer/correspondences' : null;
              return (
                <div className="item" key={lc.id} style={corrBase ? { cursor: 'pointer' } : undefined}
                  onClick={corrBase ? () => router.visit(`${corrBase}/${encodeURIComponent(lc.id)}`) : undefined}>
                  <div className="iico"><Icon name="office" /></div>
                  <div className="imeta"><b>{lc.id} — {lc.entity}</b><span>{lc.stageLabel}</span></div>
                  {corrBase && <div className="iact"><Icon name="link" /></div>}
                </div>
              );
            })}
          </div>
        </div>
      )}

      <ActionCard role={role} r={r} act={act} />

      {/* المحادثة للمكتب المُصرّح له بالمراسلة (محامٍ/إدارة/موظف الاستقبال) */}
      {role !== 'client' && r.stage >= 1 && (
        <div className="card" style={{ margin: '12px 0' }}>
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

      <div style={{ marginTop: 10 }}>
        <button className="btn soft sm" type="button" onClick={onBack}><Icon name="out" /> رجوع للقائمة</button>
      </div>
    </>
  );
};

// ── الصفحة ──
const ExecFlow: React.FC<{ role: Role; execs: ExecReq[] }> = ({ role, execs }) => {
  const toast = useToast();
  const [view, setView] = useState<View>('list');
  const [currentId, setCurrentId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const current = useMemo(() => execs.find((e) => e.id === currentId) ?? null, [execs, currentId]);

  const open = (id: string) => { setCurrentId(id); setView('detail'); };

  const act: ActFn = (action, payload = {}) => {
    if (!currentId) return;
    const id = encodeURIComponent(currentId);
    // السداد يمرّ ببوّابة ميسّر (يوجّه المتصفّح لصفحة الدفع)؛ باقي الإجراءات تُحدّث الحالة محليّاً
    if (action === 'pay') {
      router.post(`/exec-flow/${id}/pay`, {}, { onError: (errors) => toast(Object.values(errors)[0] ?? 'تعذّر بدء الدفع') });
      return;
    }
    router.post(`/exec-flow/${id}/action`, { action, ...payload }, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => toast('تم تنفيذ الإجراء'),
      onError: (errors) => toast(Object.values(errors)[0] ?? 'تعذّر تنفيذ الإجراء'),
    });
  };

  const submitNew = (d: { sanad: string; subject: string; defendant: string; amount: number; notes: string }) => {
    setBusy(true);
    router.post('/exec-flow', d, {
      preserveScroll: true,
      onSuccess: () => { setView('list'); toast('تم إرسال طلب التنفيذ'); },
      onError: (errors) => toast(Object.values(errors)[0] ?? 'تعذّر إرسال الطلب'),
      onFinish: () => setBusy(false),
    });
  };

  return (
    <div className="tflow">
      {view === 'list' && <ExecList role={role} execs={execs} onNew={() => setView('new')} onOpen={open} />}
      {view === 'new' && <ExecNew onSubmit={submitNew} onBack={() => setView('list')} busy={busy} />}
      {view === 'detail' && current && (role === 'client'
        ? <ClientExecDetail r={current} onBack={() => setView('list')} act={act} />
        : <ExecDetail role={role} r={current} onBack={() => setView('list')} act={act} />)}
      {view === 'detail' && !current && <div className="empty"><Icon name="exec" /><b>لم يُحدَّد طلب</b></div>}
    </div>
  );
};

export default ExecFlow;
