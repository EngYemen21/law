import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';

// يطابق viewExecs في index (82).html — البيانات من قاعدة البيانات

interface ExecCard { no: string; subject: string; status: string; tone: string; last: string; }

const Execs: React.FC<{ execs: ExecCard[] }> = ({ execs }) => (
  <div className="card">
    <div className="card-h">
      <h3>طلبات التنفيذ</h3>
      <span className="sub">{execs.length} طلبات</span>
    </div>
    <div className="card-b t-wrap">
      <table className="tbl">
        <thead>
          <tr>
            <th>رقم الطلب</th>
            <th>الموضوع</th>
            <th>الحالة</th>
            <th>آخر إجراء</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {execs.map((e) => (
            <tr
              key={e.no}
              className="click"
              onClick={() => router.visit(`/execs/${encodeURIComponent(e.no)}`)}
            >
              <td className="mono">{e.no}</td>
              <td>{e.subject}</td>
              <td><Badge text={e.status} tone={e.tone} /></td>
              <td className="muted">{e.last}</td>
              <td>
                <button className="btn soft sm" type="button">
                  <Icon name="reply" /> فتح المتابعة
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  </div>
);

export default Execs;
