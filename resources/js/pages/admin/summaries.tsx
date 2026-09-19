import React from 'react';
import AdminApprovals, { type HistorySummaryItem } from './approvals';

// ============================================================================
// صفحة اعتماد الملخصات القديمة — أصبحت الآن جزءاً موحداً من «مركز الاعتمادات والقرارات»
// موجهة ومتوافقة بنسبة 100% مع المكون الموحد
// ============================================================================

interface Props {
  summaries?: HistorySummaryItem[];
}

const AdminSummaries: React.FC<Props> = ({ summaries = [] }) => {
  return (
    <AdminApprovals
      ticketSummaries={[]}
      ticketTrackProposals={[]}
      sessionSummaries={[]}
      appointments={[]}
      approvedHistory={summaries}
    />
  );
};

export default AdminSummaries;
