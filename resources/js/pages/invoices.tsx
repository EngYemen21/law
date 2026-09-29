import { Link, router } from '@inertiajs/react';
import React, { useRef, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import StatRow from '@/components/babylon/StatRow';
import type {StatItem} from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import type {Invoice} from '@/lib/data';
import Icon from '@/lib/icons';
import { useServerAction } from '@/lib/use-server-action';

// يطابق viewInvoices في index (82).html — دفع حقيقي عبر ميسّر + رفع إثبات + PDF حقيقي (Browsershot)

const InvRow: React.FC<{ v: Invoice }> = ({ v }) => {
  const toast = useToast();
  const fileRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  // قفلٌ موحّد: نقرتان على «ادفع» لا تفتحان جلستَي دفع
  const payment = useServerAction();
  const paying = payment.busy;

  const pay = () => {
    void payment.run(`/invoices/${encodeURIComponent(v.no)}/checkout`, { fallback: 'تعذّر بدء الدفع، حاول بعد قليل' });
  };

  const onPick = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];

    if (!file) {
return;
}

    if (file.size > 2 * 1024 * 1024) {
      toast('حجم الملف يتجاوز الحدّ المسموح (2 ميجابايت)');

      if (fileRef.current) {
fileRef.current.value = '';
}

      return;
    }

    setBusy(true);
    router.post(`/invoices/${encodeURIComponent(v.no)}/proof`, { file }, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => toast('تم استلام إثبات التحويل وسيُراجَع'),
      onError: (err) => toast((Object.values(err)[0] as string) || 'تعذّر رفع الإثبات'),
      onFinish: () => {
 setBusy(false);

 if (fileRef.current) {
fileRef.current.value = '';
} 
},
    });
  };

  return (
    <div className="item">
      <div className="item-top">
        <div className={`iico ${v.paid ? '' : 'pay-ico'}`}><Icon name="card" /></div>
        <div className="imeta">
          <b>{v.desc}</b>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap', marginTop: 4 }}>
            <span className="mono" style={{ direction: 'ltr', fontWeight: 600, fontSize: 12 }}>{v.no}</span>
            <span style={{ color: 'var(--muted)', fontSize: 12 }}>· {v.due}</span>
          </div>
        </div>
        <div className="item-price-tag" style={{ marginInlineStart: 'auto', textAlign: 'end' }}>
          <span style={{ fontWeight: 800, color: 'var(--deep)', fontSize: 15, display: 'block' }}>
            {v.amount.toLocaleString()} ر.س
          </span>
        </div>
      </div>
      <div className="iact">
        <Badge text={v.status} tone={v.tone} />
        {/* PDF لكل فاتورة: كان محجوباً عن المستحقّة وهي الأحوج إليه (تحويل بنكي) والخادم يخدمها */}
        <a className="btn soft sm" href={`/invoices/${encodeURIComponent(v.no)}/pdf`} download>
          <Icon name="download" /> الفاتورة PDF
        </a>
        {v.paid ? (
          v.hasReceipt ? (
            <a className="btn soft sm" href={`/invoices/${encodeURIComponent(v.no)}/receipt`} download>
              <Icon name="download" /> سند القبض
            </a>
          ) : null
        ) : v.cancelled ? (
          // لا دفع ولا إثبات لملغاة — كان الزرّان ظاهرين والخادم يرفض الدفع ويقبل الإثبات فيُحيي الإلغاء
          <span className="action-hint" style={{ margin: 0 }}>أُلغيت ولا تُسدَّد — ادفع الفاتورة المحدَّثة</span>
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
  // **الذمّة من تعريف الخادم لا من `!paid`.** الملغاة والمعدومة غير مدفوعتين وليستا ديناً:
  // كان العدّاد يجمعها فيُطالَب العميل بما أُلغي (٢٤٬٠٣٥ بدل ١٧٬٥١٩)، بينما شاشة الإدارة
  // تعرض الصحيح. و`receivable` يأتي من `RevenueSnapshot::isReceivable` — المصدر نفسه.
  const owed = invoices.filter((v) => v.receivable ?? !v.paid);
  // والقائمة تبقى تعرض غير المدفوعة كلّها (ومنها الملغاة بشارتها) — العدّاد وحده هو ما يُصحَّح
  const due = invoices.filter((v) => !v.paid);
  const paid = invoices.filter((v) => v.paid);
  const dueSum = owed.reduce((a, v) => a + v.amount, 0);
  const paidSum = paid.reduce((a, v) => a + v.amount, 0);

  const stats: StatItem[] = [
    ['t-amber', 'card', owed.length, 'فواتير مستحقة'],
    ['t-blue', 'card', dueSum.toLocaleString(), 'إجمالي المستحق (ر.س)'],
    ['t-green', 'check', paid.length, 'فواتير مدفوعة'],
    ['t-cyan', 'card', paidSum.toLocaleString(), 'إجمالي المدفوع (ر.س)'],
  ];

  return (
    <>
      <StatRow items={stats} />

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
        <Link href="/statement" className="btn sm soft"><Icon name="doc" /> كشف الحساب</Link>
      </div>

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
          {paid.length
            ? paid.map((v) => <InvRow key={v.no} v={v} />)
            : <div className="empty"><Icon name="card" /><b>لا فواتير مدفوعة بعد</b></div>}
        </div>
      </div>
    </>
  );
};

export default Invoices;
