import React from 'react';
import { type ConsultCard, ConsultJourneyPage, type LawyerOpt } from '@/lib/consult-ui';

// يطابق consultView (دور الإدارة العليا) — مع صلاحيات الأولوية وتعيين المحامي

const AdminConsult: React.FC<{ consult: ConsultCard; lawyers: LawyerOpt[]; canApproveSummary: boolean }> = ({ consult, lawyers, canApproveSummary }) => (
  <ConsultJourneyPage consult={consult} base="/admin" isAdmin lawyers={lawyers} canApproveSummary={canApproveSummary} />
);

export default AdminConsult;
