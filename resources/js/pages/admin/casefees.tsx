import { Link, router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useConfirm } from '@/components/babylon/ConfirmDialog';
import Pagination from '@/components/babylon/Pagination';
import type { Paginated } from '@/components/babylon/Pagination';
import StatRow from '@/components/babylon/StatRow';
import type { StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { firstError } from '@/lib/server-message';
import { useSettings } from '@/lib/settings';

// أتعاب القضايا المحوّلة — بيانات حقيقية من الخادم (الإدارة تحدّد الأتعاب لتفعيل القضية)،
// ومالُ كلّ قضيّةٍ وفواتيرها من `Finance\CaseFeeBoard` (تطوير التبويب 2026-09-29)

interface CaseInvoice { no: string; amount: number; installment: number | null; due: string | null; status: string; tone: string; overdue: boolean }

interface CaseFee {
  no: string; type: string; client: string; lawyer: string; status: string; tone: string;
  fee: number | null; lawyerFee?: number | null; lawyerPct?: number | null; feeStatus: string;
  /** النسبة الافتراضيّة من الخادم (`LawyerShare::defaultPctFor`): نسبة ملفّ المحامي أو الافتراض الموحّد */
  lawyerDefaultPct: number;
  /** حكم انتقال `SetFee` من الخادم (`CaseFeeBoard::canSetFee`) — لا مقارنة بنصّ الحالة هنا */
  canSetFee: boolean;
  /** خطّة التقسيط (`fee_status = installments`) — الدفعات المسدَّدة من مجموعها */
  installmentsTotal?: number | null;
  installmentsPaid?: number | null;
  /** المفوتَر والمسدَّد والمتبقّي من فواتير القضيّة (`CaseFeeBoard::money`) */
  money: { invoiced: number; paid: number; remaining: number; overdue: boolean; invoices: CaseInvoice[] };
}

interface Props {
  cases: Paginated<CaseFee>;
  tab: string;
  q: string;
  tabs: { k: string; label: string }[];
  counts: Record<string, number>;
  totals: { invoiced: number; collected: number; outstanding: number; overdue: number; overdueCount: number };
}

/** تسمية حالة الأتعاب بقيمها الحيّة (`fee_status`) — وما سواها يُسمّى غير معروف ولا يُخفي المبلغ. */
const FEE_STATUS: Record<string, { label: string; tone: string }> = {
  none: { label: 'لم تُحدَّد', tone: 'b-amber' },
  pending_payment: { label: 'بانتظار السداد', tone: 'b-amber' },
  installments: { label: 'مقسّطة', tone: 'b-cyan' },
  paid: { label: 'مسدّدة', tone: 'b-green' },
  waived: { label: 'بلا أتعاب', tone: 'b-grey' },
};
const feeStatusOf = (key: string) => FEE_STATUS[key] ?? { label: 'حالة غير معروفة', tone: 'b-grey' };

const sar = (n: number | null | undefined) => `${(n ?? 0).toLocaleString()} ر.س`;

/**
 * قيمةٌ صحيحةٌ غير سالبة أو `null` — الخادم يقبل الريال الصحيح (`integer`)، و`parseInt` كان يقطع
 * «1500.75» إلى 1500 بصمتٍ فيُعتمد مبلغٌ غير المكتوب.
 */
const wholeNumber = (raw: string | undefined): number | null => {
  if (raw === undefined || raw.trim() === '') {
    return null;
  }

  const n = Number(raw);

  return Number.isInteger(n) && n >= 0 ? n : null;
};

const AdminCaseFees: React.FC<Props> = ({ cases, tab, q, tabs, counts, totals }) => {
  const ask = useConfirm();
  const toast = useToast();
  // نسبة الضريبة المشتركة من الخادم (`Setting::vatRate`) — الفاتورة تُصدر بها، فالمعاينة بها أيضاً
  const { vat_rate: vatRate } = useSettings();
  const [vals, setVals] = useState<Record<string, { fee: string; pct: string }>>({});
  const [search, setSearch] = useState(q);
  const [openNo, setOpenNo] = useState<string | null>(null);
  // النسبة المدخلة، وإلّا افتراض الخادم لهذه القضيّة — مصدرٌ واحد للحقل والمعاينة والإرسال
  const pctOf = (no: string) => vals[no]?.pct ?? String(cases.data.find((c) => c.no === no)?.lawyerDefaultPct ?? '');

  const set = (no: string, k: 'fee' | 'pct', v: string) =>
    setVals((p) => ({ ...p, [no]: { fee: p[no]?.fee ?? '', pct: pctOf(no), [k]: v } }));

  const calcLawyer = (no: string) => {
    const fee = wholeNumber(vals[no]?.fee) ?? 0;
    const pct = wholeNumber(pctOf(no)) ?? 0;

    return Math.round((fee * pct) / 100);
  };

  /** معاينة الضريبة والإجمالي بقاعدة الخادم نفسها (`Setting::vatOn` = تقريب الأساس × النسبة). */
  const preview = (no: string) => {
    const fee = wholeNumber(vals[no]?.fee) ?? 0;
    const vat = Math.round((fee * vatRate) / 100);

    return { fee, vat, total: fee + vat };
  };

  /** التبويب والبحث في العنوان — فالترقيم والتحديث والرابط المشترك يحملونهما. */
  const go = (patch: { tab?: string; q?: string }) => {
    const params: Record<string, string> = { tab: patch.tab ?? tab };
    const term = (patch.q ?? search).trim();

    if (term !== '') {
      params.q = term;
    }

    router.get('/admin/casefees', params, { preserveScroll: true });
  };

  const setFee = async (no: string) => {
    // الصفر قرارٌ صريح (قضية بلا أتعاب) لا خانةٌ فارغة — قرار المالك 2026-09-11
    const raw = vals[no]?.fee;

    if (raw === undefined || raw === '') {
      toast('أدخل قيمة الأتعاب');

      return;
    }

    const fee = wholeNumber(raw);
    const lawyer_pct = wholeNumber(pctOf(no));

    if (fee === null) {
      toast('الأتعاب بالريال الصحيح — بلا كسورٍ ولا قيمٍ سالبة', 'error');

      return;
    }

    if (lawyer_pct === null || lawyer_pct > 100) {
      toast('نسبة المحامي عددٌ صحيح بين 0 و100', 'error');

      return;
    }

    if (
      fee === 0 &&
      !(await ask({
        title: 'اعتماد أتعابٍ صفر',
        message: 'الأتعاب صفر: تُفعَّل القضية مباشرةً بلا أتعاب ولا فاتورة.',
        confirmLabel: 'اعتماد بلا أتعاب',
        tone: 'danger',
      }))
    ) {
      return;
    }

    router.post(`/admin/cases/${encodeURIComponent(no)}/fee`, { fee, lawyer_pct }, {
      preserveScroll: true,
      onSuccess: () => toast(fee === 0 ? 'فُعّلت القضية بلا أتعاب' : 'تم اعتماد الأتعاب وإصدار الفاتورة للعميل'),
      onError: (e) => toast(firstError(e, 'تعذّر اعتماد الأتعاب')),
    });
  };

  const stats: StatItem[] = [
    ['t-amber', 'scale', counts.awaiting ?? 0, 'بانتظار تحديد الأتعاب', 'awaiting'],
    ['t-blue', 'doc', sar(totals.invoiced), 'المفوتَر'],
    ['t-green', 'check', sar(totals.collected), 'المحصَّل'],
    ['t-cyan', 'clock', sar(totals.outstanding), 'المتبقّي على العملاء', 'pending_payment'],
    ['t-red', 'alert', sar(totals.overdue), `متأخّر (${totals.overdueCount} فاتورة)`],
  ];

  const awaiting = cases.data.filter((c) => c.canSetFee);
  const rest = cases.data.filter((c) => !c.canSetFee);

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>تُحدِّد <b>الإدارة العليا</b> أتعاب القضايا المحوّلة من الاستشارات. <b>لا تُفعّل القضية حتى يسدّد العميل</b> الفاتورة.</p>
      </div>

      <StatRow items={stats} onSelect={(i) => stats[i][4] && go({ tab: stats[i][4] })} />

      <div className="card">
        <div className="card-b" style={{ padding: '12px 14px' }}>
          <div className="mtabs">
            {tabs.map((t) => (
              <button key={t.k} type="button" className={`mtab ${tab === t.k ? 'on' : ''}`} onClick={() => go({ tab: t.k })}>
                {t.label} <span className="sub">({counts[t.k] ?? 0})</span>
              </button>
            ))}
          </div>
          <form
            className="cf-search"
            onSubmit={(e) => {
              e.preventDefault();
              go({});
            }}
          >
            <input className="input" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="بحث برقم القضية أو اسم العميل…" />
            <button className="btn soft sm" type="submit"><Icon name="search" /> بحث</button>
          </form>
        </div>
      </div>

      {/* ما ينتظر قرار الإدارة أوّلاً — بنموذجه كما هو */}
      {awaiting.map((c) => (
        <div key={c.no} className="card">
          <div className="card-h">
            <h3><Link href={`/admin/cases/${encodeURIComponent(c.no)}`}>{c.no}</Link></h3>
            <Badge text={c.status} tone={c.tone} />
          </div>
          <div className="card-b" style={{ padding: 16 }}>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
              <span className="chip muted">{c.client}</span>
              <span className="chip muted">{c.type}</span>
              <span className="chip muted">{c.lawyer}</span>
            </div>
            <div className="picker-grid">
              <div className="field">
                <label>قيمة الأتعاب (ر.س)</label>
                <input className="input" type="number" placeholder="0" value={vals[c.no]?.fee || ''} onChange={(e) => set(c.no, 'fee', e.target.value)} />
              </div>
              <div className="field">
                <label>نسبة أتعاب المحامي (%)</label>
                <input className="input" type="number" value={pctOf(c.no)} onChange={(e) => set(c.no, 'pct', e.target.value)} />
              </div>
            </div>
            <div className="kv"><span className="k">نصيب المحامي المحتسب</span><span className="v">{calcLawyer(c.no).toLocaleString()} ر.س</span></div>
            {/* ما سيراه العميل في فاتورته — قبل الاعتماد لا بعده */}
            <div className="kv"><span className="k">الضريبة ({vatRate}%)</span><span className="v">{preview(c.no).vat.toLocaleString()} ر.س</span></div>
            <div className="kv"><span className="k">إجمالي الفاتورة</span><span className="v"><b>{preview(c.no).total.toLocaleString()} ر.س</b></span></div>
            <button className="btn" onClick={() => setFee(c.no)} type="button">
              <Icon name="check" /> اعتماد الأتعاب وإصدار الفاتورة
            </button>
          </div>
        </div>
      ))}

      {rest.length > 0 && (
        <div className="card">
          <div className="card-h"><h3>أتعاب القضايا</h3><span className="sub">{cases.meta.total}</span></div>
          <div className="card-b t-wrap">
            <table className="tbl tbl-cards">
              <thead>
                <tr>
                  <th>القضية</th><th>العميل</th><th>المحامي</th><th>الأتعاب</th><th>المسدَّد</th><th>المتبقّي</th><th>نصيب المحامي</th><th>حالة الأتعاب</th><th></th>
                </tr>
              </thead>
              <tbody>
                {rest.map((c) => {
                  const fs = feeStatusOf(c.feeStatus);
                  const isOpen = openNo === c.no;

                  return (
                    <React.Fragment key={c.no}>
                      <tr>
                        <td className="tc-head" data-label="القضية">
                          <Link href={`/admin/cases/${encodeURIComponent(c.no)}`}><b>{c.no}</b></Link>
                          <span className="sub"> · {c.type}</span>
                        </td>
                        <td data-label="العميل">{c.client}</td>
                        <td data-label="المحامي">{c.lawyer}</td>
                        <td className="nowrap" data-label="الأتعاب">{c.fee === null ? '—' : sar(c.fee)}</td>
                        <td className="nowrap" data-label="المسدَّد">{sar(c.money.paid)}</td>
                        <td className="nowrap" data-label="المتبقّي">
                          <span className={c.money.overdue ? 'txt-overdue' : undefined}>{sar(c.money.remaining)}</span>
                          {c.money.overdue && <> <Badge text="متأخّرة" tone="b-red" /></>}
                        </td>
                        <td className="nowrap" data-label="نصيب المحامي">{c.lawyerFee ? `${sar(c.lawyerFee)} (${c.lawyerPct}%)` : '—'}</td>
                        <td data-label="حالة الأتعاب">
                          <Badge text={fs.label} tone={fs.tone} />
                          {c.feeStatus === 'installments' && <div className="sub">{c.installmentsPaid ?? 0} من {c.installmentsTotal ?? 0} دفعات</div>}
                        </td>
                        <td className="tc-actions">
                          <div>
                            <button className="btn soft sm" type="button" disabled={c.money.invoices.length === 0} onClick={() => setOpenNo(isOpen ? null : c.no)}>
                              <Icon name="doc" /> الفواتير ({c.money.invoices.length})
                            </button>
                          </div>
                        </td>
                      </tr>
                      {isOpen && (
                        <tr className="cf-invoices">
                          <td colSpan={9}>
                            <div className="cf-inv-list">
                              {c.money.invoices.map((i) => (
                                <div key={i.no} className="cf-inv">
                                  <b>{i.no}</b>
                                  <span>{i.installment ? `القسط ${i.installment}` : 'فاتورة الأتعاب'}</span>
                                  <span>{sar(i.amount)}</span>
                                  <span className="sub">الاستحقاق: {i.due ?? '—'}</span>
                                  <span>
                                    <Badge text={i.status} tone={i.tone} />
                                    {i.overdue && <> <Badge text="متأخّرة" tone="b-red" /></>}
                                  </span>
                                </div>
                              ))}
                            </div>
                            <Link href={`/admin/finance?tab=invoices&case=${encodeURIComponent(c.no)}`} className="btn soft sm">
                              <Icon name="card" /> فتح في المالية (التحصيل والإلغاء والإثبات)
                            </Link>
                          </td>
                        </tr>
                      )}
                    </React.Fragment>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {cases.data.length === 0 && (
        <div className="card"><div className="card-b"><div className="empty"><Icon name="scale" /><b>{q ? 'لا قضية تطابق البحث' : 'لا قضايا في هذا التبويب'}</b></div></div></div>
      )}
      <Pagination meta={cases.meta} only={['cases']} />
    </>
  );
};

export default AdminCaseFees;
