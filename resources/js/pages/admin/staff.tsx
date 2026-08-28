import { router, usePage } from '@inertiajs/react';
import React, { useState, useMemo } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import {  DEPTS } from '@/lib/employee-data';
import type {Staff} from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { usePermCatalog } from '@/lib/permissions';

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
}

interface Shared {
  generatedPassword?: { email: string; password: string } | null;
}

const AdminStaff: React.FC<Props> = ({ staff }) => {
  const toast = useToast();
  const { props } = usePage() as unknown as { props: Shared };
  const formRef = React.useRef<HTMLDivElement>(null);
  
  // كتالوج الصلاحيات الموحد من الخادم
  const catalog = usePermCatalog();

  // كلمة المرور المولّدة المعروضة مرة واحدة فقط
  const [cred, setCred] = useState(props.generatedPassword ?? null);
  React.useEffect(() => {
    if (props.generatedPassword) {
setCred(props.generatedPassword);
}
  }, [props.generatedPassword]);

  // التبويب النشط
  const [activeTab, setActiveTab] = useState<'list' | 'form'>('list');

  // فلاتر جدول الموظفين
  const [searchQuery, setSearchQuery] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [deptFilter, setDeptFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  // حقول نموذج الموظف
  const [name, setName] = useState('');
  const [roleKey, setRoleKey] = useState('employee');
  const [role, setRole] = useState('موظف خدمة عملاء');
  const [email, setEmail] = useState('');
  const [mobile, setMobile] = useState('');
  const [nid, setNid] = useState('');
  const [nidHint, setNidHint] = useState<{ name: string; phone: string; roles: string[] } | null>(null);
  const [dept, setDept] = useState(DEPTS[0]);
  const [join, setJoin] = useState('');
  const [start, setStart] = useState('08:00');
  const [end, setEnd] = useState('16:00');
  const [payType, setPayType] = useState<PayType>('salary');
  const [salary, setSalary] = useState('');
  const [pct, setPct] = useState('');
  const [session, setSession] = useState('');
  const [perms, setPerms] = useState<string[]>([]);
  const [permSearch, setPermSearch] = useState('');
  const [busy, setBusy] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);

  const [detail, setDetail] = useState<StaffRow | null>(null);

  // التحقق الفوري من الهوية الوطنية
  const checkNid = async (value: string) => {
    if (!/^\d{10}$/.test(value)) {
      setNidHint(null);

      return;
    }

    try {
      const res = await fetch(`/admin/staff/lookup?nid=${value}`, { headers: { Accept: 'application/json' } });
      const data = await res.json();

      if (data?.exists) {
        setNidHint({ name: data.name, phone: data.phone, roles: data.roles ?? [] });

        if (!name) {
setName(data.name);
}
      } else {
        setNidHint(null);
      }
    } catch {
      setNidHint(null);
    }
  };

  // الصلاحيات المتاحة للدور
  const allowedPerms = catalog.rolePermissions[roleKey] ?? [];
  const permGroups = useMemo(() => {
    return catalog.groups
      .map((grp) => ({
        ...grp,
        items: grp.items.filter((p) => {
          const matchRole = allowedPerms.includes(p);
          const matchFilter = !permSearch.trim() || p.toLowerCase().includes(permSearch.toLowerCase());

          return matchRole && matchFilter;
        }),
      }))
      .filter((grp) => grp.items.length > 0);
  }, [catalog.groups, allowedPerms, permSearch]);

  const presets = Object.keys(catalog.presets)
    .filter((k) => (catalog.presets[k] || []).every((p) => allowedPerms.includes(p)));

  React.useEffect(() => {
    setPerms((prev) => prev.filter((p) => allowedPerms.includes(p)));
  }, [roleKey]);

  const togglePerm = (p: string) =>
    setPerms((prev) => (prev.indexOf(p) >= 0 ? prev.filter((x) => x !== p) : [...prev, p]));

  const applyPreset = (key: string) => {
    setPerms((catalog.presets[key] || []).filter((p) => allowedPerms.includes(p)));
    toast('تم تطبيق قالب: ' + key);
  };
  const selectAllPerms = () => setPerms([...allowedPerms]);
  const clearPerms = () => setPerms([]);

  const resetForm = () => {
    setEditingId(null);
    setName('');
    setRoleKey('employee');
    setRole('موظف خدمة عملاء');
    setEmail('');
    setMobile('');
    setNid('');
    setNidHint(null);
    setDept(DEPTS[0]);
    setJoin('');
    setStart('08:00');
    setEnd('16:00');
    setPayType('salary');
    setSalary('');
    setPct('');
    setSession('');
    setPerms([]);
    setPermSearch('');
  };

  const startEdit = (s: StaffRow) => {
    setDetail(null);
    setEditingId(s.id);
    setName(s.name);
    setRoleKey(s.roleKey ?? 'employee');
    setRole(s.role || 'موظف خدمة عملاء');
    setEmail(s.email === '—' ? '' : s.email);
    setMobile(s.mobile === '—' ? '' : s.mobile);
    setNid(s.nid === '—' ? '' : s.nid);
    setDept(s.dept && s.dept !== '—' ? s.dept : DEPTS[0]);
    setJoin(s.join === '—' ? '' : s.join);
    setStart(s.start && s.start !== '—' ? s.start : '08:00');
    setEnd(s.end && s.end !== '—' ? s.end : '16:00');
    setPayType((s.payType as PayType) ?? 'salary');
    setSalary(s.salary ? String(s.salary) : '');
    setPct(s.pct != null ? String(s.pct) : '');
    setSession(s.sessionFee != null ? String(s.sessionFee) : '');
    setPerms([...(s.perms ?? [])]);
    setActiveTab('form');
    setTimeout(() => {
      formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 100);
  };

  const submitStaff = () => {
    if (!name.trim()) {
      toast('يرجى إدخال اسم الموظف الكامل');

      return;
    }

    if (!nid.trim()) {
      toast('يرجى إدخال رقم الهوية الوطنية');

      return;
    }

    if (!email.trim()) {
      toast('يرجى إدخال البريد الإلكتروني');

      return;
    }

    if (!mobile.trim()) {
      toast('يرجى إدخال رقم الجوال');

      return;
    }

    setBusy(true);
    const payload = {
      name,
      role: roleKey,
      job_title: role,
      email,
      mobile,
      nid,
      dept,
      join,
      start,
      end,
      payType,
      salary,
      pct,
      session,
      perms,
    };
    const opts = {
      preserveScroll: true,
      onError: (e: Record<string, string>) => toast((Object.values(e)[0] as string) || 'تعذّر الحفظ'),
      onFinish: () => setBusy(false),
    };

    if (editingId) {
      router.put(`/admin/staff/${editingId}`, payload, {
        ...opts,
        onSuccess: () => {
          resetForm();
          setActiveTab('list');
          toast('تم تحديث بيانات الموظف بنجاح');
        },
      });
    } else {
      router.post('/admin/staff', payload, {
        ...opts,
        onSuccess: () => {
          resetForm();
          setActiveTab('list');
          toast('تم تسجيل الموظف وتوليد بيانات الدخول بنجاح');
        },
      });
    }
  };

  const toggleStaff = (s: StaffRow) =>
    router.post(
      `/admin/staff/${s.id}/toggle`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => toast(s.status === 'موقوف' ? 'تم تفعيل الحساب' : 'تم إيقاف الحساب'),
      }
    );

  // أُلغيت «معاينة اللوحة» (الإمبرسنيشن) بقرار 2026-08-28 — المسار الخادمي معلَّق أيضًا
  // const previewStaff = (s: StaffRow) => router.post(`/admin/staff/${s.id}/preview`);

  // إحصائيات الكادر
  const lawyersCount = staff.filter((s) => s.roleKey === 'lawyer').length;
  const employeesCount = staff.filter((s) => s.roleKey === 'employee').length;
  const adminsCount = staff.filter((s) => s.roleKey === 'admin').length;

  // تصفية القائمة
  const filteredStaff = staff.filter((s) => {
    if (roleFilter && s.roleKey !== roleFilter) {
return false;
}

    if (deptFilter && s.dept !== deptFilter) {
return false;
}

    if (statusFilter && (s.status || 'نشط') !== statusFilter) {
return false;
}

    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      const matchName = s.name.toLowerCase().includes(q);
      const matchRole = s.role.toLowerCase().includes(q);
      const matchEmail = (s.email || '').toLowerCase().includes(q);
      const matchMobile = (s.mobile || '').includes(q);
      const matchNid = (s.nid || '').includes(q);
      const matchDept = (s.dept || '').toLowerCase().includes(q);

      if (!matchName && !matchRole && !matchEmail && !matchMobile && !matchNid && !matchDept) {
return false;
}
    }

    return true;
  });

  // حساب ساعات العمل اليومية للعرض
  const workHoursText = useMemo(() => {
    if (!start || !end) {
return '—';
}

    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    let diff = (eh * 60 + em) - (sh * 60 + sm);

    if (diff < 0) {
diff += 24 * 60;
}

    const hours = Math.floor(diff / 60);
    const mins = diff % 60;

    return `${hours} ساعة ${mins > 0 ? `و ${mins} دقيقة` : ''}`;
  }, [start, end]);

  return (
    <>
      {/* البانر الرئيسي المتناسق مع لوحة التحكم */}
      <div className="hero">
        <h2>إدارة وتسجيل الكادر الوظيفي 👥</h2>
        <p>تسجيل المحامين والموظفين، ضبط ساعات العمل والأجور، وتخصيص الصلاحيات بدقة وأمان.</p>
        <div className="hero-cta" style={{ flexWrap: 'wrap', gap: 8 }}>
          <button
            className={`hero-b ${activeTab === 'list' && !roleFilter ? '' : 'ghost'}`}
            onClick={() => {
 setActiveTab('list'); setRoleFilter(''); 
}}
            type="button"
          >
            <Icon name="user" /> كل الكادر ({staff.length})
          </button>
          <button
            className={`hero-b ${activeTab === 'list' && roleFilter === 'lawyer' ? '' : 'ghost'}`}
            onClick={() => {
 setActiveTab('list'); setRoleFilter('lawyer'); 
}}
            type="button"
          >
            <Icon name="scale" /> المحامين ({lawyersCount})
          </button>
          <button
            className={`hero-b ${activeTab === 'list' && roleFilter === 'employee' ? '' : 'ghost'}`}
            onClick={() => {
 setActiveTab('list'); setRoleFilter('employee'); 
}}
            type="button"
          >
            <Icon name="folder" /> الموظفين ({employeesCount})
          </button>
          <button
            className={`hero-b ${activeTab === 'list' && roleFilter === 'admin' ? '' : 'ghost'}`}
            onClick={() => {
 setActiveTab('list'); setRoleFilter('admin'); 
}}
            type="button"
          >
            <Icon name="lock" /> الإدارة العليا ({adminsCount})
          </button>
          <button
            className={`hero-b ${activeTab === 'form' ? '' : 'ghost'}`}
            onClick={() => {
              if (activeTab !== 'form') {
                resetForm();
                setActiveTab('form');
              }
            }}
            type="button"
            style={{ marginInlineStart: 'auto', background: activeTab === 'form' ? '#fff' : 'rgba(255, 255, 255, 0.25)' }}
          >
            <Icon name="user" /> {editingId ? 'تعديل موظف' : '+ تسجيل موظف جديد'}
          </button>
        </div>
      </div>

      {/* بطاقة كلمة المرور المولّدة عند إضافة موظف جديد */}
      {cred && (
        <div className="card" style={{ borderInlineStart: '4px solid var(--primary)', marginBottom: 16 }}>
          <div className="card-h">
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Icon name="lock" />
              <h3 style={{ margin: 0 }}>بيانات دخول الموظف الجديد</h3>
            </div>
            <button className="btn soft sm" onClick={() => setCred(null)} type="button">
              <Icon name="close" /> إخفاء
            </button>
          </div>
          <div className="card-b" style={{ padding: '14px 18px' }}>
            <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 10 }}>
              تُعرض كلمة المرور <b>مرة واحدة فقط</b> — يرجى نسخها وتسليمها للموظف لتسجيل دخوله وتغييرها عند أول دخول.
            </p>
            <div className="kv"><span className="k">البريد الإلكتروني</span><span className="v mono" style={{ direction: 'ltr' }}>{cred.email}</span></div>
            <div className="kv"><span className="k">كلمة المرور المؤقتة</span><span className="v mono" style={{ direction: 'ltr', fontWeight: 800, letterSpacing: 1, color: 'var(--primary)' }}>{cred.password}</span></div>
            <button
              className="btn soft sm"
              style={{ marginTop: 8 }}
              onClick={() => {
                if (navigator.clipboard) {
void navigator.clipboard.writeText(cred.password);
}

                toast('تم نسخ كلمة المرور بنجاح');
              }}
              type="button"
            >
              <Icon name="link" /> نسخ كلمة المرور
            </button>
          </div>
        </div>
      )}

      {/* شريط التبديل بين قائمة الكادر ونموذج التسجيل */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 16, borderBottom: '1px solid var(--line-soft)', paddingBottom: 10 }}>
        <button
          type="button"
          className={`btn sm ${activeTab === 'list' ? '' : 'soft'}`}
          onClick={() => setActiveTab('list')}
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
        >
          <Icon name="user" /> قائمة الكادر الوظيفي ({staff.length})
        </button>
        <button
          type="button"
          className={`btn sm ${activeTab === 'form' ? '' : 'soft'}`}
          onClick={() => {
            if (activeTab !== 'form') {
resetForm();
}

            setActiveTab('form');
          }}
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
        >
          <Icon name={editingId ? 'doc' : 'user'} /> {editingId ? `تعديل الموظف: ${name}` : 'تسجيل موظف جديد'}
        </button>
      </div>

      {/* ========================================================================= */}
      {/* 1. تبويب قائمة الموظفين (Staff Directory) */}
      {/* ========================================================================= */}
      {activeTab === 'list' && (
        <div className="card">
          <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <h3>الكادر الوظيفي</h3>
              <span className="sub">({filteredStaff.length} من {staff.length})</span>
            </div>

            <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
              <div style={{ position: 'relative', width: 220 }}>
                <input
                  type="text"
                  className="input"
                  placeholder="ابحث بالاسم، الهوية، الجوال..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  style={{ paddingInlineStart: 28, fontSize: 12.5, padding: '7px 10px 7px 28px' }}
                />
                {searchQuery && (
                  <button
                    type="button"
                    onClick={() => setSearchQuery('')}
                    style={{
                      position: 'absolute',
                      left: 8,
                      top: '50%',
                      transform: 'translateY(-50%)',
                      background: 'none',
                      border: 'none',
                      cursor: 'pointer',
                      color: 'var(--muted)',
                      fontSize: 11,
                    }}
                  >
                    ✕
                  </button>
                )}
              </div>

              <select
                className="input"
                value={roleFilter}
                onChange={(e) => setRoleFilter(e.target.value)}
                style={{ width: 140, fontSize: 12.5, padding: '7px 10px' }}
              >
                <option value="">جميع الأدوار</option>
                <option value="lawyer">محامون</option>
                <option value="employee">موظفون</option>
                <option value="admin">إدارة عليا</option>
              </select>

              <select
                className="input"
                value={deptFilter}
                onChange={(e) => setDeptFilter(e.target.value)}
                style={{ width: 140, fontSize: 12.5, padding: '7px 10px' }}
              >
                <option value="">جميع الأقسام</option>
                {DEPTS.map((d) => (
                  <option key={d} value={d}>{d}</option>
                ))}
              </select>

              <select
                className="input"
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                style={{ width: 110, fontSize: 12.5, padding: '7px 10px' }}
              >
                <option value="">كل الحالات</option>
                <option value="نشط">نشط</option>
                <option value="موقوف">موقوف</option>
              </select>

              {(searchQuery || roleFilter || deptFilter || statusFilter) && (
                <button
                  type="button"
                  className="btn sm ghost"
                  onClick={() => {
 setSearchQuery(''); setRoleFilter(''); setDeptFilter(''); setStatusFilter(''); 
}}
                  title="إلغاء الفلاتر"
                >
                  إلغاء الفلاتر
                </button>
              )}
            </div>
          </div>

          <div className="card-b t-wrap">
            {filteredStaff.length === 0 ? (
              <div className="empty">
                <Icon name="user" />
                <b>لا يوجد موظفون يطابقون معايير البحث والفلترة</b>
              </div>
            ) : (
              <table className="tbl" style={{ minWidth: 780 }}>
                <thead>
                  <tr>
                    <th style={{ minWidth: 180 }}>الموظف والصفة</th>
                    <th style={{ minWidth: 130 }}>القسم المختص</th>
                    <th style={{ minWidth: 120 }}>آلية الأجر</th>
                    <th style={{ minWidth: 120 }}>أوقات الدوام</th>
                    <th style={{ minWidth: 110 }}>الصلاحيات</th>
                    <th style={{ minWidth: 90 }}>الحالة</th>
                    <th style={{ minWidth: 150, textAlign: 'center' }}>الإجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredStaff.map((s) => (
                    <tr key={s.id}>
                      <td>
                        <div className="staff-name">
                          <div className="staff-av">
                            {s.name.replace(/^أ\.?\s*/, '').slice(0, 1)}
                          </div>
                          <div>
                            <div className="sn-b">{s.name}</div>
                            <div className="sn-s" style={{ color: 'var(--muted)', fontSize: 11.5 }}>
                              {s.role} {s.roleKey === 'lawyer' ? '⚖️' : s.roleKey === 'admin' ? '🏛️' : '💼'}
                            </div>
                          </div>
                        </div>
                      </td>
                      <td>
                        <span className="badge-s b-blue" style={{ fontSize: 11.5, padding: '3px 8px' }}>
                          <Icon name="folder" cls="ic sm" /> {s.dept || 'القسم العام'}
                        </span>
                      </td>
                      <td className="muted mono" style={{ fontSize: 12.5 }}>{s.pay || '—'}</td>
                      <td className="muted mono" style={{ direction: 'ltr', textAlign: 'right', fontSize: 12 }}>
                        {s.start && s.end && s.start !== '—' ? `${s.start} – ${s.end}` : '—'}
                      </td>
                      <td>
                        <span className="perm-count">{(s.perms && s.perms.length) || 0} صلاحية</span>
                      </td>
                      <td>
                        <Badge text={s.status || 'نشط'} tone={s.status === 'موقوف' ? 'b-grey' : 'b-green'} />
                      </td>
                      <td style={{ textAlign: 'center' }}>
                        <div style={{ display: 'inline-flex', gap: 4, flexWrap: 'nowrap' }}>
                          <button
                            className="btn soft sm"
                            onClick={() => setDetail(s)}
                            type="button"
                            title="عرض تفاصيل الموظف"
                          >
                            <Icon name="user" /> تفاصيل
                          </button>
                          <button
                            className="btn soft sm"
                            onClick={() => startEdit(s)}
                            type="button"
                            title="تعديل بيانات الموظف والصلاحيات"
                          >
                            <Icon name="doc" /> تعديل
                          </button>
                          {s.roleKey !== 'admin' && (
                            <button
                              className="btn soft sm"
                              onClick={() => toggleStaff(s)}
                              type="button"
                              title={s.status === 'موقوف' ? 'تفعيل الحساب' : 'إيقاف الحساب'}
                            >
                              {s.status === 'موقوف' ? (
                                <><Icon name="check" /> تفعيل</>
                              ) : (
                                <><Icon name="lock" /> إيقاف</>
                              )}
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* 2. تبويب نموذج تسجيل / تعديل موظف (Enterprise Onboarding Form) */}
      {/* ========================================================================= */}
      {activeTab === 'form' && (
        <div className="tflow" ref={formRef}>
          <div className="tf-grid">
            {/* العمود الرئيسي: نموذج الإدخال */}
            <div className="card" style={{ boxShadow: '0 4px 20px -8px rgba(10,42,85,.12)' }}>
            <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <Icon name={editingId ? 'doc' : 'user'} />
                <h3 style={{ margin: 0 }}>{editingId ? `تعديل بيانات الموظف — ${name}` : 'تسجيل موظف جديد في المنصة'}</h3>
              </div>
              {editingId && (
                <button
                  className="btn soft sm"
                  onClick={() => {
 resetForm(); setActiveTab('list'); 
}}
                  type="button"
                >
                  <Icon name="close" /> إلغاء التعديل والعودة للقائمة
                </button>
              )}
            </div>

            <div className="card-b" style={{ padding: '24px 26px' }}>
              {/* ------------------------------------------------------------- */}
              {/* القسم الأول: اختيار الدور الرئيسي عبر بطاقات تفاعلية */}
              {/* ------------------------------------------------------------- */}
              <div className="form-sec-h" style={{ marginTop: 0 }}>
                <span className="si"><Icon name="user" /></span> 1. الدور الوظيفي ولوحة التحكم
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12, marginBottom: 18 }}>
                {/* بطاقة محامٍ */}
                <div
                  onClick={() => {
                    setRoleKey('lawyer');

                    if (role === 'موظف خدمة عملاء' || role === 'إداري') {
setRole('محامٍ');
}
                  }}
                  style={{
                    border: `1.8px solid ${roleKey === 'lawyer' ? 'var(--primary)' : 'var(--line)'}`,
                    borderRadius: 12,
                    padding: '14px 16px',
                    cursor: 'pointer',
                    background: roleKey === 'lawyer' ? 'rgba(14,92,156,.06)' : '#fff',
                    transition: 'all .15s ease',
                    position: 'relative',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
                    <span style={{ fontSize: 20 }}>⚖️</span>
                    <b style={{ fontSize: 14, color: roleKey === 'lawyer' ? 'var(--primary)' : 'var(--deep)' }}>محامٍ مرخص</b>
                    {roleKey === 'lawyer' && <span style={{ marginInlineStart: 'auto', color: 'var(--primary)', fontWeight: 800 }}>✓</span>}
                  </div>
                  <p style={{ margin: 0, fontSize: 11.5, color: 'var(--muted)', lineHeight: 1.6 }}>
                    إدارة القضايا والاستشارات، كتابة المذكرات، ومتابعة الجلسات باللوحة القانونية.
                  </p>
                </div>

                {/* بطاقة موظف */}
                <div
                  onClick={() => {
                    setRoleKey('employee');

                    if (role === 'محامٍ' || role === 'محامٍ مستشار') {
setRole('موظف خدمة عملاء');
}
                  }}
                  style={{
                    border: `1.8px solid ${roleKey === 'employee' ? 'var(--primary)' : 'var(--line)'}`,
                    borderRadius: 12,
                    padding: '14px 16px',
                    cursor: 'pointer',
                    background: roleKey === 'employee' ? 'rgba(14,92,156,.06)' : '#fff',
                    transition: 'all .15s ease',
                    position: 'relative',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
                    <span style={{ fontSize: 20 }}>💼</span>
                    <b style={{ fontSize: 14, color: roleKey === 'employee' ? 'var(--primary)' : 'var(--deep)' }}>موظف مساند / خدمة</b>
                    {roleKey === 'employee' && <span style={{ marginInlineStart: 'auto', color: 'var(--primary)', fontWeight: 800 }}>✓</span>}
                  </div>
                  <p style={{ margin: 0, fontSize: 11.5, color: 'var(--muted)', lineHeight: 1.6 }}>
                    خدمة العملاء، معالجة التذاكر الواردة، تنسيق المواعيد والاتصالات والملفات.
                  </p>
                </div>

                {/* بطاقة إدارة عليا */}
                <div
                  onClick={() => setRoleKey('admin')}
                  style={{
                    border: `1.8px solid ${roleKey === 'admin' ? 'var(--primary)' : 'var(--line)'}`,
                    borderRadius: 12,
                    padding: '14px 16px',
                    cursor: 'pointer',
                    background: roleKey === 'admin' ? 'rgba(14,92,156,.06)' : '#fff',
                    transition: 'all .15s ease',
                    position: 'relative',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
                    <span style={{ fontSize: 20 }}>🏛️</span>
                    <b style={{ fontSize: 14, color: roleKey === 'admin' ? 'var(--primary)' : 'var(--deep)' }}>الإدارة العليا</b>
                    {roleKey === 'admin' && <span style={{ marginInlineStart: 'auto', color: 'var(--primary)', fontWeight: 800 }}>✓</span>}
                  </div>
                  <p style={{ margin: 0, fontSize: 11.5, color: 'var(--muted)', lineHeight: 1.6 }}>
                    صلاحيات إدارية ومالية شاملة، إدارة الطاقم، وتوزيع القضايا والتقارير.
                  </p>
                </div>
              </div>

              {/* ------------------------------------------------------------- */}
              {/* القسم الثاني: البيانات الشخصية والمهنية */}
              {/* ------------------------------------------------------------- */}
              <div className="picker-grid">
                <div className="field">
                  <label>الاسم الكامل للموظف <span style={{ color: 'var(--red)' }}>*</span></label>
                  <input
                    className="input"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="مثال: أ. عبدالمحسن بن خالد"
                    required
                  />
                </div>

                <div className="field">
                  <label>الصفة والمسمى الوظيفي <span style={{ color: 'var(--red)' }}>*</span></label>
                  <select value={role} onChange={(e) => setRole(e.target.value)}>
                    <option>موظف خدمة عملاء</option>
                    <option>محامٍ</option>
                    <option>محامٍ مستشار</option>
                    <option>إداري</option>
                    <option>محاسب</option>
                    <option>مدير العمليات</option>
                  </select>
                </div>
              </div>

              <div className="picker-grid">
                <div className="field">
                  <label>رقم الهوية الوطنية / الإقامة <span style={{ color: 'var(--red)' }}>*</span></label>
                  <input
                    className="input mono"
                    value={nid}
                    onChange={(e) => setNid(e.target.value)}
                    onBlur={(e) => checkNid(e.target.value)}
                    placeholder="1xxxxxxxxx (10 أرقام)"
                    maxLength={10}
                    required
                  />
                  {nidHint && (
                    <div style={{ marginTop: 6, fontSize: 12, background: 'var(--amber-bg)', color: 'var(--amber)', border: '1px solid var(--amber)', borderRadius: 8, padding: '7px 10px' }}>
                      يوجد شخص مسجل بهذه الهوية: <b>{nidHint.name}</b>
                      {nidHint.roles.length > 0 && <> (الأدوار السابقة: {nidHint.roles.join('، ')})</>} — سيُضاف <b>دور إضافي</b> لنفس الشخص.
                    </div>
                  )}
                </div>

                <div className="field">
                  <label>البريد الإلكتروني المهني <span style={{ color: 'var(--red)' }}>*</span></label>
                  <input
                    className="input"
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="name@office.sa"
                    required
                  />
                </div>
              </div>

              <div className="picker-grid">
                <div className="field">
                  <label>رقم الجوال <span style={{ color: 'var(--red)' }}>*</span></label>
                  <input
                    className="input mono"
                    value={mobile}
                    onChange={(e) => setMobile(e.target.value)}
                    placeholder="05xxxxxxxx"
                    required
                  />
                </div>

                <div className="field">
                  <label>القسم المختص <span style={{ color: 'var(--red)' }}>*</span></label>
                  <select value={dept} onChange={(e) => setDept(e.target.value)}>
                    {DEPTS.map((d) => (
                      <option key={d} value={d}>{d}</option>
                    ))}
                  </select>
                </div>
              </div>

              {/* ------------------------------------------------------------- */}
              {/* القسم الثالث: بيانات العمل والدوام */}
              {/* ------------------------------------------------------------- */}
              <div className="form-sec-h">
                <span className="si"><Icon name="folder" /></span> 2. المباشرة وأوقات الدوام الرسمي
              </div>

              <div className="picker-grid">
                <div className="field">
                  <label>تاريخ المباشرة</label>
                  <input
                    className="input"
                    type="date"
                    value={join}
                    onChange={(e) => setJoin(e.target.value)}
                  />
                </div>

                <div className="field">
                  <label>ساعات الدوام اليومي (المدة: {workHoursText})</label>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)', display: 'block', marginBottom: 3 }}>من (البداية):</span>
                      <input
                        className="input mono"
                        type="time"
                        value={start}
                        onChange={(e) => setStart(e.target.value)}
                      />
                    </div>
                    <div>
                      <span style={{ fontSize: 11, color: 'var(--muted)', display: 'block', marginBottom: 3 }}>إلى (النهاية):</span>
                      <input
                        className="input mono"
                        type="time"
                        value={end}
                        onChange={(e) => setEnd(e.target.value)}
                      />
                    </div>
                  </div>
                </div>
              </div>

              {/* ------------------------------------------------------------- */}
              {/* القسم الرابع: آلية الأجر والتعاقد المالي */}
              {/* ------------------------------------------------------------- */}
              <div className="form-sec-h">
                <span className="si"><Icon name="card" /></span> 3. آلية الأجر والتعاقد المالي
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: 8, marginBottom: 14 }}>
                {[
                  { id: 'salary', label: 'راتب شهري ثابت', icon: '💵' },
                  { id: 'pct', label: 'نسبة من الأتعاب', icon: '📈' },
                  { id: 'both', label: 'راتب + نسبة', icon: '🤝' },
                  { id: 'session', label: 'بالجلسة الواحدة', icon: '⚖️' },
                ].map((pt) => (
                  <button
                    key={pt.id}
                    type="button"
                    onClick={() => setPayType(pt.id as PayType)}
                    style={{
                      border: `1.5px solid ${payType === pt.id ? 'var(--primary)' : 'var(--line)'}`,
                      background: payType === pt.id ? 'rgba(14,92,156,.08)' : '#fff',
                      color: payType === pt.id ? 'var(--primary)' : 'var(--deep)',
                      fontWeight: payType === pt.id ? 800 : 600,
                      borderRadius: 10,
                      padding: '10px 12px',
                      fontSize: 12.5,
                      cursor: 'pointer',
                      display: 'flex',
                      alignItems: 'center',
                      gap: 6,
                      justifyContent: 'center',
                      transition: 'all .13s ease',
                    }}
                  >
                    <span>{pt.icon}</span>
                    <span>{pt.label}</span>
                  </button>
                ))}
              </div>

              <div className="picker-grid">
                {(payType === 'salary' || payType === 'both') && (
                  <div className="field">
                    <label>الراتب الشهري الأساسي (ر.س)</label>
                    <input
                      className="input mono"
                      type="number"
                      value={salary}
                      onChange={(e) => setSalary(e.target.value)}
                      placeholder="مثال: 7000"
                    />
                  </div>
                )}
                {(payType === 'pct' || payType === 'both') && (
                  <div className="field">
                    <label>النسبة المئوية (%)</label>
                    <input
                      className="input mono"
                      type="number"
                      value={pct}
                      onChange={(e) => setPct(e.target.value)}
                      placeholder="مثال: 15"
                    />
                  </div>
                )}
                {payType === 'session' && (
                  <div className="field">
                    <label>أجر الجلسة الواحدة (ر.س)</label>
                    <input
                      className="input mono"
                      type="number"
                      value={session}
                      onChange={(e) => setSession(e.target.value)}
                      placeholder="مثال: 500"
                    />
                  </div>
                )}
              </div>

              {/* ------------------------------------------------------------- */}
              {/* القسم الخامس: مصفوفة الصلاحيات المتقدمة */}
              {/* ------------------------------------------------------------- */}
              <div className="form-sec-h">
                <span className="si"><Icon name="lock" /></span> 4. مصفوفة الصلاحيات الممنوحة
              </div>

              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10, marginBottom: 12 }}>
                <div style={{ position: 'relative', width: 220 }}>
                  <input
                    type="text"
                    className="input"
                    placeholder="ابحث في الصلاحيات..."
                    value={permSearch}
                    onChange={(e) => setPermSearch(e.target.value)}
                    style={{ fontSize: 12, padding: '6px 10px 6px 26px' }}
                  />
                  {permSearch && (
                    <button
                      type="button"
                      onClick={() => setPermSearch('')}
                      style={{
                        position: 'absolute',
                        left: 8,
                        top: '50%',
                        transform: 'translateY(-50%)',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        color: 'var(--muted)',
                        fontSize: 11,
                      }}
                    >
                      ✕
                    </button>
                  )}
                </div>

                <div className="presets" style={{ margin: 0 }}>
                  <span style={{ fontSize: 11.5, color: 'var(--muted)', alignSelf: 'center', fontWeight: 700 }}>قوالب جاهزة:</span>
                  {presets.map((k) => (
                    <button
                      key={k}
                      className="preset-btn"
                      onClick={() => applyPreset(k)}
                      type="button"
                    >
                      {k}
                    </button>
                  ))}
                  <button className="preset-btn" onClick={selectAllPerms} type="button" style={{ color: 'var(--success)', borderColor: 'var(--success)' }}>
                    ✓ تحديد الكل
                  </button>
                  <button className="preset-btn" onClick={clearPerms} type="button" style={{ color: 'var(--red)', borderColor: 'var(--red)' }}>
                    ✕ مسح الكل
                  </button>
                </div>
              </div>

              <div style={{ background: '#f8fafc', padding: 14, borderRadius: 12, border: '1px solid var(--line)' }}>
                {permGroups.map((grp) => (
                  <div key={grp.g} style={{ marginBottom: 14 }}>
                    <div style={{ fontSize: 12, fontWeight: 800, color: 'var(--primary)', marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
                      <Icon name="check" cls="ic sm" /> {grp.g}
                    </div>
                    <div className="perm-grid">
                      {grp.items.map((p) => {
                        const isOn = perms.indexOf(p) >= 0;

                        return (
                          <div
                            key={p}
                            className={`perm${isOn ? ' on' : ''}`}
                            onClick={() => togglePerm(p)}
                            style={{ background: isOn ? 'rgba(14,92,156,.09)' : '#fff' }}
                          >
                            <span className="pk">
                              <Icon name="check" />
                            </span>
                            <span style={{ fontSize: 12 }}>{p}</span>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                ))}
              </div>

              {/* أزرار الحفظ والإلغاء */}
              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 24, paddingTop: 16, borderTop: '1px solid var(--line-soft)' }}>
                <button
                  type="button"
                  className="btn soft"
                  onClick={() => {
 resetForm(); setActiveTab('list'); 
}}
                >
                  إلغاء
                </button>
                <button
                  type="button"
                  className="btn"
                  onClick={submitStaff}
                  disabled={busy}
                  style={{ minWidth: 180, padding: '10px 20px', fontSize: 13.5 }}
                >
                  <Icon name={editingId ? 'check' : 'user'} />
                  {busy ? 'جارٍ الحفظ والاعتماد...' : (editingId ? 'حفظ تعديلات الموظف' : 'تسجيل واعتماد الموظف')}
                </button>
              </div>
            </div>
          </div>

          {/* العمود الجانبي: المعاينة الحية للملف الوظيفي (Live Profile Card Preview) — مخفي على شاشات الهواتف */}
          <aside className="tf-aside staff-live-preview">
            <div className="card" style={{ background: '#fff', border: '1.5px solid var(--line)' }}>
              <div className="card-h">
                <span style={{ fontSize: 12, fontWeight: 800, color: 'var(--deep)' }}>المعاينة الحية للملف الوظيفي</span>
              </div>
              <div className="card-b" style={{ padding: 18, textAlign: 'center' }}>
                <div
                  style={{
                    width: 64,
                    height: 64,
                    borderRadius: 18,
                    background: 'linear-gradient(135deg, var(--brand), var(--cyan))',
                    color: '#fff',
                    display: 'grid',
                    placeItems: 'center',
                    fontSize: 24,
                    fontWeight: 800,
                    margin: '0 auto 12px',
                    boxShadow: '0 8px 20px -6px rgba(10,42,85,.3)',
                  }}
                >
                  {name ? name.replace(/^أ\.?\s*/, '').slice(0, 1) : '؟'}
                </div>

                <div style={{ fontWeight: 800, fontSize: 15, color: 'var(--ink)', marginBottom: 3 }}>
                  {name || 'اسم الموظف الجديد'}
                </div>

                <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 12 }}>
                  {role} {roleKey === 'lawyer' ? '⚖️' : roleKey === 'admin' ? '🏛️' : '💼'}
                </div>

                <div style={{ display: 'flex', flexDirection: 'column', gap: 8, textAlign: 'right', fontSize: 12 }}>
                  <div className="kv" style={{ padding: '4px 0' }}>
                    <span className="k">القسم</span>
                    <span className="v badge-s b-blue" style={{ fontSize: 11, padding: '2px 6px' }}>{dept}</span>
                  </div>
                  <div className="kv" style={{ padding: '4px 0' }}>
                    <span className="k">الدوام</span>
                    <span className="v mono">{start} – {end}</span>
                  </div>
                  <div className="kv" style={{ padding: '4px 0' }}>
                    <span className="k">آلية الأجر</span>
                    <span className="v mono">
                      {payType === 'salary' && `${salary || 0} ر.س شهرياً`}
                      {payType === 'pct' && `${pct || 0}% نسبة`}
                      {payType === 'both' && `${salary || 0} ر.س + ${pct || 0}%`}
                      {payType === 'session' && `${session || 0} ر.س / جلسة`}
                    </span>
                  </div>
                  <div className="kv" style={{ padding: '4px 0' }}>
                    <span className="k">الصلاحيات</span>
                    <span className="v perm-count">{perms.length} صلاحية مسندة</span>
                  </div>
                </div>

                <div style={{ marginTop: 14, padding: 10, background: 'rgba(14,92,156,.05)', borderRadius: 8, fontSize: 11.5, color: 'var(--muted)', textAlign: 'right' }}>
                  💡 سيتم توليد كلمة مرور مؤقتة وتفعيل حسابه فور الضغط على زر الحفظ.
                </div>
              </div>
            </div>
          </aside>
        </div>
      </div>
      )}

      {/* نافذة معاينة تفاصيل الموظف (Modal) */}
      <Modal title={detail ? `الملف الوظيفي — ${detail.name}` : ''} open={!!detail} onClose={() => setDetail(null)}>
        {detail && (
          <>
            <div style={{ marginBottom: 14, display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
              <Badge text={detail.status || 'نشط'} tone={detail.status === 'موقوف' ? 'b-grey' : 'b-green'} />
              <button
                className="btn soft sm"
                style={{ marginInlineStart: 'auto' }}
                onClick={() => startEdit(detail)}
                type="button"
              >
                <Icon name="doc" /> تعديل البيانات
              </button>
              {/* أُلغيت «معاينة اللوحة بصلاحياته» (الإمبرسنيشن) بقرار 2026-08-28
              {detail.roleKey !== 'admin' && (
                <button className="btn sm" onClick={() => previewStaff(detail)} type="button">
                  <Icon name="out" /> معاينة اللوحة بصلاحياته
                </button>
              )} */}
            </div>

            <div className="kv"><span className="k">الاسم الكامل</span><span className="v"><b>{detail.name}</b></span></div>
            <div className="kv"><span className="k">الصفة والمسمى</span><span className="v">{detail.role}</span></div>
            <div className="kv"><span className="k">القسم المختص</span><span className="v">{detail.dept}</span></div>
            <div className="kv"><span className="k">البريد الإلكتروني</span><span className="v mono" style={{ direction: 'ltr' }}>{detail.email || '—'}</span></div>
            <div className="kv"><span className="k">رقم الجوال</span><span className="v mono" style={{ direction: 'ltr' }}>{detail.mobile || '—'}</span></div>
            <div className="kv"><span className="k">رقم الهوية الوطنية</span><span className="v mono" style={{ direction: 'ltr' }}>{detail.nid || '—'}</span></div>
            <div className="kv"><span className="k">تاريخ المباشرة</span><span className="v">{detail.join || '—'}</span></div>
            <div className="kv"><span className="k">ساعات الدوام</span><span className="v mono" style={{ direction: 'ltr' }}>{detail.start && detail.end && detail.start !== '—' ? `${detail.start} – ${detail.end}` : '—'}</span></div>
            <div className="kv"><span className="k">الأجر والتعاقد</span><span className="v mono">{detail.pay || '—'}</span></div>

            <div style={{ borderTop: '1px solid var(--line-soft)', marginTop: 14, paddingTop: 12 }}>
              <div style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--deep)', marginBottom: 8 }}>
                الصلاحيات الممنوحة ({(detail.perms && detail.perms.length) || 0})
              </div>
              {detail.perms && detail.perms.length ? (
                catalog.groups.map((grp) => {
                  const have = grp.items.filter((p) => detail.perms.indexOf(p) >= 0);

                  if (!have.length) {
return null;
}

                  return (
                    <div key={grp.g} style={{ marginBottom: 10 }}>
                      <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--primary)', marginBottom: 5 }}>
                        {grp.g}
                      </div>
                      <div className="detail-chips">
                        {have.map((p) => (
                          <span key={p} className="chip">
                            {p}
                          </span>
                        ))}
                      </div>
                    </div>
                  );
                })
              ) : (
                <span className="chip muted">لا توجد صلاحيات مسندة</span>
              )}
            </div>
          </>
        )}
      </Modal>
    </>
  );
};

export default AdminStaff;
