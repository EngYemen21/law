import { router, usePage } from '@inertiajs/react';
import React, { useMemo, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { ALLOWED_DOC_ACCEPT } from '@/lib/chat';
import { LEGAL_CATS, legalCatServices } from '@/lib/newticket-data';

// نموذج «فتح تذكرة جديدة» — يطابق ticketFormView في التصميم المرجعي (babel-system.html).
// بطاقة واحدة بسيطة: بيانات العميل (readonly) + موضوع + قسم→خدمة + أهمية + رسالة + مستندات.
// الإرسال حقيقي: يُنشئ التذكرة في قاعدة البيانات ثم يُعيد التوجيه لصفحة محادثتها.

const fld: React.CSSProperties = {
  background: '#fff', border: '1.5px solid #d5dbe2', borderRadius: 9,
  padding: '11px 13px', fontFamily: 'inherit', fontSize: 14, width: '100%', boxSizing: 'border-box', color: '#222',
};
const fldRO: React.CSSProperties = { ...fld, background: '#eef1f4', color: '#555' };
const lbl: React.CSSProperties = { fontSize: 14, fontWeight: 800, color: '#1f2937', marginBottom: 7, display: 'block' };
const rowStyle: React.CSSProperties = { display: 'flex', gap: 18, marginBottom: 16, flexWrap: 'wrap' };
const Req = () => <span style={{ color: '#C0392B' }}>*</span>;

const NewTicket: React.FC = () => {
  const toast = useToast();
  const { props } = usePage() as { props: { auth?: { user?: { name?: string; email?: string; phone?: string } } } };
  const u = props?.auth?.user ?? {};

  const [subject, setSubject] = useState('');
  const [cat, setCat] = useState('');
  const [svc, setSvc] = useState('');
  const [priority, setPriority] = useState('متوسطة');
  const [body, setBody] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [err, setErr] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const services = useMemo(() => legalCatServices(cat), [cat]);

  const onPickFiles = (e: React.ChangeEvent<HTMLInputElement>) => {
    const picked = Array.from(e.target.files || []);
    if (picked.length) setFiles((prev) => [...prev, ...picked]);
    e.target.value = '';
  };
  const removeFile = (i: number) => setFiles((prev) => prev.filter((_, x) => x !== i));

  const reset = () => { setSubject(''); setCat(''); setSvc(''); setPriority('متوسطة'); setBody(''); setFiles([]); setErr(''); };

  // إنشاء التذكرة فعلياً في قاعدة البيانات (مع مرفقاتها) ثم الانتقال إليها
  const submit = () => {
    if (submitting) return; // منع الإرسال المزدوج
    if (!subject.trim() || !cat || !svc || !body.trim()) {
      setErr('يرجى تعبئة: موضوع التذكرة، القسم، الخدمة، ونص الرسالة.');
      return;
    }
    setErr('');
    setSubmitting(true);
    router.post('/tickets', {
      type: svc,            // الخدمة المحدّدة
      subject: subject.trim(),
      department: cat,      // القسم
      details: body.trim(), // نص الرسالة
      priority,
      files,
    }, {
      forceFormData: true,
      onError: () => { setSubmitting(false); toast('تعذّر إرسال التذكرة، تحقّق من البيانات والمرفقات'); },
      onFinish: () => setSubmitting(false),
    });
  };

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
            <div style={{ flex: 2, minWidth: 240 }}><label style={lbl}>موضوع التذكرة <Req /></label><input style={fld} value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="اكتب موضوع التذكرة باختصار…" /></div>
          </div>

          {/* صف 3: القسم + الخدمة + الأهمية */}
          <div style={rowStyle}>
            <div style={{ flex: 1, minWidth: 220 }}><label style={lbl}>القسم <Req /></label>
              <select style={fld} value={cat} onChange={(e) => { setCat(e.target.value); setSvc(''); }}>
                <option value="">اختر القسم…</option>
                {LEGAL_CATS.map((x) => <option key={x.c} value={x.c}>{x.c}</option>)}
              </select>
            </div>
            <div style={{ flex: 2, minWidth: 240 }}><label style={lbl}>الخدمة المتعلقة بالتذكرة <Req /></label>
              <select style={fld} value={svc} onChange={(e) => setSvc(e.target.value)} disabled={!cat}>
                <option value="">{cat ? 'اختر الخدمة…' : '— اختر القسم أولاً —'}</option>
                {services.map((s) => <option key={s} value={s}>{s}</option>)}
              </select>
            </div>
            <div style={{ flex: 1, minWidth: 160 }}><label style={lbl}>الأهمية</label>
              <select style={fld} value={priority} onChange={(e) => setPriority(e.target.value)}>
                <option value="عالية">عالية</option>
                <option value="متوسطة">متوسطة</option>
                <option value="منخفضة">منخفضة</option>
              </select>
            </div>
          </div>

          {/* صف 4: نص الرسالة */}
          <div style={{ marginBottom: 8 }}><label style={lbl}>نص الرسالة <Req /></label>
            <textarea style={{ ...fld, minHeight: 150, lineHeight: 1.9, resize: 'vertical' }} value={body} onChange={(e) => setBody(e.target.value)} placeholder="اشرح موضوع طلبك، الوقائع الأساسية، والأطراف ذات العلاقة…" />
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
            <button className="btn" style={{ background: '#0E5C9C' }} onClick={submit} disabled={submitting} type="button">
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
