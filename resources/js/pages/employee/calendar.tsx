import React from 'react';
import type { CalendarEvent } from '@/lib/calendar-ui';
import { TimeTab } from '@/lib/time-tab-ui';
import EmployeeSchedule, {
  type AppointmentItem,
  type AwaitingConsultItem,
  type ClientItem,
  type LawyerItem,
  type ScheduleCan,
} from '@/pages/employee/schedule';

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
  can?: ScheduleCan;
}

const EmployeeCalendar: React.FC<Props> = ({ events, feedUrl, webcalUrl, clients, lawyers, appointments, counts, awaitingConsults, can }) => (
  <TimeTab
    events={events}
    feedUrl={feedUrl}
    webcalUrl={webcalUrl}
    title="التقويم والمواعيد"
    subtitle="ارتباطات المكتب الزمنية وحجز المواعيد — نظرة واحدة قبل الجدولة."
    listLabel="جدولة وتفرغ المستشارين"
    listCount={appointments?.length}
    listView={<EmployeeSchedule clients={clients} lawyers={lawyers} appointments={appointments} counts={counts} awaitingConsults={awaitingConsults} can={can} />}
  />
);

export default EmployeeCalendar;
