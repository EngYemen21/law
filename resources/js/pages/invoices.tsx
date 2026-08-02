import { router } from '@inertiajs/react';
import React, { useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import { type Invoice } from '@/lib/data';

// يطابق viewInvoices في index (82).html — طباعة PDF + رفع إثبات حقيقيّان

// طباعة الفاتورة (طباعة عميلٍ عبر window.print — نمط printOffer)
function printInvoice(v: Invoice) {
  const rows: [string, string][] = [
    ['رقم الفاتورة', v.no],
    ['الوصف', v.desc],
    ['المبلغ', v.amount.toLocaleString() + ' ر.س'],
    ['الحالة', v.status],
    ['الاستحقاق', v.due],
  ];
  const html = `<html dir="rtl" lang="ar"><head><meta charset="utf-8"><title>${v.no}</title>
    <style>body{font-family:Tahoma,Arial,sans-serif;padding:26px;color:#16245C}h2{color:#0A2A55;margin:0 0 4px}
    .sub{color:#5B6B85;font-size:13px;margin-bottom:14px}
    .atbl{width:100%;border-collapse:collapse;font-size:13px;margin-top:6px}
    .atbl th{background:#16245C;color:#fff;padding:8px 10px;text-align:right}
    .atbl td{padding:7px 10px;border-bottom:1px solid #E2E8EE;text-align:right}
    .atbl tr:nth-child(even) td{background:#F8FAFC}
    .paid{display:inline-block;padding:3px 12px;border-radius:99px;font-weight:800;font-size:12px;background:#E6F6EF;color:#1E9D6B}
    .foot{margin-top:14px;padding:10px 14px;background:#16245C;color:#fff;border-radius:8px;font-size:12px;text-align:center}</style></head>
    <body><h2>سلاسل بابل لتقنية المعلومات — فاتورة</h2>
    <div class="sub">${v.desc} · ${v.no}</div>
    <table class="atbl"><thead><tr><th>البند</th><th>التفاصيل</th></tr></thead><tbody>
    ${rows.map((r) => `<tr><td>${r[0]}</td><td>${r[1]}</td></tr>`).join('')}
    <tr><td>حالة السداد</td><td>${v.paid ? '<span class="paid">مدفوعة</span>' : 'مستحقّة'}</td></tr>
    </tbody></table>
    <div class="foot">شكراً لتعاملكم مع سلاسل بابل · www.sb-legal.sa · 011 462 2277</div></body></html>`;
  const w = window.open('', '_blank', 'width=800,height=900');
  if (!w) return;
  w.document.write(html);
  w.document.close();
  w.focus();
  w.print();
}

const InvRow: React.FC<{ v: Invoice }> = ({ v }) => {
  const toast = useToast();
  const fileRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);

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
          <button className="btn soft sm" type="button" onClick={() => printInvoice(v)}>
            <Icon name="download" /> الفاتورة PDF
          </button>
        ) : v.hasProof ? (
          <span className="action-hint" style={{ margin: 0 }}><Icon name="check" /> بانتظار المراجعة</span>
        ) : (
          <>
            <button className="btn sm" type="button" onClick={() => fileRef.current?.click()} disabled={busy}>
              <Icon name="upload" /> {busy ? 'جارٍ الرفع…' : 'رفع إثبات التحويل'}
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
