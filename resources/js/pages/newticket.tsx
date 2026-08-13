import { router } from '@inertiajs/react';
import React, { useEffect, useReducer, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { maskLawyer } from '@/lib/utils';
import { ALLOWED_DOC_ACCEPT, ALLOWED_DOC_HINT, todayDate } from '@/lib/chat';
import {
  SVC, SVC_GROUPS, RAIL, STAGE_RAIL, CONSULT_PRICES, VAT_RATE, TF_FILES,
  qrRects, QR_SIZE,
} from '@/lib/newticket-data';

// يطابق viewNewticket + محرّك tf* في index (82).html

const CLIENT_IP = '178.45.12.90', OFFICE_IP = '212.71.46.10', SYS_IP = '10.0.0.5';
const delay = (ms: number) => new Promise((r) => setTimeout(r, ms));

interface Msg {
  id: number; actor: string; name?: string; role?: string; html?: string; time?: string;
  typing?: boolean; checklist?: { intro: string; items: string[]; done: number };
}

const avatarFor = (actor: string) =>
  actor === 'ai' ? <img src="/images/mono.jpg" alt="" />
    : actor === 'me' ? 'أنت'
    : actor === 'lawyer' ? '⚖'
    : actor === 'admin' ? '■' : '⚙';

const ipFor = (actor: string) =>
  actor === 'me' || actor === 'client' ? CLIENT_IP : actor === 'system' ? SYS_IP : OFFICE_IP;

const MetaLine: React.FC<{ ip: string; time: string }> = ({ ip, time }) => (
  <div className="msg-meta">
    <span><Icon name="cal" />{todayDate()}</span>
    <span><Icon name="clock" />{time}</span>
    <span><Icon name="pin" /><bdi>{ip}</bdi></span>
  </div>
);

const MsgView: React.FC<{ m: Msg }> = ({ m }) => {
  if (m.typing) {
    return (
      <div className={`msg ${m.actor}`}>
        <div className={`av ${m.actor}`}>{avatarFor(m.actor)}</div>
        <div className="bubble-wrap">
          <div className="bubble"><span className="typing"><span /><span /><span /></span></div>
        </div>
      </div>
    );
  }
  return (
    <div className={`msg ${m.actor}`}>
      <div className={`av ${m.actor}`}>{avatarFor(m.actor)}</div>
      <div className="bubble-wrap">
        <div className="who">
          <b>{m.name}</b>
          {m.role ? <span className={`role ${m.actor}`}>{m.role}</span> : null}
          <time>{m.time}</time>
        </div>
        {m.checklist ? (
          <div className="bubble">
            <p dangerouslySetInnerHTML={{ __html: m.checklist.intro }} />
            <ul className="checklist">
              {m.checklist.items.map((x, i) => (
                <li key={i} className={i < m.checklist!.done ? 'done' : ''}>
                  <span className="tick"><Icon name="check" /></span>{x}
                </li>
              ))}
            </ul>
          </div>
        ) : (
          <div className="bubble" dangerouslySetInnerHTML={{ __html: m.html || '' }} />
        )}
        <MetaLine ip={ipFor(m.actor)} time={m.time || ''} />
      </div>
    </div>
  );
};

const Qr: React.FC<{ seed: string }> = ({ seed }) => (
  <svg className="qr" viewBox={`0 0 ${QR_SIZE} ${QR_SIZE}`}>
    <g fill="#0A2A55">
      {qrRects(seed).map((r, i) => <rect key={i} x={r.x} y={r.y} width={r.c} height={r.c} />)}
    </g>
  </svg>
);

const NewTicket: React.FC = () => {
  const toast = useToast();
  const tf = useRef({ no: '', stage: 'create', service: '', dept: '', consult: '', branch: '', lawyer: '', day: '', time: '', files: [] as string[] });
  const clock = useRef(new Date());
  const idc = useRef(0);
  const [, force] = useReducer((x) => x + 1, 0);

  const [messages, setMessages] = useState<Msg[]>([]);
  const [status, setStatus] = useState({ text: 'مسودة', tone: 'sb-grey' });
  const [threadSub, setThreadSub] = useState('ابدأ بإنشاء طلبك');
  const [actionStage, setActionStage] = useState('create');
  const [composer, setComposer] = useState(false);
  const [reply, setReply] = useState('');
  const [fService, setFService] = useState('');
  const [details, setDetails] = useState('');
  const [errSvc, setErrSvc] = useState(false);
  const [pickedFiles, setPickedFiles] = useState<File[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [invoice, setInvoice] = useState({ amt: 0, vat: 0, total: 0 });
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, [messages]);

  const tfNow = () => {
    clock.current = new Date(clock.current.getTime() + (60000 + Math.random() * 120000));
    return clock.current.toLocaleTimeString('ar-SA', { hour: '2-digit', minute: '2-digit' });
  };
  const setStage = (s: string) => { tf.current.stage = s; force(); };
  const addMsg = (actor: string, name: string, role: string, html: string) =>
    setMessages((m) => [...m, { id: ++idc.current, actor, name, role, html, time: tfNow() }]);
  const addTyping = (actor: string) => { const id = ++idc.current; setMessages((m) => [...m, { id, actor, typing: true }]); return id; };
  const rm = (id: number) => setMessages((m) => m.filter((x) => x.id !== id));
  const addChecklist = (actor: string, name: string, role: string, intro: string, items: string[]) => {
    const id = ++idc.current;
    setMessages((m) => [...m, { id, actor, name, role, time: tfNow(), checklist: { intro, items, done: 0 } }]);
    return id;
  };
  const tickChecklist = (id: number, done: number) =>
    setMessages((m) => m.map((x) => (x.id === id && x.checklist ? { ...x, checklist: { ...x.checklist, done } } : x)));

  // ── المراحل ──
  const start = () => {
    tf.current = { no: '', stage: 'create', service: '', dept: '', consult: '', branch: '', lawyer: '', day: '', time: '', files: [] };
    clock.current = new Date();
    setMessages([]); setComposer(false); setFService(''); setDetails(''); setErrSvc(false); setPickedFiles([]);
    setStatus({ text: 'مسودة', tone: 'sb-grey' }); setThreadSub('ابدأ بإنشاء طلبك'); setActionStage('create');
    force();
    setTimeout(() => addMsg('system', 'النظام', 'نظام', 'مرحباً بك. اختر نوع الخدمة، صِف طلبك، وأرفق مستنداتك ثم اضغط «إرسال الطلب».'), 0);
  };
  useEffect(() => { start(); /* eslint-disable-next-line */ }, []);

  const addFile = () => {
    if (tf.current.files.length >= TF_FILES.length) return;
    tf.current.files = [...tf.current.files, TF_FILES[tf.current.files.length]];
    force();
  };
  const rmFile = (i: number) => { tf.current.files = tf.current.files.filter((_, x) => x !== i); force(); };

  // رفع مستندات داعمة حقيقية (اختيار من جهاز العميل) قبل إنشاء التذكرة
  const onPickFiles = (e: React.ChangeEvent<HTMLInputElement>) => {
    const picked = Array.from(e.target.files || []);
    if (picked.length) setPickedFiles((prev) => [...prev, ...picked]);
    e.target.value = '';
  };
  const removePickedFile = (i: number) => setPickedFiles((prev) => prev.filter((_, x) => x !== i));

  // إنشاء التذكرة فعلياً في قاعدة البيانات (مع مرفقاتها الحقيقية) ثم الانتقال إليها
  const submit = () => {
    if (submitting) return; // منع الإرسال المزدوج (حارس متزامن قبل إعادة الرسم)
    if (!fService) { setErrSvc(true); return; }
    setSubmitting(true);
    router.post('/tickets', {
      type: SVC[fService].label,
      department: SVC[fService].dept,
      details: details.trim(),
      files: pickedFiles,
    }, {
      forceFormData: true,
      onError: () => { setSubmitting(false); toast('تعذّر إرسال الطلب، تحقّق من البيانات والمرفقات'); },
      onFinish: () => setSubmitting(false),
    });
  };

  const welcome = async () => {
    setStage('welcome'); setStatus({ text: 'جديدة', tone: 'sb-blue' }); setThreadSub('تم استلام الطلب');
    const t = addTyping('ai'); await delay(850); rm(t);
    addMsg('ai', 'الفريق القانوني', 'استقبال', '<p>مرحباً بكم في مكتب المحاماة والاستشارات القانونية. تم استلام طلبكم بنجاح.</p><p>يرجى إرفاق جميع المستندات المتعلقة بالموضوع إن وجدت. سيتم تحليل الطلب وإحالته إلى القسم القانوني المختص للمراجعة.</p><p>يمكنكم كتابة أي ملاحظة أو سؤال في التذكرة في أي وقت من خلال صندوق الكتابة بالأسفل.</p>');
    setComposer(true); await delay(450); await analysis();
  };

  const analysis = async () => {
    setStage('analysis'); setStatus({ text: 'قيد المراجعة', tone: 'sb-blue' });
    const items = ['قراءة التذكرة والمرفقات', 'تصنيف الموضوع', 'تحديد القسم المختص', 'استخراج الوقائع', 'استخراج الأطراف', 'استخراج الطلبات'];
    const id = addChecklist('ai', 'الفريق القانوني', 'تحليل', '<p>جارٍ مراجعة طلبكم ودراسة مستنداتكم…</p>', items);
    for (let i = 0; i < items.length; i++) { await delay(600); tickChecklist(id, i + 1); }
    await delay(350);
    if (tf.current.files.length < 2) missing(SVC[tf.current.service].docs); else await referred();
  };

  const missing = (req: string[]) => {
    setStage('missing'); setStatus({ text: 'بانتظار مستندات', tone: 'sb-amber' }); setThreadSub('مطلوب استكمال المستندات');
    addMsg('ai', 'الفريق القانوني', 'نواقص', `<p>لمساعدتنا في دراسة الطلب بشكل أدق، يرجى إرفاق المستندات التالية (حسب نوع القضية):</p><div class="doc-list">${req.map((d) => `<span class="doc-chip">${d}</span>`).join('')}</div>`);
    setActionStage('missing');
  };
  const confirmDocs = async () => {
    if (tf.current.files.length < 2) { addFile(); addFile(); }
    addMsg('me', 'أنت', '', `<p>تم إرفاق المستندات المطلوبة.</p><div class="doc-list">${tf.current.files.map((f) => `<span class="doc-chip">📎 ${f}</span>`).join('')}</div>`);
    setActionStage(''); await referred();
  };

  const referred = async () => {
    setStage('referred'); setStatus({ text: 'أُحيلت للقسم', tone: 'sb-blue' }); setThreadSub('تمت الإحالة');
    const t = addTyping('ai'); await delay(800); rm(t);
    addMsg('ai', 'الفريق القانوني', 'إحالة', `<p>تم استلام جميع المستندات وإحالة الطلب إلى <b>${tf.current.dept}</b> المختص لدراسة الموضوع.</p>`);
    await delay(450); await study();
  };
  const study = async () => {
    setStage('study'); setStatus({ text: 'قيد التحليل', tone: 'sb-blue' }); setThreadSub('المستشار يدرس الملف');
    const items = ['تلخيص القضية', 'تلخيص المرفقات', 'تجهيز الوقائع', 'تحديد النقاط المهمة'];
    const id = addChecklist('system', 'لوحة المستشار', 'نظام', '<p>يجهّز الفريق القانوني ملخص الملف للمستشار:</p>', items);
    for (let i = 0; i < items.length; i++) { await delay(560); tickChecklist(id, i + 1); }
    await delay(400); setActionStage('studyApprove');
  };
  const lawyerReply = async () => {
    setActionStage(''); setStage('legalreply'); setStatus({ text: 'بانتظار حجز الاستشارة', tone: 'sb-amber' });
    const t = addTyping('lawyer'); await delay(900); rm(t); setThreadSub('الرأي القانوني الأولي');
    addMsg('lawyer', 'المستشار القانوني', 'مستشار', '<p>تمت دراسة طلبكم مبدئياً من قبل الفريق القانوني.</p><p>ولإبداء الرأي القانوني الكامل ومناقشة تفاصيل الحالة، نأمل حجز استشارة قانونية.</p>');
    setActionStage('legalreply');
  };
  const chooseConsult = () => {
    setStage('consult'); setThreadSub('اختيار نوع الاستشارة');
    addMsg('me', 'أنت', '', 'أرغب بحجز استشارة قانونية.');
    setActionStage('consult');
  };
  const pickConsult = (c: string) => { tf.current.consult = c; force(); };
  const goInvoice = () => {
    if (!tf.current.consult) return;
    setStage('invoice'); setStatus({ text: 'بانتظار السداد', tone: 'sb-amber' }); setThreadSub('إصدار الفاتورة');
    const amt = CONSULT_PRICES[tf.current.consult] || 450, vat = Math.round(amt * VAT_RATE), total = amt + vat;
    setInvoice({ amt, vat, total });
    addMsg('system', 'النظام', 'نظام', 'تم إصدار فاتورة الاستشارة وإرسال رابط الدفع.');
    setActionStage('invoice');
  };
  const paid = async () => {
    setActionStage('');
    addMsg('me', 'أنت', '', `تم سداد مبلغ <b>${invoice.total} ر.س</b> بنجاح.`);
    setStage('paid'); setStatus({ text: 'بانتظار تحديد الموعد', tone: 'sb-amber' });
    const t = addTyping('ai'); await delay(700); rm(t);
    addMsg('ai', 'الفريق القانوني', 'دفع', '<p>تم تأكيد السداد. يمكنكم الآن اختيار الموعد المناسب من المواعيد المتاحة.</p>');
    await delay(300); chooseSlot();
  };
  const chooseSlot = () => { setStage('slot'); setThreadSub('اختيار الموعد'); setActionStage('slot'); };
  const confirm = async (branch: string, lawyer: string, day: string, time: string) => {
    setActionStage('');
    tf.current.branch = branch; tf.current.lawyer = lawyer; tf.current.day = day; tf.current.time = time || '11:30 ص';
    addMsg('me', 'أنت', '', `تأكيد حجز موعد: <b>${tf.current.day} — ${tf.current.time}</b>.`);
    setStage('confirm'); setStatus({ text: 'موعد مؤكد', tone: 'sb-green' }); setThreadSub('تم تأكيد الموعد');
    const t = addTyping('ai'); await delay(800); rm(t);
    const c = tf.current;
    addMsg('ai', 'الفريق القانوني', 'تأكيد',
      `<p>تم تأكيد موعدك. هذه بطاقة الموعد الخاصة بك:</p><div class="appt" style="margin-top:11px"><div class="appt-top"><div><b>بطاقة موعد استشارة</b><span>${c.no}</span></div><div style="font-weight:800;font-size:13px">${c.consult}</div></div><div class="appt-body"><div class="appt-meta"><div class="row">📅 <b>${c.day}</b><span>· ${c.time}</span></div><div class="row">🕐 <span>${maskLawyer(c.lawyer)}</span></div><div class="row">📍 <span>${c.branch}</span></div></div></div><div class="email-note">✉ تم إرسال إشعار التأكيد إلى بريدك الإلكتروني.</div></div>`);
    addMsg('system', 'النظام', 'نظام', `تم تحويل طلبك إلى استشارة ${c.consult}: وصلت إلى «استقبال الاستشارات» لدى المكتب، وتجدها في «استشاراتي».`);
    setActionStage(c.consult === 'مرئية' ? 'afterVideo' : 'afterOther');
  };
  const session = async () => {
    setActionStage(''); setStage('session'); setStatus({ text: 'الاستشارة جارية', tone: 'sb-blue' }); setThreadSub('الاستشارة منعقدة');
    addMsg('system', 'النظام', 'نظام', `بدأت الاستشارة ${tf.current.consult} مع ${maskLawyer(tf.current.lawyer)}.`);
    const items = ['تسجيل الجلسة', 'تحويل الصوت إلى نص', 'تلخيص الجلسة', 'استخراج التوصيات', 'استخراج المهام'];
    const id = addChecklist('ai', 'الفريق القانوني', 'أثناء الجلسة', '<p>يوثّق فريقنا الجلسة ويُعدّ محضرها:</p>', items);
    for (let i = 0; i < items.length; i++) { await delay(560); tickChecklist(id, i + 1); }
    await delay(420); setActionStage('sessionDone');
  };
  const lawyerReview = async () => {
    setActionStage(''); setStage('lawyerrev'); setStatus({ text: 'مراجعة المستشار', tone: 'sb-blue' });
    const t = addTyping('lawyer'); await delay(800); rm(t);
    addMsg('lawyer', 'المستشار القانوني', 'اعتماد', '<p>راجعتُ الملخص والتوصيات والإجراءات المقترحة، وهي معتمدة للرفع إلى الإدارة.</p>');
    setActionStage('lawyerRevDone');
  };
  const adminReview = async () => {
    setActionStage(''); setStage('adminrev'); setStatus({ text: 'اعتماد الإدارة', tone: 'sb-blue' });
    const t = addTyping('admin'); await delay(800); rm(t);
    addMsg('admin', 'الإدارة', 'اعتماد', '<p>تم اعتماد ملخص الاستشارة ومحضر الاجتماع. يُرسل الآن للعميل.</p>');
    await delay(400); await result();
  };
  const result = async () => {
    setStage('result'); setStatus({ text: 'مكتملة', tone: 'sb-green' }); setThreadSub('اكتملت المعالجة');
    const t = addTyping('ai'); await delay(800); rm(t); const c = tf.current;
    addMsg('ai', 'الفريق القانوني', 'النتيجة',
      `<p>تم الانتهاء من دراسة الموضوع. يمكنكم الاطلاع على ملخص الاستشارة والإجراءات المقترحة داخل التذكرة.</p><div class="result-card"><h3>✔ ملخص الاستشارة</h3><div class="result-sec"><div class="t">الوقائع</div><ul><li>${SVC[c.service].label}: تم تحديد محل الطلب والنقاط القانونية الجوهرية.</li><li>تم استلام وتدقيق كامل المستندات الداعمة.</li></ul></div><div class="result-sec"><div class="t">التوصيات</div><ul><li>توجيه إنذار رسمي للطرف الآخر خلال 5 أيام عمل.</li><li>تجهيز مذكرة دعوى احتياطية لدى ${c.dept}.</li></ul></div><div class="result-sec"><div class="t">الإجراءات / المهام</div><ul><li>صياغة خطاب المطالبة — مسؤول: ${maskLawyer(c.lawyer)}.</li><li>متابعة الرد خلال المهلة النظامية ثم التصعيد عند الحاجة.</li></ul></div></div>`);
    setActionStage('result');
  };

  const clientSend = () => {
    const v = reply.trim(); if (!v) return;
    addMsg('me', 'أنت', '', v.replace(/</g, '&lt;')); setReply(''); toast('تم إرسال رسالتك إلى الفريق القانوني');
  };
  const clientAttach = () => {
    const pool = ['مستند_إضافي.pdf', 'صورة_العقد.jpg', 'كشف_حساب.pdf', 'إفادة.pdf'];
    const f = pool[tf.current.files.length % pool.length]; tf.current.files = [...tf.current.files, f];
    addMsg('me', 'أنت', '', `<p>تم إرفاق مستند:</p><div class="doc-list"><span class="doc-chip">📎 ${f}</span></div>`); toast('تم إرفاق المستند بالتذكرة');
  };

  const railCur = STAGE_RAIL[tf.current.stage];
  const c = tf.current;

  // ── منطقة الإجراء ──
  const renderAction = () => {
    switch (actionStage) {
      case 'create':
        return (
          <>
            <div className="field">
              <label>نوع الخدمة <span className="req">*</span></label>
              <select value={fService} onChange={(e) => { setFService(e.target.value); setErrSvc(false); }}>
                <option value="">— اختر نوع الخدمة —</option>
                {SVC_GROUPS.map(([g, keys]) => (
                  <optgroup key={g} label={g}>
                    {keys.map((k) => <option key={k} value={k}>{SVC[k].label}</option>)}
                  </optgroup>
                ))}
              </select>
              {errSvc && <div style={{ color: '#C0392B', fontSize: 12, marginTop: 6 }}>يرجى اختيار نوع الخدمة.</div>}
            </div>
            <div className="field">
              <label>تفاصيل الطلب <span className="req">*</span></label>
              <textarea value={details} onChange={(e) => setDetails(e.target.value)} placeholder="اشرح موضوع طلبك والوقائع الأساسية والأطراف ذات العلاقة…" />
            </div>
            <div className="field">
              <label>المستندات الداعمة</label>
              <div className="upload" onClick={() => fileInputRef.current?.click()}><Icon name="upload" />اضغط لرفع الملفات</div>
              <input ref={fileInputRef} type="file" multiple hidden accept={ALLOWED_DOC_ACCEPT} onChange={onPickFiles} />
              <div style={{ fontSize: 11.5, color: 'var(--muted)', marginTop: 6 }}>{ALLOWED_DOC_HINT}</div>
              <div className="uploaded">
                {pickedFiles.map((f, i) => (
                  <span key={i} className="file-tag">📎 {f.name} <span className="x" onClick={() => removePickedFile(i)}>✕</span></span>
                ))}
              </div>
            </div>
            <button className="btn block" onClick={submit} disabled={submitting}>{submitting ? <><span className="spin" /> جارٍ الإرسال…</> : <>إرسال الطلب <Icon name="send" /></>}</button>
          </>
        );
      case 'missing':
        return (
          <>
            <div className="field">
              <label>إرفاق المستندات المطلوبة</label>
              <div className="upload" onClick={addFile}><Icon name="upload" />اضغط لإرفاق المستندات الناقصة</div>
              <div className="uploaded">{c.files.map((f, i) => <span key={i} className="file-tag">📎 {f}</span>)}</div>
            </div>
            <button className="btn block" onClick={confirmDocs}>إرسال المستندات <Icon name="send" /></button>
          </>
        );
      case 'studyApprove':
        return (
          <>
            <button className="btn block" onClick={lawyerReply}>المستشار يراجع الملخص ويعتمده <Icon name="check" /></button>
            <div className="action-hint">خطوة يقوم بها المستشار القانوني داخل لوحته.</div>
          </>
        );
      case 'legalreply':
        return <button className="btn block" onClick={chooseConsult}>حجز استشارة <Icon name="calplus" /></button>;
      case 'consult':
        return (
          <>
            <div className="choices">
              {([['office', 'حضورية', 'في الفرع'], ['video', 'مرئية', 'عبر الفيديو'], ['phone', 'هاتفية', 'اتصال مباشر']] as [string, string, string][]).map(([ico, label, sub]) => (
                <div key={label} className={`choice ${c.consult === label ? 'sel' : ''}`} onClick={() => pickConsult(label)}>
                  <div className="cico"><Icon name={ico} /></div><b>{label}</b><span>{sub}</span>
                </div>
              ))}
            </div>
            <button className="btn block" style={{ opacity: c.consult ? 1 : 0.5, pointerEvents: c.consult ? 'auto' : 'none' }} onClick={goInvoice}>متابعة لإصدار الفاتورة</button>
          </>
        );
      case 'invoice':
        return (
          <>
            <div className="invoice">
              <div className="inv-head"><b>فاتورة استشارة قانونية</b><span>{c.no}</span></div>
              <div className="inv-body">
                <div className="inv-row"><span className="lbl">استشارة {c.consult} · {c.dept}</span><span>{invoice.amt} ر.س</span></div>
                <div className="inv-row"><span className="lbl">ضريبة القيمة المضافة (15%)</span><span>{invoice.vat} ر.س</span></div>
                <div className="inv-row total"><span>الإجمالي</span><span>{invoice.total} ر.س</span></div>
              </div>
            </div>
            <button className="btn block" onClick={paid}><Icon name="card" /> ادفع الآن عبر الرابط الآمن</button>
            <div className="action-hint">دفع إلكتروني محاكى — لن يتم خصم أي مبلغ.</div>
          </>
        );
      case 'slot':
        return <SlotPicker onConfirm={confirm} />;
      case 'afterVideo':
        return (
          <>
            <button className="btn block" onClick={() => toast('دخول غرفة الجلسة المرئية')}><Icon name="video" /> دخول غرفة الجلسة المرئية</button>
            <div className="action-hint">جلستك متاحة في «استشاراتي»، ووصلت إلى المكتب في «استقبال الاستشارات».</div>
          </>
        );
      case 'afterOther':
        return (
          <>
            <button className="btn block" onClick={session}><Icon name="video" /> بدء الاستشارة في موعدها</button>
            <div className="action-hint">تنعقد الجلسة {c.consult === 'حضورية' ? 'في الفرع' : 'عبر الهاتف'}، وتجدها في «استشاراتي».</div>
          </>
        );
      case 'sessionDone':
        return <button className="btn block" onClick={lawyerReview}>إنهاء الجلسة وعرض الملخص على المستشار <Icon name="send" /></button>;
      case 'lawyerRevDone':
        return (
          <>
            <button className="btn block" onClick={adminReview}>رفع للإدارة للاعتماد النهائي <Icon name="send" /></button>
            <div className="action-hint">يعتمد المستشار: الملخص · التوصيات · الإجراءات المقترحة.</div>
          </>
        );
      case 'result':
        return <button className="btn block ghost" onClick={start}>إنشاء تذكرة جديدة</button>;
      default:
        return null;
    }
  };

  return (
    <div className="tflow">
      <div className="tf-grid">
        <div>
          <div className="card">
            <div className="card-h"><h3>مسار التذكرة</h3><span className="sub">{threadSub}</span></div>
            <div className="thread">
              {messages.map((m) => <MsgView key={m.id} m={m} />)}
              <div ref={endRef} />
            </div>
            <div className={`action ${actionStage ? '' : 'empty'}`}>{renderAction()}</div>
            {composer && (
              <div className="composer">
                <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--faint)', marginBottom: 8 }}>يمكنك الكتابة في التذكرة في أي وقت:</div>
                <textarea value={reply} onChange={(e) => setReply(e.target.value)} placeholder="اكتب رسالتك للفريق القانوني…" />
                <div className="crow">
                  <button className="btn" onClick={clientSend}><Icon name="send" /> إرسال</button>
                  <button className="btn soft" onClick={clientAttach}><Icon name="upload" /> إرفاق مستند</button>
                </div>
              </div>
            )}
          </div>
        </div>

        <aside className="tf-aside">
          <div className="card">
            <div className="tc-top"><div className="lbl">رقم التذكرة</div><div className="num">{c.no || '— — —'}</div></div>
            <div className="tc-body">
              <div className="tc-row"><span className="k">الحالة</span><span className={`status-badge ${status.tone}`}><span className="dot" /> {status.text}</span></div>
              <div className="tc-row"><span className="k">نوع الخدمة</span><span className="v">{c.service ? SVC[c.service].label : '—'}</span></div>
              <div className="tc-row"><span className="k">القسم المختص</span><span className="v">{c.dept || '—'}</span></div>
              <div className="tc-row"><span className="k">نوع الاستشارة</span><span className="v">{c.consult || '—'}</span></div>
              <div className="tc-row"><span className="k">عنوان IP</span><span className="v" style={{ direction: 'ltr' }}>{CLIENT_IP}</span></div>
              <div className="tc-row"><span className="k">التاريخ</span><span className="v">{todayDate()}</span></div>
              <div className="tc-row"><span className="k">وقت العميل</span><span className="v">{c.no ? 'الآن' : '—'}</span></div>
            </div>
          </div>
          <div className="card rail">
            <h3><Icon name="check" /> رحلة المعالجة</h3>
            <div className="steps">
              {RAIL.map((st, i) => (
                <div key={i} className={`step ${i < railCur ? 'done' : i === railCur ? 'active' : ''}`}>
                  <div className="marker"><div className="ring">{i < railCur ? <Icon name="check" /> : i + 1}</div><div className="line" /></div>
                  <div className="txt"><b>{st[0]}</b><span>{st[1]}</span></div>
                </div>
              ))}
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
};

// مُنتقي الموعد (يطابق tfChooseSlot)
const SlotPicker: React.FC<{ onConfirm: (b: string, l: string, d: string, t: string) => void }> = ({ onConfirm }) => {
  const [branch, setBranch] = useState('جدة — حي الروضة');
  const [lawyer, setLawyer] = useState('أ. سارة القحطاني');
  const [day, setDay] = useState('الأحد 28 يونيو');
  const [time, setTime] = useState('');
  const times = ['10:00 ص', '11:30 ص', '01:00 م', '02:30 م', '04:00 م', '05:30 م'];
  return (
    <>
      <div className="picker-grid">
        <div className="field"><label>الفرع</label>
          <select value={branch} onChange={(e) => setBranch(e.target.value)}>
            <option>جدة — حي الروضة</option><option>الرياض — حي العليا</option><option>مكة — العزيزية</option>
          </select>
        </div>
        <div className="field"><label>المستشار المتاح</label>
          <select value={lawyer} onChange={(e) => setLawyer(e.target.value)}>
            <option>أ. سارة القحطاني</option><option>أ. خالد المالكي</option><option>أ. ريم الزهراني</option>
          </select>
        </div>
      </div>
      <div className="field" style={{ marginBottom: 6 }}><label>اليوم</label>
        <select value={day} onChange={(e) => setDay(e.target.value)}>
          <option>الأحد 28 يونيو</option><option>الاثنين 29 يونيو</option><option>الثلاثاء 30 يونيو</option>
        </select>
      </div>
      <label style={{ display: 'block', fontSize: 13, fontWeight: 700, color: 'var(--ink)', margin: '13px 0 0' }}>الوقت المتاح</label>
      <div className="slots">
        {times.map((t) => (
          <button key={t} className={`slot ${time === t ? 'sel' : ''}`} onClick={() => setTime(t)}>{t}</button>
        ))}
      </div>
      <div className="cal-note"><Icon name="check" /> تم التحقق من التوافر عبر Google Calendar</div>
      <button className="btn block" style={{ marginTop: 15, opacity: time ? 1 : 0.5, pointerEvents: time ? 'auto' : 'none' }} onClick={() => onConfirm(branch, lawyer, day, time)}>تأكيد الموعد</button>
    </>
  );
};

export default NewTicket;
