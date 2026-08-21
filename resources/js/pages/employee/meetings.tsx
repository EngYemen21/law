import React from 'react';
import { type FullMeetingCard, MeetingsListPage } from '@/lib/meeting-ui';

// اجتماعات الموظف (اجتماعات المكتب كلّها) — كان الموظف يُشعَر «متاح في لوحتك» بلا أي صفحة

const EmployeeMeetings: React.FC<{ meetings: FullMeetingCard[] }> = ({ meetings }) => (
  <MeetingsListPage meetings={meetings} base="/employee" />
);

export default EmployeeMeetings;
