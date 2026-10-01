import { router } from '@inertiajs/react';
import React, { useState, useMemo } from 'react';
import { useToast } from '@/components/babylon/Toast';
import StaffPayoutsModal from '@/components/earnings/StaffPayoutsModal';
import PermissionMatrix from '@/components/staff/PermissionMatrix';
import StaffActivityModal from '@/components/staff/StaffActivityModal';
import StaffDirectory from '@/components/staff/StaffDirectory';
import StaffPreview from '@/components/staff/StaffPreview';
import type { LegalDepartmentOption, PayType, PayTypeOption, StaffFilters, StaffRow } from '@/components/staff/types';
import Icon from '@/lib/icons';
import { usePermCatalog } from '@/lib/permissions';
import { firstError } from '@/lib/server-message';

interface Props {
  staff: StaffRow[];
  legalDepartments: LegalDepartmentOption[]; // تخصّصات المحامي (كتالوج الأقسام القانونيّة)
  staffDepartments: string[]; // أقسام الموظّفين الإداريّة
  /** أنواع الأجر من الخادم (`App\Enums\PayType`) — النسبة والجلسة للمحامي وحده */
  payTypes: PayTypeOption[];
}

const PAY_TYPE_ICONS: Record<PayType, string> = { salary: '💵', pct: '📈', both: '🤝', session: '⚖️' };

/**
 * المسمّيات الوظيفيّة — قائمةٌ واحدة تُعرض في النموذج، ومسمّيات المحامي منها تُقترح حين يُختار دوره
 * (كانت تُقارن نصوصاً في معالجات بطاقات الدور).
 */
const JOB_TITLES = ['موظف خدمة عملاء', 'محامٍ', 'محامٍ مستشار', 'إداري', 'محاسب', 'مدير العمليات'];
const LAWYER_TITLES = ['محامٍ', 'محامٍ مستشار'];
const DEFAULT_TITLE = JOB_TITLES[0];

const AdminStaff: React.FC<Props> = ({ staff, legalDepartments = [], staffDepartments = [], payTypes = [] }) => {
  const toast = useToast();
  const formRef = React.useRef<HTMLDivElement>(null);
  
  // كتالوج الصلاحيات الموحد من الخادم
  const catalog = usePermCatalog();

  // التبويب النشط
  const [activeTab, setActiveTab] = useState<'list' | 'form'>('list');

  // فلاتر جدول الموظفين
  const [filters, setFilters] = useState<StaffFilters>({ search: '', role: '', dept: '', status: '' });
  const patchFilters = (patch: Partial<StaffFilters>) => setFilters((f) => ({ ...f, ...patch }));

  // حقول نموذج الموظف
  const [name, setName] = useState('');
  const [roleKey, setRoleKey] = useState('employee');
  const [role, setRole] = useState(DEFAULT_TITLE);
  const [email, setEmail] = useState('');
  const [mobile, setMobile] = useState('');
  const [nid, setNid] = useState('');
  const [nidHint, setNidHint] = useState<{ name: string; phone: string; roles: string[] } | null>(null);
  // قسم الموظّف الإداريّ، وتخصّصات المحامي من الكتالوج (أو «كل الأقسام»)
  const [dept, setDept] = useState(staffDepartments[0] ?? '');
  const [specialtyIds, setSpecialtyIds] = useState<number[]>([]);
  const [coversAll, setCoversAll] = useState(false);
  const toggleSpecialty = (id: number) =>
    setSpecialtyIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  const [join, setJoin] = useState('');
  const [payTypeChoice, setPayType] = useState<PayType>('salary');
  const [salary, setSalary] = useState('');
  const [pct, setPct] = useState('');
  const [session, setSession] = useState('');
  const [perms, setPerms] = useState<string[]>([]);
  const [permSearch, setPermSearch] = useState('');
  const [busy, setBusy] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  // درج «المستحقّات والصرف» — للموظّف والمحامي وحدهما
  const [payoutsFor, setPayoutsFor] = useState<{ id: number; name: string } | null>(null);

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

  // أنواع الأجر المتاحة للدور: النسبة والجلسة للمحامي وحده — وتبديل الدور يُسقط نوعاً لم يعد متاحاً
  // (قيمةٌ مشتقّة لا حالةٌ تُصحَّح: ما يُعرض ويُرسل هو المتاح دائماً)
  const payTypesForRole = payTypes.filter((t) => roleKey === 'lawyer' || !t.lawyerOnly);
  const payType: PayType = payTypesForRole.some((t) => t.id === payTypeChoice) ? payTypeChoice : 'salary';

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
    setRole(DEFAULT_TITLE);
    setEmail('');
    setMobile('');
    setNid('');
    setNidHint(null);
    setDept(staffDepartments[0] ?? '');
    setSpecialtyIds([]);
    setCoversAll(false);
    setJoin('');
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
    setRole(s.role || DEFAULT_TITLE);
    setEmail(s.email === '—' ? '' : s.email);
    setMobile(s.mobile === '—' ? '' : s.mobile);
    setNid(s.nid === '—' ? '' : s.nid);
    // الموظّف: قسمه الإداريّ كما هو (ولو قديماً)؛ المحامي: تخصّصاته في الكتالوج
    setDept(s.roleKey !== 'lawyer' && s.dept && s.dept !== '—' ? s.dept : (staffDepartments[0] ?? ''));
    setSpecialtyIds([...(s.specialtyIds ?? [])]);
    setCoversAll(Boolean(s.coversAll));
    setJoin(s.join === '—' ? '' : s.join);
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
      // الخادم يأخذ القسم الإداريّ لغير المحامي، والتخصّصات للمحامي
      dept,
      specialties: roleKey === 'lawyer' && !coversAll ? specialtyIds : [],
      coversAll: roleKey === 'lawyer' && coversAll,
      join,
      payType,
      salary,
      pct,
      session,
      perms,
    };
    const opts = {
      preserveScroll: true,
      onError: (e: Record<string, string>) => toast(firstError(e, 'تعذّر الحفظ')),
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

  /**
   * تفعيل/إيقاف حساب — **الحالة من ردّ الخادم لا من قلبٍ متفائل.** كانت بطاقة التفاصيل تقلب الحالة
   * فور النقر ولا تعود عند الرفض (403 لحساب إداريّ، أو خطأ)، فتقول «موقوف» لحسابٍ ما زال نشطاً.
   * الآن تُقرأ الحالة من قائمة `staff` المحدَّثة بعد النجاح، ورسالة النجاح من الخادم (flash).
   */
  const toggleStaff = (s: StaffRow) =>
    router.post(
      `/admin/staff/${s.id}/toggle`,
      {},
      {
        preserveScroll: true,
        onSuccess: (page) => {
          const fresh = ((page.props as unknown as Props).staff ?? []).find((x) => x.id === s.id);
          setDetail((prev) => (prev && prev.id === s.id && fresh ? fresh : prev));
        },
        onError: (errors) => toast(`⚠️ ${firstError(errors, 'تعذّر تغيير حالة الحساب')}`, 'error'),
      }
    );


  // إحصائيات الكادر
  const lawyersCount = staff.filter((s) => s.roleKey === 'lawyer').length;
  const employeesCount = staff.filter((s) => s.roleKey === 'employee').length;
  const adminsCount = staff.filter((s) => s.roleKey === 'admin').length;

  return (
    <>
      {/* البانر الرئيسي المتناسق مع لوحة التحكم */}
      <div className="hero">
        <h2>إدارة وتسجيل الكادر الوظيفي 👥</h2>
        <p>تسجيل المحامين والموظفين، وضبط الأجور، وتخصيص الصلاحيات بدقة وأمان.</p>
        <div className="hero-cta filter-pills" style={{ flexWrap: 'wrap', gap: 8 }}>
          <button
            className={`hero-b ${activeTab === 'list' && !filters.role ? '' : 'ghost'}`}
            onClick={() => {
 setActiveTab('list'); patchFilters({ role: '' }); 
}}
            type="button"
          >
            <Icon name="user" /> كل الكادر ({staff.length})
          </button>
          <button
            className={`hero-b ${activeTab === 'list' && filters.role === 'lawyer' ? '' : 'ghost'}`}
            onClick={() => {
 setActiveTab('list'); patchFilters({ role: 'lawyer' }); 
}}
            type="button"
          >
            <Icon name="scale" /> المحامين ({lawyersCount})
          </button>
          <button
            className={`hero-b ${activeTab === 'list' && filters.role === 'employee' ? '' : 'ghost'}`}
            onClick={() => {
 setActiveTab('list'); patchFilters({ role: 'employee' }); 
}}
            type="button"
          >
            <Icon name="folder" /> الموظفين ({employeesCount})
          </button>
          <button
            className={`hero-b ${activeTab === 'list' && filters.role === 'admin' ? '' : 'ghost'}`}
            onClick={() => {
 setActiveTab('list'); patchFilters({ role: 'admin' }); 
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
        <StaffDirectory
          staff={staff}
          filters={filters}
          onFilter={patchFilters}
          legalDepartments={legalDepartments}
          staffDepartments={staffDepartments}
          onDetail={setDetail}
          onEdit={startEdit}
          onToggle={toggleStaff}
          onPayouts={(s) => setPayoutsFor({ id: s.id, name: s.name })}
        />
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

                    if (!LAWYER_TITLES.includes(role)) {
                      setRole(LAWYER_TITLES[0]);
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

                    if (LAWYER_TITLES.includes(role)) {
                      setRole(DEFAULT_TITLE);
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
                    {JOB_TITLES.map((t) => <option key={t}>{t}</option>)}
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

                {roleKey === 'lawyer' ? (
                  // المحامي: تخصّصاتٌ متعدّدة من كتالوج الأقسام — تُسنَد إليه تذاكر أيٍّ منها تلقائيّاً
                  <div className="field">
                    <label>تخصّصات المحامي</label>
                    <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 700, fontSize: 13, marginBottom: 6 }}>
                      <input id="staff-covers-all" type="checkbox" checked={coversAll} onChange={(e) => setCoversAll(e.target.checked)} />
                      يغطّي كلّ الأقسام (محامٍ عام)
                    </label>
                    {!coversAll && (
                      <div style={{ maxHeight: 190, overflowY: 'auto', border: '1px solid var(--line)', borderRadius: 10, padding: '6px 10px', display: 'grid', gap: 2 }}>
                        {legalDepartments.map((d) => (
                          <label key={d.id} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, padding: '3px 0' }}>
                            <input
                              id={`staff-specialty-${d.id}`}
                              type="checkbox"
                              checked={specialtyIds.includes(d.id)}
                              onChange={() => toggleSpecialty(d.id)}
                            />
                            {d.name}
                          </label>
                        ))}
                      </div>
                    )}
                    {!coversAll && specialtyIds.length === 0 && (
                      <span style={{ fontSize: 12, color: 'var(--muted)' }}>بلا تخصّص لا تُسنَد إليه تذاكر تلقائيّاً.</span>
                    )}
                  </div>
                ) : (
                  // الموظّف والإدارة: قسمٌ إداريّ؛ والقيمة القديمة خارج القائمة تبقى ظاهرة كي لا تُستبدل صامتةً
                  <div className="field">
                    <label htmlFor="staff-dept">القسم الإداريّ <span style={{ color: 'var(--red)' }}>*</span></label>
                    <select id="staff-dept" value={dept} onChange={(e) => setDept(e.target.value)}>
                      {Array.from(new Set([...(dept ? [dept] : []), ...staffDepartments])).map((d) => (
                        <option key={d} value={d}>{d}</option>
                      ))}
                    </select>
                  </div>
                )}
              </div>

              {/* ------------------------------------------------------------- */}
              {/* القسم الثالث: تاريخ المباشرة */}
              {/* ------------------------------------------------------------- */}
              <div className="form-sec-h">
                <span className="si"><Icon name="folder" /></span> 2. تاريخ المباشرة
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
              </div>

              {/* ------------------------------------------------------------- */}
              {/* القسم الرابع: آلية الأجر والتعاقد المالي */}
              {/* ------------------------------------------------------------- */}
              <div className="form-sec-h">
                <span className="si"><Icon name="card" /></span> 3. آلية الأجر والتعاقد المالي
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: 8, marginBottom: 14 }}>
                {payTypesForRole.map((pt) => (
                  <button
                    key={pt.id}
                    type="button"
                    onClick={() => setPayType(pt.id)}
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
                    <span>{PAY_TYPE_ICONS[pt.id]}</span>
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
              <PermissionMatrix
                groups={permGroups}
                perms={perms}
                presets={presets}
                search={permSearch}
                onSearch={setPermSearch}
                onToggle={togglePerm}
                onPreset={applyPreset}
                onSelectAll={selectAllPerms}
                onClear={clearPerms}
              />

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
          <StaffPreview name={name} role={role} roleKey={roleKey} dept={dept} payType={payType} salary={salary} pct={pct} session={session} permsCount={perms.length} />
        </div>
      </div>
      )}

      {/* ── نافذة ملفّ النشاط: الحِمل والمستحقّات وآخر العمليّات وسجلّ الحساب ── */}
      <StaffActivityModal
        staff={detail}
        onClose={() => setDetail(null)}
        onEdit={(s) => {
          setDetail(null);
          startEdit(s);
        }}
        onToggle={toggleStaff}
        onPayouts={(s) => setPayoutsFor({ id: s.id, name: s.name })}
      />

      <StaffPayoutsModal staff={payoutsFor} onClose={() => setPayoutsFor(null)} />
    </>
  );
};

export default AdminStaff;
