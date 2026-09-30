import { Link } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import LawyerFileModal, { CAPACITY } from '@/components/babylon/LawyerFileModal';
import type { LawyerLoad } from '@/components/babylon/LawyerFileModal';
import StatRow from '@/components/babylon/StatRow';
import type { StatItem } from '@/components/babylon/StatRow';
import Icon from '@/lib/icons';
import { PresenceBadge } from '@/lib/staff-presence';
import { useServerAction } from '@/lib/use-server-action';

// يطابق adLawyers — المحامون من جدول users بدور lawyer، وحِملهم بأنواعه من المصدر الواحد (`LawyerWorkload`)
// الذي تقرؤه شاشة «التوزيع»، ووضع التوزيع لكل محامٍ (تلقائي/يدوي) قابل للتبديل عبر /admin/lawyers/{id}/mode

interface LawyerRow { id: number; name: string; depts: string[]; suspended: boolean; manual: boolean; load: LawyerLoad }

type Weights = Record<'tickets' | 'cases' | 'executions' | 'consults', number>;
type StateFilter = 'all' | LawyerLoad['capacity'] | 'suspended';

const STATE_OPTIONS: [StateFilter, string][] = [
  ['all', 'كل الحالات'], ['available', 'متاح'], ['moderate', 'متوسّط الحِمل'], ['busy', 'مشغول'], ['suspended', 'موقوف'],
];

const AdminLawyers: React.FC<{ lawyers: LawyerRow[]; weights: Weights }> = ({ lawyers, weights }) => {
  const action = useServerAction();
  const [search, setSearch] = useState('');
  const [dept, setDept] = useState('all');
  const [state, setState] = useState<StateFilter>('all');
  const [fileOf, setFileOf] = useState<number | null>(null);

  const depts = useMemo(() => [...new Set(lawyers.flatMap((l) => l.depts))].sort(), [lawyers]);

  const rows = useMemo(() => lawyers.filter((l) => {
    const q = search.trim();

    return (q === '' || l.name.includes(q))
      && (dept === 'all' || l.depts.includes(dept))
      && (state === 'all' || (state === 'suspended' ? l.suspended : !l.suspended && l.load.capacity === state));
  }), [lawyers, search, dept, state]);

  // المتاح والمشغول من غير الموقوفين — الموقوف لا يُسنَد إليه (تُظهره شارته وتصفية «موقوف»)
  const working = lawyers.filter((l) => !l.suspended);
  const stats: StatItem[] = [
    ['t-blue', 'scale', lawyers.length, 'المحامون'],
    ['t-green', 'check', working.filter((l) => l.load.capacity === 'available').length, 'متاحون'],
    ['t-amber', 'clock', working.filter((l) => l.load.capacity !== 'available').length, 'متوسّطو الحِمل أو مشغولون'],
    ['t-cyan', 'folder', lawyers.reduce((n, l) => n + l.load.tickets + l.load.cases + l.load.executions + l.load.consults, 0), 'أعمال مفتوحة'],
    ['t-red', 'alert', lawyers.reduce((n, l) => n + l.load.overdueTasks, 0), 'مهام متأخّرة'],
  ];

  // تبديل وضع التوزيع للمحامي: تلقائي ⇄ يدوي — بتأكيد، ونصّ النجاح من الخادم (flash)
  const toggleMode = (l: LawyerRow) => action.run(`/admin/lawyers/${l.id}/mode`, {
    key: l.id,
    confirm: {
      title: `تبديل توزيع ${l.name} إلى ${l.manual ? 'تلقائي' : 'يدوي'}؟`,
      message: l.manual
        ? 'يعود المحامي إلى التوزيع التلقائي العادل ويبدأ باستقبال التذاكر آلياً.'
        : 'يُستثنى المحامي من التوزيع التلقائي، ولا تُسند إليه التذاكر إلا يدوياً.',
      confirmLabel: 'تبديل',
      cancelLabel: 'تراجع',
    },
    fallback: 'تعذّر تبديل الوضع، حاول مجدداً',
  });

  return (
    <>
      <StatRow items={stats} />

      <div className="card">
        <div className="card-h">
          <h3>المحامون والأقسام</h3>
          <span className="sub">
            الحِمل = تذاكر×{weights.tickets} + قضايا×{weights.cases} + تنفيذ×{weights.executions} + استشارات×{weights.consults} — كشاشة التوزيع
          </span>
        </div>
        <div className="lw-filters">
          <input className="input" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="بحث باسم المحامي…" />
          <select className="input" value={dept} onChange={(e) => setDept(e.target.value)}>
            <option value="all">كل الأقسام</option>
            {depts.map((d) => <option key={d} value={d}>{d}</option>)}
          </select>
          <select className="input" value={state} onChange={(e) => setState(e.target.value as StateFilter)}>
            {STATE_OPTIONS.map(([v, lbl]) => <option key={v} value={v}>{lbl}</option>)}
          </select>
        </div>
        <div className="card-b t-wrap">
          <table className="tbl tbl-cards">
            <thead>
              <tr>
                <th>المحامي</th>
                <th>الأقسام</th>
                <th>تذاكر</th>
                <th>قضايا</th>
                <th>تنفيذ</th>
                <th>استشارات</th>
                <th>الحِمل</th>
                <th>متابعة</th>
                <th>التوزيع</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {rows.length ? rows.map((l) => (
                <tr key={l.id}>
                  <td className="tc-head" data-label="المحامي">
                    <button type="button" className="lw-name" onClick={() => setFileOf(l.id)} title="ملفّ المحامي المفتوح">{l.name}</button>
                    {l.suspended && <> <Badge text="موقوف" tone="b-red" /></>} <PresenceBadge userId={l.id} showFree={!l.suspended} />
                  </td>
                  <td data-label="الأقسام">
                    <div className="chips">
                      {l.depts.length ? l.depts.map((d) => <span key={d} className="chip muted">{d}</span>) : <span className="chip muted">—</span>}
                    </div>
                  </td>
                  <td data-label="تذاكر"><Link href={`/admin/tickets?lawyer_id=${l.id}&status=open`} title="تذاكره المفتوحة">{l.load.tickets}</Link></td>
                  <td data-label="قضايا">{l.load.cases}</td>
                  <td data-label="تنفيذ">{l.load.executions}</td>
                  <td data-label="استشارات">{l.load.consults}</td>
                  <td data-label="الحِمل">
                    {l.suspended ? <span className="sub">—</span> : <><b>{l.load.total}</b> <Badge text={CAPACITY[l.load.capacity].label} tone={CAPACITY[l.load.capacity].tone} /></>}
                  </td>
                  <td data-label="متابعة">
                    <div className="sub nowrap" title="الاجتماعات القادمة">اجتماعات: {l.load.upcomingMeetings}</div>
                    <div className={`nowrap ${l.load.overdueTasks > 0 ? 'txt-overdue' : 'sub'}`} title="المهام المتأخّرة">متأخّرة: {l.load.overdueTasks}</div>
                  </td>
                  <td data-label="التوزيع"><Badge text={l.manual ? 'يدوي' : 'تلقائي'} tone={l.manual ? 'b-amber' : 'b-blue'} /></td>
                  <td className="tc-actions">
                    <div>
                      <button className="btn soft sm" type="button" onClick={() => setFileOf(l.id)}>
                        <Icon name="folder" /> الملفّ
                      </button>
                      <button className="btn soft sm" type="button" disabled={action.busyKey === l.id} onClick={() => toggleMode(l)}>
                        {action.busyKey === l.id ? '…' : 'تبديل التوزيع'}
                      </button>
                    </div>
                  </td>
                </tr>
              )) : (
                <tr><td colSpan={10} style={{ textAlign: 'center', color: 'var(--muted)', padding: 20 }}>
                  {lawyers.length ? 'لا محامٍ يطابق البحث أو التصفية' : 'لا محامون مسجّلون بعد'}
                </td></tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {fileOf !== null && <LawyerFileModal key={fileOf} lawyerId={fileOf} onClose={() => setFileOf(null)} />}
    </>
  );
};

export default AdminLawyers;
