import React from 'react';
import { type ClientDirEntry, type MeetReqCard, MeetReqsPage } from '@/lib/meeting-ui';

// يطابق meetReqsView (دور الموظف) — الدعوات حقيقية من الخادم

interface Props { requests: MeetReqCard[]; clients: ClientDirEntry[] }

const EmployeeMeetReqs: React.FC<Props> = ({ requests, clients }) => (
  <MeetReqsPage requests={requests} clients={clients} base="/employee" />
);

export default EmployeeMeetReqs;
