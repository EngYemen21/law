import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';

// أتعاب القضايا المحوّلة — بيانات حقيقية من الخادم (الإدارة تحدّد الأتعاب لتفعيل القضية)

interface CaseFee { no: string; type: string; client: string; lawyer: string; status: string; tone: string; fee: number | null; lawyerFee?: number | null; lawyerPct?: number | null; feeStatus: string; }
interface Props { cases: CaseFee[]; }

const AdminCaseFees: React.FC<Props> = ({ cases }) => {
  const toast = useToast();
  const [vals, setVals] = useState<Record<string, { fee: string; pct: string }>>({});

  const set = (no: string, k: 'fee' | 'pct', v: string) =>
    setVals((p) => ({ ...p, [no]: { fee: p[no]?.fee ?? '', pct: p[no]?.pct ?? '20', [k]: v } }));

  const calcLawyer = (no: string) => {
    const fee = parseInt(vals[no]?.fee || '0', 10) || 0;
    const pct = parseInt(vals[no]?.pct || '0', 10) || 0;
    return Math.round((fee * pct) / 100);
  };

  const setFee = (no: string) => {
    const fee = parseInt(vals[no]?.fee || '0', 10) || 0;
    const lawyer_pct = parseInt(vals[no]?.pct || '0', 10) || 0;
    if (!fee) { toast('أدخل قيمة الأتعاب'); return; }
    router.post(`/admin/cases/${encodeURIComponent(no)}/fee`, { fee, lawyer_pct }, {
      preserveScroll: true,
      onSuccess: () => toast('تم اعتماد الأتعاب وإصدار الفاتورة للعميل'),
    });
  };

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>تُحدِّد <b>الإدارة العليا</b> أتعاب القضايا المحوّلة من الاستشارات. <b>لا تُفعّل القضية حتى يسدّد العميل</b> الفاتورة.</p>
      </div>
      {cases.length ? cases.map((c) => (
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
            {c.feeStatus === 'none' && c.status === 'بانتظار اعتماد الأتعاب' ? (
              <>
                <div className="picker-grid">
                  <div className="field">
                    <label>قيمة الأتعاب (ر.س)</label>
                    <input className="input" type="number" placeholder="0" value={vals[c.no]?.fee || ''} onChange={(e) => set(c.no, 'fee', e.target.value)} />
                  </div>
                  <div className="field">
                    <label>نسبة أتعاب المحامي (%)</label>
                    <input className="input" type="number" value={vals[c.no]?.pct ?? '20'} onChange={(e) => set(c.no, 'pct', e.target.value)} />
                  </div>
                </div>
                <div className="kv"><span className="k">نصيب المحامي المحتسب</span><span className="v">{calcLawyer(c.no).toLocaleString()} ر.س</span></div>
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
        <div className="card"><div className="card-b"><div className="empty"><Icon name="scale" /><b>لا قضايا بانتظار تحديد الأتعاب</b></div></div></div>
      )}
    </>
  );
};

export default AdminCaseFees;
