import { router, usePage } from '@inertiajs/react';
import React, { useMemo, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { ALLOWED_DOC_ACCEPT } from '@/lib/chat';
import { firstError } from '@/lib/server-message';

// نموذج «فتح تذكرة جديدة» — يطابق ticketFormView في التصميم المرجعي (babel-system.html).
// بطاقة واحدة بسيطة: بيانات العميل (readonly) + موضوع + قسم→خدمة + أهمية + رسالة + مستندات.
// الأقسام والخدمات من كتالوج الخادم (TicketController::create) — لا قائمة ثابتة في الواجهة؛
// ويُرسَل معرّفا القسم والخدمة فيتحقّق الخادم أنّ الخدمة تتبع قسمها وأنّ كليهما متاح.
// قسم «التنفيذ» (معرّفه من الخادم لا اسمه) يضيف حقول طلب التنفيذ: نوع السند، قيمة المطالبة، المنفَّذ ضدّه —
// وإليه يفتح زرّ «طلب تنفيذ جديد» النموذجَ (`?department=enforcement` ← `preselectDepartmentId`).

interface CatalogueService { id: number; name: string }
interface CatalogueDepartment { id: number; name: string; services: CatalogueService[] }

interface PageProps {
  auth?: { user?: { name?: string; email?: string; phone?: string } };
  catalogue?: CatalogueDepartment[];
  enforcementId?: number | null;
  execSanads?: string[];
  preselectDepartmentId?: number | null;
}

const fld: React.CSSProperties = {
  background: '#fff', border: '1.5px solid #d5dbe2', borderRadius: 9,
  padding: '11px 13px', fontFamily: 'inherit', fontSize: 14, width: '100%', boxSizing: 'border-box', color: '#222',
};
const fldRO: React.CSSProperties = { ...fld, background: '#eef1f4', color: '#555' };
const lbl: React.CSSProperties = { fontSize: 14, fontWeight: 800, color: '#1f2937', marginBottom: 7, display: 'block' };
const rowStyle: React.CSSProperties = { display: 'flex', gap: 18, marginBottom: 16, flexWrap: 'wrap' };
const fieldErr: React.CSSProperties = { color: '#C0392B', fontSize: 12.5, marginTop: 5 };
const Req = () => <span style={{ color: '#C0392B' }}>*</span>;

const NewTicket: React.FC = () => {
  const toast = useToast();
  const { props } = usePage() as unknown as { props: PageProps };
  const u = props?.auth?.user ?? {};
  const catalogue = props?.catalogue ?? [];
  const execSanads = props?.execSanads ?? [];
  const preselect = catalogue.some((d) => d.id === props?.preselectDepartmentId) ? String(props?.preselectDepartmentId) : '';

  const [subject, setSubject] = useState('');
  const [departmentId, setDepartmentId] = useState(preselect);
  const [serviceId, setServiceId] = useState('');
  const [priority, setPriority] = useState('متوسطة');
  const [body, setBody] = useState('');
  const [sanad, setSanad] = useState('');
  const [claimAmount, setClaimAmount] = useState('');
  const [opponent, setOpponent] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [err, setErr] = useState('');
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const isEnforcement = props?.enforcementId != null && departmentId === String(props.enforcementId);

  const services = useMemo(
    () => catalogue.find((d) => String(d.id) === departmentId)?.services ?? [],
    [catalogue, departmentId],
  );

  const onPickFiles = (e: React.ChangeEvent<HTMLInputElement>) => {
    const picked = Array.from(e.target.files || []);
    if (picked.length) setFiles((prev) => [...prev, ...picked]);
    e.target.value = '';
  };
  const removeFile = (i: number) => setFiles((prev) => prev.filter((_, x) => x !== i));

  const reset = () => {
    setSubject(''); setDepartmentId(''); setServiceId(''); setPriority('متوسطة');
    setBody(''); setSanad(''); setClaimAmount(''); setOpponent('');
    setFiles([]); setErr(''); setServerErrors({});
  };

  // إنشاء التذكرة فعلياً في قاعدة البيانات (مع مرفقاتها) ثم الانتقال إليها
  const submit = () => {
    if (submitting) return; // منع الإرسال المزدوج
    if (!subject.trim() || !departmentId || !serviceId || !body.trim()) {
      setErr('يرجى تعبئة: موضوع التذكرة، القسم، الخدمة، ونص الرسالة.');
      return;
    }
    if (isEnforcement && !sanad) {
      setErr('يرجى اختيار نوع السند التنفيذي.');
      return;
    }
    setErr('');
    setServerErrors({});
    setSubmitting(true);
    router.post('/tickets', {
      department_id: departmentId,
      service_id: serviceId,
      subject: subject.trim(),
      details: body.trim(),
      priority,
      // الخصم لكلّ الأقسام (قرار المالك 2026-10-02): ينتقل إلى القضيّة ثمّ «المنفَّذ ضده» في ملفّ التنفيذ
      opponent_name: opponent.trim() || null,
      ...(isEnforcement ? { exec_sanad: sanad, claim_amount: claimAmount || null } : {}),
      files,
    }, {
      forceFormData: true,
      onError: (errors) => {
        setServerErrors(errors as Record<string, string>);
        // سبب الرفض نفسه (مثل حجم مرفقٍ أو نوعه) — الأخطاء تحت حقولها أيضاً، ومنها ما لا حقل ظاهراً له
        toast(firstError(errors as Record<string, string>, 'تعذّر إرسال التذكرة، تحقّق من البيانات والمرفقات'));
      },
      onFinish: () => setSubmitting(false),
    });
  };

  const departmentError = serverErrors.department_id;
  const serviceError = serverErrors.service_id || serverErrors.type;

  return (
    <>
      <div className="greet">
        <h2>فتح تذكرة جديدة</h2>
        <p>عبّئ بيانات طلبك وسيتواصل معك الفريق القانوني.</p>
      </div>

      <div className="card">
        <div className="card-b" style={{ padding: '22px 24px' }}>
          {/* صف 1: الاسم + البريد */}
          <div style={rowStyle}>
            <div style={{ flex: 1, minWidth: 240 }}><label style={lbl}>الإسم</label><input style={fldRO} value={u.name || ''} readOnly /></div>
            <div style={{ flex: 1, minWidth: 240 }}><label style={lbl}>البريد الإلكتروني</label><input style={fldRO} value={u.email || ''} readOnly /></div>
          </div>

          {/* صف 2: رقم الجوال + موضوع التذكرة */}
          <div style={rowStyle}>
            <div style={{ flex: 1, minWidth: 240 }}><label style={lbl}>رقم الجوال</label><input style={{ ...fldRO, direction: 'ltr', textAlign: 'right' }} value={u.phone || ''} readOnly /></div>
            <div style={{ flex: 2, minWidth: 240 }}><label style={lbl} htmlFor="nt-subject">موضوع التذكرة <Req /></label><input id="nt-subject" style={fld} value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="اكتب موضوع التذكرة باختصار…" /></div>
          </div>

          {/* صف 3: القسم + الخدمة + الأهمية */}
          <div style={rowStyle}>
            <div style={{ flex: 1, minWidth: 220 }}><label style={lbl} htmlFor="nt-department">القسم <Req /></label>
              <select
                id="nt-department"
                style={fld}
                value={departmentId}
                disabled={catalogue.length === 0}
                onChange={(e) => { setDepartmentId(e.target.value); setServiceId(''); }}
              >
                <option value="">{catalogue.length ? 'اختر القسم…' : 'لا توجد أقسام متاحة حالياً'}</option>
                {catalogue.map((d) => <option key={d.id} value={String(d.id)}>{d.name}</option>)}
              </select>
              {departmentError && <div style={fieldErr}>{departmentError}</div>}
            </div>
            <div style={{ flex: 2, minWidth: 240 }}><label style={lbl} htmlFor="nt-service">الخدمة المتعلقة بالتذكرة <Req /></label>
              <select id="nt-service" style={fld} value={serviceId} onChange={(e) => setServiceId(e.target.value)} disabled={!departmentId}>
                <option value="">{departmentId ? 'اختر الخدمة…' : '— اختر القسم أولاً —'}</option>
                {services.map((s) => <option key={s.id} value={String(s.id)}>{s.name}</option>)}
              </select>
              {serviceError && <div style={fieldErr}>{serviceError}</div>}
            </div>
            <div style={{ flex: 1, minWidth: 160 }}><label style={lbl} htmlFor="nt-priority">الأهمية</label>
              <select id="nt-priority" style={fld} value={priority} onChange={(e) => setPriority(e.target.value)}>
                <option value="عالية">عالية</option>
                <option value="متوسطة">متوسطة</option>
                <option value="منخفضة">منخفضة</option>
              </select>
            </div>
          </div>

          {/* قسم التنفيذ وحده: بيانات السند والمطالبة — تنتقل إلى ملفّ التنفيذ عند اعتماد المسار */}
          {isEnforcement && (
            <div style={rowStyle}>
              <div style={{ flex: 1, minWidth: 220 }}><label style={lbl} htmlFor="nt-sanad">نوع السند التنفيذي <Req /></label>
                <select id="nt-sanad" style={fld} value={sanad} onChange={(e) => setSanad(e.target.value)}>
                  <option value="">اختر نوع السند…</option>
                  {execSanads.map((x) => <option key={x} value={x}>{x}</option>)}
                </select>
                {serverErrors.exec_sanad && <div style={fieldErr}>{serverErrors.exec_sanad}</div>}
              </div>
              <div style={{ flex: 1, minWidth: 180 }}><label style={lbl} htmlFor="nt-amount">قيمة المطالبة (ريال)</label>
                <input id="nt-amount" style={fld} type="number" min={0} inputMode="numeric" value={claimAmount} onChange={(e) => setClaimAmount(e.target.value)} placeholder="مثال: 85000" />
                {serverErrors.claim_amount && <div style={fieldErr}>{serverErrors.claim_amount}</div>}
              </div>
              <div style={{ flex: 2, minWidth: 240 }}><label style={lbl} htmlFor="nt-opponent">المنفَّذ ضده (إن وجد)</label>
                <input id="nt-opponent" style={fld} maxLength={190} value={opponent} onChange={(e) => setOpponent(e.target.value)} placeholder="اسم الطرف الآخر" />
                {serverErrors.opponent_name && <div style={fieldErr}>{serverErrors.opponent_name}</div>}
              </div>
            </div>
          )}

          {/* بقيّة الأقسام: الخصم إن وُجد — كان حقلاً لقسم التنفيذ وحده، فتنشأ القضيّة بلا خصمٍ ويُفتح تنفيذ حكمها بلا منفَّذٍ ضده */}
          {!isEnforcement && (
            <div style={rowStyle}>
              <div style={{ flex: 1, minWidth: 240 }}><label style={lbl} htmlFor="nt-opponent">الطرف الآخر / الخصم (إن وجد)</label>
                <input id="nt-opponent" style={fld} maxLength={190} value={opponent} onChange={(e) => setOpponent(e.target.value)} placeholder="اسم الفرد أو الجهة" />
                {serverErrors.opponent_name && <div style={fieldErr}>{serverErrors.opponent_name}</div>}
              </div>
            </div>
          )}

          {/* صف 4: نص الرسالة */}
          <div style={{ marginBottom: 8 }}><label style={lbl} htmlFor="nt-body">نص الرسالة <Req /></label>
            <textarea id="nt-body" style={{ ...fld, minHeight: 150, lineHeight: 1.9, resize: 'vertical' }} value={body} onChange={(e) => setBody(e.target.value)} placeholder="اشرح موضوع طلبك، الوقائع الأساسية، والأطراف ذات العلاقة…" />
          </div>

          {/* المستندات الداعمة */}
          <div style={{ marginBottom: 18 }}><label style={lbl}>المستندات الداعمة</label>
            <div onClick={() => fileRef.current?.click()} style={{ border: '1.6px dashed #cdd6df', borderRadius: 10, padding: 16, textAlign: 'center', cursor: 'pointer', color: '#5b6b7c', fontSize: 13.5 }}>
              <Icon name="upload" /> اضغط لإرفاق المستندات
            </div>
            <input ref={fileRef} type="file" multiple hidden accept={ALLOWED_DOC_ACCEPT} onChange={onPickFiles} />
            <div style={{ marginTop: 8 }}>
              {files.map((f, i) => (
                <span key={i} style={{ display: 'inline-block', background: '#eef4fb', border: '1px solid #d3e0f0', borderRadius: 8, padding: '5px 11px', margin: 3, fontSize: 12.5 }}>
                  <Icon name="doc" /> {f.name} <span onClick={(e) => { e.stopPropagation(); removeFile(i); }} style={{ color: '#C0392B', cursor: 'pointer', fontWeight: 700 }}>✕</span>
                </span>
              ))}
            </div>
          </div>

          {err && <div style={{ color: '#C0392B', fontSize: 13, marginBottom: 12 }}>{err}</div>}

          {/* أزرار */}
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn" style={{ background: '#0E5C9C' }} onClick={submit} disabled={submitting || catalogue.length === 0} type="button">
              {submitting ? <><span className="spin" /> جارٍ الإرسال…</> : <><Icon name="send" /> إرسال التذكرة</>}
            </button>
            <button className="btn soft" onClick={reset} type="button"><Icon name="close" /> مسح</button>
          </div>
        </div>
      </div>
    </>
  );
};

export default NewTicket;
