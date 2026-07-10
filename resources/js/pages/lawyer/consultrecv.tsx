import React from 'react';
import { type ConsultCard, ConsultRecvPage } from '@/lib/consult-ui';

// يطابق consultRecvView (دور المحامي) — البيانات حقيقية من الخادم

const LawyerConsultRecv: React.FC<{ consults: ConsultCard[] }> = ({ consults }) => (
  <ConsultRecvPage consults={consults} base="/lawyer" />
);

export default LawyerConsultRecv;
