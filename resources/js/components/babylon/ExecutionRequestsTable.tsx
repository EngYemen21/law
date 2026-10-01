import { Link } from '@inertiajs/react';
import React from 'react';
import Badge from '@/components/babylon/Badge';
import { usePrompt } from '@/components/babylon/ConfirmDialog';
import Icon from '@/lib/icons';
import { useServerAction } from '@/lib/use-server-action';

/** طلب فتح تنفيذ حكمٍ ينتظر الإدارة (`ApprovalsController` ← `CaseExecutionRequest::pending`). */
export interface ExecutionRequestRow {
  no: string;
  client: string;
  type: string;
  lawyer: string;
  at: string | null;
  by: string;
  reason: string;
  amount: number | null;
}

/**
 * **طلبات فتح تنفيذ الأحكام في مركز الاعتمادات** (قرار المالك 2026-09-29) — المحامي أو الموظّف يرفع الطلب
 * بسببه، والإدارة تعتمده فيُفتح ملفّ التنفيذ، أو ترفضه بسببٍ يصل رافعه. الحرّاس والقرار في الخادم.
 */
const ExecutionRequestsTable: React.FC<{ rows: ExecutionRequestRow[] }> = ({ rows }) => {
  const action = useServerAction();
  const askReason = usePrompt();

  const approve = (r: ExecutionRequestRow) =>
    action.run(`/admin/cases/${encodeURIComponent(r.no)}/execution-request/approve`, {
      key: r.no,
      confirm: {
        title: `اعتماد طلب تنفيذ الحكم في ${r.no}؟`,
        message: `رفعه ${r.by} — المبلغ المحكوم به: ${r.amount ? `${r.amount.toLocaleString('en-US')} ريال` : 'غير محدّد'} — السبب: ${r.reason}`,
        confirmLabel: 'اعتماد وفتح الملف',
        cancelLabel: 'تراجع',
      },
      success: 'اعتُمد الطلب وفُتح ملفّ التنفيذ',
      fallback: 'تعذّر اعتماد الطلب',
    });

  const reject = async (r: ExecutionRequestRow) => {
    const reason = (await askReason({
      title: `رفض طلب تنفيذ الحكم في ${r.no}`,
      message: 'يصل السبب رافعَ الطلب في إشعار.',
      label: 'سبب الرفض',
      multiline: true,
      confirmLabel: 'رفض الطلب',
      cancelLabel: 'تراجع',
    }))?.trim();

    if (reason) {
      void action.run(`/admin/cases/${encodeURIComponent(r.no)}/execution-request/reject`, {
        key: r.no,
        data: { reason },
        success: 'رُفض الطلب وأُبلغ رافعه',
        fallback: 'تعذّر رفض الطلب',
      });
    }
  };

  return (
    <div className="card" style={{ overflow: 'hidden', boxShadow: 'none' }}>
      <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <h3>⚡ طلبات فتح تنفيذ الأحكام</h3>
          <Badge text={String(rows.length)} tone={rows.length > 0 ? 'b-amber' : 'b-grey'} />
        </div>
        <span className="sub" style={{ fontSize: 12 }}>يرفعها المحامي أو الموظّف بسببها — ولا يُفتح ملفّ التنفيذ إلا باعتمادك</span>
      </div>
      <div className="card-b t-wrap" style={{ padding: 0 }}>
        {rows.length > 0 ? (
          <table className="tbl" style={{ width: '100%', minWidth: 820 }}>
            <thead>
              <tr>
                <th>القضية</th><th>العميل</th><th>المحامي</th><th>رفعه</th><th>المبلغ</th><th>السبب</th><th>منذ</th><th style={{ textAlign: 'center' }}>الإجراء</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.no}>
                  <td><Link href={`/admin/cases/${encodeURIComponent(r.no)}`} style={{ fontWeight: 700 }}>{r.no}</Link><div className="sub">{r.type}</div></td>
                  <td>{r.client}</td>
                  <td>{r.lawyer}</td>
                  <td><b>{r.by}</b></td>
                  <td style={{ whiteSpace: 'nowrap' }}>{r.amount ? `${r.amount.toLocaleString('en-US')} ريال` : '—'}</td>
                  <td style={{ maxWidth: 280, whiteSpace: 'pre-line' }}>{r.reason}</td>
                  <td className="muted">{r.at ?? '—'}</td>
                  <td>
                    <div style={{ display: 'flex', gap: 6, justifyContent: 'center', flexWrap: 'wrap' }}>
                      <button className="btn sm" type="button" disabled={action.busyKey === r.no} onClick={() => approve(r)}>
                        <Icon name="check" /> اعتماد
                      </button>
                      <button className="btn sm soft" type="button" disabled={action.busyKey === r.no} onClick={() => reject(r)}>
                        <Icon name="close" /> رفض
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div className="empty" style={{ padding: '28px 0' }}><Icon name="check" /><b>لا طلبات تنفيذ بانتظار الاعتماد</b></div>
        )}
      </div>
    </div>
  );
};

export default ExecutionRequestsTable;
