import React from 'react';
import { type ConsultCard, StaffVideoRoomPage } from '@/lib/consult-ui';

// يطابق videoRoomView (دور الإدارة) — الاستشارة حقيقية من الخادم

interface Props { consult?: ConsultCard | null; selfName?: string; selfAv?: string }

const AdminVideoRoom: React.FC<Props> = ({ consult, selfName, selfAv }) => (
  <StaffVideoRoomPage consult={consult} selfName={selfName} selfAv={selfAv} base="/admin" />
);

export default AdminVideoRoom;
