import React from 'react';
import { type FullMeetingCard, MeetingDetailPage } from '@/lib/meeting-ui';

// يطابق meetingView (دور المحامي) — الاجتماع حقيقي من الخادم

const LawyerMeeting: React.FC<{ meeting: FullMeetingCard }> = ({ meeting }) => (
  <MeetingDetailPage meeting={meeting} base="/lawyer" />
);

export default LawyerMeeting;
