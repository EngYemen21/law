import React from 'react';
import { type ConsultCard, ConsultJourneyPage, type LawyerOpt } from '@/lib/consult-ui';

// يطابق consultView (دور المحامي) — رحلة الاستشارة حقيقية من الخادم

const LawyerConsult: React.FC<{ consult: ConsultCard; lawyers: LawyerOpt[] }> = ({ consult, lawyers }) => (
  <ConsultJourneyPage consult={consult} base="/lawyer" lawyers={lawyers} />
);

export default LawyerConsult;
