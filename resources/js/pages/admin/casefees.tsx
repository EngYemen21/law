import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Pagination, { type Paginated } from '@/components/babylon/Pagination';
import Badge from '@/components/babylon/Badge';
import { useConfirm } from '@/components/babylon/ConfirmDialog';
import { useToast } from '@/components/babylon/Toast';
import { useSettings } from '@/lib/settings';

// أتعاب القضايا المحوّلة — بيانات حقيقية من الخادم (الإدارة تحدّد الأتعاب لتفعيل القضية)

interface CaseFee {
  no: string; type: string; client: string; lawyer: string; status: string; tone: string;
  fee: number | null; lawyerFee?: number | null; lawyerPct?: number | null; feeStatus: string;
  /** النسبة الافتراضيّة من الخادم (`LawyerShare::defaultPctFor`): نسبة ملفّ المحامي أو الافتراض الموحّد */
  lawyerDefaultPct: number;
  /** حكم انتقال `SetFee` من الخادم — لا مقارنة بنصّ الحالة هنا */
  canSetFee: boolean;
  /** خطّة التقسيط (`fee_status = installments`) — الدفعات المسدَّدة من مجموعها */
  installmentsTotal?: number | null;
  installmentsPaid?: number | null;
}

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
interface Props { cases: Paginated<CaseFee>; }

const AdminCaseFees: React.FC<Props> = ({ cases }) => {
  const ask = useConfirm();
  const toast = useToast();
  // نسبة الضريبة المشتركة من الخادم (`Setting::vatRate`) — الفاتورة تُصدر بها، فالمعاينة بها أيضاً
  const { vat_rate: vatRate } = useSettings();
  const [vals, setVals] = useState<Record<string, { fee: string; pct: string }>>({});
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
      onError: (e) => toast(String(Object.values(e)[0] ?? 'تعذّر اعتماد الأتعاب')),
    });
  };

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>تُحدِّد <b>الإدارة العليا</b> أتعاب القضايا المحوّلة من الاستشارات. <b>لا تُفعّل القضية حتى يسدّد العميل</b> الفاتورة.</p>
      </div>
      {cases.data.length ? cases.data.map((c) => (
        <div key={c.no} className="card">
          <div className="card-h">
            <h3>{c.no}</h3>
            <Badge text={c.status} tone={c.tone} />
          </div>
          <div className="card-b" style={{ padding: 16 }}>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
              <span className="chip muted">{c.client}</span>
              <span className="chip muted">{c.type}</span>
              <span className="chip muted">{c.lawyer}</span>
            </div>
            {c.canSetFee ? (
              <>
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
              </>
            ) : c.feeStatus === 'pending_payment' ? (
              <>
                <div className="kv"><span className="k">الأتعاب</span><span className="v">{(c.fee || 0).toLocaleString()} ر.س</span></div>
                {!!c.lawyerFee && <div className="kv"><span className="k">نصيب المحامي</span><span className="v">{c.lawyerFee.toLocaleString()} ر.س ({c.lawyerPct}%)</span></div>}
                <div className="action-hint" style={{ marginTop: 10 }}>القضية مغلقة حتى يسدّد العميل الفاتورة.</div>
              </>
            ) : c.feeStatus === 'installments' ? (
              <>
                {/* خطّة التقسيط: كانت تقع على «لا إجراء مطلوب» فلا يُعرف ما سُدّد وما بقي */}
                <div className="kv"><span className="k">الأتعاب</span><span className="v">{(c.fee || 0).toLocaleString()} ر.س</span></div>
                <div className="kv">
                  <span className="k">الدفعات المسدَّدة من خطّة التقسيط</span>
                  <span className="v">{c.installmentsPaid ?? 0} من {c.installmentsTotal ?? 0}</span>
                </div>
                {!!c.lawyerFee && <div className="kv"><span className="k">نصيب المحامي</span><span className="v">{c.lawyerFee.toLocaleString()} ر.س ({c.lawyerPct}%)</span></div>}
                <div className="action-hint" style={{ marginTop: 10 }}>
                  {(c.installmentsPaid ?? 0) > 0
                    ? `القضية مفعّلة — الدفعات المتبقّية: ${Math.max(0, (c.installmentsTotal ?? 0) - (c.installmentsPaid ?? 0))}.`
                    : 'بانتظار سداد الدفعة الأولى لتفعيل القضية.'}
                </div>
              </>
            ) : c.feeStatus === 'waived' ? (
              <div className="gov-note" style={{ marginTop: 4 }}>
                <Icon name="check" />
                <div>قضية بلا أتعاب — فُعّلت دون فاتورة.</div>
              </div>
            ) : c.feeStatus === 'paid' ? (
              <div className="gov-note" style={{ marginTop: 4 }}>
                <Icon name="check" />
                <div>تم السداد — القضية مفعّلة. الأتعاب: {(c.fee || 0).toLocaleString()} ر.س{c.lawyerFee ? ` · نصيب المحامي ${c.lawyerFee.toLocaleString()} ر.س` : ''}.</div>
              </div>
            ) : (
              <div className="action-hint">لا إجراء مطلوب حالياً.</div>
            )}
          </div>
        </div>
      )) : (
        <div className="card"><div className="card-b"><div className="empty"><Icon name="scale" /><b>لا توجد قضايا</b></div></div></div>
      )}
      <Pagination meta={cases.meta} only={['cases']} />
    </>
  );
};

export default AdminCaseFees;
