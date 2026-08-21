import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import FlowLine from '@/components/babylon/FlowLine';
import { SUM_FLOW, type SummaryData, sumStage } from '@/lib/lawyer-data';

// قائمة ملخصات الملفات الواردة من الفريق القانوني — بيانات حقيقية من الخادم

interface Props { summaries: (SummaryData & { type?: string; client?: string })[]; }

const openSummary = (ref: string) =>
  router.visit(`/lawyer/summary/${encodeURIComponent(ref)}`);

const LawyerSummaries: React.FC<Props> = ({ summaries }) => {
  const [tab, setTab] = useState<'pending' | 'approved'>('pending');
  const pend = summaries.filter((s) => !s.approved);
  const done = summaries.filter((s) => s.approved);
  const list = tab === 'pending' ? pend : done;

  return (
    <div className="card">
      <div className="card-h">
        <h3>ملخصات الملفات</h3>
        <span className="sub">{list.length} ملخص</span>
      </div>
      <div className="card-b">
        <div className="mtabs">
          <button className={`mtab ${tab === 'pending' ? 'on' : ''}`} onClick={() => setTab('pending')} type="button">
            بانتظار اعتمادي ({pend.length})
          </button>
          <button className={`mtab ${tab === 'approved' ? 'on' : ''}`} onClick={() => setTab('approved')} type="button">
            المعتمدة ({done.length})
          </button>
        </div>
        {list.length ? list.map((s) => (
          <div key={s.ref} className="item">
            <div className="iico"><Icon name="doc" /></div>
            <div className="imeta">
              <b>ملخص ملف — {s.ref}{s.type ? ` · ${s.type}` : ''}</b>
              <span><FlowLine steps={SUM_FLOW} cur={sumStage(s.resultStatus)} /></span>
            </div>
            <div className="iact">
              <button className="btn sm" onClick={() => openSummary(s.ref!)} type="button">
                <Icon name="doc" /> فتح الملخص
              </button>
            </div>
          </div>
        )) : (
          <div className="empty"><Icon name="check" /><b>{tab === 'pending' ? 'لا ملخصات بانتظار اعتمادك' : 'لا ملخصات معتمدة بعد'}</b></div>
        )}
      </div>
    </div>
  );
};

export default LawyerSummaries;
