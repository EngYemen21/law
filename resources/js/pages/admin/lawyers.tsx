import React from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';

// يطابق adLawyers — المحامون من جدول users بدور lawyer + عدد التذاكر المحالة

interface LawyerRow { name: string; depts: string[]; active: number; mode: string }

const AdminLawyers: React.FC<{ lawyers: LawyerRow[] }> = ({ lawyers }) => {
  const toast = useToast();
  return (
    <div className="card">
      <div className="card-h">
        <h3>المحامون والأقسام</h3>
        <span className="sub">توزيع التذاكر تلقائي أو يدوي</span>
      </div>
      <div className="card-b t-wrap">
        <table className="tbl">
          <thead>
            <tr>
              <th>المحامي</th>
              <th>الأقسام</th>
              <th>تذاكر نشطة</th>
              <th>التوزيع</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {lawyers.length ? lawyers.map((l) => (
              <tr key={l.name}>
                <td><b>{l.name}</b></td>
                <td>
                  <div className="chips">
                    {l.depts.length ? l.depts.map((d) => <span key={d} className="chip muted">{d}</span>) : <span className="chip muted">—</span>}
                  </div>
                </td>
                <td>{l.active}</td>
                <td><Badge text={l.mode} tone={l.mode === 'تلقائي' ? 'b-blue' : 'b-amber'} /></td>
                <td>
                  <button className="btn soft sm" onClick={() => toast('تم تحديث إعداد التوزيع')} type="button">
                    تبديل التوزيع
                  </button>
                </td>
              </tr>
            )) : (
              <tr><td colSpan={5} style={{ textAlign: 'center', color: 'var(--muted)', padding: 20 }}>لا محامون مسجّلون بعد</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default AdminLawyers;
