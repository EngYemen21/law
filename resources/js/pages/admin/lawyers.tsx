import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { PresenceBadge } from '@/lib/staff-presence';

// يطابق adLawyers — المحامون من جدول users بدور lawyer + عدد التذاكر المحالة
// + وضع التوزيع لكل محامٍ (تلقائي/يدوي) قابل للتبديل عبر /admin/lawyers/{id}/mode

interface LawyerRow { id: number; name: string; depts: string[]; active: number; mode: string; suspended?: boolean }

const AdminLawyers: React.FC<{ lawyers: LawyerRow[] }> = ({ lawyers }) => {
  const toast = useToast();
  const [busyId, setBusyId] = useState<number | undefined>(undefined);

  // تبديل وضع التوزيع للمحامي: تلقائي ⇄ يدوي
  const toggleMode = (l: LawyerRow) => {
    if (busyId === l.id) return;
    setBusyId(l.id);
    router.post(`/admin/lawyers/${l.id}/mode`, {}, {
      preserveScroll: true,
      // نصّ النجاح من الخادم (flash) — ورسالة الرفض منه كذلك
      onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر تبديل الوضع، حاول مجدداً'}`, 'error'),
      onFinish: () => setBusyId(undefined), // ضمان تحرير الزر حتى عند الخطأ
    });
  };

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
              <tr key={l.id}>
                <td><b>{l.name}</b>{l.suspended && <> <Badge text="موقوف" tone="b-red" /></>} <PresenceBadge userId={l.id} showFree={!l.suspended} /></td>
                <td>
                  <div className="chips">
                    {l.depts.length ? l.depts.map((d) => <span key={d} className="chip muted">{d}</span>) : <span className="chip muted">—</span>}
                  </div>
                </td>
                <td>{l.active}</td>
                <td><Badge text={l.mode} tone={l.mode === 'تلقائي' ? 'b-blue' : 'b-amber'} /></td>
                <td>
                  <button
                    className="btn soft sm"
                    type="button"
                    disabled={busyId === l.id}
                    onClick={() => toggleMode(l)}
                  >
                    {busyId === l.id ? '…' : 'تبديل التوزيع'}
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
