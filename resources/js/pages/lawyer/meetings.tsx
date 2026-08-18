import React from 'react';
import { type FullMeetingCard, MeetingsListPage } from '@/lib/meeting-ui';

// يطابق lwMeetings في index (82).html — الاجتماعات حقيقية من الخادم
// (القائمة المشتركة في lib/meeting-ui — نفسها لصفحة الموظف)

const LawyerMeetings: React.FC<{ meetings: FullMeetingCard[] }> = ({ meetings }) => (
  <MeetingsListPage meetings={meetings} base="/lawyer" />
);

export default LawyerMeetings;
