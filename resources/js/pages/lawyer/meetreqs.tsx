import React from 'react';
import { type ClientDirEntry, type MeetReqCard, MeetReqsPage } from '@/lib/meeting-ui';

// يطابق meetReqsView (دور المحامي) — الدعوات حقيقية من الخادم

interface Props { requests: MeetReqCard[]; clients: ClientDirEntry[] }

const LawyerMeetReqs: React.FC<Props> = ({ requests, clients }) => (
  <MeetReqsPage requests={requests} clients={clients} base="/lawyer" />
);

export default LawyerMeetReqs;
