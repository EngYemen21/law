import React from 'react';
import Badge from '@/components/babylon/Badge';
import type { LegalDepartmentOption, StaffFilters, StaffRow } from '@/components/staff/types';
import { foldSearch } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { PresenceBadge } from '@/lib/staff-presence';

interface Props {
  staff: StaffRow[];
  /** حالة الفلاتر في الصفحة — فتبقى عند التنقّل بين القائمة والنموذج. */
  filters: StaffFilters;
  onFilter: (patch: Partial<StaffFilters>) => void;
  legalDepartments: LegalDepartmentOption[];
  staffDepartments: string[];
  onDetail: (s: StaffRow) => void;
  onEdit: (s: StaffRow) => void;
  onToggle: (s: StaffRow) => void;
  onPayouts: (s: StaffRow) => void;
}

/**
 * **جدول الكادر الوظيفي** — البحث والفلاتر والجدول وأزرار كلّ صفّ. فُصل عن `pages/admin/staff.tsx`
 * (المرحلة ٤): عرضٌ خالص، والحالة والإجراءات من الصفحة.
 */
const StaffDirectory: React.FC<Props> = ({ staff, filters, onFilter, legalDepartments, staffDepartments, onDetail, onEdit, onToggle, onPayouts }) => {
  // تصفية القائمة
  const filteredStaff = staff.filter((s) => {
    if (filters.role && s.roleKey !== filters.role) {
return false;
}

    // المحامي قد يحمل عدّة تخصّصات مفصولة بـ«، » — يطابق الفلترُ أيّاً منها
    if (filters.dept && s.dept !== filters.dept && !(s.dept || '').split('، ').includes(filters.dept)) {
return false;
}

    if (filters.status && (filters.status === 'active') !== Boolean(s.active)) {
return false;
}

    if (filters.search.trim()) {
      const q = foldSearch(filters.search);
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

  // دالة مساعدة لتنسيق وعرض خلية القسم المختص بأناقة ومنع التمدد الأفقي مهما تعددت التخصصات
  const renderDeptCell = (s: StaffRow) => {
    if (s.coversAll) {
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
              value={filters.search}
              onChange={(e) => onFilter({ search: e.target.value })}
              style={{ paddingInlineStart: 28, fontSize: 12.5, padding: '7px 10px 7px 28px' }}
            />
            {filters.search && (
              <button
                type="button"
                onClick={() => onFilter({ search: '' })}
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
            value={filters.role}
            onChange={(e) => onFilter({ role: e.target.value })}
            style={{ width: 140, fontSize: 12.5, padding: '7px 10px' }}
          >
            <option value="">جميع الأدوار</option>
            <option value="lawyer">محامون</option>
            <option value="employee">موظفون</option>
            <option value="admin">إدارة عليا</option>
          </select>

          <select
            className="input"
            value={filters.dept}
            onChange={(e) => onFilter({ dept: e.target.value })}
            style={{ width: 140, fontSize: 12.5, padding: '7px 10px' }}
          >
            <option value="">جميع الأقسام</option>
            {Array.from(new Set([...staffDepartments, ...legalDepartments.map((d) => d.name)])).map((d) => (
              <option key={d} value={d}>{d}</option>
            ))}
          </select>

          <select
            className="input"
            value={filters.status}
            onChange={(e) => onFilter({ status: e.target.value })}
            style={{ width: 110, fontSize: 12.5, padding: '7px 10px' }}
          >
            <option value="">كل الحالات</option>
            <option value="active">نشط</option>
            <option value="suspended">موقوف</option>
          </select>

          {(filters.search || filters.role || filters.dept || filters.status) && (
            <button
              type="button"
              className="btn sm ghost"
              onClick={() => {
 onFilter({ search: '', role: '', dept: '', status: '' }); 
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
                          {s.name} <PresenceBadge userId={s.id} />
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
                    <Badge text={s.status} tone={!s.active ? 'b-grey' : 'b-green'} />
                  </td>
                  <td style={{ textAlign: 'center' }}>
                    <div style={{ display: 'inline-flex', gap: 5, flexWrap: 'nowrap', justifyContent: 'center' }}>
                      <button
                        className="btn soft sm"
                        onClick={() => onDetail(s)}
                        type="button"
                        title="ملفّ نشاط الموظف"
                        style={{ padding: '5px 9px', fontSize: 12 }}
                      >
                        <Icon name="user" /> تفاصيل
                      </button>
                      <button
                        className="btn soft sm"
                        onClick={() => onEdit(s)}
                        type="button"
                        title="تعديل بيانات الموظف والصلاحيات"
                        style={{ padding: '5px 9px', fontSize: 12 }}
                      >
                        <Icon name="doc" /> تعديل
                      </button>
                      {s.roleKey !== 'admin' && (
                        <button
                          className="btn soft sm"
                          onClick={() => onPayouts(s)}
                          type="button"
                          title="مستحقّات الموظف وسجلّ صرفه"
                          style={{ padding: '5px 9px', fontSize: 12 }}
                        >
                          <Icon name="card" /> المستحقّات والصرف
                        </button>
                      )}
                      {s.roleKey !== 'admin' && (
                        <button
                          className="btn soft sm"
                          onClick={() => onToggle(s)}
                          type="button"
                          title={!s.active ? 'تفعيل الحساب' : 'إيقاف الحساب'}
                          style={{
                            padding: '5px 9px',
                            fontSize: 12,
                            color: !s.active ? 'var(--green, #10b981)' : 'var(--red, #ef4444)',
                          }}
                        >
                          {!s.active ? (
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
  );
};

export default StaffDirectory;
