import React from 'react';
import type { PayType } from '@/components/staff/types';

interface Props {
  name: string;
  role: string;
  roleKey: string;
  dept: string;
  payType: PayType;
  salary: string;
  pct: string;
  session: string;
  permsCount: number;
}

/**
 * **المعاينة الحيّة بجانب نموذج الموظف** — تعكس ما يُكتب قبل الحفظ. فُصلت عن `pages/admin/staff.tsx`
 * (المرحلة ٤)؛ ومخفيّةٌ على الجوال (`staff-live-preview`).
 */
const StaffPreview: React.FC<Props> = ({ name, role, roleKey, dept, payType, salary, pct, session, permsCount }) => (
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
              <span className="v perm-count">{permsCount} صلاحية مسندة</span>
            </div>
          </div>

          <div style={{ marginTop: 14, padding: 10, background: 'rgba(14,92,156,.05)', borderRadius: 8, fontSize: 11.5, color: 'var(--muted)', textAlign: 'right' }}>
            💡 يُفعَّل حسابه فور الحفظ — ويدخل برقم هويّته ورمز التحقّق الذي يصل جواله.
          </div>
        </div>
      </div>
    </aside>
);

export default StaffPreview;
