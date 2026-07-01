import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import { SUM_FLOW, type SummaryData, sumStage } from '@/lib/lawyer-data';

// إشراف الإدارة على اعتماد الملخصات + الاعتماد النهائي للنتيجة — بيانات حقيقية من الخادم

interface Props { summaries: (SummaryData & { type?: string; client?: string })[]; }

const AdminSummaries: React.FC<Props> = ({ summaries }) => {
  const toast = useToast();

  const approveResult = (ref: string) =>
    router.post(`/admin/tickets/${encodeURIComponent(ref)}/result`, {}, {
      onSuccess: () => toast('تم الاعتماد النهائي وإرسال النتيجة للعميل'),
    });

  return (
    <div className="card">
      <div className="card-h">
        <h3>اعتماد الملخصات والنتائج</h3>
        <span className="sub">المسار: الفريق القانوني ← المستشار ← الإدارة ← العميل</span>
      </div>
      <div className="card-b">
        {summaries.length ? summaries.map((s) => (
          <div key={s.ref} className="item">
            <div className="iico"><Icon name="doc" /></div>
            <div className="imeta">
              <b>ملخص ملف — {s.ref}{s.type ? ` · ${s.type}` : ''}</b>
              <span><FlowLine steps={SUM_FLOW} cur={sumStage(s.status)} /></span>
            </div>
            <div className="iact">
              {s.resultStatus === 'pending_admin' ? (
                <button className="btn sm" onClick={() => approveResult(s.ref!)} type="button">
                  <Icon name="check" /> اعتماد نهائي وإرسال النتيجة
                </button>
              ) : s.resultStatus === 'approved' ? (
                <Badge text="مكتملة — أُرسلت النتيجة" tone="b-green" />
              ) : (
                <Badge text={s.approved ? 'الملخص معتمد' : 'بانتظار المستشار'} tone={s.approved ? 'b-cyan' : 'b-amber'} />
              )}
            </div>
          </div>
        )) : (
          <div className="empty"><Icon name="doc" /><b>لا ملخصات بعد</b></div>
        )}
      </div>
    </div>
  );
};

export default AdminSummaries;
