import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';

interface ExecRow { no: string; client: string; subject: string; lawyer: string; status: string; tone: string; last?: string | null; }
interface Props { execs: ExecRow[]; }

const open = (no: string) => router.visit(`/employee/execs/${encodeURIComponent(no)}`);

const EmployeeExecs: React.FC<Props> = ({ execs }) => (
  <div className="card">
    <div className="card-h">
      <h3>طلبات التنفيذ</h3>
      <span className="sub">{execs.length} طلب</span>
    </div>
    <div className="card-b t-wrap">
      {execs.length ? (
        <table className="tbl">
          <thead>
            <tr><th>رقم الطلب</th><th>العميل</th><th>الموضوع</th><th>المحامي</th><th>الحالة</th><th></th></tr>
          </thead>
          <tbody>
            {execs.map((e) => (
              <tr key={e.no} className="click" onClick={() => open(e.no)}>
                <td className="mono">{e.no}</td>
                <td>{e.client}</td>
                <td className="muted">{e.subject}</td>
                <td className="muted">{e.lawyer}</td>
                <td><Badge text={e.status} tone={e.tone} /></td>
                <td>
                  <button className="btn soft sm" type="button" onClick={(ev) => { ev.stopPropagation(); open(e.no); }}>
                    <Icon name="reply" /> فتح
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <div className="empty"><Icon name="exec" /><b>لا طلبات تنفيذ</b></div>
      )}
    </div>
  </div>
);

export default EmployeeExecs;
