import React from 'react';
import { type ConsultCard, ConsultRecvPage } from '@/lib/consult-ui';

// يطابق consultRecvView (دور الإدارة) — البيانات حقيقية من الخادم

const AdminConsultRecv: React.FC<{ consults: ConsultCard[] }> = ({ consults }) => (
  <ConsultRecvPage consults={consults} base="/admin" />
);

export default AdminConsultRecv;
