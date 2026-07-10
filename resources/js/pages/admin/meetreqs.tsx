import React from 'react';
import { type ClientDirEntry, type MeetReqCard, MeetReqsPage } from '@/lib/meeting-ui';

// يطابق meetReqsView (دور الإدارة) — الدعوات حقيقية من الخادم

interface Props { requests: MeetReqCard[]; clients: ClientDirEntry[] }

const AdminMeetReqs: React.FC<Props> = ({ requests, clients }) => (
  <MeetReqsPage requests={requests} clients={clients} base="/admin" />
);

export default AdminMeetReqs;
