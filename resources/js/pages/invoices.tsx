import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import { type Invoice } from '@/lib/data';

// يطابق viewInvoices في index (82).html

const InvRow: React.FC<{ v: Invoice }> = ({ v }) => {
  const toast = useToast();
  return (
    <div className="item">
      <div className={`iico ${v.paid ? '' : 'pay-ico'}`}><Icon name="card" /></div>
      <div className="imeta">
        <b>{v.desc}</b>
        <span className="mono" style={{ direction: 'ltr' }}>{v.no}</span> · <span>{v.due}</span>
      </div>
      <div className="iact">
        <span style={{ fontWeight: 800, color: 'var(--deep)', fontSize: 15 }}>
          {v.amount.toLocaleString()} ر.س
        </span>
        <Badge text={v.status} tone={v.tone} />
        {v.paid ? (
          <button className="btn soft sm" type="button" onClick={() => toast('جارٍ تحميل الفاتورة PDF')}>
            <Icon name="download" /> الفاتورة PDF
          </button>
        ) : (
          <button className="btn sm" type="button" onClick={() => toast('تم استلام إثبات التحويل وسيُراجع')}>
            <Icon name="upload" /> رفع إثبات التحويل
          </button>
        )}
      </div>
    </div>
  );
};

const Invoices: React.FC<{ invoices: Invoice[] }> = ({ invoices }) => {
  const due = invoices.filter((v) => !v.paid);
  const paid = invoices.filter((v) => v.paid);
  const dueSum = due.reduce((a, v) => a + v.amount, 0);
  const paidSum = paid.reduce((a, v) => a + v.amount, 0);

  const stats: StatItem[] = [
    ['t-amber', 'card', due.length, 'فواتير مستحقة'],
    ['t-blue', 'card', dueSum.toLocaleString(), 'إجمالي المستحق (ر.س)'],
    ['t-green', 'check', paid.length, 'فواتير مدفوعة'],
    ['t-cyan', 'card', paidSum.toLocaleString(), 'إجمالي المدفوع (ر.س)'],
  ];

  return (
    <>
      <StatRow items={stats} />

      <div className="card">
        <div className="card-h">
          <h3>الفواتير المستحقة</h3>
          <span className="sub">{due.length} فاتورة</span>
        </div>
        <div className="card-b">
          {due.length ? (
            due.map((v) => <InvRow key={v.no} v={v} />)
          ) : (
            <div className="empty"><Icon name="check" /><b>لا فواتير مستحقة</b></div>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>الفواتير المدفوعة</h3></div>
        <div className="card-b">
          {paid.map((v) => <InvRow key={v.no} v={v} />)}
        </div>
      </div>
    </>
  );
};

export default Invoices;
