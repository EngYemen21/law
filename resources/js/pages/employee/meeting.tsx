import React from 'react';
import { type FullMeetingCard, MeetingDetailPage } from '@/lib/meeting-ui';

// تفاصيل الاجتماع (دور الموظف) — نفس صفحة المحامي، والحراسة بالدور والصلاحية خادمياً

const EmployeeMeeting: React.FC<{ meeting: FullMeetingCard }> = ({ meeting }) => (
  <MeetingDetailPage meeting={meeting} base="/employee" />
);

export default EmployeeMeeting;
