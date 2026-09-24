import React, { useMemo, useState } from 'react';
import { UnifiedCalendar, type UnifiedCalendarItem } from '@/components/babylon/UnifiedCalendar';
import { type Appt } from '@/lib/data';
import Icon from '@/lib/icons';
import {
  type TimelineEvent, type TimelineFilters, type TimelineMeta,
  TimelinePager, TimelineToolbar,
} from '@/lib/timeline-ui';
import Appointments from '@/pages/appointments';

// ============================================================
// مركز التقويم والمواعيد للعميل (Client Interactive Calendar & Hub)
// يجمع: التقويم الشهري التفاعلي + الأجندة الذكية + جدول الارتباطات الكلاسيكي + بطاقات المواعيد
// خالي تماماً من التكرار وبدون أي روابط مزامنة جوال أو ICS
// ============================================================

interface Props {
  events: TimelineEvent[];
  meta: TimelineMeta;
  counts: Record<string, number>;
  statuses: string[];
  filters: TimelineFilters;
  appointments?: Appt[];
  feedUrl?: string;
  webcalUrl?: string;
}

const ClientCalendar: React.FC<Props> = ({
  events, meta, counts, statuses, filters, appointments,
}) => {
  const [view, setView] = useState<'calendar' | 'cards'>('calendar');
  const [showAdvancedFilters, setShowAdvancedFilters] = useState(false);

  // تحويل الأحداث إلى النمط الموحد التفاعلي للتقويم والجدول
  const calendarItems: UnifiedCalendarItem[] = useMemo(() => {
    return events.map((e, idx) => ({
      id: e.id || `evt-${idx}`,
      kind: e.kind,
      kindKey: e.kindKey,
      tone: e.tone,
      title: e.title,
      day: e.day,
      time: e.time,
      where: e.where,
      status: e.status,
      statusTone: e.statusTone,
      joinLink: e.joinLink,
      cardUrl: e.cardUrl,
    }));
  }, [events]);

  return (
    <>
      {/* 1. أزرار التبديل الرئيسية (بدون أي تكرار) */}
      <div
        className="stat-strip"
        style={{ marginBottom: 16 }}
        role="tablist"
        aria-label="منظر التبويب الزمني"
      >
        <button
          type="button"
          role="tab"
          aria-selected={view === 'calendar'}
          className={view === 'calendar' ? 'btn pri sm' : 'btn soft sm'}
          onClick={() => setView('calendar')}
        >
          <Icon name="calgrid" /> التقويم وجدول المواعيد ({meta.total})
        </button>

        <button
          type="button"
          role="tab"
          aria-selected={view === 'cards'}
          className={view === 'cards' ? 'btn pri sm' : 'btn soft sm'}
          onClick={() => setView('cards')}
        >
          <Icon name="cal" /> بطاقات مواعيدي{appointments ? ` (${appointments.length})` : ''}
        </button>
      </div>

      {/* 2. مساحة العرض حسب النمط المختار */}
      {view === 'calendar' ? (
        <UnifiedCalendar
          items={calendarItems}
          title="التقويم والمواعيد"
          subtitle="مواعيدك واستشاراتك وجلساتك القضائية في مكان واحد منظم وسهل الوصول."
          emptyMessage="لا توجد مواعيد أو جلسات مسجلة في تقويمك"
          headerActions={
            <button
              type="button"
              className={`btn sm ${showAdvancedFilters ? 'pri' : 'soft'}`}
              onClick={() => setShowAdvancedFilters((prev) => !prev)}
              title="خيارات الفلترة المتقدمة والمدى الزمني"
            >
              <Icon name="search" /> {showAdvancedFilters ? 'إخفاء التصفية المتقدمة' : 'تصفية وبحث متقدم'}
            </button>
          }
          filterToolbar={
            showAdvancedFilters ? (
              <TimelineToolbar filters={filters} counts={counts} statuses={statuses} />
            ) : undefined
          }
          pager={<TimelinePager meta={meta} filters={filters} />}
        />
      ) : (
        <Appointments appointments={appointments ?? []} />
      )}
    </>
  );
};

export default ClientCalendar;
