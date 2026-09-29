import { Link, router } from '@inertiajs/react';
import React, { useState } from 'react';
import StatRow from '@/components/babylon/StatRow';
import type { StatItem } from '@/components/babylon/StatRow';
import { sar } from '@/components/finance/expenses';
import Icon from '@/lib/icons';
import { todayISO } from '@/lib/local-date';

// كشف حساب العميل (المرحلة ج) — صفحةٌ واحدة للعميل (`/statement`) وللإدارة (`/admin/clients/{id}/statement`).
// الأرقام كلّها من `ClientStatement::build` بالهللة؛ والـPDF من المصدر نفسه فلا يختلف المطبوع عن الشاشة.

interface Row { date: string; kind: string; ref: string; description: string; debit: number; credit: number; balance: number }
interface Statement { from: string; to: string; opening: number; closing: number; debit: number; credit: number; rows: Row[] }

/** الموجب مستحقٌّ على العميل، والسالب رصيدٌ له — نظير `ClientStatement::balanceLabel`. */
const balance = (h: number): string => `${sar(Math.abs(h) / 100)}${h > 0 ? ' عليه' : h < 0 ? ' له' : ''}`;
const amount = (h: number): string => (h ? sar(h / 100) : '');

const StatementPage: React.FC<{
  client: { id: number; name: string };
  statement: Statement;
  pdfUrl: string;
  backUrl: string | null;
}> = ({ client, statement: s, pdfUrl, backUrl }) => {
  const [from, setFrom] = useState(s.from);
  const [to, setTo] = useState(s.to);
  const query = `from=${s.from}&to=${s.to}`;

  const apply = (e: React.FormEvent) => {
    e.preventDefault();
    router.get(window.location.pathname, { from, to }, { preserveScroll: true });
  };

  // جهة الرصيد في التسمية لا بجانب الرقم — البطاقة تتّسع للرقم وحده
  const side = (h: number): string => (h > 0 ? ' (عليه)' : h < 0 ? ' (له)' : '');
  const stats: StatItem[] = [
    ['t-blue', 'card', sar(Math.abs(s.opening) / 100), `الرصيد الافتتاحيّ${side(s.opening)}`],
    ['t-amber', 'card', sar(s.debit / 100), 'إجمالي ما عليه'],
    ['t-green', 'check', sar(s.credit / 100), 'إجمالي ما له'],
    [s.closing > 0 ? 't-red' : 't-cyan', 'card', sar(Math.abs(s.closing) / 100), `الرصيد الختاميّ${side(s.closing)}`],
  ];

  return (
    <>
      <div style={{ marginBottom: 14, display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10 }}>
        {backUrl ? (
          <Link href={backUrl} className="btn sm soft"><Icon name="reply" /> العودة لملف {client.name}</Link>
        ) : (
          <Link href="/invoices" className="btn sm soft"><Icon name="card" /> الفواتير</Link>
        )}
        <a className="btn sm" href={`${pdfUrl}?${query}`} download><Icon name="download" /> تنزيل الكشف PDF</a>
      </div>

      <form className="card" onSubmit={apply}>
        <div className="card-b" style={{ display: 'flex', gap: 10, alignItems: 'end', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 150px', margin: 0 }}>
            <label>من</label>
            <input className="input" type="date" value={from} max={to || todayISO()} onChange={(e) => setFrom(e.target.value)} required />
          </div>
          <div className="field" style={{ flex: '1 1 150px', margin: 0 }}>
            <label>إلى</label>
            <input className="input" type="date" value={to} min={from} max={todayISO()} onChange={(e) => setTo(e.target.value)} required />
          </div>
          <button type="submit" className="btn">عرض</button>
        </div>
      </form>

      <StatRow items={stats} />

      <div className="card">
        <div className="card-h">
          <h3>حركات الحساب — {client.name}</h3>
          <span className="sub">من {s.from} إلى {s.to} · {s.rows.length} حركة</span>
        </div>
        <div className="card-b">
          <div className="t-wrap">
            <table className="tbl soa-tbl">
              <thead>
                <tr><th>التاريخ</th><th>البيان</th><th>المرجع</th><th className="n">عليه</th><th className="n">له</th><th className="n">الرصيد</th></tr>
              </thead>
              <tbody>
                <tr>
                  <td />
                  <td><b>رصيد افتتاحيّ</b></td>
                  <td />
                  <td className="n" />
                  <td className="n" />
                  <td className="n"><b>{balance(s.opening)}</b></td>
                </tr>
                {s.rows.map((r, i) => (
                  <tr key={i}>
                    <td className="mono">{r.date}</td>
                    <td><b>{r.kind}</b><div className="sub">{r.description}</div></td>
                    <td className="mono">{r.ref}</td>
                    <td className="n">{amount(r.debit)}</td>
                    <td className="n">{amount(r.credit)}</td>
                    <td className="n">{balance(r.balance)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr>
                  <td />
                  <td><b>الإجمالي</b></td>
                  <td />
                  <td className="n"><b>{sar(s.debit / 100)}</b></td>
                  <td className="n"><b>{sar(s.credit / 100)}</b></td>
                  <td className="n"><b>{balance(s.closing)}</b></td>
                </tr>
              </tfoot>
            </table>
          </div>
          {!s.rows.length && <div className="empty"><Icon name="check" /><b>لا حركات في هذه الفترة</b></div>}
          <p className="sub" style={{ marginTop: 10 }}>
            «عليه» ما صدر على العميل من فواتير، و«له» ما سدّده أو أُلغي أو شُطب؛ والمبالغ شاملة الضريبة كما صدرت بها الفواتير.
          </p>
        </div>
      </div>
    </>
  );
};

export default StatementPage;
