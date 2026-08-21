import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import Pagination, { type Paginated } from '@/components/babylon/Pagination';
import { useToast } from '@/components/babylon/Toast';

// محاسبة الإدارة — فواتير حقيقية من قاعدة البيانات (موديل Invoice)

const fmt = (n: number) => n.toLocaleString('en-US') + ' ر.س';

interface Inv { no: string; client: string; desc: string; amount: number; status: string; tone: string; due: string; paid: boolean; }
interface Props { invoices: Paginated<Inv>; filter: string; totals: { issued: number; collected: number; due: number; overdue: number; unpaid: number }; }

const TABS: [string, string][] = [['all', 'الكل'], ['مدفوعة', 'مدفوعة'], ['غير مدفوعة', 'غير المدفوعة']];

const AdminAccounting: React.FC<Props> = ({ invoices, filter, totals }) => {
  const toast = useToast();
  const list = invoices.data;

  // التصفية خادميّة مع الترقيم: التصفية محلياً كانت ستقتصر على الصفحة الحالية
  const setFilter = (f: string) =>
    router.get('/admin/accounting', f === 'all' ? {} : { filter: f }, { preserveScroll: true, preserveState: true });

  const markPaid = (no: string) => {
    router.post(`/admin/invoices/${encodeURIComponent(no)}/pay`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('تم تسجيل تحصيل الفاتورة ' + no),
    });
  };

  return (
    <>
      <div className="greet">
        <h2>الفواتير والمحاسبة</h2>
        <p>متابعة الفواتير الصادرة والتحصيل والذمم — بيانات حقيقية من النظام.</p>
      </div>

      <div className="stats">
        <div className="stat t-blue"><div className="si"><Icon name="card" /></div><div className="num">{fmt(totals.issued)}</div><div className="lbl">إجمالي المُصدَر</div></div>
        <div className="stat t-green"><div className="si"><Icon name="check" /></div><div className="num">{fmt(totals.collected)}</div><div className="lbl">المحصّل</div></div>
        <div className="stat t-amber"><div className="si"><Icon name="folder" /></div><div className="num">{fmt(totals.due)}</div><div className="lbl">المستحق (الذمم)</div></div>
        <div className="stat t-cyan"><div className="si"><Icon name="scale" /></div><div className="num">{totals.overdue}</div><div className="lbl">فواتير متأخرة</div></div>
      </div>

      <div className="card" style={{ marginTop: 16 }}>
        <div className="card-h"><h3>الفواتير الصادرة</h3><span className="sub">{invoices.meta.total}</span></div>
        <div className="card-b" style={{ padding: '12px 14px' }}>
          <div className="mtabs">
            {TABS.map((t) => (
              <button key={t[0]} className={`mtab ${filter === t[0] ? 'on' : ''}`} onClick={() => setFilter(t[0])} type="button">{t[1]}</button>
            ))}
          </div>
          {list.length ? (
            <table className="tbl" style={{ marginTop: 6 }}>
              <thead>
                <tr><th>رقم الفاتورة</th><th>العميل</th><th>الوصف</th><th className="n">المبلغ</th><th>الحالة</th><th>إجراءات</th></tr>
              </thead>
              <tbody>
                {list.map((v) => (
                  <tr key={v.no}>
                    <td className="mono">{v.no}</td>
                    <td>{v.client}</td>
                    <td className="muted">{v.desc}</td>
                    <td className="n">{fmt(v.amount)}</td>
                    <td><Badge text={v.status} tone={v.tone} /></td>
                    <td>
                      {!v.paid && <button className="btn sm" onClick={() => markPaid(v.no)} type="button"><Icon name="check" /> تحصيل</button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="empty"><Icon name="card" /><b>لا فواتير في هذا التصنيف</b></div>
          )}
          <Pagination meta={invoices.meta} only={['invoices']} />
        </div>
      </div>
    </>
  );
};

export default AdminAccounting;
