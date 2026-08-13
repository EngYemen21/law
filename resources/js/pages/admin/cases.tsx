import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';

// إشراف الإدارة على كل القضايا + الإغلاق والأرشفة بعد الحكم

interface CaseRow { no: string; client: string; type: string; lawyer: string; status: string; tone: string; canClose: boolean; canArchive: boolean; canExecute: boolean; }
interface Props { cases: CaseRow[]; }

const AdminCases: React.FC<Props> = ({ cases }) => {
  const toast = useToast();
  const close = (no: string) =>
    router.post(`/admin/cases/${encodeURIComponent(no)}/close`, {}, {
      preserveScroll: true, onSuccess: () => toast('تم إغلاق القضية'),
    });
  const archive = (no: string) =>
    router.post(`/admin/cases/${encodeURIComponent(no)}/archive`, {}, {
      preserveScroll: true, onSuccess: () => toast('تمت أرشفة القضية'),
    });
  const execute = (no: string) =>
    router.post(`/admin/cases/${encodeURIComponent(no)}/execute`, {}, {
      preserveScroll: true, onSuccess: () => toast('تم فتح طلب تنفيذ للقضية'),
    });

  return (
    <div className="card">
      <div className="card-h">
        <h3>كل القضايا</h3>
        <span className="sub">{cases.length} قضية</span>
      </div>
      <div className="card-b t-wrap">
        {cases.length ? (
          <table className="tbl">
            <thead>
              <tr><th>رقم القضية</th><th>العميل</th><th>النوع</th><th>المحامي</th><th>الحالة</th><th></th></tr>
            </thead>
            <tbody>
              {cases.map((c) => (
                <tr key={c.no}>
                  <td className="mono">{c.no}</td>
                  <td>{c.client}</td>
                  <td className="muted">{c.type}</td>
                  <td className="muted">{c.lawyer}</td>
                  <td><Badge text={c.status} tone={c.tone} /></td>
                  <td>
                    {c.canClose && (
                      <button className="btn sm" type="button" onClick={() => close(c.no)}>
                        <Icon name="check" /> إغلاق
                      </button>
                    )}
                    {c.canArchive && (
                      <button className="btn sm soft" type="button" onClick={() => archive(c.no)}>
                        <Icon name="folder" /> أرشفة
                      </button>
                    )}
                    {c.canExecute && (
                      <button className="btn sm soft" type="button" onClick={() => execute(c.no)}>
                        <Icon name="exec" /> تحويل لتنفيذ
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div className="empty"><Icon name="scale" /><b>لا قضايا</b></div>
        )}
      </div>
    </div>
  );
};

export default AdminCases;
