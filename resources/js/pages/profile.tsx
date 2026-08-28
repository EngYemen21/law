import { router, usePage } from '@inertiajs/react';
import React, { useState } from 'react';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';

// يطابق viewProfile في index (82).html — بيانات حقيقية من auth.user

// مفتاح معطّل (ميزة قيد الإنجاز) — لا يُحفظ حتى تكتمل بنيته الخلفية
const DisabledToggle: React.FC = () => <div className="toggle" style={{ opacity: 0.45, cursor: 'not-allowed' }} />;

const Profile: React.FC = () => {
  const toast = useToast();
  const { props } = usePage() as any;
  const authUser = props?.auth?.user ?? {};
  // تأكيد تغيير الجوال: الخادم يرسل رمزاً للرقم الجديد ويعلّق التغيير حتى تأكيده —
  // كانت النقطة الخادمية كاملة بلا أي واجهة، والحقل يوهم بأن الرقم حُفظ
  const [phoneOtp, setPhoneOtp] = useState('');
  const [otpBusy, setOtpBusy] = useState(false);
  const [showOtp, setShowOtp] = useState(false);
  const confirmPhone = () => {
    if (!phoneOtp.trim()) {
 return; 
}

    setOtpBusy(true);
    router.post('/profile/phone/verify', { code: phoneOtp.trim() }, {
      preserveScroll: true,
      onFinish: () => setOtpBusy(false),
      onSuccess: () => {
 setShowOtp(false); setPhoneOtp(''); 
},
    });
  };


  const [name, setName] = useState<string>(authUser.name ?? '');
  const [phone, setPhone] = useState<string>(authUser.phone ?? '');
  const [email, setEmail] = useState<string>(authUser.email ?? '');
  const [saveBusy, setSaveBusy] = useState(false);

  const [curPw, setCurPw] = useState('');
  const [newPw, setNewPw] = useState('');
  const [newPw2, setNewPw2] = useState('');
  const [pwBusy, setPwBusy] = useState(false);

  const saveProfile = () => {
    setSaveBusy(true);
    router.post('/profile', { name, phone, email }, {
      preserveScroll: true,
      onSuccess: () => {
        // الخادم يعلّق تغيير الجوال على رمز يصل الرقم الجديد — نُظهر حقل التأكيد
        if (phone.trim() && phone.trim() !== (authUser.phone ?? '')) {
          setShowOtp(true);
          toast('حُفظت بياناتك — أُرسل رمز تحقّق للرقم الجديد، أدخله أدناه لإتمام التغيير');
        } else {
          toast('تم حفظ بياناتك');
        }
      },
      onError: (e) => toast((Object.values(e)[0] as string) || 'تعذّر حفظ البيانات'),
      onFinish: () => setSaveBusy(false),
    });
  };

  const changePassword = () => {
    setPwBusy(true);
    router.post('/profile/password', {
      current_password: curPw,
      password: newPw,
      password_confirmation: newPw2,
    }, {
      preserveScroll: true,
      onSuccess: () => {
 setCurPw(''); setNewPw(''); setNewPw2(''); toast('تم تغيير كلمة المرور'); 
},
      onError: (e) => toast((Object.values(e)[0] as string) || 'تعذّر تغيير كلمة المرور'),
      onFinish: () => setPwBusy(false),
    });
  };

  return (
    <div className="grid-2">
      <div className="card">
        <div className="card-h"><h3>البيانات الشخصية</h3></div>
        <div className="card-b" style={{ padding: 18 }}>
          <div className="field">
            <label>الاسم الكامل</label>
            <input className="input" value={name} onChange={(e) => setName(e.target.value)} />
          </div>
          <div className="field">
            <label>رقم الجوال</label>
            <input className="input" dir="ltr" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="05XXXXXXXX" />
          </div>
          {/* تأكيد تغيير الجوال — النقطة الخادمية كانت كاملة بلا أي واجهة، والحقل يوهم بالحفظ */}
          {showOtp && (
            <div className="field" style={{ background: 'var(--paper-2)', border: '1px dashed var(--line)', borderRadius: 10, padding: 12 }}>
              <label>رمز التحقّق المُرسَل للرقم الجديد</label>
              <div style={{ display: 'flex', gap: 8 }}>
                <input className="input" dir="ltr" inputMode="numeric" maxLength={6} value={phoneOtp} onChange={(e) => setPhoneOtp(e.target.value)} placeholder="____" style={{ maxWidth: 160, textAlign: 'center', letterSpacing: 4 }} />
                <button className="btn sm" type="button" onClick={confirmPhone} disabled={otpBusy || !phoneOtp.trim()}>
                  {otpBusy ? 'جارٍ التأكيد…' : 'تأكيد الرقم الجديد'}
                </button>
              </div>
            </div>
          )}
          <div className="field">
            <label>البريد الإلكتروني</label>
            <input className="input" dir="ltr" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
          </div>
          <button className="btn" type="button" onClick={saveProfile} disabled={saveBusy}>
            <Icon name="check" /> {saveBusy ? 'جارٍ الحفظ…' : 'حفظ التغييرات'}
          </button>
        </div>
      </div>

      <div>
        {/* دخول العميل برمز SMS وكلمة مروره عشوائية مولَّدة لا يعرفها — البطاقة كانت طريقاً
            مسدوداً («الحالية غير صحيحة» دوماً)؛ تُعرض لأدوار المكتب فقط */}
        {authUser.role !== 'client' && (
        <div className="card">
          <div className="card-h"><h3>كلمة المرور</h3></div>
          <div className="card-b" style={{ padding: 18 }}>
            <div className="field">
              <label>كلمة المرور الحالية</label>
              <input className="input" type="password" value={curPw} onChange={(e) => setCurPw(e.target.value)} placeholder="••••••••" />
            </div>
            <div className="field">
              <label>كلمة المرور الجديدة</label>
              <input className="input" type="password" value={newPw} onChange={(e) => setNewPw(e.target.value)} placeholder="••••••••" />
            </div>
            <div className="field">
              <label>تأكيد كلمة المرور الجديدة</label>
              <input className="input" type="password" value={newPw2} onChange={(e) => setNewPw2(e.target.value)} placeholder="••••••••" />
            </div>
            <button className="btn ghost" type="button" onClick={changePassword} disabled={pwBusy}>
              <Icon name="lock" /> {pwBusy ? 'جارٍ التغيير…' : 'تغيير كلمة المرور'}
            </button>
          </div>
        </div>
        )}

        <div className="card">
          <div className="card-h"><h3>أمان الحساب</h3></div>
          <div className="card-b" style={{ padding: '6px 18px 14px' }}>
            <div className="switch">
              <div className="sw-t">
                <b>التحقق الثنائي (2FA) <span className="soon-tag">قريباً</span></b>
                <span>رمز إضافي عبر الرسائل عند تسجيل الدخول</span>
              </div>
              <DisabledToggle />
            </div>
            <div className="switch">
              <div className="sw-t">
                <b>تنبيهات تسجيل الدخول <span className="soon-tag">قريباً</span></b>
                <span>إشعار عند الدخول من جهاز جديد</span>
              </div>
              <DisabledToggle />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default Profile;
