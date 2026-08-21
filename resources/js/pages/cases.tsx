import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';

// يطابق viewCases في index (82).html — البيانات من قاعدة البيانات + سداد الأتعاب لتفعيل القضية

interface CaseCard {
  no: string; type: string; status: string; tone: string; update: string; next: string;
  fee?: number | null; feeStatus?: string; invoice?: string | null;
}

const Cases: React.FC<{ cases: CaseCard[] }> = ({ cases }) => {
  const toast = useToast();

  const pay = (no: string) =>
    router.post(`/cases/${encodeURIComponent(no)}/pay`, {}, {
      preserveScroll: true,
      onError: (errors) => toast(Object.values(errors)[0] ?? 'تعذّر بدء الدفع، حاول بعد قليل'),
    });

  return (
    <div className="card">
      <div className="card-h">
        <h3>القضايا النشطة</h3>
        <span className="sub">{cases.length} قضايا</span>
      </div>
      <div className="card-b t-wrap">
        <table className="tbl">
          <thead>
            <tr>
              <th>رقم القضية</th>
              <th>النوع</th>
              <th>الحالة</th>
              <th>الجلسة القادمة</th>
              <th>آخر تحديث</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {cases.map((c) => (
              <tr
                key={c.no}
                className="click"
                onClick={() => router.visit(`/cases/${encodeURIComponent(c.no)}`)}
              >
                <td className="mono">{c.no}</td>
                <td>{c.type}</td>
                <td><Badge text={c.status} tone={c.tone} /></td>
                <td className="muted">{c.next ?? '—'}</td>
                <td className="last muted">{c.update}</td>
                <td>
                  {c.feeStatus === 'pending_payment' ? (
                    <button className="btn sm" type="button" onClick={(e) => { e.stopPropagation(); pay(c.no); }}>
                      <Icon name="card" /> سداد الأتعاب{c.fee ? ` (${c.fee.toLocaleString()} ر.س)` : ''}
                    </button>
                  ) : (
                    <button className="btn soft sm" type="button">
                      <Icon name="scale" /> التفاصيل
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default Cases;
