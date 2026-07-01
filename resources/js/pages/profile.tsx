import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

// يطابق viewProfile في index (82).html

const Toggle: React.FC<{ initial?: boolean; onChange: (on: boolean) => void }> = ({ initial = true, onChange }) => {
  const [on, setOn] = useState(initial);
  return (
    <div
      className={`toggle ${on ? 'on' : ''}`}
      onClick={() => { const next = !on; setOn(next); onChange(next); }}
    />
  );
};

const Profile: React.FC = () => {
  const toast = useToast();

  return (
    <div className="grid-2">
      <div className="card">
        <div className="card-h"><h3>البيانات الشخصية</h3></div>
        <div className="card-b" style={{ padding: 18 }}>
          <div className="field">
            <label>الاسم الكامل</label>
            <input className="input" defaultValue="عبدالله محمد العتيبي" />
          </div>
          <div className="field">
            <label>رقم الجوال</label>
            <input className="input" dir="ltr" defaultValue="+966 5X XXX 1234" />
          </div>
          <div className="field">
            <label>البريد الإلكتروني</label>
            <input className="input" dir="ltr" defaultValue="abdullah@example.com" />
          </div>
          <button className="btn" type="button" onClick={() => toast('تم حفظ التغييرات')}>
            <Icon name="check" /> حفظ التغييرات
          </button>
        </div>
      </div>

      <div>
        <div className="card">
          <div className="card-h"><h3>كلمة المرور</h3></div>
          <div className="card-b" style={{ padding: 18 }}>
            <div className="field">
              <label>كلمة المرور الحالية</label>
              <input className="input" type="password" defaultValue="········" />
            </div>
            <div className="field">
              <label>كلمة المرور الجديدة</label>
              <input className="input" type="password" placeholder="••••••••" />
            </div>
            <button className="btn ghost" type="button" onClick={() => toast('تم تغيير كلمة المرور')}>
              <Icon name="lock" /> تغيير كلمة المرور
            </button>
          </div>
        </div>

        <div className="card">
          <div className="card-h"><h3>أمان الحساب</h3></div>
          <div className="card-b" style={{ padding: '6px 18px 14px' }}>
            <div className="switch">
              <div className="sw-t">
                <b>التحقق الثنائي (2FA)</b>
                <span>رمز إضافي عبر الرسائل عند تسجيل الدخول</span>
              </div>
              <Toggle onChange={(on) => toast(on ? 'تم تفعيل التحقق الثنائي' : 'تم إيقاف التحقق الثنائي')} />
            </div>
            <div className="switch">
              <div className="sw-t">
                <b>تنبيهات تسجيل الدخول</b>
                <span>إشعار عند الدخول من جهاز جديد</span>
              </div>
              <Toggle onChange={() => toast('تم تحديث الإعداد')} />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default Profile;
