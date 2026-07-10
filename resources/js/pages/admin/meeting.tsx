import React from 'react';
import { type FullMeetingCard, MeetingDetailPage } from '@/lib/meeting-ui';

// يطابق meetingView (دور الإدارة) — الاجتماع حقيقي من الخادم

const AdminMeeting: React.FC<{ meeting: FullMeetingCard }> = ({ meeting }) => (
  <MeetingDetailPage meeting={meeting} base="/admin" />
);

export default AdminMeeting;
