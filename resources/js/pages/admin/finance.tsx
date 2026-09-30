import { Link, router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { usePrompt } from '@/components/babylon/ConfirmDialog';
import Modal from '@/components/babylon/Modal';
import Pagination from '@/components/babylon/Pagination';
import type { Paginated } from '@/components/babylon/Pagination';
import { useToast } from '@/components/babylon/Toast';
import { ExpenseForm, ExpensesTable, sar } from '@/components/finance/expenses';
import type { ExpenseOpt, ExpenseRow } from '@/components/finance/expenses';
import Icon from '@/lib/icons';
import { useServerAction } from '@/lib/use-server-action';

/** طرق التحصيل اليدويّ — تُطبع على سند القبض (`Payment::MANUAL_METHODS`). */
const MANUAL_METHODS = [
  { value: 'bank_transfer', label: 'تحويل بنكيّ' },
  { value: 'cash', label: 'نقداً' },
];

/**
 * **المالية والمحاسبة — الشاشة الواحدة بتبويباتها الستّة** (م٣ من خطّة النظام الماليّ).
 *
 * تضمّ شاشة الفواتير القديمة كاملةً في تبويب «الفواتير».
 *
 * **والواجهة لا تحسب شيئاً**: كلّ رقمٍ هنا — بطاقةً كان أو شريحةَ أعمارٍ أو مجموعَ إقرار —
 * يصل محسوباً من `App\Support\Finance\FinanceBoard`. حسابٌ في المتصفّح يعني مصدراً ثانياً
 * للحقيقة يفترق عن الخادم عند أوّل ترقيم صفحة — وهو بعينه العطل الذي وُلدت منه هذه الخطّة.
 *
 * **والتبويب والفترة والمرشّحات كلُّها في العنوان (`?tab=…&period=…`)**: قابلةٌ للحفظ
 * والمشاركة، وتبقى صحيحةً مع الترقيم.
 */

// المقبوضات تصل بكسر الهللة (`FinanceBoard::riyals`) — منزلتان على الأكثر، وبلا أصفارٍ زائدة للصحيح
const fmt = (n: number) => n.toLocaleString('en-US', { maximumFractionDigits: 2 }) + ' ر.س';

interface Opt { k: string; label: string }
interface StatusOpt extends Opt { tone: string }
interface Bucket { k: string; label: string; from: number; to: number | null }
interface Period { key: string; label: string; from: string; to: string }

interface Inv {
  no: string; client: string; desc: string; amount: number; status: string; tone: string;
  due: string | null; overdue: boolean; paid: boolean; cancelled: boolean; hasProof: boolean;
  kind: string; subtotal: number; vat: number; vatRate: number;
  issuedAt: string | null; paidAt: string | null; writtenOffReason: string | null;
  can: string[];
}

interface Debtor {
  id: number; client: string; b1: number; b2: number; b3: number; b4: number; notYetDue: number;
  overdue: number; overdueCount: number; total: number; count: number; lastReminder: string | null;
}

interface Receipt { id: number; receiptNo: string | null; at: string | null; method: string; invoice: string; client: string; amount: number; actor: string }
interface MonthRow { m: string; label: string; subtotal: number; vat: number; total: number; count: number }

interface Props {
  tab: string;
  tabs: Opt[];
  periods: Opt[];
  period: Period;
  status: string;
  kind: string;
  /** فواتير قضيّةٍ واحدة بكلّ فتراتها (`?case=`) — يفتحها «فتح في المالية» من «أتعاب القضايا» */
  caseFilter: string | null;
  statuses: StatusOpt[];
  kinds: Opt[];
  buckets: Bucket[];
  dashboard: null | {
    issued: number; collected: number; vat: number; collectedCount: number;
    receivables: number; receivablesCount: number; overdue: number; overdueCount: number; topDebtors: Debtor[];
  };
  invoices: null | Paginated<Inv>;
  receipts: null | { rows: Paginated<Receipt>; total: number };
  expenses: null | {
    rows: Paginated<ExpenseRow>;
    summary: { approved: number; approvedVat: number; pending: number };
    status: string; category: string;
    statuses: ExpenseOpt[]; categories: ExpenseOpt[]; paidFrom: ExpenseOpt[];
  };
  aging: null | { rows: Debtor[] };
  vat: null | { total: number; vat: number; subtotal: number; count: number; months: MonthRow[]; invoices: Paginated<Inv> };
}

/** التبويبات التي تحتمل الفترة — الذمم والأعمار رصيدُ اللحظة لا تدفّقُ فترة، والتقارير روابط. */
const PERIOD_TABS = ['dashboard', 'invoices', 'receipts', 'expenses', 'vat'];

const AdminFinance: React.FC<Props> = ({
  tab, tabs, periods, period, status, kind, caseFilter, statuses, kinds, buckets,
  dashboard, invoices, receipts, expenses, aging, vat,
}) => {
  const toast = useToast();
  const askFor = usePrompt();
  const [reasonFor, setReasonFor] = useState<{ no: string; action: 'cancel' | 'write-off' } | null>(null);
  const [reason, setReason] = useState('');
  const [from, setFrom] = useState(period.from);
  const [to, setTo] = useState(period.to);

  /** كلّ تنقّلٍ في هذه الشاشة يمرّ من هنا: العنوان هو الحالة، فلا حالةٌ محلّيّة تكذب مع الترقيم. */
  const go = (patch: Record<string, string | null>) => {
    const q: Record<string, string> = { tab, period: period.key, status, kind };

    if (caseFilter) {
      q.case = caseFilter;
    }

    if (period.key === 'custom') {
      q.from = period.from;
      q.to = period.to;
    }

    Object.entries(patch).forEach(([k, v]) => {
      if (v === null) {
        delete q[k];
      } else {
        q[k] = v;
      }
    });
    router.get('/admin/finance', q, { preserveScroll: true, preserveState: false });
  };

  /**
   * نجاحُ كلّ إجراءٍ يُعلنه الخادم برسالته (`flash`) ويعرضها `AppLayout` — تنبيهٌ محلّيّ فوقه كان
   * يُكرّره. والرفض (حارس الانتقال ٤٢٢ · «محصّلة مسبقاً») يبلغ `onError` برسالته نفسها؛ كان صامتاً.
   */
  // قفلٌ موحّد (`useServerAction`): النقرة الثانية على «إصدار» أو «تحصيل» لا تصل الخادم
  const { run, busyKey } = useServerAction();
  const act = (no: string, path: string, data: Record<string, string> = {}) =>
    run(`/admin/invoices/${encodeURIComponent(no)}/${path}`, {
      data, key: no, fallback: 'تعذّر تنفيذ الإجراء على الفاتورة ' + no,
    });

  /**
   * تحصيلٌ يدويّ — يُسجّل السداد ويُشعر العميل ويُحرّك ملفّه، فلا يقع بنقرةٍ عابرة (قرار المالك 2026-09-27).
   * والنافذة نفسها تسأل عن طريقة القبض لسند القبض؛ ومرفوعُ إثبات التحويل يبدأ بـ«تحويل».
   */
  const collect = async (no: string, hasProof: boolean) => {
    const method = await askFor({
      title: 'تسجيل تحصيل الفاتورة؟',
      message: 'تُعلَّم الفاتورة مدفوعةً ويُشعَر العميل ويتقدّم ملفّه ويصدر سند قبض. تأكّد من وصول المبلغ قبل التسجيل.',
      label: 'طريقة القبض',
      choices: MANUAL_METHODS,
      defaultValue: hasProof ? 'bank_transfer' : 'cash',
      confirmLabel: 'تسجيل التحصيل',
      cancelLabel: 'تراجع',
    });

    if (method) {
      act(no, 'pay', { method });
    }
  };

  // ── المصروفات: مرشّحاها في العنوان (`estatus` · `category`)، والإجراء بحكم الخادم (`r.can`) ──
  const [newExpense, setNewExpense] = useState(false);
  const goExpenses = (patch: Record<string, string>) =>
    go({ estatus: expenses?.status ?? 'all', category: expenses?.category ?? 'all', ...patch });
  const expenseAct = async (r: ExpenseRow, action: 'approve' | 'reject' | 'void') => {
    let data: Record<string, string> = {};

    if (action !== 'approve') {
      const why = await askFor({
        title: action === 'reject' ? 'رفض المصروف؟' : 'إلغاء المصروف المعتمد؟',
        message: action === 'reject'
          ? 'يُبلَغ من سجّله بالسبب، ولا يُحسب المصروف.'
          : 'يخرج من التقارير، ويبقى سند صرفه في الدفتر مطبوعاً «ملغى» بسببه.',
        label: 'السبب',
        multiline: true,
        rows: 3,
        confirmLabel: action === 'reject' ? 'تأكيد الرفض' : 'تأكيد الإلغاء',
        cancelLabel: 'تراجع',
      });

      if (!why) {
        return;
      }

      data = { reason: why.trim() };
    }

    run(`/admin/expenses/${r.id}/${action}`, { data, key: `exp-${r.id}`, fallback: 'تعذّر تنفيذ الإجراء على المصروف' });
  };

  /** رفض إثبات التحويل بعد تأكيدٍ وسببٍ يصل العميل — الخادم يقبل `reason` ويُشعره به. */
  const rejectProof = async (no: string) => {
    const why = await askFor({
      title: 'رفض إثبات التحويل؟',
      message: 'يُحذف الملفّ المرفوع وتعود الفاتورة للاستحقاق، ويُشعَر العميل بالسبب ليرفع إثباتاً صحيحاً أو يدفع إلكترونياً.',
      label: 'سبب الرفض (يصل العميل)',
      placeholder: 'مثال: المبلغ في الإيصال لا يطابق الفاتورة',
      confirmLabel: 'رفض الإثبات',
      required: false,
    });

    if (why === null) {
      return;
    }

    act(no, 'proof/reject', { reason: why.trim() });
  };

  /** فتح نافذة السبب — الإلغاء والشطب كلاهما قرارٌ يُسبَّب، والشطب سببُه إلزاميّ. */
  const askReason = (no: string, action: 'cancel' | 'write-off') => {
    setReasonFor({ no, action });
    setReason('');
  };

  const submitReason = () => {
    if (!reasonFor) {
      return;
    }

    if (reasonFor.action === 'write-off' && !reason.trim()) {
      toast('سبب شطب الدين مطلوب — لا إسقاطَ مالٍ بلا تعليل');

      return;
    }

    act(reasonFor.no, reasonFor.action, { reason: reason.trim() });
    setReasonFor(null);
    setReason('');
  };

  return (
    <>
      <div className="greet">
        <h2>المالية والمحاسبة</h2>
        <p>متابعة الفواتير الصادرة والتحصيل والذمم والضريبة — بيانات حقيقية من النظام.</p>
      </div>

      {/* التبويب خادميّ: التصفية محلياً كانت ستقتصر على الصفحة الحالية وتعطي عدّاداً كاذباً */}
      <div className="mtabs">
        {tabs.map((t) => (
          <button key={t.k} className={`mtab ${tab === t.k ? 'on' : ''}`} onClick={() => go({ tab: t.k })} type="button">{t.label}</button>
        ))}
      </div>

      {PERIOD_TABS.includes(tab) && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="card-h">
            <h3>الفترة</h3>
            <span className="sub">{period.label}</span>
          </div>
          <div className="card-b" style={{ padding: '12px 14px' }}>
            <div className="mtabs" style={{ margin: 0 }}>
              {periods.map((p) => (
                <button key={p.k} className={`mtab ${period.key === p.k ? 'on' : ''}`} onClick={() => go({ period: p.k })} type="button">{p.label}</button>
              ))}
            </div>
            {period.key === 'custom' && (
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center', marginTop: 10 }}>
                <label className="sub">من</label>
                <input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} style={{ width: 170 }} />
                <label className="sub">إلى</label>
                <input className="input" type="date" value={to} onChange={(e) => setTo(e.target.value)} style={{ width: 170 }} />
                <button className="btn sm" type="button" onClick={() => go({ period: 'custom', from, to })}><Icon name="check" /> تطبيق المدى</button>
              </div>
            )}
            {/* خ٩: الأساس نقديّ — «المحصَّل» و«الضريبة» مرجعُهما تاريخ السداد لا الإصدار */}
            <p className="sub" style={{ marginTop: 10 }}>
              أرقام التحصيل والضريبة تُحتسب بتاريخ سداد الفاتورة (الاعتراف بالإيراد عند التحصيل)، وأرقام الذمم والأعمار رصيدُ اليوم لا الفترة.
            </p>
          </div>
        </div>
      )}

      {tab === 'dashboard' && dashboard && (
        <>
          <div className="stats">
            <div className="stat t-blue"><div className="si"><Icon name="card" /></div><div className="num">{fmt(dashboard.issued)}</div><div className="lbl">المُصدَر في الفترة</div></div>
            <div className="stat t-green"><div className="si"><Icon name="check" /></div><div className="num">{fmt(dashboard.collected)}</div><div className="lbl">المحصّل في الفترة</div></div>
            <div className="stat t-amber"><div className="si"><Icon name="folder" /></div><div className="num">{fmt(dashboard.receivables)}</div><div className="lbl">المستحق (الذمم) حتى اليوم ({dashboard.receivablesCount} فاتورة)</div></div>
            <div className="stat t-red"><div className="si"><Icon name="alert" /></div><div className="num">{fmt(dashboard.overdue)}</div><div className="lbl">المتأخّر حتى اليوم ({dashboard.overdueCount} فاتورة)</div></div>
            <div className="stat t-cyan"><div className="si"><Icon name="scale" /></div><div className="num">{fmt(dashboard.vat)}</div><div className="lbl">الضريبة المستحقّة للفترة</div></div>
          </div>

          <div className="card">
            <div className="card-h"><h3>أعلى خمسة مدينين</h3><span className="sub">حتى اليوم</span></div>
            <div className="card-b">
              {dashboard.topDebtors.length ? (
                <div className="t-wrap">
                  <table className="tbl">
                    <thead><tr><th>الموكّل</th><th className="n">الذمّة</th><th className="n">منها متأخّر</th><th className="n">عدد الفواتير</th><th>آخر تذكير</th></tr></thead>
                    <tbody>
                      {dashboard.topDebtors.map((d) => (
                        <tr key={d.id}>
                          <td>{d.client}</td>
                          <td className="n">{fmt(d.total)}</td>
                          <td className="n">{fmt(d.overdue)}</td>
                          <td className="n">{d.count}</td>
                          <td className="muted">{d.lastReminder ?? 'لم يُرسل'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div className="empty"><Icon name="check" /><b>لا ذمم قائمة</b></div>
              )}
            </div>
          </div>
        </>
      )}

      {tab === 'invoices' && invoices && (
        <div className="card">
          <div className="card-h"><h3>الفواتير الصادرة</h3><span className="sub">{invoices.meta.total}</span></div>
          <div className="card-b" style={{ padding: '12px 14px' }}>
            {/* الحالات السبع كلّها ولونُ كلٍّ من مصدره (`InvoiceStatus::tone`) لا من مصفوفةٍ هنا */}
            <div className="mtabs">
              {statuses.map((s) => (
                <button key={s.k} className={`mtab ${status === s.k ? 'on' : ''}`} onClick={() => go({ status: s.k })} type="button">{s.label}</button>
              ))}
            </div>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 10, flexWrap: 'wrap' }}>
              <label className="sub">النوع</label>
              <select className="input" value={kind} onChange={(e) => go({ kind: e.target.value })} style={{ width: 170 }}>
                {kinds.map((k) => <option key={k.k} value={k.k}>{k.label}</option>)}
              </select>
              {caseFilter ? (
                <span className="chip">
                  فواتير القضية {caseFilter} — كل الفترات
                  <button type="button" className="chip-x" onClick={() => go({ case: null })} title="إزالة التصفية" aria-label="إزالة تصفية القضية">✕</button>
                </span>
              ) : (
                <span className="sub">الفترة بتاريخ إصدار الفاتورة</span>
              )}
            </div>
            {invoices.data.length ? (
              <div className="t-wrap">
                <table className="tbl">
                  <thead>
                    <tr>
                      <th>رقم الفاتورة</th><th>العميل</th><th>الوصف</th><th>النوع</th>
                      <th className="n">الأساس</th><th className="n">الضريبة</th><th className="n">المبلغ</th>
                      <th>الحالة</th><th>إجراءات</th>
                    </tr>
                  </thead>
                  <tbody>
                    {invoices.data.map((v) => (
                      <tr key={v.no}>
                        <td className="mono">{v.no}</td>
                        <td>{v.client}</td>
                        <td className="muted">{v.desc}{v.writtenOffReason ? ' — سبب الشطب: ' + v.writtenOffReason : ''}</td>
                        <td><span className="chip">{v.kind}</span></td>
                        <td className="n">{fmt(v.subtotal)}</td>
                        <td className="n">{fmt(v.vat)}</td>
                        <td className="n">{fmt(v.amount)}</td>
                        <td><Badge text={v.status} tone={v.tone} /><div className="sub">{v.paidAt ? 'سُدّدت في ' + v.paidAt : v.due}</div></td>
                        <td>
                          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                            {/* نفس نقطة PDF التي يستعملها العميل — والمستند صار فاتورةً ضريبيّة (م١) */}
                            <a className="btn soft sm" href={`/admin/invoices/${encodeURIComponent(v.no)}/pdf`} download>
                              <Icon name="download" /> الفاتورة الضريبية PDF
                            </a>
                            {/* إثبات التحويل الذي رفعه العميل — النقطة كانت موجودة بلا أي زرّ يفتحها */}
                            {v.hasProof && (
                              <a className="btn soft sm" href={`/admin/invoices/${encodeURIComponent(v.no)}/proof`} download>
                                <Icon name="doc" /> إثبات التحويل
                              </a>
                            )}
                            {/* الأزرار من `Workflow::allowed` — الحارس الذي يقبل الإجراء هو من يُظهر زرّه */}
                            {v.can.includes('invoice.issue') && (
                              <button className="btn sm" type="button" disabled={busyKey === v.no} onClick={() => act(v.no, 'issue')}><Icon name="send" /> إصدار للعميل</button>
                            )}
                            {v.can.includes('invoice.settle') && (
                              <button className="btn sm" type="button" disabled={busyKey === v.no} onClick={() => collect(v.no, v.hasProof)}><Icon name="check" /> تحصيل</button>
                            )}
                            {/* رافع الملف الخاطئ كان يفقد زرّ الدفع نهائياً — الرفض يعيد الفاتورة للاستحقاق ويُشعره */}
                            {v.hasProof && !v.paid && (
                              <button className="btn ghost sm" type="button" disabled={busyKey === v.no} onClick={() => rejectProof(v.no)}>
                                <Icon name="close" /> رفض الإثبات
                              </button>
                            )}
                            {v.can.includes('invoice.cancel') && (
                              <button className="btn ghost sm" type="button" onClick={() => askReason(v.no, 'cancel')}><Icon name="close" /> إلغاء</button>
                            )}
                            {v.can.includes('invoice.write_off') && (
                              <button className="btn ghost sm" type="button" onClick={() => askReason(v.no, 'write-off')}><Icon name="trash" /> شطب ديناً معدوماً</button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="empty"><Icon name="card" /><b>لا فواتير في هذا التصنيف</b></div>
            )}
            <Pagination meta={invoices.meta} />
          </div>
        </div>
      )}

      {tab === 'receipts' && receipts && (
        <div className="card">
          <div className="card-h"><h3>المقبوضات — دفتر ما دخل</h3><span className="sub">{fmt(receipts.total)} في الفترة</span></div>
          <div className="card-b">
            {receipts.rows.data.length ? (
              <div className="t-wrap">
                <table className="tbl">
                  <thead><tr><th>رقم السند</th><th>التاريخ</th><th>الطريقة</th><th>الفاتورة</th><th>الموكّل</th><th className="n">المبلغ</th><th>من قيَّده</th><th></th></tr></thead>
                  <tbody>
                    {receipts.rows.data.map((r) => (
                      <tr key={r.id}>
                        <td className="mono">{r.receiptNo ?? '—'}</td>
                        <td>{r.at ?? '—'}</td>
                        <td><span className="chip">{r.method}</span></td>
                        <td className="mono">{r.invoice}</td>
                        <td>{r.client}</td>
                        <td className="n">{fmt(r.amount)}</td>
                        <td className="muted">{r.actor}</td>
                        <td>
                          {r.receiptNo && (
                            <a className="btn soft sm" href={`/admin/receipts/${r.id}/pdf`} download>
                              <Icon name="download" /> سند القبض
                            </a>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="empty"><Icon name="card" /><b>لا مقبوضات في هذه الفترة</b></div>
            )}
            <Pagination meta={receipts.rows.meta} />
          </div>
        </div>
      )}

      {tab === 'expenses' && expenses && (
        <>
          <div className="stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 200px), 1fr))' }}>
            <div className="stat t-red">
              <div className="si"><Icon name="card" /></div>
              <div className="num" style={{ fontSize: 19 }}>{sar(expenses.summary.approved)}</div>
              <div className="lbl">المصروف المعتمد في الفترة</div>
            </div>
            <div className="stat t-blue">
              <div className="si"><Icon name="scale" /></div>
              <div className="num" style={{ fontSize: 19 }}>{sar(expenses.summary.approvedVat)}</div>
              <div className="lbl">منها ضريبة مشتريات</div>
            </div>
            <div className="stat t-amber" onClick={() => goExpenses({ estatus: 'pending' })} title="عرض ما ينتظر الاعتماد">
              <div className="si"><Icon name="clock" /></div>
              <div className="num" style={{ fontSize: 19 }}>{expenses.summary.pending}</div>
              <div className="lbl">بانتظار الاعتماد</div>
            </div>
          </div>

          <div className="card">
            <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
              <div><h3>المصروفات</h3><span className="sub">الرواتب ومستحقّات الموظّفين من «المستحقّات والصرف»، لا من هنا</span></div>
              <button className="btn sm" type="button" onClick={() => setNewExpense(true)}><Icon name="plus" /> مصروف جديد</button>
            </div>
            <div className="card-b">
              <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center', marginBottom: 12 }}>
                <div className="mtabs filter-pills" style={{ margin: 0 }}>
                  {[{ value: 'all', label: 'الكل' }, ...expenses.statuses].map((o) => (
                    <button key={o.value} className={`mtab ${expenses.status === o.value ? 'on' : ''}`} type="button" onClick={() => goExpenses({ estatus: o.value })}>{o.label}</button>
                  ))}
                </div>
                <select className="input" value={expenses.category} onChange={(e) => goExpenses({ category: e.target.value })} style={{ width: 220 }} aria-label="التصنيف">
                  <option value="all">كلّ التصنيفات</option>
                  {expenses.categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                </select>
              </div>
              {expenses.rows.data.length ? (
                <ExpensesTable
                  rows={expenses.rows.data}
                  documentHref={(r) => `/admin/expenses/${r.id}/document`}
                  voucherHref={(r) => `/admin/expenses/${r.id}/voucher.pdf`}
                  actions={(r) => (
                    <>
                      {r.can.includes('approve') && (
                        <button className="btn sm" type="button" disabled={busyKey === `exp-${r.id}`} onClick={() => expenseAct(r, 'approve')}><Icon name="check" /> اعتماد</button>
                      )}
                      {r.can.includes('reject') && (
                        <button className="btn soft sm" type="button" disabled={busyKey === `exp-${r.id}`} onClick={() => expenseAct(r, 'reject')}><Icon name="close" /> رفض</button>
                      )}
                      {r.can.includes('void') && (
                        <button className="btn soft sm" type="button" disabled={busyKey === `exp-${r.id}`} onClick={() => expenseAct(r, 'void')}><Icon name="close" /> إلغاء</button>
                      )}
                    </>
                  )}
                />
              ) : (
                <div className="empty"><Icon name="card" /><b>لا مصروفات بهذه التصفية</b></div>
              )}
              <Pagination meta={expenses.rows.meta} />
            </div>
          </div>

          <Modal title="مصروف جديد" subtitle="ما تسجّله الإدارة معتمدٌ فوراً ويصدر له سند صرف." open={newExpense} onClose={() => setNewExpense(false)} maxWidth={720}>
            <ExpenseForm action="/admin/expenses" categories={expenses.categories} paidFrom={expenses.paidFrom} submitLabel="تسجيل واعتماد" onDone={() => setNewExpense(false)} />
          </Modal>
        </>
      )}

      {tab === 'aging' && aging && (
        <div className="card">
          <div className="card-h"><h3>الذمم وأعمارها</h3><span className="sub">رصيد اليوم — {aging.rows.length} موكّلاً</span></div>
          <div className="card-b">
            {aging.rows.length ? (
              <div className="t-wrap">
                <table className="tbl">
                  <thead>
                    <tr>
                      <th>الموكّل</th>
                      {buckets.map((b) => <th key={b.k} className="n">{b.label}</th>)}
                      <th className="n">لم يحلّ أجلها</th>
                      <th className="n">الإجمالي</th>
                      <th>آخر تذكير</th>
                    </tr>
                  </thead>
                  <tbody>
                    {aging.rows.map((d) => (
                      <tr key={d.id}>
                        <td>{d.id > 0 ? <Link href={`/admin/clients/${d.id}/statement`}>{d.client}</Link> : d.client}</td>
                        {buckets.map((b) => <td key={b.k} className="n">{fmt(d[b.k as 'b1' | 'b2' | 'b3' | 'b4'])}</td>)}
                        <td className="n">{fmt(d.notYetDue)}</td>
                        <td className="n">{fmt(d.total)}</td>
                        <td className="muted">{d.lastReminder ?? 'لم يُرسل'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="empty"><Icon name="check" /><b>لا ذمم قائمة</b></div>
            )}
            {/* يوم الاستحقاق نفسه ليس تأخّراً — الشرط نفسه في `Invoice::isOverdue` فلا يفترق جدولان */}
            <p className="sub" style={{ marginTop: 10 }}>العمر يُحتسب من اليوم التالي لتاريخ الاستحقاق؛ والملغاة والمعدومة خارج الذمم.</p>
          </div>
        </div>
      )}

      {tab === 'vat' && vat && (
        <>
          <div className="stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 200px), 1fr))' }}>
            <div className="stat t-blue"><div className="si"><Icon name="card" /></div><div className="num">{fmt(vat.subtotal)}</div><div className="lbl">الأساس المحصَّل في الفترة</div></div>
            <div className="stat t-cyan"><div className="si"><Icon name="scale" /></div><div className="num">{fmt(vat.vat)}</div><div className="lbl">الضريبة المستحقّة</div></div>
            <div className="stat t-green"><div className="si"><Icon name="check" /></div><div className="num">{fmt(vat.total)}</div><div className="lbl">الإجمالي ({vat.count} فاتورة)</div></div>
          </div>

          <div className="card">
            <div className="card-h"><h3>الإقرار شهراً بشهر</h3><span className="sub">{vat.months.length}</span></div>
            <div className="card-b">
              {vat.months.length ? (
                <div className="t-wrap">
                  <table className="tbl">
                    <thead><tr><th>الشهر</th><th className="n">الأساس</th><th className="n">الضريبة</th><th className="n">الإجمالي</th><th className="n">عدد الفواتير</th></tr></thead>
                    <tbody>
                      {vat.months.map((m) => (
                        <tr key={m.m}>
                          <td>{m.label}</td>
                          <td className="n">{fmt(m.subtotal)}</td>
                          <td className="n">{fmt(m.vat)}</td>
                          <td className="n">{fmt(m.total)}</td>
                          <td className="n">{m.count}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div className="empty"><Icon name="scale" /><b>لا تحصيل في هذه الفترة</b></div>
              )}
            </div>
          </div>

          <div className="card">
            <div className="card-h"><h3>الفواتير المكوِّنة للإقرار</h3><span className="sub">{vat.invoices.meta.total}</span></div>
            <div className="card-b">
              {vat.invoices.data.length ? (
                <div className="t-wrap">
                  <table className="tbl">
                    <thead><tr><th>رقم الفاتورة</th><th>العميل</th><th>تاريخ السداد</th><th className="n">الأساس</th><th className="n">النسبة</th><th className="n">الضريبة</th><th className="n">الإجمالي</th></tr></thead>
                    <tbody>
                      {vat.invoices.data.map((v) => (
                        <tr key={v.no}>
                          <td className="mono">{v.no}</td>
                          <td>{v.client}</td>
                          <td>{v.paidAt ?? '—'}</td>
                          <td className="n">{fmt(v.subtotal)}</td>
                          <td className="n">{v.vatRate}%</td>
                          <td className="n">{fmt(v.vat)}</td>
                          <td className="n">{fmt(v.amount)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div className="empty"><Icon name="doc" /><b>لا فواتير محصَّلة في هذه الفترة</b></div>
              )}
              <Pagination meta={vat.invoices.meta} />
            </div>
          </div>
        </>
      )}

      {tab === 'reports' && (
        <div className="card">
          <div className="card-h"><h3>التقارير</h3><span className="sub">ثلاث شاشات قائمة</span></div>
          <div className="card-b">
            {/* لا تكرار: التقارير تعيش في شاشتيها، وهنا نقلٌ إليهما فقط */}
            <div className="stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 200px), 1fr))', marginBottom: 0 }}>
              <div className="stat t-cyan" onClick={() => router.visit('/admin/financial-reports')} title="التقارير الماليّة">
                <div className="si"><Icon name="file" /></div>
                <div className="num" style={{ fontSize: 19 }}>الأرباح والخسائر</div>
                <div className="lbl">الإيرادات والمصروفات والربح لفترةٍ بمقارنة السابقة، وتصدير PDF وCSV</div>
                <div className="go"><Icon name="out" /></div>
              </div>
              <div className="stat t-green" onClick={() => router.visit('/admin/revenue')} title="تقرير الإيرادات">
                <div className="si"><Icon name="card" /></div>
                <div className="num" style={{ fontSize: 19 }}>الإيرادات</div>
                <div className="lbl">الدخل المحصَّل وتوزيعه على الاستشارات والقضايا والتنفيذ، والرواتب، وتصدير PDF</div>
                <div className="go"><Icon name="out" /></div>
              </div>
              <div className="stat t-blue" onClick={() => router.visit('/admin/reports')} title="التقارير التشغيلية">
                <div className="si"><Icon name="calgrid" /></div>
                <div className="num" style={{ fontSize: 19 }}>التقارير</div>
                <div className="lbl">مؤشّرات التشغيل والأداء والملفّات، وتصدير PDF</div>
                <div className="go"><Icon name="out" /></div>
              </div>
            </div>
          </div>
        </div>
      )}

      <Modal
        title={(reasonFor?.action === 'cancel' ? 'إلغاء الفاتورة ' : 'شطب دين معدوم — ') + (reasonFor?.no ?? '')}
        subtitle={reasonFor?.action === 'cancel'
          ? 'الملغاة تخرج من الصادر ومن الذمم، ولا تُحصَّل بعدها.'
          : 'إسقاط مطالبةٍ لن تُحصَّل — تخرج من الذمم ويبقى أثرها وسببها في سجل الانتقالات.'}
        open={reasonFor !== null}
        onClose={() => setReasonFor(null)}
        maxWidth={520}
      >
        <label className="sub" style={{ display: 'block', marginBottom: 6 }}>
          {reasonFor?.action === 'cancel' ? 'سبب الإلغاء (اختياري)' : 'سبب شطب الدين (إلزامي)'}
        </label>
        <textarea className="input" rows={3} maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} />
        <div style={{ display: 'flex', gap: 8, marginTop: 12, justifyContent: 'flex-end' }}>
          <button className="btn soft sm" type="button" onClick={() => setReasonFor(null)}>تراجع</button>
          <button className="btn sm" type="button" onClick={submitReason}>
            <Icon name="check" /> {reasonFor?.action === 'cancel' ? 'تأكيد الإلغاء' : 'تأكيد الشطب'}
          </button>
        </div>
      </Modal>
    </>
  );
};

export default AdminFinance;
