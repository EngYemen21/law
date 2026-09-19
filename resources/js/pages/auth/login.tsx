import { router, useForm, usePage } from '@inertiajs/react';
import React from 'react';
import LandingIcon from '@/components/landing/LandingIcon';

// صفحة الدخول — هوية + رمز SMS (OTP) بلا كلمة مرور + تسجيل ذاتي للعميل.
// الدور يُشتقّ حصراً من الخادم بعد التحقّق (لا يُرسَل من الواجهة إطلاقاً).
// كل وصفٍ في الواجهة يقابله سلوكٌ فعليّ في النظام — لا ادّعاءات اعتمادٍ أو دعمٍ غير موجود.

type AuthState = {
  step: 'otp';
  mode: 'login' | 'register';
  channel: 'sms' | 'email';
  maskedTarget: string | null;
  resendSeconds: number;
  nonce: string | null;
} | null;

type AccountChoice = Array<{ id: number; roleLabel: string }> | null;

type PageProps = {
  authState: AuthState;
  accountChoice: AccountChoice;
  devOtp: string | null;
  errors: Record<string, string>;
};

// أنماط التحقّق ورسائل الخطأ — تطابق قواعد الخادم (دفاع مزدوج: واجهة + خادم)
const RE_NID = /^\d{10}$/;
// يطابق App\Support\Phone::RULE حرفياً — يقبل المحلي السعودي والدوليّ الكامل
const RE_PHONE = /^(?:05\d{8}|\+?[1-9]\d{7,14})$/;
const RE_EMAIL = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
const MSG = {
  nid: 'رقم الهوية يجب أن يتكوّن من 10 أرقام.',
  phone: 'أدخل رقم جوال صحيحاً: محليّ 05XXXXXXXX أو دوليّ بصيغة ‎+9665XXXXXXXX.',
  email: 'أدخل بريداً إلكترونياً صحيحاً.',
  name: 'أدخل الاسم كاملاً (كلمتان على الأقل).',
  code: 'أدخل رمز التحقّق المكوّن من 4 أرقام.',
};

// مزايا النظام كما هي فعلاً (مصدرها شاشات ومسارات قائمة)
const FEATURES = [
  { icon: 'scale', title: 'إدارة القضايا', text: 'مراحل القضية وجلساتها وحكمها وتحويلها إلى تنفيذ' },
  { icon: 'cal', title: 'المواعيد والاجتماعات', text: 'جدولة المواعيد والاجتماعات المرئية عبر Zoom' },
  { icon: 'chat', title: 'الاستشارات القانونية', text: 'طلب الاستشارة وتسعيرها وسدادها وتقريرها' },
  { icon: 'doc', title: 'المستندات والمخاطبات', text: 'حفظ المستندات وتنزيلها المحميّ والمخاطبات الرسمية' },
  { icon: 'archive', title: 'أرشيف الجلسات', text: 'تسجيلات الجلسات المرئية وتفريغها النصّي' },
  { icon: 'card', title: 'الفواتير والمدفوعات', text: 'دفع إلكتروني أو رفع إثبات التحويل وفاتورة PDF' },
  { icon: 'chart', title: 'التقارير والمحاسبة', text: 'الإيرادات والتقارير التفصيلية بصيغة PDF' },
  { icon: 'bell', title: 'التقويم والتنبيهات', text: 'مواعيدك في تقويم واحد مع تذكير قبل كل موعد' },
];

const PILLS = [
  { icon: 'shield', label: 'آمن' },
  { icon: 'list', label: 'متكامل' },
  { icon: 'sparkles', label: 'ذكي' },
  { icon: 'users', label: 'اعتماد بشري' },
];

const TRUST = [
  { icon: 'lock', title: 'دخول بلا كلمة مرور', text: 'رقم الهوية ورمز تحقّق يصل إلى جوالك' },
  { icon: 'shield', title: 'صلاحيات حسب الدور', text: 'لكل دور لوحته والصلاحيات تمنحها الإدارة' },
  { icon: 'check', title: 'اعتماد قبل النشر', text: 'لا يصل العميلَ ملخّصٌ قبل اعتماده' },
  { icon: 'list', title: 'سجل تدقيق أمني', text: 'الأنشطة مسجّلة تطّلع عليها الإدارة' },
];

const Divider: React.FC = () => (
  <div className="lgn-div" aria-hidden="true"><span /></div>
);

const Login: React.FC = () => {
  const { authState, accountChoice, devOtp, errors } = usePage<PageProps>().props;
  const otpActive = authState?.step === 'otp';
  const chooseActive = !!accountChoice && accountChoice.length > 0;

  // التبويب (دخول/تسجيل) — في وضع الرمز يُشتقّ من الخادم
  const [mode, setMode] = React.useState<'login' | 'register'>(authState?.mode ?? 'login');
  const activeMode = otpActive ? authState!.mode : mode;
  const activeChannel = otpActive ? authState!.channel : 'sms';

  const loginForm = useForm({ national_id: '' });
  const regForm = useForm({ name: '', national_id: '', phone: '', email: '' });
  const otpForm = useForm({ code: '' });

  // أخطاء التحقّق في الواجهة (قبل الإرسال) — تُدمج مع أخطاء الخادم
  const [ce, setCe] = React.useState<Record<string, string>>({});
  const err = (k: string) => ce[k] || errors?.[k];
  const switchMode = (m: 'login' | 'register') => {
    setCe({});
    setMode(m);
  };

  // خانات الرمز الأربع
  const [digits, setDigits] = React.useState(['', '', '', '']);
  const boxes = React.useRef<Array<HTMLInputElement | null>>([]);

  // مؤقّت إعادة الإرسال — يُعاد ضبطه مع كل رمز جديد (nonce)
  const [left, setLeft] = React.useState(0);
  React.useEffect(() => {
    if (!otpActive) return;
    setDigits(['', '', '', '']);
    setLeft(authState!.resendSeconds);
    setTimeout(() => boxes.current[0]?.focus(), 60);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [otpActive, authState?.nonce]);
  React.useEffect(() => {
    if (left <= 0) return;
    const t = setTimeout(() => setLeft((v) => v - 1), 1000);
    return () => clearTimeout(t);
  }, [left]);

  // يُرجع أوّل خطأ لكل حقل بعد التحقّق المحليّ؛ يمنع الإرسال إن وُجد خطأ
  const guard = (checks: Record<string, string | null>): boolean => {
    const found: Record<string, string> = {};
    for (const [k, v] of Object.entries(checks)) if (v) found[k] = v;
    setCe(found);
    return Object.keys(found).length === 0;
  };

  const submitLogin = (e: React.FormEvent) => {
    e.preventDefault();
    if (!guard({ national_id: RE_NID.test(loginForm.data.national_id) ? null : MSG.nid })) return;
    loginForm.post('/auth/otp/request', { preserveScroll: true });
  };
  const submitRegister = (e: React.FormEvent) => {
    e.preventDefault();
    const ok = guard({
      name: regForm.data.name.trim().split(/\s+/).filter(Boolean).length >= 2 ? null : MSG.name,
      national_id: RE_NID.test(regForm.data.national_id) ? null : MSG.nid,
      phone: RE_PHONE.test(regForm.data.phone) ? null : MSG.phone,
      email: RE_EMAIL.test(regForm.data.email) ? null : MSG.email,
    });
    if (!ok) return;
    regForm.post('/auth/register', { preserveScroll: true });
  };
  const submitOtp = (e: React.FormEvent) => {
    e.preventDefault();
    const code = digits.join('');
    if (!guard({ code: /^\d{4}$/.test(code) ? null : MSG.code })) return;
    const endpoint =
      activeMode === 'login'
        ? '/auth/otp/verify'
        : activeChannel === 'email'
          ? '/auth/register/verify-email'
          : '/auth/register/verify-phone';
    otpForm.transform(() => ({ code }));
    otpForm.post(endpoint, {
      preserveScroll: true,
      // تصفير الخانات عند رفض الرمز ليعيد المستخدم الإدخال من جديد
      onError: () => {
        setDigits(['', '', '', '']);
        boxes.current[0]?.focus();
      },
    });
  };

  const onDigit = (i: number, v: string) => {
    const d = v.replace(/\D/g, '').slice(-1);
    const next = [...digits];
    next[i] = d;
    setDigits(next);
    if (d && i < 3) boxes.current[i + 1]?.focus();
  };
  const onDigitKey = (i: number, e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Backspace' && !digits[i] && i > 0) boxes.current[i - 1]?.focus();
  };

  const resend = () => router.post('/auth/otp/resend', {}, { preserveScroll: true });
  const back = () => router.get('/login', { restart: 1 }, { preserveScroll: true });

  const year = new Date().getFullYear();

  return (
    <div className="lgn" dir="rtl">
      <div className="lgn-bg" aria-hidden="true" />

      <div className="lgn-wrap">
        <div className="lgn-grid">
          {/* ── التعريف بالنظام (يمين) ── */}
          <section className="lgn-hero">
            <div className="lgn-brand">
              <img src="/images/sb-mark.png" alt="" width={320} height={303} />
              <div>
                <b>سلاسل بابل لتقنية المعلومات</b>
                <span>SALASEL BABEL INFORMATION TECHNOLOGY</span>
              </div>
            </div>

            <div className="lgn-hero-txt">
              <h2>
                النظام الإداري للمحاماة
                <br />
                والاستشارات القانونية <em>الذكية</em>
              </h2>
              <Divider />
              <p className="lgn-lead">
                منصة متكاملة لإدارة الطلبات والقضايا والمواعيد والاستشارات القانونية،
                بصلاحيات حسب الدور واعتماد بشري قبل وصول أي نتيجة إلى العميل.
              </p>
              <ul className="lgn-pills">
                {PILLS.map((p) => (
                  <li key={p.label}>
                    <LandingIcon name={p.icon} className="ic" />
                    {p.label}
                  </li>
                ))}
              </ul>
            </div>

            <ul className="lgn-feats">
              {FEATURES.map((f) => (
                <li key={f.title} className="lgn-feat lgn-glass">
                  <LandingIcon name={f.icon} className="ic" />
                  <h3>{f.title}</h3>
                  <p>{f.text}</p>
                </li>
              ))}
            </ul>
          </section>

          {/* ── بطاقة الدخول (يسار) ── */}
          <section className="lgn-card lgn-glass" aria-labelledby="lgn-title">
            <img className="lgn-logo" src="/images/sb-mark.png" alt="سلاسل بابل" width={320} height={303} />
            <h1 id="lgn-title">مرحباً بك</h1>
            <p className="lgn-sub">في النظام الإداري للمحاماة</p>
            <p className="lgn-accent">والاستشارات القانونية الذكية</p>
            <Divider />

            {/* ── مُنتقي الحساب (تعدّدت حسابات نفس الهُويّة) ── */}
            {chooseActive ? (
              <div>
                <div className="lgn-info">
                  <LandingIcon name="info" className="ic" />
                  <span>لديك أكثر من حساب بهذه الهوية — اختر الحساب الذي تريد الدخول إليه.</span>
                </div>
                {errors?.account_id && <div className="lgn-err">{errors.account_id}</div>}
                <div className="lgn-stack">
                  {accountChoice!.map((a) => (
                    <button
                      key={a.id}
                      type="button"
                      className="lgn-btn"
                      onClick={() => router.post('/auth/choose-account', { account_id: a.id }, { preserveScroll: true })}
                    >
                      <LandingIcon name="user" className="ic" /> {a.roleLabel}
                    </button>
                  ))}
                </div>
                <button type="button" className="lgn-link" onClick={back}>
                  <LandingIcon name="reply" className="ic" /> رجوع
                </button>
              </div>
            ) : otpActive ? (
              <form onSubmit={submitOtp} noValidate>
                <div className="lgn-sec">
                  <LandingIcon name="shield" className="ic" />
                  <span>رمز التحقّق</span>
                </div>

                {activeMode === 'register' && activeChannel === 'email' && (
                  <div className="lgn-ok">
                    <LandingIcon name="check" className="ic" />
                    <span>تم تأكيد رقم الجوال بنجاح — بقيت خطوة تأكيد البريد (2 من 2).</span>
                  </div>
                )}
                <div className="lgn-info">
                  <LandingIcon name="info" className="ic" />
                  {activeChannel === 'email' ? (
                    <span>أرسلنا رمز التحقّق إلى بريدك <b className="lgn-ltr">{authState!.maskedTarget}</b></span>
                  ) : activeMode === 'login' ? (
                    // الخادم لا يكشف في الدخول هل للهويّة حساب — فالنصّ واحد للجميع
                    <span>إن كان رقم الهوية مسجّلاً ومفعّلاً لدينا فسيصلك رمز التحقّق على الجوال المسجّل. إن لم يصلك خلال دقيقة فتأكّد من الرقم أو راجع الإدارة.</span>
                  ) : (
                    <span>
                      <b>تأكيد رقم الجوال (1 من 2): </b>
                      أرسلنا رمز التحقّق إلى جوالك المنتهي بـ <b>{authState!.maskedTarget}</b>
                    </span>
                  )}
                </div>

                {devOtp && (
                  <div className="lgn-ok">
                    <LandingIcon name="info" className="ic" />
                    <span>وضع تطوير مؤقّت — استخدم الرمز <b className="lgn-code">{devOtp}</b></span>
                  </div>
                )}

                {err('code') && <div className="lgn-err">{err('code')}</div>}

                <p className="lgn-otp-lbl" id="lgn-otp-lbl">أدخل رمز التحقّق (4 أرقام)</p>
                <div className="lgn-otp" role="group" aria-labelledby="lgn-otp-lbl">
                  {digits.map((d, i) => (
                    <input
                      key={i}
                      ref={(el) => { boxes.current[i] = el; }}
                      inputMode="numeric"
                      autoComplete={i === 0 ? 'one-time-code' : 'off'}
                      maxLength={1}
                      value={d}
                      aria-label={`الرقم ${i + 1}`}
                      onChange={(e) => onDigit(i, e.target.value)}
                      onKeyDown={(e) => onDigitKey(i, e)}
                    />
                  ))}
                </div>

                <button className="lgn-btn" type="submit" disabled={otpForm.processing || digits.join('').length < 4}>
                  <LandingIcon name="check" className="ic" />
                  {otpForm.processing
                    ? 'جارٍ التأكيد…'
                    : activeMode === 'login'
                      ? 'تأكيد الدخول'
                      : activeChannel === 'email'
                        ? 'تأكيد البريد وإنشاء الحساب'
                        : 'تأكيد الجوال'}
                </button>

                <div className="lgn-timer">
                  {left > 0 ? (
                    `إعادة الإرسال بعد ${left} ثانية`
                  ) : (
                    <button type="button" className="lgn-link" onClick={resend}>إعادة إرسال الرمز</button>
                  )}
                </div>
                <button type="button" className="lgn-link" onClick={back}>
                  <LandingIcon name="reply" className="ic" /> رجوع
                </button>
              </form>
            ) : (
              <>
                {/* ── التبويبات ── */}
                <div className="lgn-tabs" role="tablist">
                  <button
                    type="button"
                    role="tab"
                    aria-selected={mode === 'login'}
                    className={`lgn-tab${mode === 'login' ? ' on' : ''}`}
                    onClick={() => switchMode('login')}
                  >
                    <LandingIcon name="user" className="ic" /> تسجيل الدخول
                  </button>
                  <button
                    type="button"
                    role="tab"
                    aria-selected={mode === 'register'}
                    className={`lgn-tab${mode === 'register' ? ' on' : ''}`}
                    onClick={() => switchMode('register')}
                  >
                    <LandingIcon name="userplus" className="ic" /> إنشاء حساب
                  </button>
                </div>

                {/* ── تسجيل الدخول ── */}
                {mode === 'login' && (
                  <form onSubmit={submitLogin} noValidate>
                    <div className="lgn-sec">
                      <LandingIcon name="shield" className="ic" />
                      <span>تسجيل الدخول</span>
                    </div>
                    <p className="lgn-sec-sub">أدخل رقم هويتك ليصلك رمز تحقّق على جوالك المسجّل</p>

                    {err('national_id') && <div className="lgn-err">{err('national_id')}</div>}

                    <label className="lgn-f">
                      <span className="lgn-sr">رقم الهوية الوطنية / الإقامة</span>
                      <input
                        className="lgn-txt ltr"
                        inputMode="numeric"
                        autoComplete="username"
                        maxLength={10}
                        placeholder="أدخل رقم الهوية الوطنية / الإقامة"
                        value={loginForm.data.national_id}
                        onChange={(e) => loginForm.setData('national_id', e.target.value.replace(/\D/g, ''))}
                      />
                      <LandingIcon name="idcard" className="ic" />
                    </label>

                    <button className="lgn-btn" type="submit" disabled={loginForm.processing}>
                      <LandingIcon name="shield" className="ic" />
                      {loginForm.processing ? 'جارٍ الإرسال…' : 'تسجيل الدخول عبر التحقّق'}
                    </button>

                    <div className="lgn-or" aria-hidden="true">أو</div>

                    <button type="button" className="lgn-btn lgn-btn-o" onClick={() => switchMode('register')}>
                      <LandingIcon name="userplus" className="ic" /> إنشاء حساب عميل جديد
                    </button>
                  </form>
                )}

                {/* ── حساب جديد (عميل) ── */}
                {mode === 'register' && (
                  <form onSubmit={submitRegister} noValidate>
                    <div className="lgn-sec">
                      <LandingIcon name="userplus" className="ic" />
                      <span>حساب عميل جديد</span>
                    </div>
                    <p className="lgn-sec-sub">نؤكّد حسابك برمز إلى جوالك ثمّ رمز إلى بريدك الإلكتروني</p>

                    {err('name') && <div className="lgn-err">{err('name')}</div>}
                    {err('national_id') && <div className="lgn-err">{err('national_id')}</div>}
                    {err('phone') && <div className="lgn-err">{err('phone')}</div>}
                    {err('email') && <div className="lgn-err">{err('email')}</div>}

                    <label className="lgn-f">
                      <span className="lgn-sr">الاسم الكامل</span>
                      <input className="lgn-txt" autoComplete="name" placeholder="الاسم الكامل" value={regForm.data.name} onChange={(e) => regForm.setData('name', e.target.value)} />
                      <LandingIcon name="user" className="ic" />
                    </label>
                    <label className="lgn-f">
                      <span className="lgn-sr">رقم الهوية الوطنية / الإقامة</span>
                      <input className="lgn-txt ltr" inputMode="numeric" maxLength={10} placeholder="رقم الهوية الوطنية / الإقامة" value={regForm.data.national_id} onChange={(e) => regForm.setData('national_id', e.target.value.replace(/\D/g, ''))} />
                      <LandingIcon name="idcard" className="ic" />
                    </label>
                    <label className="lgn-f">
                      <span className="lgn-sr">رقم الجوال</span>
                      <input className="lgn-txt ltr" inputMode="tel" autoComplete="tel" maxLength={16} placeholder="رقم الجوال 05XXXXXXXX" value={regForm.data.phone} onChange={(e) => regForm.setData('phone', e.target.value.replace(/[^\d+]/g, ''))} />
                      <LandingIcon name="phone" className="ic" />
                    </label>
                    <label className="lgn-f">
                      <span className="lgn-sr">البريد الإلكتروني</span>
                      <input className="lgn-txt ltr" type="email" autoComplete="email" placeholder="البريد الإلكتروني" value={regForm.data.email} onChange={(e) => regForm.setData('email', e.target.value)} />
                      <LandingIcon name="mail" className="ic" />
                    </label>

                    <button className="lgn-btn" type="submit" disabled={regForm.processing}>
                      <LandingIcon name="userplus" className="ic" />
                      {regForm.processing ? 'جارٍ الإرسال…' : 'إنشاء الحساب وإرسال رمز التحقّق'}
                    </button>

                    <div className="lgn-or" aria-hidden="true">أو</div>

                    <button type="button" className="lgn-btn lgn-btn-o" onClick={() => switchMode('login')}>
                      <LandingIcon name="lock" className="ic" /> لديك حساب؟ تسجيل الدخول
                    </button>
                  </form>
                )}
              </>
            )}

            <p className="lgn-ft">
              <LandingIcon name="shield" className="ic" />
              نستخدم رمز تحقّق يصل إلى جوالك لحماية حسابك
            </p>
          </section>
        </div>

        {/* ── شريط الضمانات ── */}
        <footer className="lgn-strip lgn-glass">
          <ul>
            {TRUST.map((t) => (
              <li key={t.title}>
                <LandingIcon name={t.icon} className="ic" />
                <div>
                  <b>{t.title}</b>
                  <span>{t.text}</span>
                </div>
              </li>
            ))}
          </ul>
          <p className="lgn-copy">جميع الحقوق محفوظة © {year} سلاسل بابل لتقنية المعلومات</p>
        </footer>
      </div>
    </div>
  );
};

export default Login;
