import React from 'react';
import { type ConsultCard, ConsultJourneyPage, type LawyerOpt } from '@/lib/consult-ui';

// يطابق consultView (دور الموظف) — رحلة الاستشارة حقيقية من الخادم

const EmployeeConsult: React.FC<{ consult: ConsultCard; lawyers: LawyerOpt[]; canApproveSummary: boolean }> = ({ consult, lawyers, canApproveSummary }) => (
  <ConsultJourneyPage consult={consult} base="/employee" lawyers={lawyers} canApproveSummary={canApproveSummary} />
);

export default EmployeeConsult;
