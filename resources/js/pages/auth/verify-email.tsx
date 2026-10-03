import { router, useForm, usePage } from '@inertiajs/react';
import React from 'react';
import LandingIcon from '@/components/landing/LandingIcon';
import { useSettings } from '@/lib/settings';

/**
 * **«أكّد بريدك»** — إلزاميّة للعميل غير المؤكَّد بريدُه بعد دخوله (قرار المالك 2026-10-03). بتصميم بطاقة الدخول:
 * إرسال رمزٍ من أربعة أرقام إلى البريد، وإدخاله، وتصحيح البريد إن كان خطأً، والخروج.
 */
type PageProps = {
  email: string;
  /** لحظة إصدار الرمز المعلّق — null حين لا رمز؛ تتغيّر مع كلّ إرسال */
  sentAt: string | null;
  resendSeconds: number;
  devOtp: string | null;
  flash?: { success?: string | null };
  errors: Record<string, string>;
};

const RE_EMAIL = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

/** خانات الرمز ومؤقّت إعادة الإرسال — تُرسم من جديد مع كلّ رمز (`key` = لحظة إصداره) فتبدأ فارغة. */
const CodeEntry: React.FC<{ resendSeconds: number; onResend: () => void; resending: boolean; onLocalError: (m: string | null) => void }> = ({
  resendSeconds,
  onResend,
  resending,
  onLocalError,
}) => {
  const [digits, setDigits] = React.useState(['', '', '', '']);
  const [left, setLeft] = React.useState(resendSeconds);
  const boxes = React.useRef<Array<HTMLInputElement | null>>([]);
  const form = useForm({ code: '' });

  React.useEffect(() => {
    if (left <= 0) {
      return;
    }

    const t = setTimeout(() => setLeft((v) => v - 1), 1000);

    return () => clearTimeout(t);
  }, [left]);

  const onDigit = (i: number, v: string) => {
    const d = v.replace(/\D/g, '').slice(-1);
    const next = [...digits];
    next[i] = d;
    setDigits(next);

    if (d && i < 3) {
      boxes.current[i + 1]?.focus();
    }
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const code = digits.join('');

    if (!/^\d{4}$/.test(code)) {
      onLocalError('أدخل رمز التحقّق المكوّن من 4 أرقام.');

      return;
    }

    onLocalError(null);
    form.transform(() => ({ code }));
    form.post('/verify-email', {
      preserveScroll: true,
      onError: () => {
        setDigits(['', '', '', '']);
        boxes.current[0]?.focus();
      },
    });
  };

  return (
    <form onSubmit={submit} noValidate>
      <p className="lgn-otp-lbl" id="ve-otp-lbl">أدخل الرمز الذي وصل إلى بريدك (4 أرقام)</p>
      <div className="lgn-otp" role="group" aria-labelledby="ve-otp-lbl">
        {digits.map((d, i) => (
          <input
            key={i}
            ref={(el) => {
              boxes.current[i] = el;
            }}
            autoFocus={i === 0}
            inputMode="numeric"
            autoComplete={i === 0 ? 'one-time-code' : 'off'}
            maxLength={1}
            value={d}
            aria-label={`الرقم ${i + 1}`}
            onChange={(e) => onDigit(i, e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Backspace' && !digits[i] && i > 0) {
                boxes.current[i - 1]?.focus();
              }
            }}
          />
        ))}
      </div>
      <button className="lgn-btn" type="submit" disabled={form.processing || digits.join('').length < 4}>
        <LandingIcon name="check" className="ic" />
        {form.processing ? 'جارٍ التأكيد…' : 'تأكيد البريد'}
      </button>
      <div className="lgn-timer">
        {left > 0 ? (
          `إعادة الإرسال بعد ${left} ثانية`
        ) : (
          <button type="button" className="lgn-link" onClick={onResend} disabled={resending}>
            إعادة إرسال الرمز
          </button>
        )}
      </div>
    </form>
  );
};

const VerifyEmail: React.FC = () => {
  const { email, sentAt, resendSeconds, devOtp, flash, errors } = usePage<PageProps>().props;
  const officeName = useSettings().office_name;
  const [editing, setEditing] = React.useState(false);
  const [localError, setLocalError] = React.useState<string | null>(null);
  const sendForm = useForm({});
  const emailForm = useForm({ email });

  const send = () => sendForm.post('/verify-email/send', { preserveScroll: true });

  const submitEmail = (e: React.FormEvent) => {
    e.preventDefault();

    if (!RE_EMAIL.test(emailForm.data.email)) {
      setLocalError('أدخل بريداً إلكترونياً صحيحاً.');

      return;
    }

    setLocalError(null);
    emailForm.post('/verify-email/change', { preserveScroll: true, onSuccess: () => setEditing(false) });
  };

  const toggleEditing = (on: boolean) => {
    setEditing(on);
    setLocalError(null);
  };

  const error = localError || errors?.code || errors?.email;

  return (
    <div className="lgn" dir="rtl">
      <div className="lgn-bg" aria-hidden="true" />
      <div className="lgn-wrap">
        <section className="lgn-card lgn-glass" aria-labelledby="ve-title" style={{ maxWidth: 460, margin: '0 auto' }}>
          <img className="lgn-logo" src="/images/sb-mark.png" alt="سلاسل بابل" width={320} height={303} />
          <h1 id="ve-title">أكّد بريدك الإلكتروني</h1>
          <p className="lgn-sub">{officeName}</p>

          <div className="lgn-info">
            <LandingIcon name="info" className="ic" />
            <span>
              تصلك الفواتير وتأكيد المواعيد والتذكيرات على بريدك — أكّده مرّةً واحدة لتستعمل حسابك.
              <br />
              بريدك: <b className="lgn-ltr">{email}</b>
            </span>
          </div>

          {flash?.success && (
            <div className="lgn-ok">
              <LandingIcon name="check" className="ic" />
              <span>{flash.success}</span>
            </div>
          )}
          {devOtp && sentAt && (
            <div className="lgn-ok">
              <LandingIcon name="info" className="ic" />
              <span>
                وضع تطوير مؤقّت — استخدم الرمز <b className="lgn-code">{devOtp}</b>
              </span>
            </div>
          )}
          {error && <div className="lgn-err">{error}</div>}

          {editing ? (
            <form onSubmit={submitEmail} noValidate>
              <label className="lgn-f">
                <span className="lgn-sr">البريد الإلكتروني الصحيح</span>
                <input
                  className="lgn-txt ltr"
                  type="email"
                  value={emailForm.data.email}
                  onChange={(e) => emailForm.setData('email', e.target.value)}
                  placeholder="name@example.com"
                  aria-label="البريد الإلكتروني الصحيح"
                />
              </label>
              <button className="lgn-btn" type="submit" disabled={emailForm.processing}>
                <LandingIcon name="check" className="ic" />
                {emailForm.processing ? 'جارٍ الحفظ…' : 'حفظ البريد وإرسال الرمز'}
              </button>
              <button type="button" className="lgn-link" onClick={() => toggleEditing(false)}>
                <LandingIcon name="reply" className="ic" /> رجوع
              </button>
            </form>
          ) : sentAt ? (
            <CodeEntry key={sentAt} resendSeconds={resendSeconds} onResend={send} resending={sendForm.processing} onLocalError={setLocalError} />
          ) : (
            <button className="lgn-btn" type="button" onClick={send} disabled={sendForm.processing}>
              <LandingIcon name="mail" className="ic" />
              {sendForm.processing ? 'جارٍ الإرسال…' : 'أرسل رمز التأكيد إلى بريدي'}
            </button>
          )}

          {!editing && (
            <button type="button" className="lgn-link" onClick={() => toggleEditing(true)}>
              <LandingIcon name="mail" className="ic" /> البريد خطأ؟ صحّحه
            </button>
          )}
          <button type="button" className="lgn-link" onClick={() => router.post('/logout')}>
            <LandingIcon name="out" className="ic" /> تسجيل الخروج
          </button>
        </section>
      </div>
    </div>
  );
};

export default VerifyEmail;
