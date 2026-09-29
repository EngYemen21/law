import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import RescheduleDialog from '@/components/babylon/RescheduleDialog';
import TimeSlotPicker from '@/components/babylon/TimeSlotPicker';
import { useToast } from '@/components/babylon/Toast';
import { HEARING_DURATION, hearingDurationLabel, type Hearing } from '@/lib/case-ui';
import Icon from '@/lib/icons';
import { todayISO } from '@/lib/local-date';
import { firstError } from '@/lib/server-message';
import { useServerAction } from '@/lib/use-server-action';

// ============================================================
// إجراءات المحكمة على القضيّة — رفعها في ناجز وقيدها، وجدولة جلساتها وتحديثها، وتسجيل الحكم.
// بطاقاتٌ واحدة يعرضها المحامي المسنَد والموظّف (قرار المالك 2026-09-11)؛ يختلف `base` وحده،
// والخادم يحرس كلّ إجراءٍ بالحرّاس نفسها ويردّ السبب إن رفض.
// ============================================================

export interface NajizData { requestNo?: string | null; filedAt?: string | null; caseNo?: string | null; court?: string | null; circuit?: string | null; registeredAt?: string | null }
export interface Filing { canFile: boolean; canRegister: boolean; data: NajizData | null }

export interface AppealData {
  status: string;
  statusLabel: string;
  deadlineAt?: string | null;
  daysRemaining?: number | null;
  isDeadlineOver: boolean;
  requestNo?: string | null;
  court?: string | null;
  circuit?: string | null;
  ruling?: string | null;
  filedAt?: string | null;
  judgedAt?: string | null;
}


/**
 * حقل «المدّة المتوقّعة (دقائق)» — واحدٌ لنماذج الجدولة والقيد والتعديل والتأجيل (قرار المالك 2026-09-26).
 * اختياريّ: تركُه فارغاً يعني أنّ المدّة غير معروفة، فلا يكتب التقويم نهايةً مختلَقة للجلسة.
 * القيمة نصّ الحقل كما هو؛ والفارغ يصل الخادم null (ConvertEmptyStringsToNull).
 */
const HearingDurationField: React.FC<{ value: string; onChange: (v: string) => void }> = ({ value, onChange }) => (
  <div className="field">
    <label>المدّة المتوقّعة (دقائق)</label>
    <input
      className="input"
      type="number"
      inputMode="numeric"
      min={HEARING_DURATION.min}
      max={HEARING_DURATION.max}
      step={5}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder="اختياري — مثلاً 30"
    />
  </div>
);

/** مدّة الجلسة المخزّنة ⇐ نصّ الحقل — لتعبئة نموذج التعديل بما أُدخل سابقاً. */
const durationText = (min?: number | null): string => (min ? String(min) : '');

/** رفع الدعوى في ناجز ثمّ قيدها — ما يجوز يحدّده الخادم (الخطّة ب). لا تُعرض إن لم يجز شيءٌ ولا بيانات. */
export const NajizFilingCard: React.FC<{ base: string; filing: Filing; defaultCourt?: string }> = ({ base, filing, defaultCourt = '' }) => {
  const toast = useToast();
  const [nf, setNf] = useState({ request_no: '', filed_at: '' });
  const [nr, setNr] = useState({ case_no: '', court: defaultCourt, circuit: '', registered_at: '', hearing_day: '', hearing_time: '', hearing_duration_min: '', hearing_mode: 'حضورية' });
  const [nrFile, setNrFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);

  if (!filing.canFile && !filing.canRegister && !filing.data) {
    return null;
  }

  const post = (path: string, data: Record<string, string | File>, ok: string) => {
    setBusy(true);
    router.post(`${base}/najiz/${path}`, data, {
      preserveScroll: true,
      forceFormData: true,
      onSuccess: () => toast(ok),
      onError: (e) => toast(firstError(e, 'تعذّر تنفيذ الإجراء')),
      onFinish: () => setBusy(false),
    });
  };
  const fileNajiz = (e: React.FormEvent) => {
    e.preventDefault();
    post('file', nf, 'سُجّل رفع الدعوى في ناجز — بانتظار القيد');
  };
  const registerNajiz = (e: React.FormEvent) => {
    e.preventDefault();
    post('register', nrFile ? { ...nr, file: nrFile } : nr, 'سُجّل قيد الدعوى وجُدولت الجلسة الأولى');
  };
  const d = filing.data;

  return (
    <div className="card">
      <div className="card-h">
        <h3>رفع الدعوى في ناجز</h3>
        <Badge text={d?.caseNo ? 'مقيّدة' : d?.requestNo ? 'بانتظار القيد' : 'لم تُرفع بعد'} tone={d?.caseNo ? 'b-green' : 'b-amber'} />
      </div>
      <div className="card-b" style={{ padding: 14 }}>
        {d && (
          <div className="tc-body" style={{ padding: 0, marginBottom: 10 }}>
            {d.requestNo && <div className="tc-row"><span className="k">رقم الطلب</span><span className="v">{d.requestNo}</span></div>}
            {d.filedAt && <div className="tc-row"><span className="k">تاريخ الرفع</span><span className="v">{d.filedAt}</span></div>}
            {d.caseNo && <div className="tc-row"><span className="k">رقم القضية</span><span className="v">{d.caseNo}</span></div>}
            {d.court && <div className="tc-row"><span className="k">المحكمة</span><span className="v">{d.court}</span></div>}
            {d.circuit && <div className="tc-row"><span className="k">الدائرة</span><span className="v">{d.circuit}</span></div>}
            {d.registeredAt && <div className="tc-row"><span className="k">تاريخ القيد</span><span className="v">{d.registeredAt}</span></div>}
          </div>
        )}

        {filing.canFile && (
          <form onSubmit={fileNajiz}>
            <div style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 8 }}>ارفع الصحيفة في منصّة ناجز، ثم سجّل رقم الطلب الذي صدر.</div>
            <div className="field"><label>رقم الطلب في ناجز</label><input className="input" value={nf.request_no} onChange={(e) => setNf({ ...nf, request_no: e.target.value })} /></div>
            <div className="field"><label>تاريخ الرفع</label><input className="input" type="date" value={nf.filed_at} onChange={(e) => setNf({ ...nf, filed_at: e.target.value })} /></div>
            <button className="btn sm" type="submit" disabled={busy || !nf.request_no.trim() || !nf.filed_at}><Icon name="send" /> تسجيل الرفع في ناجز</button>
          </form>
        )}

        {filing.canRegister && (
          <form onSubmit={registerNajiz}>
            <div style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 8 }}>حين تقبل المحكمة الدعوى وتقيّدها، سجّل ما وصل: رقم القضية والدائرة والجلسة الأولى.</div>
            <div className="field"><label>رقم القضية</label><input className="input" value={nr.case_no} onChange={(e) => setNr({ ...nr, case_no: e.target.value })} /></div>
            <div className="field"><label>المحكمة</label><input className="input" value={nr.court} onChange={(e) => setNr({ ...nr, court: e.target.value })} /></div>
            <div className="field"><label>الدائرة</label><input className="input" value={nr.circuit} onChange={(e) => setNr({ ...nr, circuit: e.target.value })} placeholder="الدائرة التجارية الأولى" /></div>
            <div className="field"><label>تاريخ القيد</label><input className="input" type="date" value={nr.registered_at} onChange={(e) => setNr({ ...nr, registered_at: e.target.value })} /></div>
            <div className="picker-grid">
              <div className="field"><label>موعد الجلسة الأولى</label><input className="input" type="date" value={nr.hearing_day} onChange={(e) => setNr({ ...nr, hearing_day: e.target.value })} /></div>
              <div className="field"><label>الوقت</label><input className="input" type="time" value={nr.hearing_time} onChange={(e) => setNr({ ...nr, hearing_time: e.target.value })} /></div>
            </div>
            <HearingDurationField value={nr.hearing_duration_min} onChange={(v) => setNr({ ...nr, hearing_duration_min: v })} />
            <div className="field">
              <label>طريقة الانعقاد</label>
              <select className="input" value={nr.hearing_mode} onChange={(e) => setNr({ ...nr, hearing_mode: e.target.value })}>
                <option value="حضورية">حضورية في المحكمة</option>
                <option value="عن بُعد">عن بُعد</option>
              </select>
            </div>
            <div className="field"><label>صورة الصحيفة المقيّدة (اختياري)</label><input className="input" type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setNrFile(e.target.files?.[0] ?? null)} /></div>
            <button className="btn sm" type="submit" disabled={busy || !nr.case_no.trim() || !nr.court.trim() || !nr.circuit.trim() || !nr.registered_at || !nr.hearing_day || !nr.hearing_time}>
              <Icon name="check" /> تسجيل القيد وجدولة الجلسة الأولى
            </button>
          </form>
        )}
      </div>
    </div>
  );
};

/** جدولة جلسة — والقضيّة منظورة (يقرّر المُنادي متى تُعرض، والخادم يرفض خارجها). */
export const ScheduleHearingCard: React.FC<{ base: string }> = ({ base }) => {
  const toast = useToast();
  const [h, setH] = useState({ title: '', day: '', time: '', court: '', duration_min: '' });

  const addHearing = (e: React.FormEvent) => {
    e.preventDefault();

    if (!h.title.trim() || !h.day.trim()) {
      toast('أدخل عنوان الجلسة واليوم');

      return;
    }

    router.post(`${base}/hearings`, h, {
      preserveScroll: true,
      onSuccess: () => {
        setH({ title: '', day: '', time: '', court: '', duration_min: '' });
        toast('تمت جدولة الجلسة');
      },
      onError: (err) => toast(firstError(err, 'تعذّر تنفيذ الإجراء')),
    });
  };

  return (
    <div className="card" style={{ marginTop: 16 }}>
      <div className="card-h"><h3>جدولة جلسة</h3></div>
      <div className="card-b" style={{ padding: 16 }}>
        <form onSubmit={addHearing}>
          <div className="picker-grid">
            <div className="field"><label>عنوان الجلسة</label><input className="input" value={h.title} onChange={(e) => setH({ ...h, title: e.target.value })} placeholder="الجلسة الأولى" /></div>
            <div className="field"><label>التاريخ</label><input className="input" type="date" value={h.day} onChange={(e) => setH({ ...h, day: e.target.value })} /></div>
          </div>
          <div className="field"><label>الدائرة</label><input className="input" value={h.court} onChange={(e) => setH({ ...h, court: e.target.value })} placeholder="الدائرة التجارية الأولى" /></div>
          <TimeSlotPicker value={h.time} onChange={(t) => setH({ ...h, time: t })} date={h.day} label="وقت الجلسة" required allowCustom={false} />
          <HearingDurationField value={h.duration_min} onChange={(v) => setH({ ...h, duration_min: v })} />
          <button className="btn" type="submit" disabled={!h.time}><Icon name="cal" /> جدولة الجلسة</button>
        </form>
      </div>
    </div>
  );
};

/**
 * تسجيل الحكم — أو عرضه مع إتاحة التصحيح المسبّب للأخطاء المادية.
 *
 * `canRecord`: الموظّف يلزمه «تسجيل الأحكام» فوق إجراءات المحكمة (قرار المالك 2026-09-18) —
 * فيُعرض له سببُ الغياب بدل نموذجٍ يردّه الخادم. والمحامي المسنَد يبقى على الأصل (`true`).
 */
export const RulingCard: React.FC<{ base: string; ruling?: string | null; canCorrect?: boolean; canRecord?: boolean }> = ({ base, ruling: initialRuling, canCorrect = false, canRecord = true }) => {
  const toast = useToast();
  const [ruling, setRuling] = useState('');
  const [correcting, setCorrecting] = useState(false);
  const [newRuling, setNewRuling] = useState(initialRuling ?? '');
  const [reasonText, setReasonText] = useState('');
  // قفلٌ موحّد: تسجيل الحكم وتصحيحه لا يُرسلان مرّتين (الحكم ينقل القضيّة ويفتح مهلة الاعتراض)
  const action = useServerAction();
  const busy = action.busy;

  const recordRuling = (e: React.FormEvent) => {
    e.preventDefault();

    if (!ruling.trim()) {
      toast('أدخل منطوق الحكم');
      return;
    }

    void action.run(`${base}/ruling`, {
      data: { ruling },
      confirm: {
        title: 'تسجيل منطوق الحكم',
        message: 'تسجيل الحكم ينقل القضيّة إلى «صدر الحكم» ويفتح مسار الاستئناف ومهلة الاعتراض النظامية (30 يوماً).',
        confirmLabel: 'تسجيل الحكم',
        tone: 'danger',
      },
      success: 'تم تسجيل الحكم وتفعيل مهلة الاعتراض',
      onSuccess: () => setRuling(''),
    });
  };

  const submitCorrection = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newRuling.trim() || !reasonText.trim()) {
      toast('أدخل المنطوق المصحح وسبب التصحيح');
      return;
    }
    void action.run(`${base}/ruling/correct`, {
      data: { ruling: newRuling, reason: reasonText },
      success: 'تم تصحيح منطوق الحكم بنجاح وتوثيقه',
      onSuccess: () => {
        setCorrecting(false);
        setReasonText('');
      },
    });
  };

  if (initialRuling) {
    return (
      <div className="card" style={{ marginTop: 16 }}>
        <div className="card-h">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon name="scale" />
            <h3>منطوق الحكم القضائي</h3>
          </div>
          <Badge text="صدر الحكم" tone="b-cyan" />
        </div>
        <div className="card-b" style={{ padding: 16 }}>
          {!correcting ? (
            <>
              <div style={{ padding: '12px 14px', background: 'var(--paper-2)', borderRadius: 8, whiteSpace: 'pre-line', lineHeight: 1.8, fontSize: 13.5, marginBottom: 12 }}>
                {initialRuling}
              </div>
              {canCorrect && (
                <button className="btn soft sm" type="button" onClick={() => { setCorrecting(true); setNewRuling(initialRuling); }}>
                  <Icon name="edit" /> تصحيح خطأ مادي في الحكم
                </button>
              )}
            </>
          ) : (
            <form onSubmit={submitCorrection}>
              <div style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 10 }}>
                يُتاح تصحيح الأخطاء المادية أو الحسابية في منطوق الحكم مع توثيق سبب التصحيح في سجل التدقيق والمحادثة.
              </div>
              <div className="field">
                <label>المنطوق المصحح</label>
                <textarea rows={4} value={newRuling} onChange={(e) => setNewRuling(e.target.value)} required />
              </div>
              <div className="field">
                <label>سبب التصحيح (إلزامي للتوثيق)</label>
                <input className="input" value={reasonText} onChange={(e) => setReasonText(e.target.value)} placeholder="مثال: تصحيح خطأ كتابي في رقم الهوية أو المبلغ المقضي به" required />
              </div>
              <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                <button className="btn sm" type="submit" disabled={busy || !newRuling.trim() || !reasonText.trim()}>
                  حفظ التصحيح
                </button>
                <button className="btn soft sm" type="button" disabled={busy} onClick={() => setCorrecting(false)}>
                  إلغاء
                </button>
              </div>
            </form>
          )}
        </div>
      </div>
    );
  }

  if (!canRecord) {
    return (
      <div className="card" style={{ marginTop: 16 }}>
        <div className="card-h"><h3>تسجيل الحكم</h3></div>
        <div className="card-b" style={{ padding: 16, fontSize: 12.5, color: 'var(--muted)' }}>
          يسجّل الحكمَ المحامي المسنَد أو الإدارة، أو من منحته الإدارة صلاحيّة «تسجيل الأحكام».
        </div>
      </div>
    );
  }

  return (
    <div className="card" style={{ marginTop: 16 }}>
      <div className="card-h"><h3>تسجيل الحكم</h3></div>
      <div className="card-b" style={{ padding: 16 }}>
        <form onSubmit={recordRuling}>
          <textarea value={ruling} onChange={(e) => setRuling(e.target.value)} placeholder="منطوق الحكم…" aria-label="منطوق الحكم" />
          <div className="crow"><button className="btn" type="submit" disabled={busy}><Icon name="scale" /> تسجيل الحكم</button></div>
        </form>
      </div>
    </div>
  );
};

/**
 * بطاقة مسار الاستئناف والاعتراض — تعرض مهلة الاعتراض وعداد الأيام وقيد الاستئناف وقرار محكمة الاستئناف.
 * `canRule` يفصل تسجيل حكم الاستئناف عن قيده — للموظّف صلاحيّة «تسجيل الأحكام»؛ وغيابه = `canAct`.
 */
export const AppealCard: React.FC<{
  base: string;
  appeal?: AppealData | null;
  canAct?: boolean;
  canRule?: boolean;
  defaultCourt?: string;
}> = ({ base, appeal, canAct = false, canRule: canRuleProp, defaultCourt = '' }) => {
  const canRule = canRuleProp ?? canAct;
  const toast = useToast();
  const [showFilingForm, setShowFilingForm] = useState(false);
  const [showRulingForm, setShowRulingForm] = useState(false);
  // قفلٌ موحّد لقيد الاستئناف وحكمه
  const action = useServerAction();
  const busy = action.busy;

  // Form for filing appeal
  const [af, setAf] = useState({
    appeal_request_no: '',
    appeal_court: defaultCourt ? `محكمة الاستئناف (${defaultCourt})` : 'محكمة الاستئناف',
    appeal_circuit: '',
    appeal_filed_at: todayISO(),
  });

  // Form for appeal ruling
  const [ar, setAr] = useState({
    appeal_outcome: 'تأييد الحكم الابتدائي',
    appeal_ruling: '',
    appeal_judged_at: todayISO(),
  });

  if (!appeal) {
    return null;
  }

  const fileAppeal = (e: React.FormEvent) => {
    e.preventDefault();
    if (!af.appeal_request_no.trim() || !af.appeal_court.trim() || !af.appeal_circuit.trim()) {
      toast('أكمل جميع بيانات قيد الاستئناف');
      return;
    }
    void action.run(`${base}/appeal`, {
      data: af,
      success: 'تم تسجيل قيد الاستئناف بنجاح',
      onSuccess: () => setShowFilingForm(false),
    });
  };

  const recordAppealRuling = (e: React.FormEvent) => {
    e.preventDefault();
    if (!ar.appeal_ruling.trim() || !ar.appeal_outcome.trim()) {
      toast('أدخل منطوق حكم الاستئناف والنتيجة');
      return;
    }
    void action.run(`${base}/appeal/ruling`, {
      data: ar,
      success: 'تم تسجيل حكم الاستئناف بنجاح',
      onSuccess: () => setShowRulingForm(false),
    });
  };

  const tone = appeal.status === 'appeal_judged' ? 'b-green' : appeal.status === 'appeal_filed' ? 'b-blue' : 'b-amber';

  return (
    <div className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <Icon name="scale" />
          <h3>مسار الاستئناف والاعتراض</h3>
        </div>
        <Badge text={appeal.statusLabel} tone={tone} />
      </div>
      <div className="card-b" style={{ padding: 16 }}>
        {/* Stage 1: Pending Appeal */}
        {appeal.status === 'pending_appeal' && (
          <div>
            <div style={{ padding: '12px 14px', background: appeal.isDeadlineOver ? 'var(--red-soft, #fee2e2)' : 'var(--amber-soft, #fef3c7)', borderRadius: 8, marginBottom: 12, border: '1px solid var(--line-soft)' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                <b style={{ color: appeal.isDeadlineOver ? 'var(--red)' : 'var(--ink)' }}>مهلة الاعتراض النظامية (30 يوماً):</b>
                <Badge
                  text={appeal.isDeadlineOver ? 'انقضت المهلة' : `متبقّي ${appeal.daysRemaining} يوم`}
                  tone={appeal.isDeadlineOver ? 'b-red' : 'b-amber'}
                />
              </div>
              <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>
                تنتهي مهلة تقديم لائحة الاعتراض بالاستئناف بتاريخ: <b>{appeal.deadlineAt ?? '—'}</b>
              </div>
            </div>

            {canAct && !showFilingForm && (
              <button className="btn sm" type="button" onClick={() => setShowFilingForm(true)}>
                <Icon name="send" /> قيد لائحة الاستئناف
              </button>
            )}

            {canAct && showFilingForm && (
              <form onSubmit={fileAppeal} style={{ background: 'var(--paper-2)', padding: 14, borderRadius: 8, marginTop: 10 }}>
                <h4 style={{ margin: '0 0 10px 0', fontSize: 14 }}>تسجيل قيد الاستئناف</h4>
                <div className="field">
                  <label>رقم طلب الاستئناف (ناجز)</label>
                  <input className="input" value={af.appeal_request_no} onChange={(e) => setAf({ ...af, appeal_request_no: e.target.value })} required />
                </div>
                <div className="field">
                  <label>محكمة الاستئناف</label>
                  <input className="input" value={af.appeal_court} onChange={(e) => setAf({ ...af, appeal_court: e.target.value })} required />
                </div>
                <div className="field">
                  <label>دائرة الاستئناف</label>
                  <input className="input" value={af.appeal_circuit} onChange={(e) => setAf({ ...af, appeal_circuit: e.target.value })} placeholder="دائرة الاستئناف الحقوقية الأولى" required />
                </div>
                <div className="field">
                  <label>تاريخ التقديم</label>
                  <input className="input" type="date" value={af.appeal_filed_at} onChange={(e) => setAf({ ...af, appeal_filed_at: e.target.value })} required />
                </div>
                <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
                  <button className="btn sm" type="submit" disabled={busy}><Icon name="check" /> حفظ قيد الاستئناف</button>
                  <button className="btn soft sm" type="button" disabled={busy} onClick={() => setShowFilingForm(false)}>إلغاء</button>
                </div>
              </form>
            )}
          </div>
        )}

        {/* Stage 2: Appeal Filed */}
        {appeal.status === 'appeal_filed' && (
          <div>
            <div className="tc-body" style={{ padding: 0, marginBottom: 12 }}>
              <div className="tc-row"><span className="k">رقم طلب الاستئناف</span><span className="v">{appeal.requestNo}</span></div>
              <div className="tc-row"><span className="k">محكمة الاستئناف</span><span className="v">{appeal.court}</span></div>
              <div className="tc-row"><span className="k">الدائرة</span><span className="v">{appeal.circuit}</span></div>
              <div className="tc-row"><span className="k">تاريخ القيد</span><span className="v">{appeal.filedAt}</span></div>
            </div>

            {canRule && !showRulingForm && (
              <button className="btn sm" type="button" onClick={() => setShowRulingForm(true)}>
                <Icon name="scale" /> تسجيل حكم الاستئناف
              </button>
            )}

            {canRule && showRulingForm && (
              <form onSubmit={recordAppealRuling} style={{ background: 'var(--paper-2)', padding: 14, borderRadius: 8, marginTop: 10 }}>
                <h4 style={{ margin: '0 0 10px 0', fontSize: 14 }}>تسجيل قرار/حكم الاستئناف</h4>
                <div className="field">
                  <label>نتيجة الاستئناف</label>
                  <select className="input" value={ar.appeal_outcome} onChange={(e) => setAr({ ...ar, appeal_outcome: e.target.value })}>
                    <option value="تأييد الحكم الابتدائي">تأييد الحكم الابتدائي</option>
                    <option value="تعديل الحكم">تعديل الحكم</option>
                    <option value="نقض الحكم">نقض الحكم</option>
                  </select>
                </div>
                <div className="field">
                  <label>منطوق حكم/قرار الاستئناف</label>
                  <textarea rows={4} value={ar.appeal_ruling} onChange={(e) => setAr({ ...ar, appeal_ruling: e.target.value })} required placeholder="حكمت المحكمة بتأييد الحكم الابتدائي..." />
                </div>
                <div className="field">
                  <label>تاريخ صدور القرار</label>
                  <input className="input" type="date" value={ar.appeal_judged_at} onChange={(e) => setAr({ ...ar, appeal_judged_at: e.target.value })} required />
                </div>
                <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
                  <button className="btn sm" type="submit" disabled={busy}><Icon name="check" /> حفظ حكم الاستئناف</button>
                  <button className="btn soft sm" type="button" disabled={busy} onClick={() => setShowRulingForm(false)}>إلغاء</button>
                </div>
              </form>
            )}
          </div>
        )}

        {/* Stage 3: Appeal Judged */}
        {appeal.status === 'appeal_judged' && (
          <div>
            <div className="tc-body" style={{ padding: 0, marginBottom: 10 }}>
              <div className="tc-row"><span className="k">رقم طلب الاستئناف</span><span className="v">{appeal.requestNo}</span></div>
              <div className="tc-row"><span className="k">محكمة الاستئناف</span><span className="v">{appeal.court}</span></div>
              <div className="tc-row"><span className="k">تاريخ صدور الحكم</span><span className="v">{appeal.judgedAt}</span></div>
            </div>
            <div style={{ marginTop: 8 }}>
              <b style={{ fontSize: 13, display: 'block', marginBottom: 4 }}>منطوق حكم الاستئناف:</b>
              <div style={{ padding: '12px 14px', background: 'var(--paper-2)', borderRadius: 8, whiteSpace: 'pre-line', lineHeight: 1.8, fontSize: 13.5 }}>
                {appeal.ruling}
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

/** «2026-10-05» و«09:30» من موعد الجلسة الحقيقيّ — ما يملأ نموذج التعديل ويُقارَن به. */
const slotOf = (hr: Hearing) => ({ day: hr.startsAt ? hr.startsAt.slice(0, 10) : '', time: hr.startsAt ? hr.startsAt.slice(11, 16) : '' });

/**
 * تحديث الجلسات — تسجيل النتيجة، والتعديل والتأجيل، والإلغاء. المغلقة والمؤرشفة لا يعرضها المُنادي.
 *
 * التعديل صنفان والخادم يفرّقهما بالموعد: تغيُّر اليوم أو الساعة **تأجيل** — سببٌ إلزاميّ، وتبقى الجلسة
 * الحاليّة سجلّاً «مؤجلة» وتُنشأ تاليتها ويُبلَّغ العميل؛ وتعديل العنوان أو الدائرة وحده تصحيحٌ في مكانه
 * لا يُشعَر به العميل. فنافذة السبب تُفتح للأوّل وحده، وما يجوز من الأزرار من الخادم (`canRecord`…).
 */
export const HearingUpdatesCard: React.FC<{ base: string; hearings: Hearing[] }> = ({ base, hearings }) => {
  const toast = useToast();
  // قفلٌ موحّد لإلغاء الجلسة — مفتاحه معرّف الجلسة فيُعطَّل زرّها وحده
  const cancelAction = useServerAction();
  const [editId, setEditId] = useState<number | null>(null);
  const [eh, setEh] = useState({ title: '', day: '', time: '', court: '', duration_min: '' });
  const [orig, setOrig] = useState({ day: '', time: '' });
  // الجلسة التي تنتظر سبب تأجيلها — النافذة تُركَّب عند الحاجة فلا يُرحَّل سببٌ من جلسةٍ لأخرى
  const [postponing, setPostponing] = useState<Hearing | null>(null);
  const [recOutcome, setRecOutcome] = useState('');

  // السلسلة من القائمة نفسها: السابقة بمعرّفها، ومَن لها تالية حُسم موعدها فلا يُحرَّك ثانيةً
  const byId = new Map(hearings.map((h) => [h.id, h]));
  const followed = new Set(hearings.map((h) => h.postponedFromId).filter((id): id is number => id != null));
  const moved = eh.day !== orig.day || eh.time !== orig.time;

  const recordHearing = (id: number, status: string) =>
    router.post(`${base}/hearings/${id}`, { status, outcome: recOutcome }, {
      preserveScroll: true,
      onSuccess: () => {
        setRecOutcome('');
        toast('تم تحديث الجلسة');
      },
      onError: (err) => toast(firstError(err, 'تعذّر تنفيذ الإجراء')),
    });
  const startEdit = (hr: Hearing) => {
    const slot = slotOf(hr);
    setEditId(hr.id);
    setOrig(slot);
    // المدّة تُعبّأ بما أُدخل — تغييرها وحده تصحيحٌ في مكانه لا تأجيل (الخادم يفرّق بالموعد وحده)
    setEh({ title: hr.title, ...slot, court: hr.court || '', duration_min: durationText(hr.durationMin) });
  };
  const submitEdit = (hr: Hearing) => {
    if (!eh.title.trim() || !eh.day.trim()) {
      toast('أدخل عنوان الجلسة والتاريخ');

      return;
    }

    if (moved) {
      setPostponing(hr);

      return;
    }

    router.post(`${base}/hearings/${hr.id}/update`, eh, {
      preserveScroll: true,
      onSuccess: () => {
        setEditId(null);
        toast('حُفظت بيانات الجلسة — الموعد باقٍ ولم يُبلَّغ العميل');
      },
      onError: (err) => toast(firstError(err, 'تعذّر تنفيذ الإجراء')),
    });
  };
  const cancelHearing = (id: number) =>
    cancelAction.run(`${base}/hearings/${id}/cancel`, {
      key: id,
      confirm: {
        title: 'إلغاء الجلسة',
        message: 'إلغاء الجلسة يُبلَّغ به العميل ولا يُتراجع عنه.',
        confirmLabel: 'إلغاء الجلسة',
        cancelLabel: 'تراجع',
        tone: 'danger',
      },
      success: 'أُلغيت الجلسة',
    });

  return (
    <div className="card">
      <div className="card-h"><h3>تحديث الجلسات</h3></div>
      <div className="card-b">
        {hearings.map((hr) => {
          const from = hr.postponedFromId ? byId.get(hr.postponedFromId) : undefined;

          return (
            <div key={hr.id} className="item" style={{ flexDirection: 'column', alignItems: 'stretch', gap: 8 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <div className="imeta">
                  <b>{hr.title}</b>
                  <span>{hr.day}{hr.time ? ` · ${hr.time}` : ''} · {hr.lapsed ? 'فائتة — سجّل نتيجتها' : hr.status}</span>
                  {hearingDurationLabel(hr.durationMin) && <span>{hearingDurationLabel(hr.durationMin)}</span>}
                  {from && <span>مؤجّلة من {from.day}{from.time ? ` · ${from.time}` : ''}</span>}
                </div>
                {(hr.canEdit || hr.canCancel) && (
                  <div className="iact" style={{ gap: 6 }}>
                    {hr.canEdit && <button className="btn soft sm" type="button" onClick={() => (editId === hr.id ? setEditId(null) : startEdit(hr))}>تعديل</button>}
                    {hr.canCancel && <button className="btn soft sm" type="button" disabled={cancelAction.busyKey === hr.id} onClick={() => cancelHearing(hr.id)}>إلغاء</button>}
                  </div>
                )}
              </div>

              {hr.canRecord && editId !== hr.id && (
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
                  <input className="input" placeholder="نتيجة الجلسة (اختياري)" value={recOutcome} onChange={(e) => setRecOutcome(e.target.value)} style={{ flex: 1, minWidth: 150 }} />
                  <button className="btn soft sm" type="button" onClick={() => recordHearing(hr.id, 'منعقدة')}>منعقدة</button>
                  <button className="btn soft sm" type="button" onClick={() => recordHearing(hr.id, 'مؤجلة')}>مؤجلة</button>
                </div>
              )}

              {editId === hr.id && (
                <form
                  onSubmit={(e) => {
                    e.preventDefault();
                    submitEdit(hr);
                  }}
                >
                  <div className="picker-grid">
                    <div className="field"><label>عنوان الجلسة</label><input className="input" value={eh.title} onChange={(e) => setEh({ ...eh, title: e.target.value })} /></div>
                    <div className="field"><label>الدائرة</label><input className="input" value={eh.court} onChange={(e) => setEh({ ...eh, court: e.target.value })} placeholder="الدائرة التجارية الأولى" /></div>
                  </div>
                  {followed.has(hr.id) ? (
                    <p style={{ margin: '4px 0 0', fontSize: 12.5, color: 'var(--muted)' }}>
                      حُدّد موعد الجلسة التالية لهذه الجلسة — لتغيير الموعد عدّل الجلسة التالية.
                    </p>
                  ) : (
                    <>
                      <div className="field"><label>التاريخ</label><input className="input" type="date" value={eh.day} onChange={(e) => setEh({ ...eh, day: e.target.value })} /></div>
                      <TimeSlotPicker value={eh.time} onChange={(t) => setEh({ ...eh, time: t })} date={eh.day} label="وقت الجلسة" required allowCustom={false} />
                    </>
                  )}
                  <HearingDurationField value={eh.duration_min} onChange={(v) => setEh({ ...eh, duration_min: v })} />
                  <div style={{ display: 'flex', gap: 6, marginTop: 8 }}>
                    <button className="btn sm" type="submit" disabled={!eh.time}>
                      <Icon name="cal" /> {moved ? 'تأجيل الجلسة…' : 'حفظ التعديل'}
                    </button>
                    <button className="btn soft sm" type="button" onClick={() => setEditId(null)}>إلغاء التعديل</button>
                  </div>
                </form>
              )}
            </div>
          );
        })}
      </div>

      {postponing && (
        <RescheduleDialog
          open
          domain="hearing"
          title={`تأجيل الجلسة «${postponing.title}»`}
          confirmLabel="تأجيل الجلسة"
          consequence={
            <>
              {postponing.canRecord
                ? 'تبقى الجلسة الحاليّة في السجلّ بحالة «مؤجلة» وسببها، '
                : 'تبقى الجلسة الحاليّة في السجلّ كما سُجّلت، '}
              وتُنشأ جلسةٌ جديدة في {eh.day}{eh.time ? ` · ${eh.time}` : ''}
              {eh.duration_min ? ` بمدّةٍ متوقّعة ${eh.duration_min} دقيقة` : ' بلا مدّةٍ محدّدة'} بتذكيراتها. ويُبلَّغ العميل والمحامي بإشعارٍ وبريد.
            </>
          }
          onClose={() => setPostponing(null)}
          onSubmit={(choice) =>
            new Promise<void>((resolve) => {
              router.post(`${base}/hearings/${postponing.id}/update`, { ...eh, ...choice }, {
                preserveScroll: true,
                onSuccess: () => {
                  setPostponing(null);
                  setEditId(null);
                  toast('أُجّلت الجلسة — وبقيت السابقة في السجلّ', 'success');
                },
                onError: (err) => toast(firstError(err, 'تعذّر تنفيذ الإجراء'), 'error'),
                onFinish: () => resolve(),
              });
            })
          }
        />
      )}
    </div>
  );
};

/** نافذة إرفاق مستند أو مذكرة مع إمكانية ربطها بجلسة محددة */
export const AttachDocModal: React.FC<{
  open: boolean;
  onClose: () => void;
  base: string;
  hearings: Hearing[];
}> = ({ open, onClose, base, hearings }) => {
  const toast = useToast();
  const [file, setFile] = useState<File | null>(null);
  const [hearingId, setHearingId] = useState<string>('');
  const [busy, setBusy] = useState(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) {
      toast('اختر ملفاً للإرفاق');
      return;
    }

    setBusy(true);
    const data: Record<string, any> = { file };
    if (hearingId) {
      data.hearing_id = hearingId;
    }

    router.post(`${base}/attach`, data, {
      preserveScroll: true,
      forceFormData: true,
      onSuccess: () => {
        toast('تم رفع وإرفاق المستند بنجاح');
        setFile(null);
        setHearingId('');
        onClose();
      },
      onError: (err) => toast(firstError(err, 'تعذّر تنفيذ الإجراء')),
      onFinish: () => setBusy(false),
    });
  };

  return (
    <Modal open={open} onClose={onClose} title="إرفاق مستند أو مذكرة" maxWidth={500}>
      <form onSubmit={handleSubmit} style={{ padding: 4 }}>
        <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 14 }}>
          يمكنك إرفاق لوائح جوابية، مذكرات دفاع، أو أدلة وإثباتات وربطها بجلسة قضائية معينة لتسهيل تتبعها.
        </div>

        <div className="field">
          <label>الملف المرفق (PDF, Word, صور)</label>
          <input
            className="input"
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            required
          />
        </div>

        {hearings.length > 0 && (
          <div className="field">
            <label>ربط بجلسة قضائية (اختياري)</label>
            <select className="input" value={hearingId} onChange={(e) => setHearingId(e.target.value)}>
              <option value="">— مستند عام لملف القضية (غير مرتبط بجلسة) —</option>
              {hearings.map((h) => (
                <option key={h.id} value={h.id}>
                  {h.title} ({h.day}{h.court ? ` · ${h.court}` : ''})
                </option>
              ))}
            </select>
          </div>
        )}

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 18 }}>
          <button className="btn soft sm" type="button" disabled={busy} onClick={onClose}>
            إلغاء
          </button>
          <button className="btn sm" type="submit" disabled={busy || !file}>
            <Icon name="upload" /> رفع وإرفاق المستند
          </button>
        </div>
      </form>
    </Modal>
  );
};
