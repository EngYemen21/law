import React from 'react';
import { type CalendarEvent } from '@/lib/calendar-ui';
import { TimeTab } from '@/lib/time-tab-ui';

// التبويب الزمني للمحامي — جلسات قضاياه واجتماعاته واستشاراته المكلّف بها.
// بلا منظر قائمة: المحامي لا يحجز نيابةً عن العملاء، فالصفحة كما كانت تماماً.

const LawyerCalendar: React.FC<{ events: CalendarEvent[]; feedUrl?: string; webcalUrl?: string }> = (props) => (
  <TimeTab
    {...props}
    title="التقويم والمواعيد"
    subtitle="جلسات قضاياك، اجتماعاتك، واستشاراتك القانونية المكلّف بها — متزامنة لحظياً."
  />
);

export default LawyerCalendar;
