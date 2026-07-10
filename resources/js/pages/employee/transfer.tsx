import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';

// تحويل التذاكر — الموظف يحوّل تذكرة من محامٍ إلى آخر (يكتب assigned_lawyer_id فعلياً)

interface T { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; }
interface Props { tickets: T[]; lawyers: { id: number; name: string }[]; }

const EmployeeTransfer: React.FC<Props> = ({ tickets, lawyers }) => {
  const toast = useToast();
  const [sel, setSel] = useState<Record<string, number>>(() =>
    Object.fromEntries(tickets.map((t) => [t.no, lawyers[0]?.id ?? 0])));
  const [reason, setReason] = useState<Record<string, string>>({});

  const doTransfer = (no: string) => {
    router.post(`/employee/transfer/${encodeURIComponent(no)}`, { lawyer_id: sel[no], reason: reason[no] || '' }, {
      preserveScroll: true,
      onSuccess: () => toast('تم تحويل التذكرة'),
    });
  };

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p><b>تحويل التذاكر</b> — حوّل التذكرة من محامٍ إلى آخر مع بيان السبب، ويُحفظ التحويل فعلياً.</p>
      </div>
      <div className="card">
        <div className="card-h"><h3>التذاكر النشطة</h3><span className="sub">{tickets.length}</span></div>
        <div className="card-b t-wrap">
          {tickets.length ? (
            <table className="tbl">
              <thead>
                <tr><th>التذكرة</th><th>الحالة</th><th>المحامي الحالي</th><th>تحويل إلى</th><th>السبب</th><th></th></tr>
              </thead>
              <tbody>
                {tickets.map((t) => (
                  <tr key={t.no}>
                    <td className="mono">{t.no}</td>
                    <td><Badge text={t.status} tone={t.tone} /></td>
                    <td className="muted">{t.lawyer}</td>
                    <td>
                      <select value={sel[t.no]} onChange={(e) => setSel((p) => ({ ...p, [t.no]: Number(e.target.value) }))}>
                        {lawyers.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                      </select>
                    </td>
                    <td><input value={reason[t.no] || ''} onChange={(e) => setReason((p) => ({ ...p, [t.no]: e.target.value }))} placeholder="اختياري" style={{ minWidth: 120 }} /></td>
                    <td><button className="btn soft sm" onClick={() => doTransfer(t.no)} type="button"><Icon name="reply" /> تحويل</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="empty"><Icon name="folder" /><b>لا تذاكر نشطة للتحويل</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default EmployeeTransfer;
