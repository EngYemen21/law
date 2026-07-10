import React from 'react';
import { type ConsultCard, ConsultsListPage } from '@/lib/consult-ui';

// يطابق emConsultsView + cKPIs — البيانات حقيقية من الخادم

const EmployeeConsults: React.FC<{ consults: ConsultCard[] }> = ({ consults }) => (
  <ConsultsListPage consults={consults} base="/employee" />
);

export default EmployeeConsults;
