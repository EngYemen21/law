import React from 'react';
import {  StaffMeetingRoom } from '@/lib/meeting-ui';
import type {FullMeetingCard} from '@/lib/meeting-ui';

// غرفة الاجتماع المضمّنة (دور الموظف)
const EmployeeMeetingRoom: React.FC<{ meeting: FullMeetingCard }> = ({ meeting }) => (
  <StaffMeetingRoom meeting={meeting} base="/employee" />
);

export default EmployeeMeetingRoom;
