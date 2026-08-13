import { router } from '@inertiajs/react';
import React, { useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import { type Invoice } from '@/lib/data';

// يطابق viewInvoices في index (82).html — دفع حقيقي عبر ميسّر + رفع إثبات + PDF حقيقي (Browsershot)

const InvRow: React.FC<{ v: Invoice }> = ({ v }) => {
  const toast = useToast();
  const fileRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [paying, setPaying] = useState(false);

  const pay = () => {
    setPaying(true);
    router.post(`/invoices/${encodeURIComponent(v.no)}/checkout`, {}, {
      preserveScroll: true,
      onError: (err) => toast((Object.values(err)[0] as string) || 'تعذّر بدء الدفع، حاول بعد قليل'),
      onFinish: () => setPaying(false),
    });
  };

  const onPick = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
      toast('حجم الملف يتجاوز الحدّ المسموح (2 ميجابايت)');
      if (fileRef.current) fileRef.current.value = '';
      return;
    }
    setBusy(true);
    router.post(`/invoices/${encodeURIComponent(v.no)}/proof`, { file }, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => toast('تم استلام إثبات التحويل وسيُراجَع'),
      onError: (err) => toast((Object.values(err)[0] as string) || 'تعذّر رفع الإثبات'),
      onFinish: () => { setBusy(false); if (fileRef.current) fileRef.current.value = ''; },
    });
  };

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
          <a className="btn soft sm" href={`/invoices/${encodeURIComponent(v.no)}/pdf`}>
            <Icon name="download" /> الفاتورة PDF
          </a>
        ) : v.hasProof ? (
          <span className="action-hint" style={{ margin: 0 }}><Icon name="check" /> بانتظار المراجعة</span>
        ) : (
          <>
            <button className="btn sm" type="button" onClick={pay} disabled={paying}>
              <Icon name="card" /> {paying ? 'جارٍ التحويل…' : 'ادفع عبر ميسّر'}
            </button>
            <button className="btn soft sm" type="button" onClick={() => fileRef.current?.click()} disabled={busy}>
              <Icon name="upload" /> {busy ? 'جارٍ الرفع…' : 'رفع إثبات تحويل بديل'}
            </button>
            <input ref={fileRef} type="file" hidden onChange={onPick} />
          </>
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
