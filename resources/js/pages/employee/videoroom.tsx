import React from 'react';
import { type ConsultCard, StaffVideoRoomPage } from '@/lib/consult-ui';

// يطابق videoRoomView (دور الموظف) — الاستشارة حقيقية من الخادم (وتبقى kind=req لطلبات الاجتماعات)

interface Props { consult?: ConsultCard | null; selfName?: string; selfAv?: string }

const EmployeeVideoRoom: React.FC<Props> = ({ consult, selfName, selfAv }) => (
  <StaffVideoRoomPage consult={consult} selfName={selfName} selfAv={selfAv} base="/employee" />
);

export default EmployeeVideoRoom;
