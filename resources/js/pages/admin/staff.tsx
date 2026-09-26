import { router, usePage } from '@inertiajs/react';
import React, { useState, useMemo } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { foldSearch } from '@/lib/employee-data';
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
  specialtyIds?: number[]; // تخصّصات المحامي في كتالوج الأقسام
  coversAll?: boolean; // محامٍ عامّ يغطّي كلّ الأقسام
};

interface LegalDepartmentOption { id: number; name: string }

interface Props {
  staff: StaffRow[];
  legalDepartments: LegalDepartmentOption[]; // تخصّصات المحامي (كتالوج الأقسام القانونيّة)
  staffDepartments: string[]; // أقسام الموظّفين الإداريّة
}

interface Shared {
  generatedPassword?: { email: string; password: string } | null;
}

const AdminStaff: React.FC<Props> = ({ staff, legalDepartments = [], staffDepartments = [] }) => {
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
  // قسم الموظّف الإداريّ، وتخصّصات المحامي من الكتالوج (أو «كل الأقسام»)
  const [dept, setDept] = useState(staffDepartments[0] ?? '');
  const [specialtyIds, setSpecialtyIds] = useState<number[]>([]);
  const [coversAll, setCoversAll] = useState(false);
  const toggleSpecialty = (id: number) =>
    setSpecialtyIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
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
  const [modalPermSearch, setModalPermSearch] = useState('');

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
    setDept(staffDepartments[0] ?? '');
    setSpecialtyIds([]);
    setCoversAll(false);
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
    // الموظّف: قسمه الإداريّ كما هو (ولو قديماً)؛ المحامي: تخصّصاته في الكتالوج
    setDept(s.roleKey !== 'lawyer' && s.dept && s.dept !== '—' ? s.dept : (staffDepartments[0] ?? ''));
    setSpecialtyIds([...(s.specialtyIds ?? [])]);
    setCoversAll(Boolean(s.coversAll));
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
      // الخادم يأخذ القسم الإداريّ لغير المحامي، والتخصّصات للمحامي
      dept,
      specialties: roleKey === 'lawyer' && !coversAll ? specialtyIds : [],
      coversAll: roleKey === 'lawyer' && coversAll,
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
        onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر تغيير حالة الحساب'}`, 'error'),
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

    // المحامي قد يحمل عدّة تخصّصات مفصولة بـ«، » — يطابق الفلترُ أيّاً منها
    if (deptFilter && s.dept !== deptFilter && !(s.dept || '').split('، ').includes(deptFilter)) {
return false;
}

    if (statusFilter && (s.status || 'نشط') !== statusFilter) {
return false;
}

    if (searchQuery.trim()) {
      const q = foldSearch(searchQuery);
      const matchName = foldSearch(s.name).includes(q);
      const matchRole = foldSearch(s.role).includes(q);
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

  // دالة مساعدة لتنسيق وعرض خلية القسم المختص بأناقة ومنع التمدد الأفقي مهما تعددت التخصصات
  const renderDeptCell = (s: StaffRow) => {
    if (s.coversAll || s.dept === 'كل الأقسام' || s.dept === 'يغطي كل الأقسام') {
      return (
        <span
          className="badge-s b-green"
          style={{
            fontSize: 11.5,
            padding: '3px 9px',
            background: 'rgba(16, 185, 129, 0.1)',
            color: '#047857',
            border: '1px solid rgba(16, 185, 129, 0.25)',
            fontWeight: 700,
            whiteSpace: 'nowrap',
            display: 'inline-flex',
            alignItems: 'center',
            gap: 5,
          }}
          title="يغطي كافة الأقسام القانونية (محامٍ عام)"
        >
          <Icon name="check" cls="ic sm" /> كل الأقسام (شامل)
        </span>
      );
    }

    const depts = (s.dept || '')
      .split(/[،,]\s*/)
      .map((d) => d.trim())
      .filter(Boolean);

    if (depts.length === 0 || s.dept === '—') {
      return <span className="muted" style={{ fontSize: 12 }}>القسم العام</span>;
    }

    if (depts.length === 1) {
      return (
        <span
          className="badge-s b-blue"
          style={{
            fontSize: 11.5,
            padding: '3px 8px',
            maxWidth: 180,
            overflow: 'hidden',
            textOverflow: 'ellipsis',
            whiteSpace: 'nowrap',
            display: 'inline-flex',
            alignItems: 'center',
            gap: 5,
          }}
          title={depts[0]}
        >
          <Icon name="folder" cls="ic sm" /> {depts[0]}
        </span>
      );
    }

    // أقسام متعددة: نعرض القسم الأول مع شارة عدّاد الأقسام الإضافية وتلميح تفصيلي
    const firstDept = depts[0];
    const extraCount = depts.length - 1;
    const allDeptsTooltip = `الأقسام المتخصصة (${depts.length}):\n• ` + depts.join('\n• ');

    return (
      <div
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          gap: 5,
          maxWidth: 220,
          flexWrap: 'nowrap',
        }}
      >
        <span
          className="badge-s b-blue"
          style={{
            fontSize: 11.5,
            padding: '3px 8px',
            maxWidth: 130,
            overflow: 'hidden',
            textOverflow: 'ellipsis',
            whiteSpace: 'nowrap',
            display: 'inline-flex',
            alignItems: 'center',
            gap: 4,
          }}
          title={`القسم الأساسي: ${firstDept}`}
        >
          <Icon name="folder" cls="ic sm" /> {firstDept}
        </span>
        <span
          className="badge-s"
          style={{
            fontSize: 11,
            padding: '2px 7px',
            cursor: 'help',
            background: 'rgba(14, 92, 156, 0.08)',
            border: '1px solid rgba(14, 92, 156, 0.22)',
            color: 'var(--primary)',
            fontWeight: 800,
            whiteSpace: 'nowrap',
            borderRadius: 999,
          }}
          title={allDeptsTooltip}
        >
          +{extraCount} أقسام
        </span>
      </div>
    );
  };

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
          <div className="card-h staff-toolbar" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <h3>الكادر الوظيفي</h3>
              <span className="sub">({filteredStaff.length} من {staff.length})</span>
            </div>

            <div className="staff-filters" style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
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
                {Array.from(new Set([...staffDepartments, ...legalDepartments.map((d) => d.name)])).map((d) => (
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
                <b>{staff.length === 0 ? 'لا يوجد موظفون بعد' : 'لا يوجد موظفون يطابقون معايير البحث والفلترة'}</b>
              </div>
            ) : (
              <table className="tbl" style={{ minWidth: 840 }}>
                <thead>
                  <tr>
                    <th style={{ minWidth: 200 }}>الموظف والصفة</th>
                    <th style={{ minWidth: 170, maxWidth: 240 }}>القسم المختص</th>
                    <th style={{ minWidth: 120 }}>آلية الأجر</th>
                    <th style={{ minWidth: 130 }}>أوقات الدوام</th>
                    <th style={{ minWidth: 110 }}>الصلاحيات</th>
                    <th style={{ minWidth: 90 }}>الحالة</th>
                    <th style={{ minWidth: 160, textAlign: 'center' }}>الإجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredStaff.map((s) => (
                    <tr key={s.id}>
                      <td>
                        <div className="staff-name">
                          <div
                            className="staff-av"
                            style={{
                              background:
                                s.roleKey === 'admin'
                                  ? 'linear-gradient(135deg, #4f46e5 0%, #0A2A55 100%)'
                                  : s.roleKey === 'lawyer'
                                  ? 'linear-gradient(135deg, #0A2A55 0%, #11A0C8 100%)'
                                  : 'linear-gradient(135deg, #0e5c9c 0%, #10b981 100%)',
                              boxShadow: '0 2px 6px -1px rgba(0,0,0,0.12)',
                            }}
                          >
                            {s.name.replace(/^أ\.?\s*/, '').slice(0, 1)}
                          </div>
                          <div style={{ minWidth: 0 }}>
                            <div className="sn-b" style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--ink)' }}>
                              {s.name}
                            </div>
                            <div className="sn-s" style={{ color: 'var(--muted)', fontSize: 11.5, display: 'flex', alignItems: 'center', gap: 4, marginTop: 1 }}>
                              <span>{s.role}</span>
                              <span style={{ fontSize: 11 }}>
                                {s.roleKey === 'lawyer' ? '⚖️' : s.roleKey === 'admin' ? '🏛️' : '💼'}
                              </span>
                            </div>
                          </div>
                        </div>
                      </td>
                      <td style={{ maxWidth: 240, overflow: 'hidden' }}>
                        {renderDeptCell(s)}
                      </td>
                      <td style={{ fontSize: 12 }}>
                        {s.pay && s.pay !== '—' ? (
                          <div style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontWeight: 600, color: 'var(--ink)' }}>
                            <span style={{ fontSize: 12 }}>
                              {s.pay.includes('نسبة') ? '📊' : s.pay.includes('جلسة') ? '⚖️' : '💵'}
                            </span>
                            <span>{s.pay}</span>
                          </div>
                        ) : (
                          <span className="muted">—</span>
                        )}
                      </td>
                      <td style={{ fontSize: 12 }}>
                        {s.start && s.end && s.start !== '—' ? (
                          <div style={{ display: 'inline-flex', alignItems: 'center', gap: 5, color: 'var(--ink)' }}>
                            <Icon name="clock" cls="ic sm" style={{ color: 'var(--faint)' }} />
                            <span dir="ltr" style={{ fontWeight: 600 }}>
                              {s.start} – {s.end}
                            </span>
                          </div>
                        ) : (
                          <span className="muted">—</span>
                        )}
                      </td>
                      <td>
                        {s.roleKey === 'admin' ? (
                          <span
                            className="perm-count"
                            style={{
                              background: 'rgba(99, 102, 241, 0.08)',
                              color: '#4f46e5',
                              borderColor: 'rgba(99, 102, 241, 0.22)',
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: 4,
                              fontWeight: 700,
                            }}
                            title="كامل صلاحيات الإدارة العليا والتحكم بالمنصة"
                          >
                            <Icon name="lock" cls="ic sm" /> إدارة شاملة
                          </span>
                        ) : s.perms && s.perms.length > 0 ? (
                          <span
                            className="perm-count"
                            style={{
                              cursor: 'help',
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: 4,
                            }}
                            title={`الصلاحيات الممنوحة (${s.perms.length}):\n• ` + s.perms.join('\n• ')}
                          >
                            <Icon name="lock" cls="ic sm" /> {s.perms.length} صلاحية
                          </span>
                        ) : (
                          <span className="muted" style={{ fontSize: 11.5 }}>لا توجد</span>
                        )}
                      </td>
                      <td>
                        <Badge text={s.status || 'نشط'} tone={s.status === 'موقوف' ? 'b-grey' : 'b-green'} />
                      </td>
                      <td style={{ textAlign: 'center' }}>
                        <div style={{ display: 'inline-flex', gap: 5, flexWrap: 'nowrap', justifyContent: 'center' }}>
                          <button
                            className="btn soft sm"
                            onClick={() => setDetail(s)}
                            type="button"
                            title="عرض تفاصيل الموظف"
                            style={{ padding: '5px 9px', fontSize: 12 }}
                          >
                            <Icon name="user" /> تفاصيل
                          </button>
                          <button
                            className="btn soft sm"
                            onClick={() => startEdit(s)}
                            type="button"
                            title="تعديل بيانات الموظف والصلاحيات"
                            style={{ padding: '5px 9px', fontSize: 12 }}
                          >
                            <Icon name="doc" /> تعديل
                          </button>
                          {s.roleKey !== 'admin' && (
                            <button
                              className="btn soft sm"
                              onClick={() => toggleStaff(s)}
                              type="button"
                              title={s.status === 'موقوف' ? 'تفعيل الحساب' : 'إيقاف الحساب'}
                              style={{
                                padding: '5px 9px',
                                fontSize: 12,
                                color: s.status === 'موقوف' ? 'var(--green, #10b981)' : 'var(--red, #ef4444)',
                              }}
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

      {/* ── نافذة الملف الوظيفي الموحد (Executive Staff Dossier Modal) ── */}
      <Modal
        title={detail ? `الملف الوظيفي — ${detail.name}` : ''}
        subtitle="بطاقة البيانات المهنية، الأقسام المسندة، وحوكمة الصلاحيات"
        open={!!detail}
        onClose={() => {
          setDetail(null);
          setModalPermSearch('');
        }}
        maxWidth={780}
      >
        {detail && (
          <div className="staff-dossier" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            {/* 1. الترويسة الرئيسية للملف الوظيفي (Profile Hero Banner) */}
            <div className="staff-dossier-hero">
              <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
                <div
                  style={{
                    width: 58,
                    height: 58,
                    borderRadius: 16,
                    background:
                      detail.roleKey === 'admin'
                        ? 'linear-gradient(135deg, #4f46e5 0%, #0A2A55 100%)'
                        : detail.roleKey === 'lawyer'
                        ? 'linear-gradient(135deg, #0A2A55 0%, #11A0C8 100%)'
                        : 'linear-gradient(135deg, #0e5c9c 0%, #10b981 100%)',
                    color: '#fff',
                    display: 'grid',
                    placeItems: 'center',
                    fontSize: 20,
                    fontWeight: 800,
                    boxShadow: '0 4px 12px -2px rgba(10, 42, 85, 0.25)',
                    position: 'relative',
                  }}
                >
                  {detail.name.replace(/^أ\.?\s*/, '').slice(0, 1)}
                  <span
                    style={{
                      position: 'absolute',
                      bottom: -2,
                      left: -2,
                      width: 14,
                      height: 14,
                      borderRadius: '50%',
                      background: detail.status === 'موقوف' ? 'var(--red, #ef4444)' : 'var(--green, #10b981)',
                      border: '2px solid #fff',
                    }}
                    title={detail.status === 'موقوف' ? 'حساب موقوف' : 'حساب نشط'}
                  />
                </div>

                <div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <h3 style={{ margin: 0, fontSize: 17, fontWeight: 800, color: 'var(--ink, #0f172a)' }}>
                      {detail.name}
                    </h3>
                    <Badge
                      text={detail.status || 'نشط'}
                      tone={detail.status === 'موقوف' ? 'b-grey' : 'b-green'}
                    />
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 4, flexWrap: 'wrap' }}>
                    <span
                      style={{
                        fontSize: 12,
                        fontWeight: 700,
                        color: 'var(--primary, #0e5c9c)',
                        background: 'rgba(14, 92, 156, 0.08)',
                        padding: '2px 8px',
                        borderRadius: 6,
                      }}
                    >
                      {detail.roleKey === 'lawyer' ? '⚖️ محامٍ مرخص' : detail.roleKey === 'admin' ? '🏛️ الإدارة العليا' : '💼 كادر إداري ومساند'}
                    </span>
                    <span style={{ fontSize: 12, color: 'var(--muted, #64748b)' }}>•</span>
                    <span style={{ fontSize: 12, color: 'var(--muted, #64748b)' }}>{detail.role}</span>
                  </div>
                </div>
              </div>

              {/* أزرار الإجراءات السريعة */}
              <div className="staff-hero-actions">
                <button
                  className="btn sm"
                  onClick={() => {
                    setDetail(null);
                    startEdit(detail);
                  }}
                  type="button"
                  style={{ gap: 6 }}
                >
                  <Icon name="doc" /> تعديل البيانات
                </button>

                {detail.roleKey !== 'admin' && (
                  <button
                    className="btn sm soft"
                    onClick={() => toggleStaff(detail)}
                    type="button"
                    style={{
                      gap: 6,
                      color: detail.status === 'موقوف' ? 'var(--green, #10b981)' : 'var(--red, #ef4444)',
                    }}
                    title={detail.status === 'موقوف' ? 'تفعيل حساب الموظف' : 'إيقاف حساب الموظف'}
                  >
                    {detail.status === 'موقوف' ? (
                      <><Icon name="check" /> تفعيل الحساب</>
                    ) : (
                      <><Icon name="lock" /> إيقاف الحساب</>
                    )}
                  </button>
                )}
              </div>
            </div>

            {/* 2. شريط النبض التشغيلي (Key Metrics Strip) */}
            <div className="staff-metrics-grid">
              <div
                style={{
                  background: '#fff',
                  border: '1px solid var(--line, #e2e8f0)',
                  borderRadius: 10,
                  padding: '10px 12px',
                  display: 'flex',
                  alignItems: 'center',
                  gap: 10,
                }}
              >
                <div
                  style={{
                    width: 34,
                    height: 34,
                    borderRadius: 8,
                    background: 'rgba(14, 92, 156, 0.08)',
                    color: 'var(--primary)',
                    display: 'grid',
                    placeItems: 'center',
                    flex: '0 0 34px',
                  }}
                >
                  <Icon name="folder" />
                </div>
                <div style={{ minWidth: 0 }}>
                  <span style={{ fontSize: 10.5, color: 'var(--muted)', display: 'block' }}>التخصص والأقسام</span>
                  <b style={{ fontSize: 12, color: 'var(--ink)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', display: 'block' }}>
                    {detail.coversAll || detail.dept === 'كل الأقسام'
                      ? 'تغطية شاملة'
                      : (detail.dept || '').split(/[،,]/).length > 1
                      ? `${(detail.dept || '').split(/[،,]/).length} أقسام معتمدة`
                      : detail.dept || 'القسم العام'}
                  </b>
                </div>
              </div>

              <div
                style={{
                  background: '#fff',
                  border: '1px solid var(--line, #e2e8f0)',
                  borderRadius: 10,
                  padding: '10px 12px',
                  display: 'flex',
                  alignItems: 'center',
                  gap: 10,
                }}
              >
                <div
                  style={{
                    width: 34,
                    height: 34,
                    borderRadius: 8,
                    background: 'rgba(16, 185, 129, 0.08)',
                    color: '#059669',
                    display: 'grid',
                    placeItems: 'center',
                    flex: '0 0 34px',
                  }}
                >
                  <Icon name="cal" />
                </div>
                <div style={{ minWidth: 0 }}>
                  <span style={{ fontSize: 10.5, color: 'var(--muted)', display: 'block' }}>التعاقد والأجر</span>
                  <b style={{ fontSize: 12, color: 'var(--ink)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', display: 'block' }}>
                    {detail.pay || '—'}
                  </b>
                </div>
              </div>

              <div
                style={{
                  background: '#fff',
                  border: '1px solid var(--line, #e2e8f0)',
                  borderRadius: 10,
                  padding: '10px 12px',
                  display: 'flex',
                  alignItems: 'center',
                  gap: 10,
                }}
              >
                <div
                  style={{
                    width: 34,
                    height: 34,
                    borderRadius: 8,
                    background: 'rgba(245, 158, 11, 0.08)',
                    color: '#d97706',
                    display: 'grid',
                    placeItems: 'center',
                    flex: '0 0 34px',
                  }}
                >
                  <Icon name="clock" />
                </div>
                <div style={{ minWidth: 0 }}>
                  <span style={{ fontSize: 10.5, color: 'var(--muted)', display: 'block' }}>الدوام اليومي</span>
                  <b style={{ fontSize: 12, color: 'var(--ink)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', display: 'block' }}>
                    {detail.start && detail.end && detail.start !== '—' ? `${detail.start} – ${detail.end}` : '—'}
                  </b>
                </div>
              </div>

              <div
                style={{
                  background: '#fff',
                  border: '1px solid var(--line, #e2e8f0)',
                  borderRadius: 10,
                  padding: '10px 12px',
                  display: 'flex',
                  alignItems: 'center',
                  gap: 10,
                }}
              >
                <div
                  style={{
                    width: 34,
                    height: 34,
                    borderRadius: 8,
                    background: 'rgba(99, 102, 241, 0.08)',
                    color: '#4f46e5',
                    display: 'grid',
                    placeItems: 'center',
                    flex: '0 0 34px',
                  }}
                >
                  <Icon name="lock" />
                </div>
                <div style={{ minWidth: 0 }}>
                  <span style={{ fontSize: 10.5, color: 'var(--muted)', display: 'block' }}>الصلاحيات</span>
                  <b style={{ fontSize: 12, color: 'var(--ink)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', display: 'block' }}>
                    {detail.roleKey === 'admin' ? 'إدارة شاملة' : `${detail.perms?.length || 0} صلاحية`}
                  </b>
                </div>
              </div>
            </div>

            {/* 3. شبكة تفاصيل الملف (Personal & Professional Info Grid) */}
            <div className="staff-info-grid">
              {/* بطاقة معلومات الهوية والاتصال */}
              <div
                style={{
                  background: '#fff',
                  border: '1px solid var(--line, #e2e8f0)',
                  borderRadius: 12,
                  padding: '14px 16px',
                }}
              >
                <div
                  style={{
                    fontSize: 13,
                    fontWeight: 800,
                    color: 'var(--deep, #0A2A55)',
                    marginBottom: 12,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 7,
                    borderBottom: '1px solid var(--line-soft, #f1f5f9)',
                    paddingBottom: 8,
                  }}
                >
                  <Icon name="user" cls="ic sm" /> بيانات الهوية والتواصل
                </div>

                <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
                  <div className="kv" style={{ padding: '4px 0' }}>
                    <span className="k" style={{ fontSize: 12 }}>رقم الهوية / الإقامة</span>
                    <span className="v mono" style={{ direction: 'ltr', fontWeight: 700, color: 'var(--ink)' }}>
                      {detail.nid || '—'}
                    </span>
                  </div>

                  <div className="kv" style={{ padding: '4px 0' }}>
                    <span className="k" style={{ fontSize: 12 }}>البريد الإلكتروني</span>
                    <div className="v" style={{ display: 'flex', alignItems: 'center', gap: 6, minWidth: 0 }}>
                      <a
                        href={detail.email ? `mailto:${detail.email}` : undefined}
                        className="mono"
                        style={{
                          direction: 'ltr',
                          fontSize: 12,
                          color: 'var(--primary)',
                          textDecoration: 'none',
                          wordBreak: 'break-all',
                        }}
                      >
                        {detail.email || '—'}
                      </a>
                      {detail.email && (
                        <button
                          type="button"
                          className="btn ghost sm"
                          style={{ padding: '2px 5px', fontSize: 10 }}
                          onClick={() => {
                            if (navigator.clipboard) {
                              void navigator.clipboard.writeText(detail.email);
                              toast('تم نسخ البريد الإلكتروني');
                            }
                          }}
                          title="نسخ البريد"
                        >
                          <Icon name="link" cls="ic sm" />
                        </button>
                      )}
                    </div>
                  </div>

                  <div className="kv" style={{ padding: '4px 0' }}>
                    <span className="k" style={{ fontSize: 12 }}>رقم الجوال</span>
                    <div className="v" style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                      <a
                        href={detail.mobile && detail.mobile !== '—' ? `tel:${detail.mobile}` : undefined}
                        className="mono"
                        style={{
                          direction: 'ltr',
                          fontSize: 12,
                          color: 'var(--primary)',
                          textDecoration: 'none',
                        }}
                      >
                        {detail.mobile || '—'}
                      </a>
                      {detail.mobile && detail.mobile !== '—' && (
                        <button
                          type="button"
                          className="btn ghost sm"
                          style={{ padding: '2px 5px', fontSize: 10 }}
                          onClick={() => {
                            if (navigator.clipboard) {
                              void navigator.clipboard.writeText(detail.mobile);
                              toast('تم نسخ رقم الجوال');
                            }
                          }}
                          title="نسخ الجوال"
                        >
                          <Icon name="link" cls="ic sm" />
                        </button>
                      )}
                    </div>
                  </div>

                  <div className="kv" style={{ padding: '4px 0' }}>
                    <span className="k" style={{ fontSize: 12 }}>تاريخ المباشرة</span>
                    <span className="v" style={{ fontSize: 12, color: 'var(--ink)' }}>
                      {detail.join || '—'}
                    </span>
                  </div>
                </div>
              </div>

              {/* بطاقة الأقسام والتخصصات المعتمدة */}
              <div
                style={{
                  background: '#fff',
                  border: '1px solid var(--line, #e2e8f0)',
                  borderRadius: 12,
                  padding: '14px 16px',
                  display: 'flex',
                  flexDirection: 'column',
                }}
              >
                <div
                  style={{
                    fontSize: 13,
                    fontWeight: 800,
                    color: 'var(--deep, #0A2A55)',
                    marginBottom: 12,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 7,
                    borderBottom: '1px solid var(--line-soft, #f1f5f9)',
                    paddingBottom: 8,
                  }}
                >
                  <Icon name="folder" cls="ic sm" /> الأقسام والتخصصات المسندة
                </div>

                <div style={{ flex: 1 }}>
                  {detail.coversAll || detail.dept === 'كل الأقسام' ? (
                    <div
                      style={{
                        background: 'rgba(16, 185, 129, 0.08)',
                        border: '1.4px solid rgba(16, 185, 129, 0.25)',
                        borderRadius: 10,
                        padding: '12px 14px',
                        display: 'flex',
                        alignItems: 'flex-start',
                        gap: 10,
                      }}
                    >
                      <span style={{ fontSize: 20 }}>🌟</span>
                      <div>
                        <b style={{ color: '#047857', fontSize: 13, display: 'block', marginBottom: 2 }}>
                          تغطية شاملة لكافة الأقسام (محامٍ عام)
                        </b>
                        <p style={{ margin: 0, fontSize: 11.5, color: '#065f46', lineHeight: 1.6 }}>
                          معتمد للترافع وتوزيع التذاكر والاستشارات التخصصية في كافة مجالات ودوائر المكتب الـ 29 دون حصر.
                        </p>
                      </div>
                    </div>
                  ) : (
                    <div>
                      <div style={{ fontSize: 11.5, color: 'var(--muted)', marginBottom: 8 }}>
                        الأقسام المصرح له بمباشرة ملفاتها وقضاياها:
                      </div>
                      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                        {(detail.dept || '')
                          .split(/[،,]\s*/)
                          .map((d) => d.trim())
                          .filter(Boolean)
                          .map((d, idx) => (
                            <span
                              key={idx}
                              style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6,
                                background: 'rgba(14, 92, 156, 0.06)',
                                border: '1px solid rgba(14, 92, 156, 0.18)',
                                color: 'var(--primary)',
                                fontSize: 12,
                                fontWeight: 700,
                                padding: '5px 10px',
                                borderRadius: 8,
                              }}
                            >
                              <Icon name="folder" cls="ic sm" /> {d}
                            </span>
                          ))}
                      </div>
                    </div>
                  )}
                </div>
              </div>
            </div>

            {/* 4. مصفوفة الصلاحيات الممنوحة (Role-Based Permissions Dossier) */}
            <div
              style={{
                background: '#fff',
                border: '1px solid var(--line, #e2e8f0)',
                borderRadius: 12,
                padding: '16px',
              }}
            >
              <div className="staff-perm-header">
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <Icon name="lock" cls="ic sm" />
                  <b style={{ fontSize: 13.5, color: 'var(--deep)' }}>
                    مصفوفة الصلاحيات الممنوحة
                  </b>
                  <span
                    style={{
                      fontSize: 11,
                      fontWeight: 800,
                      background: 'rgba(14, 92, 156, 0.08)',
                      color: 'var(--primary)',
                      padding: '2px 8px',
                      borderRadius: 999,
                    }}
                  >
                    {detail.perms?.length || 0} صلاحية نشطة
                  </span>
                </div>

                {/* بحث سريع داخل صلاحيات المودال */}
                <div className="staff-perm-search">
                  <input
                    type="text"
                    className="input"
                    placeholder="بحث في الصلاحيات..."
                    value={modalPermSearch}
                    onChange={(e) => setModalPermSearch(e.target.value)}
                    style={{ width: '100%', fontSize: 11.5, padding: '5px 8px 5px 24px', borderRadius: 8 }}
                  />
                  {modalPermSearch && (
                    <button
                      type="button"
                      onClick={() => setModalPermSearch('')}
                      style={{
                        position: 'absolute',
                        left: 6,
                        top: '50%',
                        transform: 'translateY(-50%)',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        color: 'var(--muted)',
                        fontSize: 10,
                      }}
                    >
                      ✕
                    </button>
                  )}
                </div>
              </div>

              {detail.roleKey === 'admin' ? (
                <div
                  style={{
                    background: 'linear-gradient(135deg, rgba(79, 70, 229, 0.06), rgba(14, 92, 156, 0.08))',
                    border: '1.5px solid rgba(79, 70, 229, 0.2)',
                    borderRadius: 10,
                    padding: '14px 16px',
                    display: 'flex',
                    alignItems: 'center',
                    gap: 12,
                  }}
                >
                  <span style={{ fontSize: 24 }}>👑</span>
                  <div>
                    <b style={{ color: '#4338ca', fontSize: 13.5, display: 'block', marginBottom: 2 }}>
                      حساب قيادي — إدارة عليا بصلاحيات كاملة
                    </b>
                    <p style={{ margin: 0, fontSize: 12, color: 'var(--muted)', lineHeight: 1.6 }}>
                      يمتلك هذا الحساب تفويضاً كاملاً غير مقيد للإدارة العامة، التوزيع والتعيين، الرقابة المالية، وإعدادات النظام.
                    </p>
                  </div>
                </div>
              ) : detail.perms && detail.perms.length > 0 ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                  {catalog.groups
                    .map((grp) => {
                      const have = grp.items.filter(
                        (p) =>
                          detail.perms.indexOf(p) >= 0 &&
                          (!modalPermSearch.trim() || p.toLowerCase().includes(modalPermSearch.toLowerCase()))
                      );
                      if (!have.length) return null;

                      return (
                        <div
                          key={grp.g}
                          style={{
                            background: '#f8fafc',
                            border: '1px solid var(--line-soft, #f1f5f9)',
                            borderRadius: 10,
                            padding: '10px 12px',
                          }}
                        >
                          <div
                            style={{
                              fontSize: 11.5,
                              fontWeight: 800,
                              color: 'var(--primary)',
                              marginBottom: 8,
                              display: 'flex',
                              alignItems: 'center',
                              gap: 6,
                            }}
                          >
                            <span style={{ width: 6, height: 6, borderRadius: '50%', background: 'var(--primary)' }} />
                            {grp.g} ({have.length})
                          </div>
                          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                            {have.map((p) => (
                              <span
                                key={p}
                                style={{
                                  display: 'inline-flex',
                                  alignItems: 'center',
                                  gap: 5,
                                  background: '#fff',
                                  border: '1px solid rgba(14, 92, 156, 0.16)',
                                  color: 'var(--ink)',
                                  fontSize: 11.5,
                                  fontWeight: 600,
                                  padding: '4px 9px',
                                  borderRadius: 7,
                                  boxShadow: '0 1px 2px rgba(0,0,0,0.03)',
                                }}
                              >
                                <span style={{ color: 'var(--success)', fontWeight: 800 }}>✓</span> {p}
                              </span>
                            ))}
                          </div>
                        </div>
                      );
                    })
                    .filter(Boolean)}
                </div>
              ) : (
                <div style={{ textAlign: 'center', padding: '16px', color: 'var(--muted)', fontSize: 12 }}>
                  لا توجد صلاحيات مسندة لهذا الحساب حالياً
                </div>
              )}
            </div>

            {/* 5. شريط التذييل وأزرار الإغلاق (Modal Footer) */}
            <div className="staff-dossier-footer">
              <span style={{ fontSize: 11.5, color: 'var(--faint)' }}>
                معرّف الموظف بالنظام: #{detail.id}
              </span>

              <div className="staff-footer-actions">
                <button
                  className="btn soft sm"
                  onClick={() => setDetail(null)}
                  type="button"
                >
                  إغلاق
                </button>
                <button
                  className="btn sm"
                  onClick={() => {
                    setDetail(null);
                    startEdit(detail);
                  }}
                  type="button"
                  style={{ gap: 6 }}
                >
                  <Icon name="doc" /> تعديل الملف الوظيفي
                </button>
              </div>
            </div>
          </div>
        )}
      </Modal>
    </>
  );
};

export default AdminStaff;
