import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';

interface CaseRow { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; next?: string | null; }
interface Props { cases: CaseRow[]; }

const open = (no: string) => router.visit(`/lawyer/cases/${encodeURIComponent(no)}`);

const LawyerCases: React.FC<Props> = ({ cases }) => (
  <div className="card">
    <div className="card-h">
      <h3>قضاياي</h3>
      <span className="sub">{cases.length} قضية</span>
    </div>
    <div className="card-b t-wrap">
      {cases.length ? (
        <table className="tbl">
          <thead>
            <tr><th>رقم القضية</th><th>العميل</th><th>النوع</th><th>الجلسة القادمة</th><th>الحالة</th><th></th></tr>
          </thead>
          <tbody>
            {cases.map((c) => (
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
        <div className="empty"><Icon name="scale" /><b>لا قضايا محالة إليك</b></div>
      )}
    </div>
  </div>
);

export default LawyerCases;
