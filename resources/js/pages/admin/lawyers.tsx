import React from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { LAWYERS } from '@/lib/admin-data';

// يطابق adLawyers في index (82).html

const AdminLawyers: React.FC = () => {
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
            {LAWYERS.map((l) => (
              <tr key={l.name}>
                <td><b>{l.name}</b></td>
                <td>
                  <div className="chips">
                    {l.depts.map((d) => <span key={d} className="chip muted">{d}</span>)}
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
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default AdminLawyers;
