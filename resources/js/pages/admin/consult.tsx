import React from 'react';
import { type ConsultCard, ConsultJourneyPage } from '@/lib/consult-ui';

// يطابق consultView (دور الإدارة العليا) — مع صلاحيات الأولوية وتعيين المحامي

const AdminConsult: React.FC<{ consult: ConsultCard }> = ({ consult }) => (
  <ConsultJourneyPage consult={consult} base="/admin" isAdmin />
);

export default AdminConsult;
