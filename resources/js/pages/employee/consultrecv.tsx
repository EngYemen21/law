import React from 'react';
import { type ConsultCard, ConsultRecvPage } from '@/lib/consult-ui';

// يطابق consultRecvView (دور الموظف) — البيانات حقيقية من الخادم

const EmployeeConsultRecv: React.FC<{ consults: ConsultCard[] }> = ({ consults }) => (
  <ConsultRecvPage consults={consults} base="/employee" />
);

export default EmployeeConsultRecv;
