import React from 'react';
import {  StaffMeetingRoom } from '@/lib/meeting-ui';
import type {FullMeetingCard} from '@/lib/meeting-ui';

// غرفة الاجتماع المضمّنة (دور الإدارة)
const AdminMeetingRoom: React.FC<{ meeting: FullMeetingCard }> = ({ meeting }) => (
  <StaffMeetingRoom meeting={meeting} base="/admin" />
);

export default AdminMeetingRoom;
