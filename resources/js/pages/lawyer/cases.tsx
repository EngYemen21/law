import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { matchesSearch } from '@/lib/employee-data';
import Icon from '@/lib/icons';

interface CaseRow { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; next?: string | null; }

/** مجموعات الحالة من الخادم (`CaseJourney::adminTabs`) — المصدر نفسه لتبويبات قضايا الإدارة */
interface CaseTabs { pendingFee: string[]; active: string[]; judged: string[]; closed: string[] }
type TabKey = 'all' | keyof CaseTabs;

interface Props { cases: CaseRow[]; tabs: CaseTabs; }

const TAB_LABELS: Record<keyof CaseTabs, string> = {
  active: 'قيد العمل',
  judged: 'صدر الحكم',
  pendingFee: 'بانتظار الأتعاب',
  closed: 'مغلقة ومؤرشفة',
};

const open = (no: string) => router.visit(`/lawyer/cases/${encodeURIComponent(no)}`);

const LawyerCases: React.FC<Props> = ({ cases, tabs }) => {
  const [tab, setTab] = useState<TabKey>('all');
  const [search, setSearch] = useState('');
  const inTab = (c: CaseRow, k: TabKey) => k === 'all' || tabs[k].includes(c.status);
  const shown = cases.filter((c) => inTab(c, tab) && matchesSearch(search, c.no, c.client, c.type, c.dept, c.status));

  return (
    <div className="card">
      <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h3>قضاياي</h3>
          <span className="sub">{cases.length} قضية</span>
        </div>
        <input
          className="input"
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="ابحث برقم القضية أو العميل أو النوع…"
          style={{ maxWidth: 300 }}
          aria-label="بحث في القضايا"
        />
      </div>
      <div className="card-b" style={{ paddingBottom: 0 }}>
        <div className="tabs" style={{ margin: 0 }}>
          {(['all', 'active', 'judged', 'pendingFee', 'closed'] as TabKey[]).map((k) => (
            <button key={k} className={`tab${tab === k ? ' on' : ''}`} type="button" onClick={() => setTab(k)}>
              {k === 'all' ? 'الكل' : TAB_LABELS[k]} ({cases.filter((c) => inTab(c, k)).length})
            </button>
          ))}
        </div>
      </div>
      <div className="card-b t-wrap">
        {shown.length ? (
          <table className="tbl">
            <thead>
              <tr><th>رقم القضية</th><th>العميل</th><th>النوع</th><th>الجلسة القادمة</th><th>الحالة</th><th></th></tr>
            </thead>
            <tbody>
              {shown.map((c) => (
                <tr key={c.no} className="click" onClick={() => open(c.no)}>
                  <td className="mono">{c.no}</td>
                  <td>{c.client}</td>
                  <td className="muted">{c.type}</td>
                  <td className="muted">{c.next ?? '—'}</td>
                  <td><Badge text={c.status} tone={c.tone} /></td>
                  <td>
                    <button className="btn soft sm" type="button" onClick={(e) => { e.stopPropagation(); open(c.no); }}>
                      <Icon name="scale" /> إدارة
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div className="empty"><Icon name="scale" /><b>{cases.length ? 'لا قضايا مطابقة للبحث أو التبويب' : 'لا قضايا محالة إليك'}</b></div>
        )}
      </div>
    </div>
  );
};

export default LawyerCases;
