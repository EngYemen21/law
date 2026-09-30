import React from 'react';
import { type ConsultCard, ConsultJourneyPage, type LawyerOpt } from '@/lib/consult-ui';

// يطابق consultView (دور المحامي) — رحلة الاستشارة حقيقية من الخادم

const LawyerConsult: React.FC<{ consult: ConsultCard; lawyers: LawyerOpt[]; canApproveSummary: boolean }> = ({ consult, lawyers, canApproveSummary }) => (
  <ConsultJourneyPage consult={consult} base="/lawyer" lawyers={lawyers} canApproveSummary={canApproveSummary} />
);

export default LawyerConsult;
