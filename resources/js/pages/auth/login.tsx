import { useForm } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';

// صفحة تسجيل الدخول — بنظام تصميم سلاسل بابل

const DEMO = [
  { role: 'العميل', email: 'client@salasel.test' },
  { role: 'الموظف', email: 'employee@salasel.test' },
  { role: 'المحامي', email: 'lawyer@salasel.test' },
  { role: 'الإدارة', email: 'admin@salasel.test' },
];

const Login: React.FC = () => {
  const { data, setData, post, processing, errors } = useForm({
    email: '',
    password: '',
    remember: false,
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/login');
  };

  return (
    <div style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', background: 'var(--bg)', padding: 20 }}>
      <div style={{ width: '100%', maxWidth: 400 }}>
        {/* الشعار */}
        <div style={{ textAlign: 'center', marginBottom: 22 }}>
          <img src="/images/logo.jpg" alt="سلاسل بابل" style={{ height: 56, margin: '0 auto 10px' }} />
          <h1 style={{ fontSize: 20, fontWeight: 800, color: 'var(--deep)' }}>منصة سلاسل بابل القانونية</h1>
          <p style={{ fontSize: 13, color: 'var(--muted)', marginTop: 4 }}>تسجيل الدخول إلى حسابك</p>
        </div>

        <div className="card">
          <div className="card-b" style={{ padding: 22 }}>
            <form onSubmit={submit}>
              <div className="field">
                <label>البريد الإلكتروني</label>
                <input
                  className="input"
                  type="email"
                  dir="ltr"
                  value={data.email}
                  onChange={(e) => setData('email', e.target.value)}
                  placeholder="you@example.com"
                  autoFocus
                />
                {errors.email && <div style={{ color: 'var(--red)', fontSize: 12, marginTop: 6 }}>{errors.email}</div>}
              </div>

              <div className="field">
                <label>كلمة المرور</label>
                <input
                  className="input"
                  type="password"
                  value={data.password}
                  onChange={(e) => setData('password', e.target.value)}
                  placeholder="••••••••"
                />
                {errors.password && <div style={{ color: 'var(--red)', fontSize: 12, marginTop: 6 }}>{errors.password}</div>}
              </div>

              <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: 'var(--muted)', marginBottom: 16, cursor: 'pointer' }}>
                <input type="checkbox" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} />
                تذكّرني
              </label>

              <button className="btn block" type="submit" disabled={processing}>
                <Icon name="lock" /> {processing ? 'جارٍ الدخول…' : 'تسجيل الدخول'}
              </button>
            </form>
          </div>
        </div>

        {/* حسابات تجريبية */}
        <div className="card" style={{ marginTop: 14 }}>
          <div className="card-h"><h3>حسابات تجريبية</h3><span className="sub">كلمة المرور: password</span></div>
          <div className="card-b" style={{ padding: '6px 14px 12px' }}>
            {DEMO.map((d) => (
              <div
                key={d.email}
                className="tc-row"
                style={{ cursor: 'pointer' }}
                onClick={() => setData((prev) => ({ ...prev, email: d.email, password: 'password' }))}
              >
                <span className="k">{d.role}</span>
                <span className="v" style={{ direction: 'ltr' }}>{d.email}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
};

export default Login;
