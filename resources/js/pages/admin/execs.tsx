import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';

// إشراف الإدارة على طلبات التنفيذ + الإغلاق والأرشفة

interface ExecRow { no: string; client: string; subject: string; lawyer: string; status: string; tone: string; canClose: boolean; }
interface Props { execs: ExecRow[]; }

const AdminExecs: React.FC<Props> = ({ execs }) => {
  const toast = useToast();
  const close = (no: string) =>
    router.post(`/admin/execs/${encodeURIComponent(no)}/close`, {}, { preserveScroll: true, onSuccess: () => toast('تم إغلاق طلب التنفيذ') });

  return (
    <div className="card">
      <div className="card-h">
        <h3>كل طلبات التنفيذ</h3>
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
                <tr key={e.no}>
                  <td className="mono">{e.no}</td>
                  <td>{e.client}</td>
                  <td className="muted">{e.subject}</td>
                  <td className="muted">{e.lawyer}</td>
                  <td><Badge text={e.status} tone={e.tone} /></td>
                  <td>
                    {e.canClose && (
                      <button className="btn sm" type="button" onClick={() => close(e.no)}>
                        <Icon name="check" /> إغلاق وأرشفة
                      </button>
                    )}
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
};

export default AdminExecs;
