import React from 'react';
import { type CalendarEvent, CalendarPage } from '@/lib/calendar-ui';

// تقويم الموظف — كل ارتباطات المكتب (جلسات وقضايا واجتماعات واستشارات) لتنسيق الجدولة.
// كان الموظف يجدول المواعيد بلا أي نظرة زمنية على ارتباطات المكتب (عدم تماثل مع المحامي).

const EmployeeCalendar: React.FC<{ events: CalendarEvent[]; feedUrl?: string; webcalUrl?: string }> = (props) => (
  <CalendarPage
    {...props}
    title="تقويم المكتب"
    subtitle="جلسات القضايا والاجتماعات والاستشارات القادمة — لتنسيق الجدولة ومتابعة الارتباطات."
  />
);

export default EmployeeCalendar;
