import React from 'react';
import {  StaffMeetingRoom } from '@/lib/meeting-ui';
import type {FullMeetingCard} from '@/lib/meeting-ui';

// غرفة الاجتماع المضمّنة (دور المحامي)
const LawyerMeetingRoom: React.FC<{ meeting: FullMeetingCard }> = ({ meeting }) => (
  <StaffMeetingRoom meeting={meeting} base="/lawyer" />
);

export default LawyerMeetingRoom;
