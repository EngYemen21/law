import React from 'react';
import { type ConsultCard, ConsultJourneyPage, type LawyerOpt } from '@/lib/consult-ui';

// يطابق consultView (دور الإدارة العليا) — مع صلاحيات الأولوية وتعيين المحامي

const AdminConsult: React.FC<{ consult: ConsultCard; lawyers: LawyerOpt[] }> = ({ consult, lawyers }) => (
  <ConsultJourneyPage consult={consult} base="/admin" isAdmin lawyers={lawyers} />
);

export default AdminConsult;
