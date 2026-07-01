import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import { CLIENT_IP, OFFICE_IP, cleanTime, todayDate } from '@/lib/chat';
import { CF_RAIL, CF_STAGE } from '@/lib/lawyer-data';

// يطابق caseflowView + محرك التحويل (cfStart..cfCloseCase) في index (82).html

type Actor = 'ai' | 'me' | 'lawyer' | 'admin' | 'system';
interface CfMsg { actor: Actor; name: string; role: string; html: string; time: string; }

interface CfState {
  ticket: string;
  caseNo: string | null;
  stage: string;
  type: string;
  dept: string;
  amount: number;
  vat: number;
  total: number;
  lawPct: number;
  lawyerFee: number;
  payType: string;
  lawyer: string;
}

const LAWYER = 'أ. سارة القحطاني';
const delay = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));

function actorAv(actor: Actor): React.ReactNode {
  if (actor === 'ai') return <img src="/images/mono.jpg" alt="" />;
  if (actor === 'me') return 'أنت';
  if (actor === 'lawyer') return '⚖';
  if (actor === 'admin') return '■';
  return '⚙';
}

function msgIP(actor: Actor): string {
  return actor === 'me' ? CLIENT_IP : OFFICE_IP;
}

const Bubble: React.FC<{ m: CfMsg }> = ({ m }) => (
  <div className={`msg ${m.actor}`}>
    <div className={`av ${m.actor}`}>{actorAv(m.actor)}</div>
    <div className="bubble-wrap">
      <div className="who">
        <b>{m.name}</b>
        {m.role && <span className={`role ${m.actor}`}>{m.role}</span>}
        <time>{m.time}</time>
      </div>
      <div className="bubble" dangerouslySetInnerHTML={{ __html: m.html }} />
      <div className="msg-meta">
        <span><Icon name="cal" />{todayDate()}</span>
        <span><Icon name="clock" />{cleanTime(m.time)}</span>
        <span><Icon name="pin" /><bdi>{msgIP(m.actor)}</bdi></span>
      </div>
    </div>
  </div>
);

const fmtNum = (n: number) => n.toLocaleString();

const LawyerCaseflow: React.FC = () => {
  const [cf, setCf] = useState<CfState>(() => ({
    ticket: 'SB-2026-1042', caseNo: null, stage: 'ended', type: '', dept: '',
    amount: 0, vat: 0, total: 0, lawPct: 15, lawyerFee: 0, payType: 'once', lawyer: LAWYER,
  }));
  const [msgs, setMsgs] = useState<CfMsg[]>([]);
  const [typing, setTyping] = useState<Actor | null>(null);
  const [sub, setSub] = useState('من نتيجة الاستشارة');
  const [statusText, setStatusText] = useState('استشارة منتهية');
  const [statusTone, setStatusTone] = useState('sb-grey');
  // محتوى منطقة الإجراء — نوع حسب المرحلة
  const [action, setAction] = useState<string>('init');

  // حقول الأتعاب
  const [amt, setAmt] = useState(20000);
  const [lawPct, setLawPct] = useState(15);

  const clock = useRef(new Date());
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }, [msgs, typing, action]);

  // يطابق cfNow
  const cfNow = (): string => {
    clock.current = new Date(clock.current.getTime() + (60000 + Math.random() * 120000));
    try {
      return clock.current.toLocaleTimeString('ar-SA', { hour: '2-digit', minute: '2-digit' });
    } catch {
      return clock.current.toLocaleTimeString();
    }
  };

  const push = (actor: Actor, name: string, role: string, html: string) =>
    setMsgs((p) => [...p, { actor, name, role, html, time: cfNow() }]);

  const vat = Math.round(amt * 0.15);
  const total = amt + vat;
  const lawyerFee = Math.round(amt * lawPct / 100);

  // ── مراحل المحرك ──
  const start = async () => {
    clock.current = new Date();
    setCf((p) => ({ ...p, caseNo: null, stage: 'ended', type: '', dept: '', amount: 0, vat: 0, total: 0 }));
    setMsgs([]);
    setAction('none');
    await ended();
  };

  const ended = async () => {
    setCf((p) => ({ ...p, stage: 'ended' }));
    setStatusText('استشارة منتهية'); setStatusTone('sb-grey');
    setSub('حفظ مخرجات الاستشارة');
    push('system', 'النظام', 'نظام',
      `<p>انتهت الاستشارة المرتبطة بالتذكرة <b>${cf.ticket}</b>. تم الحفظ داخل التذكرة:</p><ul class="checklist"><li class="done"><span class="tick">✓</span>تسجيل الاستشارة</li><li class="done"><span class="tick">✓</span>ملخص الاستشارة</li><li class="done"><span class="tick">✓</span>محضر الاستشارة</li><li class="done"><span class="tick">✓</span>جميع المرفقات</li></ul>`);
    await delay(500);
    await analysis();
  };

  const analysis = async () => {
    setCf((p) => ({ ...p, stage: 'analysis' }));
    setStatusText('تحليل ذكي'); setStatusTone('sb-blue');
    const items = ['تحليل القضية', 'تحليل المستندات', 'استخراج الوقائع', 'استخراج الطلبات', 'اقتراح نوع القضية', 'اقتراح القسم المختص'];
    push('ai', 'المساعد القانوني', 'تحليل',
      `<p>يحلّل الفريق القانوني الاستشارة تمهيداً للتحويل:</p><ul class="checklist">${items.map((x) => `<li class="done"><span class="tick">✓</span>${x}</li>`).join('')}</ul>`);
    await delay(items.length * 520);
    setCf((p) => ({ ...p, type: 'دعوى مطالبة مالية', dept: 'القضايا التجارية' }));
    await delay(350);
    push('ai', 'المساعد القانوني', 'اقتراح', `<p>التوصية: <b>دعوى مطالبة مالية</b> — القسم المختص: <b>القضايا التجارية</b>.</p>`);
    await delay(300);
    review();
  };

  const review = () => {
    setCf((p) => ({ ...p, stage: 'review' }));
    setStatusText('مراجعة المحامي'); setStatusTone('sb-blue');
    setSub('قرار المحامي');
    push('lawyer', 'المستشار القانوني', 'مراجعة', 'راجعتُ الملخص والمستندات والتوصيات. الخيارات المتاحة:');
    setAction('review');
  };

  const close = () => {
    setAction('none');
    setStatusText('مغلق'); setStatusTone('sb-grey');
    push('lawyer', 'المستشار القانوني', 'إغلاق', 'تم إغلاق الطلب بعد الاستشارة دون تحويله إلى قضية، وحُفظت كامل المخرجات داخل التذكرة.');
    setAction('restart');
  };

  const reqDocs = () => {
    push('lawyer', 'المستشار القانوني', 'نواقص', 'يرجى تزويدنا بالمستندات الإضافية التالية لاستكمال التقييم:');
    push('ai', 'المساعد القانوني', 'نواقص', '<div class="doc-list"><span class="doc-chip">صك الملكية</span><span class="doc-chip">كشف حساب بنكي</span><span class="doc-chip">إثبات المطالبة</span></div>');
    setAction('reqdocs');
  };

  const backReview = () => {
    push('me', 'العميل', '', 'تم إرفاق المستندات الإضافية المطلوبة.');
    setAction('none');
    review();
  };

  const convert = async () => {
    setAction('none');
    setCf((p) => ({ ...p, stage: 'convert' }));
    setStatusText('جارٍ التحويل'); setStatusTone('sb-blue');
    setSub('إنشاء ملف القضية');
    push('me', 'المستشار القانوني', '', 'تحويل الطلب إلى قضية قانونية.');
    setTyping('ai');
    await delay(800);
    setTyping(null);
    const caseNo = `CASE-${new Date().getFullYear()}-${String(Math.floor(1 + Math.random() * 9999)).padStart(4, '0')}`;
    setCf((p) => ({ ...p, caseNo }));
    push('ai', 'النظام', 'إنشاء',
      `<p>تم إنشاء القضية <b>${caseNo}</b> وملفها القانوني:</p><div class="result-card" style="margin-top:6px"><div class="result-sec"><div class="t">ملف القضية</div><ul><li>بيانات العميل والخصوم</li><li>المرفقات والمستندات</li><li>ملخص الاستشارة ومحاضر الاجتماعات</li></ul></div></div>`);
    await delay(400);
    fees();
  };

  const fees = () => {
    setCf((p) => ({ ...p, stage: 'fees' }));
    setStatusText('بانتظار الإدارة العليا'); setStatusTone('sb-amber');
    setSub('تحديد قيمة القضية والأتعاب');
    push('admin', 'الإدارة العليا', 'اعتماد', 'تُحدِّد الإدارة العليا قيمة القضية وأتعاب المحامي. لن تُفتح القضية حتى يسدّد العميل فاتورة الأتعاب.');
    setAction('fees');
  };

  const invoice = () => {
    setCf((p) => ({ ...p, stage: 'invoice', amount: amt, vat, total, lawPct, lawyerFee }));
    setAction('none');
    const invNo = `INV-${new Date().getFullYear()}-${String(Math.floor(100 + Math.random() * 899))}`;
    const caseNo = cf.caseNo || '';
    push('system', 'النظام', 'نظام',
      `<p>تم إصدار فاتورة أتعاب القضية:</p><div class="invoice" style="margin-top:6px"><div class="inv-head"><b>فاتورة أتعاب قضية</b><span>${invNo}</span></div><div class="inv-body"><div class="inv-row"><span class="lbl">رقم القضية</span><span class="mono">${caseNo}</span></div><div class="inv-row"><span class="lbl">العميل</span><span>عبدالله محمد العتيبي</span></div><div class="inv-row"><span class="lbl">قيمة الأتعاب</span><span>${fmtNum(amt)} ر.س</span></div><div class="inv-row"><span class="lbl">الضريبة</span><span>${fmtNum(vat)} ر.س</span></div><div class="inv-row total"><span>الإجمالي</span><span>${fmtNum(total)} ر.س</span></div></div></div>`);
    notify();
  };

  const notify = () => {
    setCf((p) => ({ ...p, stage: 'notify' }));
    setStatusText('بانتظار سداد الأتعاب'); setStatusTone('sb-amber');
    setSub('إشعار العميل');
    push('ai', 'الفريق القانوني', 'إشعار العميل',
      `<div class="quote">تمت دراسة الموضوع واعتماد تحويله إلى قضية قانونية.<br>رقم القضية: <b>${cf.caseNo}</b><br>للبدء في إجراءات القضية نأمل سداد فاتورة الأتعاب.</div>`);
    setAction('notify');
  };

  const pay = async () => {
    setAction('none');
    push('me', 'العميل', '', `تم سداد فاتورة الأتعاب بمبلغ <b>${fmtNum(total)} ر.س</b>.`);
    setCf((p) => ({ ...p, stage: 'pay' }));
    setStatusText('جاهزة للبدء'); setStatusTone('sb-green');
    setTyping('ai');
    await delay(700);
    setTyping(null);
    push('ai', 'النظام', 'دفع', '<p>تم اعتماد السداد. القضية الآن <b>جاهزة للبدء</b>.</p>');
    await delay(300);
    activate();
  };

  const activate = async () => {
    setCf((p) => ({ ...p, stage: 'activate' }));
    setStatusText('قضية نشطة'); setStatusTone('sb-blue');
    setSub('تفعيل القضية');
    const items = ['تفعيل القضية', `إسنادها للمحامي (${cf.lawyer})`, 'إنشاء خطة العمل'];
    push('system', 'النظام', 'تفعيل',
      `<p>بدء العمل القانوني تلقائياً:</p><ul class="checklist">${items.map((x) => `<li class="done"><span class="tick">✓</span>${x}</li>`).join('')}</ul>`);
    await delay(items.length * 560 + 350);
    plan();
  };

  const plan = () => {
    setCf((p) => ({ ...p, stage: 'plan' }));
    const steps = ['إعداد اللائحة', 'تجهيز المستندات', 'رفع الدعوى', 'متابعة الجلسات', 'متابعة الحكم', 'التنفيذ'];
    push('ai', 'المساعد القانوني', 'خطة العمل',
      `<p>خطة العمل الذكية المقترحة للقضية:</p><div class="result-card" style="margin-top:6px"><div class="result-sec"><div class="t">مراحل القضية</div><ul>${steps.map((s) => `<li>${s}</li>`).join('')}</ul></div></div>`);
    setAction('plan');
  };

  const statement = async () => {
    setAction('none');
    setCf((p) => ({ ...p, stage: 'statement' }));
    setTyping('ai');
    await delay(900);
    setTyping(null);
    push('ai', 'المساعد القانوني', 'مسودة اللائحة',
      `<div class="draft"><span class="lead">لائحة دعوى — ${cf.caseNo}</span>\n\nالوقائع: إخلال المدّعى عليه بالتزاماته التعاقدية وتأخره عن التنفيذ في المدة المتفق عليها.\n\nالطلبات: إلزام المدّعى عليه بالتنفيذ والتعويض عن الأضرار والمصاريف.\n\nالدفوع: ثبوت الإخلال بالمستندات واستحقاق الشرط الجزائي.</div>`);
    setAction('statement');
  };

  const approve = () => {
    setAction('none');
    push('lawyer', 'المستشار القانوني', 'اعتماد', 'تم اعتماد اللائحة، وهي جاهزة لرفع الدعوى ومتابعة الجلسات.');
    track();
  };

  const track = () => {
    setCf((p) => ({ ...p, stage: 'track' }));
    setStatusText('منظورة'); setStatusTone('sb-blue');
    setSub('متابعة القضية');
    push('system', 'النظام', 'متابعة',
      `<p>تظهر القضية للعميل في <b>⚖️ القضايا النشطة</b>:</p><div class="result-card" style="margin-top:6px"><div class="result-sec"><div class="t">بطاقة المتابعة</div><ul><li>رقم القضية: ${cf.caseNo}</li><li>الحالة: منظورة</li><li>الجلسة القادمة: الخميس 09 يوليو</li><li>آخر تحديث: اعتماد اللائحة ورفع الدعوى</li><li>الفواتير: أتعاب القضية (${fmtNum(total)} ر.س) — مدفوعة</li></ul></div></div>`);
    setAction('track');
  };

  const closeCase = async () => {
    setAction('none');
    setCf((p) => ({ ...p, stage: 'closed' }));
    setStatusText('قضية مغلقة'); setStatusTone('sb-grey');
    setSub('أُغلقت القضية وأُرشفت');
    setTyping('admin');
    await delay(700);
    setTyping(null);
    push('admin', 'الإدارة', 'إغلاق',
      `<p>بعد انتهاء الحكم والتنفيذ والخدمة، تحوّلت القضية إلى <b>مغلقة</b> وحُفظ كامل الملف في الأرشيف القانوني:</p><div class="result-card" style="margin-top:6px"><div class="result-sec"><div class="t">الأرشيف القانوني</div><ul><li>اللوائح والمذكرات</li><li>الجلسات والمستندات</li><li>الفواتير والمدفوعات</li></ul></div></div>`);
    setAction('restart');
  };

  // تشغيل المحرك عند أول عرض
  useEffect(() => { start(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, []);

  const cur = CF_STAGE[cf.stage] ?? 0;

  // ── منطقة الإجراء ──
  const renderAction = () => {
    switch (action) {
      case 'review':
        return (
          <div className="choices" style={{ gridTemplateColumns: '1fr 1fr 1fr' }}>
            <div className="choice" onClick={close}>
              <div className="cico"><Icon name="check" /></div><b>إغلاق الطلب</b><span>دون تحويل</span>
            </div>
            <div className="choice" onClick={reqDocs}>
              <div className="cico"><Icon name="upload" /></div><b>مستندات إضافية</b><span>طلب من العميل</span>
            </div>
            <div className="choice" onClick={convert}>
              <div className="cico"><Icon name="scale" /></div><b>تحويل إلى قضية</b><span>فتح قضية</span>
            </div>
          </div>
        );
      case 'reqdocs':
        return (
          <button className="btn block" onClick={backReview} type="button">
            <Icon name="check" /> استلمنا المستندات — متابعة
          </button>
        );
      case 'fees':
        return (
          <>
            <div className="picker-grid">
              <div className="field">
                <label>قيمة القضية (ر.س)</label>
                <input className="input" type="number" value={amt} onChange={(e) => setAmt(parseInt(e.target.value || '0', 10) || 0)} />
              </div>
              <div className="field">
                <label>نسبة أتعاب المحامي (%)</label>
                <input className="input" type="number" value={lawPct} onChange={(e) => setLawPct(parseFloat(e.target.value || '0') || 0)} />
              </div>
            </div>
            <div className="kv"><span className="k">أتعاب المحامي المحتسبة</span><span className="v">{fmtNum(lawyerFee)} ر.س ({lawPct}%)</span></div>
            <div className="field">
              <label>طريقة السداد</label>
              <div className="choices" style={{ gridTemplateColumns: '1fr 1fr 1fr' }}>
                {([['once', 'دفعة واحدة', 'كامل المبلغ', 'card'], ['install', 'دفعات', 'على دفعات', 'card'], ['stages', 'حسب المراحل', 'مراحل القضية', 'scale']] as [string, string, string, string][]).map(([p, t, s, ic]) => (
                  <div key={p} className={`choice${cf.payType === p ? ' sel' : ''}`} onClick={() => setCf((c) => ({ ...c, payType: p }))}>
                    <div className="cico"><Icon name={ic} /></div><b>{t}</b><span>{s}</span>
                  </div>
                ))}
              </div>
            </div>
            {cf.payType === 'install' && (
              <div style={{ marginBottom: 13 }}>
                <div className="kv"><span className="k">دفعة أولى</span><span className="v">5,000 ر.س</span></div>
                <div className="kv"><span className="k">دفعة ثانية</span><span className="v">5,000 ر.س</span></div>
                <div className="kv"><span className="k">دفعة ثالثة</span><span className="v">10,000 ر.س</span></div>
              </div>
            )}
            {cf.payType === 'stages' && (
              <div style={{ marginBottom: 13 }}>
                <div className="kv"><span className="k">عند رفع الدعوى</span><span className="v">—</span></div>
                <div className="kv"><span className="k">عند الجلسات</span><span className="v">—</span></div>
                <div className="kv"><span className="k">عند صدور الحكم</span><span className="v">—</span></div>
                <div className="kv"><span className="k">عند التنفيذ</span><span className="v">—</span></div>
              </div>
            )}
            <div className="field">
              <label>ملاحظات الأتعاب</label>
              <textarea placeholder="أي شروط أو ملاحظات على الأتعاب…" />
            </div>
            <div className="invoice">
              <div className="inv-head"><b>أتعاب القضية</b><span>{cf.caseNo || ''}</span></div>
              <div className="inv-body">
                <div className="inv-row"><span className="lbl">قيمة الأتعاب</span><span>{fmtNum(amt)} ر.س</span></div>
                <div className="inv-row"><span className="lbl">ضريبة القيمة المضافة (15%)</span><span>{fmtNum(vat)} ر.س</span></div>
                <div className="inv-row total"><span>الإجمالي</span><span>{fmtNum(total)} ر.س</span></div>
              </div>
            </div>
            <button className="btn block" onClick={invoice} type="button">
              <Icon name="card" /> اعتماد الإدارة وإصدار الفاتورة
            </button>
          </>
        );
      case 'notify':
        return (
          <>
            <button className="btn block" onClick={pay} type="button">
              <Icon name="card" /> محاكاة سداد العميل للأتعاب
            </button>
            <div className="action-hint">قبل السداد تبقى الحالة «بانتظار سداد الأتعاب».</div>
          </>
        );
      case 'plan':
        return (
          <button className="btn block" onClick={statement} type="button">
            <Icon name="doc" /> إعداد اللائحة عبر المساعد القانوني
          </button>
        );
      case 'statement':
        return (
          <button className="btn block" onClick={approve} type="button">
            <Icon name="check" /> اعتماد المحامي للائحة
          </button>
        );
      case 'track':
        return (
          <div style={{ display: 'flex', gap: 9, flexWrap: 'wrap' }}>
            <button className="btn soft" onClick={closeCase} type="button">
              <Icon name="check" /> إغلاق القضية
            </button>
          </div>
        );
      case 'restart':
        return (
          <button className="btn block ghost" onClick={start} type="button">
            <Icon name="reply" /> {cf.stage === 'closed' ? 'تحويل استشارة أخرى إلى قضية' : 'بدء تحويل جديد'}
          </button>
        );
      default:
        return null;
    }
  };

  const actionNode = renderAction();

  return (
    <div className="tflow">
      <div className="tf-grid">
        <div>
          <div className="card">
            <div className="card-h">
              <h3>تحويل الاستشارة إلى قضية</h3>
              <span className="sub">{sub}</span>
            </div>
            <div className="thread">
              {msgs.map((m, i) => <Bubble key={i} m={m} />)}
              {typing && (
                <div className={`msg ${typing}`}>
                  <div className={`av ${typing}`}>{actorAv(typing)}</div>
                  <div className="bubble-wrap">
                    <div className="bubble">
                      <span className="typing"><span /><span /><span /></span>
                    </div>
                  </div>
                </div>
              )}
              <div ref={endRef} />
            </div>
            <div className={`action${actionNode ? '' : ' empty'}`}>{actionNode}</div>
          </div>
        </div>

        <aside className="tf-aside">
          <div className="card">
            <div className="tc-top">
              <div className="lbl">رقم القضية</div>
              <div className="num">{cf.caseNo || '— — —'}</div>
            </div>
            <div className="tc-body">
              <div className="tc-row">
                <span className="k">الحالة</span>
                <span className={`status-badge ${statusTone}`}><span className="dot" /> {statusText}</span>
              </div>
              <div className="tc-row"><span className="k">نوع القضية</span><span className="v">{cf.type || '—'}</span></div>
              <div className="tc-row"><span className="k">القسم المختص</span><span className="v">{cf.dept || '—'}</span></div>
              <div className="tc-row"><span className="k">إجمالي الأتعاب</span><span className="v">{cf.amount ? `${fmtNum(cf.total)} ر.س` : '—'}</span></div>
            </div>
          </div>

          <div className="card rail">
            <h3><Icon name="scale" /> مسار القضية</h3>
            <div className="steps">
              {CF_RAIL.map((st, i) => {
                const cls = i < cur ? 'done' : i === cur ? 'active' : '';
                return (
                  <div key={i} className={`step ${cls}`}>
                    <div className="marker">
                      <div className="ring">{i < cur ? <Icon name="check" /> : i + 1}</div>
                      <div className="line" />
                    </div>
                    <div className="txt"><b>{st[0]}</b><span>{st[1]}</span></div>
                  </div>
                );
              })}
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
};

export default LawyerCaseflow;
