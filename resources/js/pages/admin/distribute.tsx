import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

// توزيع التذاكر — الإدارة تُسند التذاكر للمحامين فعلياً (يكتب assigned_lawyer_id)،
// أو توزّع غير المسندة تلقائياً عبر محرك التوزيع العادل (تخصّص + حمل + أقدمية).

interface T { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; }
interface Props { tickets: T[]; lawyers: { id: number; name: string }[]; }

const AdminDistribute: React.FC<Props> = ({ tickets, lawyers }) => {
  const toast = useToast();
  const [sel, setSel] = useState<Record<string, number>>(() =>
    Object.fromEntries(tickets.map((t) => [t.no, lawyers[0]?.id ?? 0])));
  const [autoBusy, setAutoBusy] = useState(false);

  // عدد التذاكر غير المسندة (لا محامٍ مسند) — هي هدف التوزيع التلقائي
  const autoCount = tickets.filter((t) => !t.lawyer || t.lawyer === '—').length;

  const assign = (no: string) => {
    router.post(`/admin/distribute/${encodeURIComponent(no)}`, { lawyer_id: sel[no] }, {
      preserveScroll: true,
      onSuccess: () => toast('تم إسناد التذكرة'),
      onError: () => toast('تعذّر إسناد التذكرة، حاول مجدداً'),
    });
  };

  // التوزيع التلقائي: يستدعي محرك TicketAssignment على غير المسندة فقط
  const runAuto = () => {
    if (autoCount === 0 || autoBusy) return;
    if (!window.confirm(`سيتم توزيع ${autoCount} تذكرة غير مسندة تلقائياً حسب التخصّص والحمل. متابعة؟`)) return;
    setAutoBusy(true);
    router.post('/admin/distribute/auto', {}, {
      preserveScroll: true,
      onSuccess: () => toast('اكتمل التوزيع التلقائي'),
      onError: () => toast('فشل التوزيع التلقائي، حاول مجدداً'),
      onFinish: () => setAutoBusy(false), // ضمان تحرير الزر حتى عند الخطأ
    });
  };

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p><b>التوزيع</b> — تُسند الإدارة كل تذكرة للمحامي المختص (يدوي)، أو توزّع غير المسندة تلقائياً حسب التخصّص والحمل.</p>
      </div>
      <div className="card">
        <div className="card-h">
          <h3>توزيع التذاكر</h3>
          <span className="sub">{tickets.length}</span>
          <button
            className="btn primary sm"
            type="button"
            disabled={autoBusy || autoCount === 0}
            onClick={runAuto}
            style={{ marginRight: 8 }}
          >
            <Icon name="scale" /> {autoBusy ? 'جارٍ التوزيع…' : `توزيع تلقائي (${autoCount})`}
          </button>
        </div>
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
