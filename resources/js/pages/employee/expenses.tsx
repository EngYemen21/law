import { Head } from '@inertiajs/react';
import React from 'react';
import Pagination from '@/components/babylon/Pagination';
import type { Paginated } from '@/components/babylon/Pagination';
import { ExpenseForm, ExpensesTable } from '@/components/finance/expenses';
import type { ExpenseOpt, ExpenseRow } from '@/components/finance/expenses';
import Icon from '@/lib/icons';

/**
 * **مصروفاتي** — بصلاحيّة «تسجيل المصروفات»: يسجّل الموظّف مصروفاً فينتظر اعتماد الإدارة، ويرى
 * ما سجّله بحالته (معتمدٌ برقم سند الصرف، أو مرفوضٌ بسببه). الاعتماد والإلغاء للإدارة وحدها.
 */
interface Props {
  rows: Paginated<ExpenseRow>;
  categories: ExpenseOpt[];
  paidFrom: ExpenseOpt[];
}

const EmployeeExpenses: React.FC<Props> = ({ rows, categories, paidFrom }) => (
  <>
    <Head title="المصروفات" />
    <div className="greet">
      <h2>المصروفات</h2>
      <p>سجّل مصروفاً للمكتب مع مرفقه — يُحسب بعد أن تعتمده الإدارة ويصدر له سند صرف.</p>
    </div>

    <div className="card">
      <div className="card-h"><h3>مصروف جديد</h3><span className="sub">الرواتب ومستحقّات الموظّفين تصرفها الإدارة من «المستحقّات والصرف»</span></div>
      <div className="card-b">
        <ExpenseForm action="/employee/expenses" categories={categories} paidFrom={paidFrom} submitLabel="إرسال للاعتماد" />
      </div>
    </div>

    <div className="card">
      <div className="card-h"><h3>ما سجّلتُه</h3><span className="sub">بحالته — والمرفوض بسببه</span></div>
      <div className="card-b">
        {rows.data.length ? (
          <ExpensesTable rows={rows.data} documentHref={(r) => `/employee/expenses/${r.id}/document`} />
        ) : (
          <div className="empty"><Icon name="card" /><b>لم تسجّل مصروفاً بعد</b></div>
        )}
        <Pagination meta={rows.meta} />
      </div>
    </div>
  </>
);

export default EmployeeExpenses;
