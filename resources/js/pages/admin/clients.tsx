import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { CLIENTS } from '@/lib/admin-data';

// يطابق adClients في index (82).html

const AdminClients: React.FC = () => (
  <>
    <div className="ai-banner">
      <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
      <p>بيانات العملاء (الاسم، الهوية، الجوال، البريد، العنوان) <b>مشفّرة</b> ولا تظهر كاملة إلا للمصرّح لهم.</p>
    </div>
    <div className="card">
      <div className="card-h">
        <h3>العملاء</h3>
        <span className="lock-badge"><Icon name="lock" /> بيانات مشفّرة</span>
      </div>
      <div className="card-b t-wrap">
        <table className="tbl">
          <thead>
            <tr>
              <th>الاسم</th>
              <th>الهوية</th>
              <th>الجوال</th>
              <th>التذاكر</th>
              <th>الحالة</th>
            </tr>
          </thead>
          <tbody>
            {CLIENTS.map((c) => (
              <tr key={c.id}>
                <td>{c.name}</td>
                <td className="mono">{c.id}</td>
                <td className="mono">{c.mobile}</td>
                <td>{c.tickets}</td>
                <td><Badge text={c.status} tone={c.status === 'نشط' ? 'b-green' : 'b-grey'} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  </>
);

export default AdminClients;
