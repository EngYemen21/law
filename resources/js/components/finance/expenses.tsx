import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { todayISO } from '@/lib/local-date';

/**
 * **المصروفات — نموذج التسجيل وجدول العرض** (المرحلة ب من خطّة النظام الماليّ).
 * مشتركان بين تبويب «المصروفات» في المالية (الإدارة: تعتمد وترفض وتلغي) وصفحة الموظّف
 * `/employee/expenses` (يسجّل ويرى ما سجّله). القواعد كلّها في الخادم (`Finance\Expenses`)،
 * والأزرار من `can` الذي يحسبه الخادم — لا شرطَ حالةٍ في المتصفّح.
 */

export interface ExpenseOpt { value: string; label: string }

export interface ExpenseRow {
  id: number;
  voucherNo: string | null;
  date: string;
  category: string;
  description: string;
  vendor: string | null;
  amount: number;
  vat: number;
  paidFrom: string;
  reference: string | null;
  status: string;
  tone: string;
  creator: string;
  approver: string | null;
  reason: string | null;
  hasDocument: boolean;
  can: ('approve' | 'reject' | 'void')[];
}

export const sar = (n: number): string =>
  `${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ر.س`;


/** نموذج مصروفٍ جديد — `action` مسار الحفظ (`/admin/expenses` أو `/employee/expenses`). */
export const ExpenseForm: React.FC<{
  action: string;
  categories: ExpenseOpt[];
  paidFrom: ExpenseOpt[];
  submitLabel: string;
  onDone?: () => void;
}> = ({ action, categories, paidFrom, submitLabel, onDone }) => {
  const toast = useToast();
  const blank = { spent_on: todayISO(), category: '', description: '', amount: '', vat: '', vendor: '', paid_from: paidFrom[0]?.value ?? '', reference: '' };
  const [f, setF] = useState(blank);
  const [file, setFile] = useState<File | null>(null);
  // حقل الملفّ لا يُفرَّغ بالحالة وحدها (قيمته في المتصفّح) — مفتاحٌ جديد يعيد إنشاءه بعد الحفظ
  const [fileKey, setFileKey] = useState(0);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof blank) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setF({ ...f, [k]: e.target.value });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();

    if (busy) {
      return;
    }

    setBusy(true);
    router.post(action, { ...f, document: file }, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        setF(blank);
        setFile(null);
        setFileKey((k) => k + 1);
        setErrors({});
        onDone?.();
      },
      onError: (errs) => {
        setErrors(errs as Record<string, string>);
        toast('تعذّر حفظ المصروف — راجع الحقول', 'error');
      },
      onFinish: () => setBusy(false),
    });
  };

  const err = (k: string) => (errors[k] ? <div className="sub" style={{ color: '#C0392B', marginTop: 4 }}>{errors[k]}</div> : null);

  return (
    <form onSubmit={submit}>
      <div className="filter-selects" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 200px), 1fr))', gap: 12 }}>
        <div className="field" style={{ margin: 0 }}>
          <label>تاريخ الصرف</label>
          <input className="input" type="date" max={todayISO()} value={f.spent_on} onChange={set('spent_on')} required />
          {err('spent_on')}
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label>التصنيف</label>
          <select className="input" value={f.category} onChange={set('category')} required>
            <option value="">— اختر التصنيف —</option>
            {categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
          </select>
          {err('category')}
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label>المبلغ الإجماليّ (ر.س)</label>
          <input className="input" type="number" min="0.01" step="0.01" inputMode="decimal" value={f.amount} onChange={set('amount')} required />
          {err('amount')}
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label>منها ضريبة القيمة المضافة (اختياري)</label>
          <input className="input" type="number" min="0" step="0.01" inputMode="decimal" value={f.vat} onChange={set('vat')} />
          {err('vat')}
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label>جهة الدفع</label>
          <select className="input" value={f.paid_from} onChange={set('paid_from')} required>
            {paidFrom.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
          </select>
          {err('paid_from')}
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label>المورّد / المستفيد (اختياري)</label>
          <input className="input" maxLength={150} value={f.vendor} onChange={set('vendor')} />
          {err('vendor')}
        </div>
      </div>
      <div className="field" style={{ marginTop: 12 }}>
        <label>البيان</label>
        <input className="input" maxLength={300} value={f.description} onChange={set('description')} placeholder="مثال: إيجار المكتب لشهر أكتوبر" required />
        {err('description')}
      </div>
      <div className="filter-selects" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 200px), 1fr))', gap: 12 }}>
        <div className="field" style={{ margin: 0 }}>
          <label>مرجع التحويل أو الشيك (اختياري)</label>
          <input className="input" maxLength={100} value={f.reference} onChange={set('reference')} />
          {err('reference')}
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label>فاتورة المورّد أو الإيصال (PDF أو صورة)</label>
          <input key={fileKey} className="input" type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          {err('document')}
        </div>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 14 }}>
        <button className="btn" type="submit" disabled={busy}><Icon name="check" /> {busy ? 'جارٍ الحفظ…' : submitLabel}</button>
      </div>
    </form>
  );
};

/** جدول المصروفات — `actions` لأزرار الإدارة، و`documentHref`/`voucherHref` لروابط الدور. */
export const ExpensesTable: React.FC<{
  rows: ExpenseRow[];
  documentHref: (r: ExpenseRow) => string;
  voucherHref?: (r: ExpenseRow) => string;
  actions?: (r: ExpenseRow) => React.ReactNode;
}> = ({ rows, documentHref, voucherHref, actions }) => (
  <div className="t-wrap">
    <table className="tbl">
      <thead>
        <tr>
          <th>سند الصرف</th><th>التاريخ</th><th>التصنيف</th><th>البيان</th><th className="n">المبلغ</th><th>الحالة</th><th>سجّله</th><th />
        </tr>
      </thead>
      <tbody>
        {rows.map((r) => (
          <tr key={r.id}>
            <td className="mono">{r.voucherNo ?? '—'}</td>
            <td>{r.date}</td>
            <td>{r.category}</td>
            <td>
              {r.description}
              <div className="sub">{[r.vendor, r.paidFrom, r.reference].filter(Boolean).join(' · ')}</div>
            </td>
            <td className="n">
              {sar(r.amount)}
              {r.vat > 0 && <div className="sub">ضريبة {sar(r.vat)}</div>}
            </td>
            <td>
              <Badge text={r.status} tone={r.tone} />
              {r.reason && <div className="sub">{r.reason}</div>}
            </td>
            <td className="muted">{r.creator}{r.approver && <div className="sub">اعتمده {r.approver}</div>}</td>
            <td>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {voucherHref && r.voucherNo && (
                  <a className="btn soft sm" href={voucherHref(r)} download><Icon name="download" /> سند الصرف</a>
                )}
                {r.hasDocument && (
                  <a className="btn soft sm" href={documentHref(r)} download><Icon name="doc" /> المرفق</a>
                )}
                {actions?.(r)}
              </div>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  </div>
);
