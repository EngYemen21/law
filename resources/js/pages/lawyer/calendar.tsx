import React from 'react';
import { type CalendarEvent, CalendarPage } from '@/lib/calendar-ui';

// تقويم المحامي — جلسات قضاياه واجتماعاته واستشاراته الحقيقية مع روابط تقويم جوجل والاشتراك الحي

const LawyerCalendar: React.FC<{ events: CalendarEvent[]; feedUrl?: string; webcalUrl?: string }> = (props) => (
  <CalendarPage
    {...props}
    title="تقويم المحامي"
    subtitle="جلسات قضاياك، اجتماعاتك، واستشاراتك القانونية المكلّف بها — متزامنة لحظياً."
  />
);

export default LawyerCalendar;
