import { router, usePage } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { type Staff, DEPTS } from '@/lib/employee-data';
import { usePermCatalog } from '@/lib/permissions';

// يطابق adStaff + addStaff + toggleStaff + staffDetail + applyPreset + previewStaff — مربوط بالخادم (spatie)

type PayType = 'salary' | 'pct' | 'both' | 'session';

type StaffRow = Staff & {
  id: number;
  roleKey?: string;
  payType?: PayType | null;
  pct?: number | null;
  sessionFee?: number | null;
};

interface Props {
  staff: StaffRow[];
  branches: string[];
}

interface Shared { generatedPassword?: { email: string; password: string } | null }

const AdminStaff: React.FC<Props> = ({ staff, branches }) => {
  const toast = useToast();
  const { props } = usePage() as unknown as { props: Shared };
  const formRef = React.useRef<HTMLDivElement>(null);
  // كتالوج الصلاحيات (المجموعات + القوالب) من الخادم — مصدر وحيد
  const catalog = usePermCatalog();
  // كلمة المرور المولّدة تصل مرة واحدة بعد التسجيل (flash)
  const [cred, setCred] = useState(props.generatedPassword ?? null);
  React.useEffect(() => { if (props.generatedPassword) setCred(props.generatedPassword); }, [props.generatedPassword]);

  // حقول النموذج
  const [name, setName] = useState('');
  const [roleKey, setRoleKey] = useState('employee'); // الدور/اللوحة (enum) صراحةً
  const [role, setRole] = useState('موظف خدمة عملاء');  // الصفة (وصفية فقط)
  const [email, setEmail] = useState('');
  const [mobile, setMobile] = useState('');
  const [nid, setNid] = useState('');
  const [branch, setBranch] = useState(branches[0] ?? '');
  const [dept, setDept] = useState(DEPTS[0]);
  const [join, setJoin] = useState('');
  const [start, setStart] = useState('08:00');
  const [end, setEnd] = useState('16:00');
  const [payType, setPayType] = useState<PayType>('salary');
  const [salary, setSalary] = useState('');
  const [pct, setPct] = useState('');
  const [session, setSession] = useState('');
  const [perms, setPerms] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null); // null = وضع الإضافة

  const [detail, setDetail] = useState<StaffRow | null>(null);

  // الصلاحيات المتاحة للدور المختار فقط (لا يرى الموظف صلاحيات المحامي/الإدارة والعكس)
  const allowedPerms = catalog.rolePermissions[roleKey] ?? [];
  // المجموعات مُصفّاة على صلاحيات الدور؛ تُحذف المجموعات الفارغة
  const permGroups = catalog.groups
    .map((grp) => ({ ...grp, items: grp.items.filter((p) => allowedPerms.includes(p)) }))
    .filter((grp) => grp.items.length > 0);
  // القوالب المناسبة للدور فقط (كل صلاحياتها ضمن صلاحيات الدور)
  const presets = Object.keys(catalog.presets)
    .filter((k) => (catalog.presets[k] || []).every((p) => allowedPerms.includes(p)));

  // عند تغيّر الدور: أبقِ فقط الصلاحيات المتاحة له (احذف ما لا يخصّه)
  React.useEffect(() => {
    setPerms((prev) => prev.filter((p) => allowedPerms.includes(p)));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [roleKey]);

  const togglePerm = (p: string) =>
    setPerms((prev) => (prev.indexOf(p) >= 0 ? prev.filter((x) => x !== p) : [...prev, p]));

  // القوالب من كتالوج الخادم — تُقصر على صلاحيات الدور
  const applyPreset = (key: string) => {
    setPerms((catalog.presets[key] || []).filter((p) => allowedPerms.includes(p)));
    toast('تم تطبيق صلاحيات: ' + key);
  };
  const selectAllPerms = () => setPerms([...allowedPerms]);
  const clearPerms = () => setPerms([]);

  const resetForm = () => {
    setEditingId(null);
    setName(''); setRoleKey('employee'); setRole('موظف خدمة عملاء');
    setEmail(''); setMobile(''); setNid(''); setBranch(branches[0] ?? ''); setDept(DEPTS[0]);
    setJoin(''); setStart('08:00'); setEnd('16:00');
    setPayType('salary'); setSalary(''); setPct(''); setSession(''); setPerms([]);
  };

  // تحميل موظف في النموذج العلوي لتعديله (يطابق حقول staffCard الخام)
  const startEdit = (s: StaffRow) => {
    setDetail(null);
    setEditingId(s.id);
    setName(s.name);
    setRoleKey(s.roleKey ?? 'employee');
    setRole(s.role || 'موظف خدمة عملاء');
    setEmail(s.email === '—' ? '' : s.email);
    setMobile(s.mobile === '—' ? '' : s.mobile);
    setNid(s.nid === '—' ? '' : s.nid);
    setBranch(s.branch && s.branch !== '—' ? s.branch : (branches[0] ?? ''));
    setDept(s.dept && s.dept !== '—' ? s.dept : DEPTS[0]);
    setJoin(s.join === '—' ? '' : s.join);
    setStart(s.start && s.start !== '—' ? s.start : '08:00');
    setEnd(s.end && s.end !== '—' ? s.end : '16:00');
    setPayType((s.payType as PayType) ?? 'salary');
    setSalary(s.salary ? String(s.salary) : '');
    setPct(s.pct != null ? String(s.pct) : '');
    setSession(s.sessionFee != null ? String(s.sessionFee) : '');
    setPerms([...(s.perms ?? [])]);
    formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  const submitStaff = () => {
    if (!name.trim()) { toast('أدخل اسم الموظف'); return; }
    setBusy(true);
    const payload = {
      name, role: roleKey, job_title: role, email, mobile, nid,
      branch, dept, join, start, end,
      payType, salary, pct, session, perms,
    };
    const opts = {
      preserveScroll: true,
      onError: (e: Record<string, string>) => toast((Object.values(e)[0] as string) || 'تعذّر الحفظ'),
      onFinish: () => setBusy(false),
    };
    if (editingId) {
      router.put(`/admin/staff/${editingId}`, payload, {
        ...opts,
        onSuccess: () => { resetForm(); toast('تم تحديث بيانات الموظف'); },
      });
    } else {
      router.post('/admin/staff', payload, {
        ...opts,
        onSuccess: () => { resetForm(); toast('تم تسجيل الموظف'); },
      });
    }
  };

  const toggleStaff = (s: StaffRow) =>
    router.post(`/admin/staff/${s.id}/toggle`, {}, {
      preserveScroll: true,
      onSuccess: () => toast(s.status === 'موقوف' ? 'تم تفعيل الموظف' : 'تم إيقاف الموظف'),
    });

  const previewStaff = (s: StaffRow) =>
    router.post(`/admin/staff/${s.id}/preview`);

  return (
    <>
      <div className="greet">
        <h2>تسجيل الموظفين</h2>
        <p>إضافة الموظفين والمحامين وربطهم بالفروع والأقسام وتحديد صلاحياتهم — بحسابات دخول حقيقية.</p>
      </div>

      {cred && (
        <div className="card" style={{ borderInlineStart: '4px solid var(--primary)' }}>
          <div className="card-h">
            <h3><Icon name="lock" /> بيانات دخول الموظف الجديد</h3>
            <button className="btn soft sm" onClick={() => setCred(null)} type="button"><Icon name="close" /> إخفاء</button>
          </div>
          <div className="card-b" style={{ padding: '14px 18px' }}>
            <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 10 }}>
              تُعرض كلمة المرور <b>مرة واحدة فقط</b> — انسخها وسلّمها للموظف ليغيّرها عند أول دخول.
            </p>
            <div className="kv"><span className="k">البريد</span><span className="v" style={{ direction: 'ltr' }}>{cred.email}</span></div>
            <div className="kv"><span className="k">كلمة المرور</span><span className="v" style={{ direction: 'ltr', fontWeight: 800, letterSpacing: 1 }}>{cred.password}</span></div>
            <button
              className="btn soft sm"
              style={{ marginTop: 8 }}
              onClick={() => { if (navigator.clipboard) void navigator.clipboard.writeText(cred.password); toast('تم نسخ كلمة المرور'); }}
              type="button"
            >
              <Icon name="link" /> نسخ كلمة المرور
            </button>
          </div>
        </div>
      )}

      <div className="card" ref={formRef}>
        <div className="card-h">
          <h3>{editingId ? `تعديل الموظف — ${name}` : 'تسجيل موظف جديد'}</h3>
          {editingId && (
            <button className="btn soft sm" onClick={resetForm} type="button">
              <Icon name="close" /> إلغاء التعديل
            </button>
          )}
        </div>
        <div className="card-b" style={{ padding: 20 }}>
          <div className="form-sec-h"><span className="si"><Icon name="user" /></span> البيانات الأساسية</div>
          <div className="picker-grid">
            <div className="field"><label>الاسم الكامل</label><input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="الاسم الكامل" /></div>
            <div className="field"><label>الدور / اللوحة</label>
              <select value={roleKey} onChange={(e) => setRoleKey(e.target.value)}>
                <option value="employee">موظف (لوحة الموظف)</option>
                <option value="lawyer">محامٍ (لوحة المحامي)</option>
                <option value="admin">الإدارة العليا (صلاحيات كاملة)</option>
              </select>
            </div>
          </div>
          {roleKey === 'admin' && (
            <div className="action-hint" style={{ marginBottom: 4 }}>
              <Icon name="lock" /> حساب «الإدارة العليا» يملك صلاحيات كاملة تتجاوز القالب المحدّد.
            </div>
          )}
          <div className="field"><label>الصفة (وصف وظيفي)</label>
            <select value={role} onChange={(e) => setRole(e.target.value)}>
              <option>موظف خدمة عملاء</option><option>محامٍ</option><option>إداري</option><option>محاسب</option><option>مدير</option>
            </select>
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
                {branches.map((b) => <option key={b}>{b}</option>)}
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
          <div className="action-hint" style={{ marginBottom: 8 }}>
            <Icon name="info" /> تُعرض صلاحيات دور «{roleKey === 'lawyer' ? 'المحامي' : roleKey === 'admin' ? 'الإدارة العليا' : 'الموظف'}» فقط — اختر ما يناسبه أو «اختيار الكل».
          </div>
          <div className="presets">
            <span style={{ fontSize: 12, color: 'var(--muted)', alignSelf: 'center' }}>قوالب جاهزة:</span>
            {presets.map((k) => (
              <button key={k} className="preset-btn" onClick={() => applyPreset(k)} type="button">{k}</button>
            ))}
            <button className="preset-btn" onClick={selectAllPerms} type="button">اختيار الكل</button>
            <button className="preset-btn" onClick={clearPerms} type="button">مسح الكل</button>
          </div>
          <div>
            {permGroups.map((grp) => (
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
          <button className="btn block" style={{ marginTop: 18 }} onClick={submitStaff} disabled={busy} type="button">
            <Icon name={editingId ? 'check' : 'user'} /> {busy ? 'جارٍ الحفظ…' : (editingId ? 'حفظ التعديلات' : 'تسجيل الموظف')}
          </button>
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>الموظفون المسجّلون</h3><span className="sub">{staff.length} موظفين</span></div>
        <div className="card-b t-wrap">
          <table className="tbl">
            <thead>
              <tr>
                <th>الموظف</th><th>الفرع</th><th>الأجر</th><th>الدوام</th><th>الصلاحيات</th><th>الحالة</th><th></th>
              </tr>
            </thead>
            <tbody>
              {staff.map((s) => (
                <tr key={s.id}>
                  <td>
                    <div className="staff-name">
                      <div className="staff-av">{s.name.replace(/^أ\.?\s*/, '').slice(0, 1)}</div>
                      <div><div className="sn-b">{s.name}</div><div className="sn-s">{s.role}</div></div>
                    </div>
                  </td>
                  <td className="muted">{s.branch}</td>
                  <td className="muted">{s.pay || '—'}</td>
                  <td className="muted" style={{ direction: 'ltr' }}>{s.start && s.end && s.start !== '—' ? `${s.start} – ${s.end}` : '—'}</td>
                  <td><span className="perm-count">{(s.perms && s.perms.length) || 0} صلاحية</span></td>
                  <td><Badge text={s.status || 'نشط'} tone={s.status === 'موقوف' ? 'b-grey' : 'b-green'} /></td>
                  <td>
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                      <button className="btn soft sm" onClick={() => setDetail(s)} type="button"><Icon name="user" /> تفاصيل</button>
                      <button className="btn soft sm" onClick={() => startEdit(s)} type="button"><Icon name="doc" /> تعديل</button>
                      <button className="btn soft sm" onClick={() => toggleStaff(s)} type="button">
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
            <div style={{ marginBottom: 12, display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
              <Badge text={detail.status || 'نشط'} tone={detail.status === 'موقوف' ? 'b-grey' : 'b-green'} />
              <button className="btn soft sm" style={{ marginInlineStart: 'auto' }} onClick={() => startEdit(detail)} type="button">
                <Icon name="doc" /> تعديل البيانات
              </button>
              <button className="btn sm" onClick={() => previewStaff(detail)} type="button">
                <Icon name="out" /> معاينة اللوحة بصلاحياته
              </button>
            </div>
            <div className="kv"><span className="k">الاسم</span><span className="v">{detail.name}</span></div>
            <div className="kv"><span className="k">الصفة</span><span className="v">{detail.role}</span></div>
            <div className="kv"><span className="k">الفرع</span><span className="v">{detail.branch}</span></div>
            <div className="kv"><span className="k">القسم</span><span className="v">{detail.dept}</span></div>
            <div className="kv"><span className="k">البريد الإلكتروني</span><span className="v" style={{ direction: 'ltr' }}>{detail.email || '—'}</span></div>
            <div className="kv"><span className="k">الجوال</span><span className="v" style={{ direction: 'ltr' }}>{detail.mobile || '—'}</span></div>
            <div className="kv"><span className="k">رقم الهوية</span><span className="v" style={{ direction: 'ltr' }}>{detail.nid || '—'}</span></div>
            <div className="kv"><span className="k">تاريخ المباشرة</span><span className="v">{detail.join || '—'}</span></div>
            <div className="kv"><span className="k">الدوام</span><span className="v" style={{ direction: 'ltr' }}>{detail.start && detail.end && detail.start !== '—' ? `${detail.start} – ${detail.end}` : '—'}</span></div>
            <div className="kv"><span className="k">الأجر</span><span className="v">{detail.pay || '—'}</span></div>
            <div style={{ borderTop: '1px solid var(--line-soft)', marginTop: 12, paddingTop: 12 }}>
              <div style={{ fontSize: 12, fontWeight: 800, color: 'var(--deep)', marginBottom: 8 }}>
                الصلاحيات حسب الفئة ({(detail.perms && detail.perms.length) || 0})
              </div>
              {detail.perms && detail.perms.length ? catalog.groups.map((grp) => {
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
