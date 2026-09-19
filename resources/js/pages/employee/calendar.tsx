import React from 'react';
import type { CalendarEvent } from '@/lib/calendar-ui';
import { TimeTab } from '@/lib/time-tab-ui';
import EmployeeSchedule from '@/pages/employee/schedule';
import type { AppointmentItem, AwaitingConsultItem, ClientItem, LawyerItem } from '@/pages/employee/schedule';

// التبويب الزمني الموحّد للموظف — «الأحداث» (جلسات واجتماعات واستشارات المكتب)
// و«المواعيد» (اللوحة نفسها التي كانت تبويب «جدولة المواعيد» المستقلّ، بحجزها كما هو).

interface Props {
  events: CalendarEvent[];
  feedUrl?: string;
  webcalUrl?: string;
  clients: ClientItem[];
  lawyers: LawyerItem[];
  appointments?: AppointmentItem[];
  counts?: { today: number; upcoming: number; video: number; office: number };
  awaitingConsults?: AwaitingConsultItem[]; // استشاراتٌ مدفوعة بانتظار موعدها — يُحجز لها من نموذج الجدولة
}

const EmployeeCalendar: React.FC<Props> = ({ events, feedUrl, webcalUrl, clients, lawyers, appointments, counts, awaitingConsults }) => (
  <TimeTab
    events={events}
    feedUrl={feedUrl}
    webcalUrl={webcalUrl}
    title="التقويم والمواعيد"
    subtitle="ارتباطات المكتب الزمنية وحجز المواعيد — نظرة واحدة قبل الجدولة."
    listLabel="المواعيد"
    listCount={appointments?.length}
    listView={<EmployeeSchedule clients={clients} lawyers={lawyers} appointments={appointments} counts={counts} awaitingConsults={awaitingConsults} />}
  />
);

export default EmployeeCalendar;
