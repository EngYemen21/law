import { Link, router } from '@inertiajs/react';
import React, { useState } from 'react';
import { sar } from '@/components/finance/expenses';
import Icon from '@/lib/icons';

// التقارير الماليّة (المرحلة د) — الإيرادات والمصروفات والأرباح والخسائر لفترةٍ، مقارنةً بالفترة السابقة.
// الأرقام كلّها من `ProfitAndLoss::report` بالهللة، والتصدير PDF وCSV من الحمولة نفسها.

interface Opt { k: string; label: string }
interface PeriodInfo { key: string; label: string; from: string; to: string }
interface Summary { revenue: number; revenueVat: number; expenses: number; expensesVat: number; payouts: number; profit: number }
interface Line { key: string; label: string; current: number; previous: number }
interface Month { month: string; label: string; revenue: number; expenses: number; payouts: number; profit: number }
interface Report {
  period: PeriodInfo;
  previous: PeriodInfo;
  summary: { current: Summary; previous: Summary };
  revenueByKind: Line[];
  expensesByCategory: Line[];
  payoutsByKind: Line[];
  months: Month[];
  undatedPaid: number;
  pendingExpenses: number;
}

const money = (h: number): string => sar(h / 100);

/** نسبة التغيّر — نظير `ProfitAndLoss::change`: لا نسبة حين السابقة صفر. */
const change = (current: number, previous: number): string => {
  if (previous === 0) {
    return '—';
  }

  const pct = Math.round(((current - previous) / Math.abs(previous)) * 1000) / 10;

  return `${pct > 0 ? '+' : ''}${pct}%`;
};

const CompareTable: React.FC<{ title: string; rows: Line[]; empty: string }> = ({ title, rows, empty }) => (
  <div className="card">
    <div className="card-h"><h3>{title}</h3></div>
    <div className="card-b">
      {rows.length ? (
        <div className="t-wrap">
          <table className="tbl soa-tbl">
            <thead><tr><th>البند</th><th className="n">الفترة</th><th className="n">السابقة</th><th className="n">التغيّر</th></tr></thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.key}>
                  <td>{r.label}</td>
                  <td className="n">{money(r.current)}</td>
                  <td className="n muted">{money(r.previous)}</td>
                  <td className="n">{change(r.current, r.previous)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="empty"><Icon name="check" /><b>{empty}</b></div>
      )}
    </div>
  </div>
);

const FinancialReports: React.FC<{ periods: Opt[]; report: Report }> = ({ periods, report: r }) => {
  const [from, setFrom] = useState(r.period.from);
  const [to, setTo] = useState(r.period.to);
  const cur = r.summary.current;
  const prev = r.summary.previous;

  const query = new URLSearchParams(
    r.period.key === 'custom' ? { period: 'custom', from: r.period.from, to: r.period.to } : { period: r.period.key },
  ).toString();
  const go = (q: Record<string, string>) => router.get('/admin/financial-reports', q, { preserveScroll: true });

  const cards: [string, string, number, number][] = [
    ['t-green', 'صافي الإيرادات المحصَّلة', cur.revenue, prev.revenue],
    ['t-amber', 'صافي المصروفات المعتمدة', cur.expenses, prev.expenses],
    ['t-blue', 'مستحقّات الموظّفين المصروفة', cur.payouts, prev.payouts],
    [cur.profit >= 0 ? 't-cyan' : 't-red', cur.profit >= 0 ? 'صافي الربح' : 'صافي الخسارة', cur.profit, prev.profit],
  ];

  return (
    <>
      <div style={{ marginBottom: 14, display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10 }}>
        <Link href="/admin/finance" className="btn sm soft"><Icon name="reply" /> المالية والمحاسبة</Link>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <a className="btn sm soft" href={`/admin/financial-reports/csv?${query}`} download><Icon name="download" /> CSV</a>
          <a className="btn sm" href={`/admin/financial-reports/pdf?${query}`} download><Icon name="download" /> PDF</a>
        </div>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>الفترة</h3>
          <span className="sub">{r.period.label} · مقارنةً بـ {r.previous.label}</span>
        </div>
        <div className="card-b" style={{ padding: '12px 14px' }}>
          <div className="mtabs" style={{ margin: 0 }}>
            {periods.map((p) => (
              <button key={p.k} className={`mtab ${r.period.key === p.k ? 'on' : ''}`} onClick={() => go({ period: p.k })} type="button">{p.label}</button>
            ))}
          </div>
          {r.period.key === 'custom' && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center', marginTop: 10 }}>
              <label className="sub">من</label>
              <input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} style={{ width: 170 }} />
              <label className="sub">إلى</label>
              <input className="input" type="date" value={to} onChange={(e) => setTo(e.target.value)} style={{ width: 170 }} />
              <button className="btn sm" type="button" onClick={() => go({ period: 'custom', from, to })}><Icon name="check" /> تطبيق المدى</button>
            </div>
          )}
          <p className="sub" style={{ marginTop: 10 }}>
            المبالغ قبل ضريبة القيمة المضافة. الإيراد بتاريخ السداد، والمصروفات المعتمدة وحدها بتاريخ الصرف، ومستحقّات الموظّفين غير الملغاة.
          </p>
        </div>
      </div>

      {(r.undatedPaid > 0 || r.pendingExpenses > 0) && (
        <div className="fr-warn">
          {r.undatedPaid > 0 && (
            <div><Icon name="alert" /> {r.undatedPaid} فاتورة مدفوعة بلا تاريخ سداد (بيانات قديمة) لم تدخل أيّ فترة.</div>
          )}
          {r.pendingExpenses > 0 && (
            <div>
              <Icon name="alert" /> {r.pendingExpenses} مصروف بانتظار الاعتماد في هذه الفترة لم يُحسب —{' '}
              <Link href="/admin/finance?tab=expenses&estatus=pending">راجعها</Link>
            </div>
          )}
        </div>
      )}

      <div className="stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 210px), 1fr))' }}>
        {cards.map(([tone, label, c, p]) => (
          <div className={`stat ${tone}`} key={label}>
            <div className="si"><Icon name="card" /></div>
            <div className="num" style={{ fontSize: 19 }}>{money(c)}</div>
            <div className="lbl">{label}</div>
            <div className="fr-prev">السابقة {money(p)} · {change(c, p)}</div>
          </div>
        ))}
      </div>

      <CompareTable title="الإيرادات بالنوع" rows={r.revenueByKind} empty="لا إيرادات محصَّلة في الفترتين" />
      <CompareTable title="المصروفات بالتصنيف" rows={r.expensesByCategory} empty="لا مصروفات معتمدة في الفترتين" />
      <CompareTable title="مستحقّات الموظّفين بالبند" rows={r.payoutsByKind} empty="لا صرف للموظّفين في الفترتين" />

      <div className="card">
        <div className="card-h"><h3>الأشهر</h3><span className="sub">داخل الفترة</span></div>
        <div className="card-b">
          <div className="t-wrap">
            <table className="tbl soa-tbl">
              <thead><tr><th>الشهر</th><th className="n">الإيرادات</th><th className="n">المصروفات</th><th className="n">الموظّفون</th><th className="n">الربح</th></tr></thead>
              <tbody>
                {r.months.map((m) => (
                  <tr key={m.month}>
                    <td>{m.label}</td>
                    <td className="n">{money(m.revenue)}</td>
                    <td className="n">{money(m.expenses)}</td>
                    <td className="n">{money(m.payouts)}</td>
                    <td className="n"><b style={{ color: m.profit < 0 ? 'var(--red)' : undefined }}>{money(m.profit)}</b></td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr>
                  <td><b>الإجمالي</b></td>
                  <td className="n"><b>{money(cur.revenue)}</b></td>
                  <td className="n"><b>{money(cur.expenses)}</b></td>
                  <td className="n"><b>{money(cur.payouts)}</b></td>
                  <td className="n"><b>{money(cur.profit)}</b></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>ضريبة القيمة المضافة</h3><span className="sub">خارج الأرباح — للهيئة لا للمكتب</span></div>
        <div className="card-b">
          <div className="t-wrap">
            <table className="tbl soa-tbl">
              <thead><tr><th>البند</th><th className="n">الفترة</th><th className="n">السابقة</th></tr></thead>
              <tbody>
                <tr><td>ضريبة المخرجات المحصَّلة</td><td className="n">{money(cur.revenueVat)}</td><td className="n muted">{money(prev.revenueVat)}</td></tr>
                <tr><td>ضريبة المدخلات على المصروفات</td><td className="n">{money(cur.expensesVat)}</td><td className="n muted">{money(prev.expensesVat)}</td></tr>
              </tbody>
            </table>
          </div>
          <p className="sub" style={{ marginTop: 10 }}>
            للإقرار الضريبيّ بتفاصيله: <Link href="/admin/finance?tab=vat">تبويب الضريبة</Link>.
          </p>
        </div>
      </div>
    </>
  );
};

export default FinancialReports;
