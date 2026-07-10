import React from 'react';
import { type ConsultCard, ConsultJourneyPage } from '@/lib/consult-ui';

// يطابق consultView (دور الموظف) — رحلة الاستشارة حقيقية من الخادم

const EmployeeConsult: React.FC<{ consult: ConsultCard }> = ({ consult }) => (
  <ConsultJourneyPage consult={consult} base="/employee" />
);

export default EmployeeConsult;
