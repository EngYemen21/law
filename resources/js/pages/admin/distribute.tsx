import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';

// توزيع التذاكر — الإدارة تُسند التذاكر للمحامين فعلياً (يكتب assigned_lawyer_id)

interface T { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; }
interface Props { tickets: T[]; lawyers: { id: number; name: string }[]; }

const AdminDistribute: React.FC<Props> = ({ tickets, lawyers }) => {
  const toast = useToast();
  const [sel, setSel] = useState<Record<string, number>>(() =>
    Object.fromEntries(tickets.map((t) => [t.no, lawyers[0]?.id ?? 0])));

  const assign = (no: string) => {
    router.post(`/admin/distribute/${encodeURIComponent(no)}`, { lawyer_id: sel[no] }, {
      preserveScroll: true,
      onSuccess: () => toast('تم إسناد التذكرة'),
    });
  };

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p><b>التوزيع اليدوي</b> — تُسند الإدارة كل تذكرة للمحامي المختص، ويُحفظ الإسناد فعلياً.</p>
      </div>
      <div className="card">
        <div className="card-h"><h3>توزيع التذاكر</h3><span className="sub">{tickets.length}</span></div>
        <div className="card-b t-wrap">
          {tickets.length ? (
            <table className="tbl">
              <thead>
                <tr><th>التذكرة</th><th>القسم</th><th>المحامي الحالي</th><th>إسناد إلى</th><th></th></tr>
              </thead>
              <tbody>
                {tickets.map((t) => (
                  <tr key={t.no}>
                    <td className="mono">{t.no}</td>
                    <td className="muted">{t.dept || '—'}</td>
                    <td className="muted">{t.lawyer}</td>
                    <td>
                      <select value={sel[t.no]} onChange={(e) => setSel((p) => ({ ...p, [t.no]: Number(e.target.value) }))}>
                        {lawyers.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                      </select>
                    </td>
                    <td><button className="btn soft sm" onClick={() => assign(t.no)} type="button"><Icon name="reply" /> إسناد</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="empty"><Icon name="folder" /><b>لا تذاكر نشطة للتوزيع</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default AdminDistribute;
