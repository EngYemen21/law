import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { type Staff, STAFF, DEPTS } from '@/lib/employee-data';
import { BRANCHES, PERM_GROUPS, PERM_PRESETS } from '@/lib/admin-data';

// يطابق adStaff + addStaff + toggleStaff + staffDetail + applyPreset في index (82).html

type PayType = 'salary' | 'pct' | 'both' | 'session';

function payLabel(t: PayType, sal: number, pct: number, ses: number): string {
  if (t === 'salary') return 'راتب ثابت: ' + sal.toLocaleString() + ' ر.س/شهري';
  if (t === 'pct') return 'نسبة: ' + pct + '%';
  if (t === 'both') return 'راتب ' + sal.toLocaleString() + ' ر.س + نسبة ' + pct + '%';
  if (t === 'session') return 'بالجلسة: ' + ses.toLocaleString() + ' ر.س/جلسة';
  return '—';
}

const AdminStaff: React.FC = () => {
  const toast = useToast();
  const [list, setList] = useState<Staff[]>(() => STAFF.map((s) => ({ ...s, perms: [...s.perms] })));

  // حقول النموذج
  const [name, setName] = useState('');
  const [role, setRole] = useState('موظف خدمة عملاء');
  const [email, setEmail] = useState('');
  const [mobile, setMobile] = useState('');
  const [nid, setNid] = useState('');
  const [branch, setBranch] = useState(BRANCHES[0].name);
  const [dept, setDept] = useState(DEPTS[0]);
  const [join, setJoin] = useState('');
  const [start, setStart] = useState('08:00');
  const [end, setEnd] = useState('16:00');
  const [payType, setPayType] = useState<PayType>('salary');
  const [salary, setSalary] = useState('');
  const [pct, setPct] = useState('');
  const [session, setSession] = useState('');
  const [perms, setPerms] = useState<string[]>([]);

  // مودال التفاصيل
  const [detail, setDetail] = useState<Staff | null>(null);

  const togglePerm = (p: string) =>
    setPerms((prev) => (prev.indexOf(p) >= 0 ? prev.filter((x) => x !== p) : [...prev, p]));

  const applyPreset = (key: string) => {
    setPerms([...(PERM_PRESETS[key] || [])]);
    toast('تم تطبيق صلاحيات: ' + key);
  };
  const clearPerms = () => setPerms([]);

  const addStaff = () => {
    if (!name.trim()) { toast('أدخل اسم الموظف'); return; }
    const sal = parseInt(salary || '0', 10) || 0;
    const p = parseFloat(pct || '0') || 0;
    const ses = parseInt(session || '0', 10) || 0;
    const s: Staff = {
      name: name.trim(), role, branch, dept,
      pay: payLabel(payType, sal, p, ses),
      salary: payType === 'salary' || payType === 'both' ? sal : 0,
      status: 'نشط', perms: [...perms],
      email: email || '—', mobile: mobile || '—', nid: nid || '—',
      join: join || '—', start: start || '—', end: end || '—',
    };
    setList((prev) => [...prev, s]);
    setName(''); setEmail(''); setMobile(''); setNid(''); setJoin('');
    setSalary(''); setPct(''); setSession(''); setPerms([]);
    toast('تم تسجيل الموظف');
  };

  const toggleStaff = (i: number) => {
    setList((prev) => prev.map((s, idx) => {
      if (idx !== i) return s;
      const status = s.status === 'موقوف' ? 'نشط' : 'موقوف';
      return { ...s, status };
    }));
    const cur = list[i];
    toast(cur.status === 'موقوف' ? 'تم تفعيل الموظف' : 'تم إيقاف الموظف');
  };

  return (
    <>
      <div className="greet">
        <h2>تسجيل الموظفين</h2>
        <p>إضافة الموظفين والمحامين وربطهم بالفروع والأقسام وتحديد صلاحياتهم.</p>
      </div>

      <div className="card">
        <div className="card-h"><h3>تسجيل موظف جديد</h3></div>
        <div className="card-b" style={{ padding: 20 }}>
          <div className="form-sec-h"><span className="si"><Icon name="user" /></span> البيانات الأساسية</div>
          <div className="picker-grid">
            <div className="field"><label>الاسم الكامل</label><input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="الاسم الكامل" /></div>
            <div className="field"><label>الصفة</label>
              <select value={role} onChange={(e) => setRole(e.target.value)}>
                <option>موظف خدمة عملاء</option><option>محامٍ</option><option>إداري</option><option>محاسب</option><option>مدير</option>
              </select>
            </div>
          </div>
          <div className="picker-grid">
            <div className="field"><label>البريد الإلكتروني</label><input className="input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="name@salasel.sa" /></div>
            <div className="field"><label>الجوال</label><input className="input" value={mobile} onChange={(e) => setMobile(e.target.value)} placeholder="05xxxxxxxx" /></div>
          </div>
          <div className="field"><label>رقم الهوية</label><input className="input" value={nid} onChange={(e) => setNid(e.target.value)} placeholder="1xxxxxxxxx" /></div>

          <div className="form-sec-h"><span className="si"><Icon name="office" /></span> بيانات العمل</div>
          <div className="picker-grid">
            <div className="field"><label>الفرع</label>
              <select value={branch} onChange={(e) => setBranch(e.target.value)}>
                {BRANCHES.map((b) => <option key={b.name}>{b.name}</option>)}
              </select>
            </div>
            <div className="field"><label>القسم</label>
              <select value={dept} onChange={(e) => setDept(e.target.value)}>
                {DEPTS.map((d) => <option key={d}>{d}</option>)}
              </select>
            </div>
          </div>
          <div className="picker-grid">
            <div className="field"><label>تاريخ المباشرة</label><input className="input" type="date" value={join} onChange={(e) => setJoin(e.target.value)} /></div>
            <div className="field"><label>&nbsp;</label>
              <div style={{ display: 'flex', gap: 8 }}>
                <div style={{ flex: 1 }}><input className="input" type="time" value={start} onChange={(e) => setStart(e.target.value)} title="بداية الدوام" /></div>
                <div style={{ flex: 1 }}><input className="input" type="time" value={end} onChange={(e) => setEnd(e.target.value)} title="نهاية الدوام" /></div>
              </div>
            </div>
          </div>

          <div className="form-sec-h"><span className="si"><Icon name="card" /></span> الأجر</div>
          <div className="field"><label>آلية الأجر</label>
            <select value={payType} onChange={(e) => setPayType(e.target.value as PayType)}>
              <option value="salary">راتب ثابت</option>
              <option value="pct">نسبة</option>
              <option value="both">راتب + نسبة</option>
              <option value="session">بالجلسة</option>
            </select>
          </div>
          <div className="picker-grid">
            {(payType === 'salary' || payType === 'both') && (
              <div className="field"><label>الراتب الشهري (ر.س)</label><input className="input" type="number" value={salary} onChange={(e) => setSalary(e.target.value)} placeholder="0" /></div>
            )}
            {(payType === 'pct' || payType === 'both') && (
              <div className="field"><label>النسبة (%)</label><input className="input" type="number" value={pct} onChange={(e) => setPct(e.target.value)} placeholder="0" /></div>
            )}
            {payType === 'session' && (
              <div className="field"><label>أجر الجلسة (ر.س)</label><input className="input" type="number" value={session} onChange={(e) => setSession(e.target.value)} placeholder="0" /></div>
            )}
          </div>

          <div className="form-sec-h"><span className="si"><Icon name="lock" /></span> الصلاحيات</div>
          <div className="presets">
            <span style={{ fontSize: 12, color: 'var(--muted)', alignSelf: 'center' }}>قوالب جاهزة:</span>
            {Object.keys(PERM_PRESETS).map((k) => (
              <button key={k} className="preset-btn" onClick={() => applyPreset(k)} type="button">{k}</button>
            ))}
            <button className="preset-btn" onClick={clearPerms} type="button">مسح الكل</button>
          </div>
          <div>
            {PERM_GROUPS.map((grp) => (
              <React.Fragment key={grp.g}>
                <div style={{ fontSize: '11.5px', fontWeight: 800, color: 'var(--primary)', margin: '13px 0 7px' }}>{grp.g}</div>
                <div className="perm-grid">
                  {grp.items.map((p) => (
                    <div key={p} className={`perm${perms.indexOf(p) >= 0 ? ' on' : ''}`} onClick={() => togglePerm(p)}>
                      <span className="pk"><Icon name="check" /></span>{p}
                    </div>
                  ))}
                </div>
              </React.Fragment>
            ))}
          </div>
          <button className="btn block" style={{ marginTop: 18 }} onClick={addStaff} type="button">
            <Icon name="user" /> تسجيل الموظف
          </button>
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>الموظفون المسجّلون</h3><span className="sub">{list.length} موظفين</span></div>
        <div className="card-b t-wrap">
          <table className="tbl">
            <thead>
              <tr>
                <th>الموظف</th><th>الفرع</th><th>الأجر</th><th>الدوام</th><th>الصلاحيات</th><th>الحالة</th><th></th>
              </tr>
            </thead>
            <tbody>
              {list.map((s, i) => (
                <tr key={i}>
                  <td>
                    <div className="staff-name">
                      <div className="staff-av">{s.name.replace(/^أ\.?\s*/, '').slice(0, 1)}</div>
                      <div><div className="sn-b">{s.name}</div><div className="sn-s">{s.role}</div></div>
                    </div>
                  </td>
                  <td className="muted">{s.branch}</td>
                  <td className="muted">{s.pay || '—'}</td>
                  <td className="muted" style={{ direction: 'ltr' }}>{s.start && s.end ? `${s.start} – ${s.end}` : '—'}</td>
                  <td><span className="perm-count">{(s.perms && s.perms.length) || 0} صلاحية</span></td>
                  <td><Badge text={s.status || 'نشط'} tone={s.status === 'موقوف' ? 'b-grey' : 'b-green'} /></td>
                  <td>
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                      <button className="btn soft sm" onClick={() => setDetail(s)} type="button"><Icon name="user" /> تفاصيل</button>
                      <button className="btn soft sm" onClick={() => toggleStaff(i)} type="button">
                        {s.status === 'موقوف' ? <><Icon name="check" /> تفعيل</> : <><Icon name="lock" /> إيقاف</>}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <Modal title={detail ? `بيانات الموظف — ${detail.name}` : ''} open={!!detail} onClose={() => setDetail(null)}>
        {detail && (
          <>
            <div style={{ marginBottom: 12 }}>
              <Badge text={detail.status || 'نشط'} tone={detail.status === 'موقوف' ? 'b-grey' : 'b-green'} />
            </div>
            <div className="kv"><span className="k">الاسم</span><span className="v">{detail.name}</span></div>
            <div className="kv"><span className="k">الصفة</span><span className="v">{detail.role}</span></div>
            <div className="kv"><span className="k">الفرع</span><span className="v">{detail.branch}</span></div>
            <div className="kv"><span className="k">القسم</span><span className="v">{detail.dept}</span></div>
            <div className="kv"><span className="k">البريد الإلكتروني</span><span className="v" style={{ direction: 'ltr' }}>{detail.email || '—'}</span></div>
            <div className="kv"><span className="k">الجوال</span><span className="v" style={{ direction: 'ltr' }}>{detail.mobile || '—'}</span></div>
            <div className="kv"><span className="k">رقم الهوية</span><span className="v" style={{ direction: 'ltr' }}>{detail.nid || '—'}</span></div>
            <div className="kv"><span className="k">تاريخ المباشرة</span><span className="v">{detail.join || '—'}</span></div>
            <div className="kv"><span className="k">الدوام</span><span className="v" style={{ direction: 'ltr' }}>{detail.start && detail.end ? `${detail.start} – ${detail.end}` : '—'}</span></div>
            <div className="kv"><span className="k">الأجر</span><span className="v">{detail.pay || '—'}</span></div>
            <div style={{ borderTop: '1px solid var(--line-soft)', marginTop: 12, paddingTop: 12 }}>
              <div style={{ fontSize: 12, fontWeight: 800, color: 'var(--deep)', marginBottom: 8 }}>
                الصلاحيات حسب الفئة ({(detail.perms && detail.perms.length) || 0})
              </div>
              {detail.perms && detail.perms.length ? PERM_GROUPS.map((grp) => {
                const have = grp.items.filter((p) => detail.perms.indexOf(p) >= 0);
                if (!have.length) return null;
                return (
                  <div key={grp.g} style={{ marginBottom: 9 }}>
                    <div style={{ fontSize: '10.5px', fontWeight: 800, color: 'var(--primary)', marginBottom: 5 }}>{grp.g}</div>
                    <div className="detail-chips">
                      {have.map((p) => <span key={p} className="chip">{p}</span>)}
                    </div>
                  </div>
                );
              }) : <span className="chip muted">لا توجد صلاحيات</span>}
            </div>
          </>
        )}
      </Modal>
    </>
  );
};

export default AdminStaff;
