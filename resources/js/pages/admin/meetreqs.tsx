import React from 'react';
import { type ClientDirEntry, type MeetReqCard, MeetReqsPage } from '@/lib/meeting-ui';

// يطابق meetReqsView (دور الإدارة) — الدعوات حقيقية من الخادم

interface Props { requests: MeetReqCard[]; clients: ClientDirEntry[]; lawyers: { id: number; name: string }[]; selfLawyerId: number | null }

const AdminMeetReqs: React.FC<Props> = ({ requests, clients, lawyers, selfLawyerId }) => (
  <MeetReqsPage requests={requests} clients={clients} lawyers={lawyers} selfLawyerId={selfLawyerId} base="/admin" />
);

export default AdminMeetReqs;
