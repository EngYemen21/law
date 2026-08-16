import { router, useForm, usePage } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';

// صفحة الدخول — هوية + رمز SMS (OTP) بلا كلمة مرور + تسجيل ذاتي للعميل.
// الدور يُشتقّ حصراً من الخادم بعد التحقّق (لا يُرسَل من الواجهة إطلاقاً).

type AuthState = {
  step: 'otp';
  mode: 'login' | 'register';
  channel: 'sms' | 'email';
  maskedTarget: string | null;
  resendSeconds: number;
  nonce: string | null;
} | null;

type AccountChoice = Array<{ id: number; roleLabel: string; branch: string | null }> | null;

type PageProps = {
  authState: AuthState;
  accountChoice: AccountChoice;
  devOtp: string | null;
  errors: Record<string, string>;
};

// أنماط التحقّق ورسائل الخطأ — تطابق قواعد الخادم (دفاع مزدوج: واجهة + خادم)
const RE_NID = /^\d{10}$/;
const RE_PHONE = /^05\d{8}$/;
const RE_EMAIL = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
const MSG = {
  nid: 'رقم الهوية يجب أن يتكوّن من 10 أرقام.',
  phone: 'رقم الجوال يجب أن يبدأ بـ 05 ويتكوّن من 10 أرقام.',
  email: 'أدخل بريداً إلكترونياً صحيحاً.',
  name: 'أدخل الاسم كاملاً (كلمتان على الأقل).',
  code: 'أدخل رمز التحقّق المكوّن من 4 أرقام.',
};

const Login: React.FC = () => {
  const { authState, accountChoice, devOtp, errors } = usePage<PageProps>().props;
  const otpActive = authState?.step === 'otp';
  const chooseActive = !!accountChoice && accountChoice.length > 0;

  // التبويب/العرض (دخول/تسجيل/دخول سريع بالجوال) — في وضع الرمز يُشتقّ من الخادم
  const [mode, setMode] = React.useState<'login' | 'register' | 'forgot'>(authState?.mode ?? 'login');
  const activeMode = otpActive ? authState!.mode : mode;
  const activeChannel = otpActive ? authState!.channel : 'sms';

  const loginForm = useForm({ national_id: '' });
  const regForm = useForm({ name: '', national_id: '', phone: '', email: '' });
  const forgotForm = useForm({ national_id: '' });
  const otpForm = useForm({ code: '' });

  // أخطاء التحقّق في الواجهة (قبل الإرسال) — تُدمج مع أخطاء الخادم
  const [ce, setCe] = React.useState<Record<string, string>>({});
  const err = (k: string) => ce[k] || errors?.[k];
  const switchMode = (m: 'login' | 'register' | 'forgot') => {
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
  const submitForgot = (e: React.FormEvent) => {
    e.preventDefault();
    // الاستعادة = دخول برقم الهوية (كما في المرجع) — نفس مسار طلب الرمز
    if (!guard({ national_id: RE_NID.test(forgotForm.data.national_id) ? null : MSG.nid })) return;
    forgotForm.post('/auth/otp/request', { preserveScroll: true });
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

  return (
    <div className="lgn">
      <div className="lgn-card">
        <div className="lgn-hd">
          <img src="/images/021.png" alt="سلاسل بابل" />
          <h1>سلاسل بابل لتقنية المعلومات</h1>
          <p>بوابة الدخول الآمن — النظام القانوني</p>
        </div>

        <div className="lgn-bd">
          {/* ── مُنتقي الحساب (تعدّدت حسابات نفس الهُويّة) ── */}
          {chooseActive ? (
            <div>
              <div className="lgn-info">
                <Icon name="info" />
                <span>لديك أكثر من حساب بهذه الهوية — اختر الحساب الذي تريد الدخول إليه.</span>
              </div>
              {errors?.account_id && <div className="lgn-err">{errors.account_id}</div>}
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 6 }}>
                {accountChoice!.map((a) => (
                  <button
                    key={a.id}
                    type="button"
                    className="lgn-btn"
                    onClick={() => router.post('/auth/choose-account', { account_id: a.id }, { preserveScroll: true })}
                  >
                    <Icon name="user" /> {a.roleLabel}{a.branch ? ` — ${a.branch}` : ''}
                  </button>
                ))}
              </div>
              <div style={{ textAlign: 'center' }}>
                <button type="button" className="lgn-link" onClick={back}>
                  <Icon name="reply" /> رجوع
                </button>
              </div>
            </div>
          ) : otpActive ? (
            <form onSubmit={submitOtp} noValidate>
              {activeMode === 'register' && activeChannel === 'email' && (
                <div className="lgn-ok">
                  <Icon name="check" /> تم تأكيد رقم الجوال بنجاح — بقيت خطوة تأكيد البريد (2 من 2).
                </div>
              )}
              <div className="lgn-info">
                <Icon name="info" />
                {activeChannel === 'email' ? (
                  <span>أرسلنا رمز التحقّق إلى بريدك <b style={{ direction: 'ltr', display: 'inline-block' }}>{authState!.maskedTarget}</b></span>
                ) : (
                  <span>
                    {activeMode === 'register' && <b>تأكيد رقم الجوال (1 من 2): </b>}
                    أرسلنا رمز التحقّق إلى جوالك المنتهي بـ <b>{authState!.maskedTarget}</b>
                  </span>
                )}
              </div>

              {devOtp && (
                <div className="lgn-ok">
                  <Icon name="info" /> وضع تطوير مؤقّت — استخدم الرمز <b style={{ letterSpacing: 3 }}>{devOtp}</b>
                </div>
              )}

              {err('code') && <div className="lgn-err">{err('code')}</div>}

              <div className="lgn-f">
                <label style={{ textAlign: 'center' }}>أدخل رمز التحقّق (4 أرقام)</label>
                <div className="lgn-otp">
                  {digits.map((d, i) => (
                    <input
                      key={i}
                      ref={(el) => { boxes.current[i] = el; }}
                      inputMode="numeric"
                      maxLength={1}
                      value={d}
                      onChange={(e) => onDigit(i, e.target.value)}
                      onKeyDown={(e) => onDigitKey(i, e)}
                    />
                  ))}
                </div>
              </div>

              <button className="lgn-btn" type="submit" disabled={otpForm.processing || digits.join('').length < 4}>
                <Icon name="check" />{' '}
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
              <div style={{ textAlign: 'center' }}>
                <button type="button" className="lgn-link" onClick={back}>
                  <Icon name="reply" /> رجوع
                </button>
              </div>
            </form>
          ) : (
            <>
              {/* ── التبويبات ── */}
              <div className="lgn-tabs">
                <button className={`lgn-tab${mode === 'login' ? ' on' : ''}`} onClick={() => switchMode('login')} type="button">
                  تسجيل الدخول
                </button>
                <button className={`lgn-tab${mode === 'register' ? ' on' : ''}`} onClick={() => switchMode('register')} type="button">
                  حساب جديد (عميل)
                </button>
              </div>

              {/* ── تسجيل الدخول ── */}
              {mode === 'login' && (
                <form onSubmit={submitLogin} noValidate>
                  {err('national_id') && <div className="lgn-err">{err('national_id')}</div>}

                  <div className="lgn-f">
                    <label>رقم الهوية الوطنية / الإقامة</label>
                    <input
                      className="lgn-txt ltr"
                      inputMode="numeric"
                      maxLength={10}
                      placeholder="1XXXXXXXXX"
                      value={loginForm.data.national_id}
                      onChange={(e) => loginForm.setData('national_id', e.target.value.replace(/\D/g, ''))}
                    />
                  </div>

                  <button className="lgn-btn" type="submit" disabled={loginForm.processing}>
                    <Icon name="phone" /> {loginForm.processing ? 'جارٍ الإرسال…' : 'إرسال رمز التحقّق'}
                  </button>

                  <div style={{ textAlign: 'center' }}>
                    <button type="button" className="lgn-link" onClick={() => switchMode('forgot')}>
                      نسيت كلمة المرور؟ دخول سريع بالجوال
                    </button>
                  </div>

                  <div className="lgn-info" style={{ marginTop: 12 }}>
                    <Icon name="info" />
                    <span>سيصلك رمز تحقّق (OTP) عبر رسالة نصية إلى جوالك المسجّل لدى المكتب.</span>
                  </div>
                </form>
              )}

              {/* ── استعادة الدخول (نسيت كلمة المرور) — برقم الهوية كما في المرجع ── */}
              {mode === 'forgot' && (
                <form onSubmit={submitForgot} noValidate>
                  <div className="lgn-info">
                    <Icon name="lock" />
                    <span>استعادة الدخول: أدخل رقم هويتك ليصلك رمز تحقّق على جوالك المسجّل، ثم تدخل عبر <b>الدخول السريع</b>.</span>
                  </div>

                  {err('national_id') && <div className="lgn-err">{err('national_id')}</div>}

                  <div className="lgn-f">
                    <label>رقم الهوية الوطنية / الإقامة</label>
                    <input
                      className="lgn-txt ltr"
                      inputMode="numeric"
                      maxLength={10}
                      placeholder="1XXXXXXXXX"
                      value={forgotForm.data.national_id}
                      onChange={(e) => forgotForm.setData('national_id', e.target.value.replace(/\D/g, ''))}
                    />
                  </div>

                  <button className="lgn-btn" type="submit" disabled={forgotForm.processing}>
                    <Icon name="phone" /> {forgotForm.processing ? 'جارٍ الإرسال…' : 'إرسال رمز الاستعادة'}
                  </button>

                  <div style={{ textAlign: 'center' }}>
                    <button type="button" className="lgn-link" onClick={() => switchMode('login')}>
                      <Icon name="reply" /> رجوع لتسجيل الدخول
                    </button>
                  </div>
                </form>
              )}

              {/* ── حساب جديد (عميل) ── */}
              {mode === 'register' && (
                <form onSubmit={submitRegister} noValidate>
                  {err('name') && <div className="lgn-err">{err('name')}</div>}
                  {err('national_id') && <div className="lgn-err">{err('national_id')}</div>}
                  {err('phone') && <div className="lgn-err">{err('phone')}</div>}
                  {err('email') && <div className="lgn-err">{err('email')}</div>}

                  <div className="lgn-f">
                    <label>الاسم الكامل</label>
                    <input className="lgn-txt" placeholder="الاسم الرباعي" value={regForm.data.name} onChange={(e) => regForm.setData('name', e.target.value)} />
                  </div>
                  <div className="lgn-f">
                    <label>رقم الهوية الوطنية / الإقامة</label>
                    <input className="lgn-txt ltr" inputMode="numeric" maxLength={10} placeholder="1XXXXXXXXX" value={regForm.data.national_id} onChange={(e) => regForm.setData('national_id', e.target.value.replace(/\D/g, ''))} />
                  </div>
                  <div className="lgn-f">
                    <label>رقم الجوال</label>
                    <input className="lgn-txt ltr" inputMode="numeric" maxLength={10} placeholder="05XXXXXXXX" value={regForm.data.phone} onChange={(e) => regForm.setData('phone', e.target.value.replace(/\D/g, ''))} />
                  </div>
                  <div className="lgn-f">
                    <label>البريد الإلكتروني</label>
                    <input className="lgn-txt ltr" type="email" placeholder="name@email.com" value={regForm.data.email} onChange={(e) => regForm.setData('email', e.target.value)} />
                  </div>

                  <button className="lgn-btn" type="submit" disabled={regForm.processing}>
                    <Icon name="user" /> {regForm.processing ? 'جارٍ الإرسال…' : 'إنشاء الحساب وإرسال رمز التحقّق'}
                  </button>

                  <div className="lgn-info" style={{ marginTop: 14 }}>
                    <Icon name="info" />
                    <span>سنؤكّد حسابك بخطوتين: رمز عبر رسالة نصية إلى جوالك، ثمّ رمز إلى بريدك الإلكتروني.</span>
                  </div>
                </form>
              )}
            </>
          )}
        </div>

        <div className="lgn-ft">© سلاسل بابل لتقنية المعلومات — جميع الحقوق محفوظة</div>
      </div>
    </div>
  );
};

export default Login;
