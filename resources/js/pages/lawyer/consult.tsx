import React from 'react';
import { type ConsultCard, ConsultJourneyPage } from '@/lib/consult-ui';

// يطابق consultView (دور المحامي) — رحلة الاستشارة حقيقية من الخادم

const LawyerConsult: React.FC<{ consult: ConsultCard }> = ({ consult }) => (
  <ConsultJourneyPage consult={consult} base="/lawyer" />
);

export default LawyerConsult;
