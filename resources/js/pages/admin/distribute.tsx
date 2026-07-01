import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { type SysTicket, SYS_TICKETS } from '@/lib/employee-data';
import { LAWYERS } from '@/lib/admin-data';

// يطابق adDistribute + assignTicket في index (82).html

const AdminDistribute: React.FC = () => {
  const toast = useToast();
  const [rows, setRows] = useState<SysTicket[]>(() => SYS_TICKETS.map((t) => ({ ...t })));
  const [sel, setSel] = useState<string[]>(() => SYS_TICKETS.map((t) => t.lawyer));

  const assign = (i: number) => {
    const lawyer = sel[i];
    setRows((p) => p.map((t, idx) => {
      if (idx !== i) return t;
      const next = { ...t, lawyer };
      if (t.status === 'جديدة' || t.status === 'قيد التحليل') {
        next.status = 'محالة للقسم القانوني';
        next.tone = 'b-blue';
      }
      return next;
    }));
    toast(`تم إسناد التذكرة إلى ${lawyer}`);
  };

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p><b>التوزيع اليدوي</b> — تُسند الإدارة كل تذكرة للمحامي المختص يدوياً.</p>
      </div>
      <div className="card">
        <div className="card-h"><h3>توزيع التذاكر يدوياً</h3></div>
        <div className="card-b t-wrap">
          <table className="tbl">
            <thead>
              <tr>
                <th>التذكرة</th>
                <th>القسم</th>
                <th>المحامي الحالي</th>
                <th>إسناد إلى</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {rows.map((t, i) => (
                <tr key={t.no}>
                  <td className="mono">{t.no}</td>
                  <td className="muted">{t.dept}</td>
                  <td className="muted">{t.lawyer}</td>
                  <td>
                    <select value={sel[i]} onChange={(e) => setSel((p) => p.map((v, idx) => (idx === i ? e.target.value : v)))}>
                      {LAWYERS.map((l) => <option key={l.name} value={l.name}>{l.name}</option>)}
                    </select>
                  </td>
                  <td>
                    <button className="btn soft sm" onClick={() => assign(i)} type="button">
                      <Icon name="reply" /> إسناد
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
};

export default AdminDistribute;
