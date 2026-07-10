import React from 'react';
import { type ConsultCard, StaffVideoRoomPage } from '@/lib/consult-ui';

// يطابق videoRoomView (دور المحامي) — الاستشارة حقيقية من الخادم

interface Props { consult?: ConsultCard | null; selfName?: string; selfAv?: string }

const LawyerVideoRoom: React.FC<Props> = ({ consult, selfName, selfAv }) => (
  <StaffVideoRoomPage consult={consult} selfName={selfName} selfAv={selfAv} base="/lawyer" />
);

export default LawyerVideoRoom;
