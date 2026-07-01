import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import FlowLine from '@/components/babylon/FlowLine';
import { SUM_FLOW, type SummaryData, sumStage } from '@/lib/lawyer-data';

// قائمة ملخصات الملفات الواردة من الفريق القانوني — بيانات حقيقية من الخادم

interface Props { summaries: (SummaryData & { type?: string; client?: string })[]; }

const openSummary = (ref: string) =>
  router.visit(`/lawyer/summary/${encodeURIComponent(ref)}`);

const LawyerSummaries: React.FC<Props> = ({ summaries }) => {
  const pend = summaries.filter((s) => !s.approved);

  return (
    <div className="card">
      <div className="card-h">
        <h3>ملخصات بانتظار اعتمادي</h3>
        <span className="sub">{pend.length} ملخص</span>
      </div>
      <div className="card-b">
        {pend.length ? pend.map((s) => (
          <div key={s.ref} className="item">
            <div className="iico"><Icon name="doc" /></div>
            <div className="imeta">
              <b>ملخص ملف — {s.ref}{s.type ? ` · ${s.type}` : ''}</b>
              <span><FlowLine steps={SUM_FLOW} cur={sumStage(s.status)} /></span>
            </div>
            <div className="iact">
              <button className="btn sm" onClick={() => openSummary(s.ref!)} type="button">
                <Icon name="doc" /> فتح الملخص
              </button>
            </div>
          </div>
        )) : (
          <div className="empty"><Icon name="check" /><b>لا ملخصات بانتظار اعتمادك</b></div>
        )}
      </div>
    </div>
  );
};

export default LawyerSummaries;
