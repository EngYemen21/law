import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { UnifiedCalendar, type UnifiedCalendarItem } from '@/components/babylon/UnifiedCalendar';
import { type CalendarEvent } from '@/lib/calendar-ui';
import { hearingTone } from '@/lib/case-ui';
import Icon from '@/lib/icons';
import { meetStatusTone } from '@/lib/meeting-ui';
import EmployeeSchedule, {
  type AppointmentItem,
  type AwaitingConsultItem,
  type ClientItem,
  type LawyerItem,
  type ScheduleCan,
} from '@/pages/employee/schedule';

// ============================================================
// مركز القيادة الزمني والتقويم التنفيذي للإدارة العليا (Executive Legal Calendar Hub)
// يوفر: مؤشرات رقابية عليا + التقويم التفاعلي الموحد + إدارة تفرغ المستشارين واعتماد المواعيد
// ============================================================

interface Props {
  events: CalendarEvent[];
  feedUrl?: string;
  webcalUrl?: string;
  clients: ClientItem[];
  lawyers: LawyerItem[];
  appointments?: AppointmentItem[];
  counts?: { today: number; upcoming: number; video: number; office: number };
  awaitingConsults?: AwaitingConsultItem[];
  can?: ScheduleCan;
}

const statusTone = (e: CalendarEvent): string =>
  e.kindKey === 'meeting' ? meetStatusTone(e.status) : hearingTone(e.status);

const AdminCalendar: React.FC<Props> = ({
  events,
  feedUrl,
  webcalUrl,
  clients,
  lawyers,
  appointments = [],
  counts,
  awaitingConsults = [],
  can,
}) => {
  const [view, setView] = useState<'calendar' | 'schedule'>('calendar');

  // تحويل الأحداث إلى النمط الموحد التفاعلي
  const calendarItems: UnifiedCalendarItem[] = useMemo(() => {
    return events.map((e, idx) => ({
      id: `${e.kindKey}-${e.startsAt ?? 'na'}-${idx}`,
      kind: e.kind,
      kindKey: e.kindKey,
      tone: e.tone,
      title: e.title,
      day: e.day,
      time: e.time,
      where: e.where,
      status: e.status,
      statusTone: statusTone(e),
      startsAt: e.startsAt,
    }));
  }, [events]);

  // عدد المواعيد المعلقة بانتظار اعتماد الإدارة
  const pendingApprovalsCount = useMemo(() => {
    return appointments.filter((a) => a.status === 'بانتظار الاعتماد').length;
  }, [appointments]);

  // إحصائيات الجلسات والمواعيد
  const hearingsCount = useMemo(() => events.filter((e) => e.kindKey === 'hearing').length, [events]);
  const consultsCount = useMemo(() => events.filter((e) => e.kindKey === 'consult').length, [events]);

  return (
    <>
      {/* 1. شريط الرقابة والتحكم التنفيذي */}
      <div
        className="card"
        style={{
          marginBottom: 16,
          padding: '14px 18px',
          background: 'linear-gradient(135deg, rgba(14,92,156,0.04) 0%, rgba(255,255,255,1) 100%)',
          borderInlineStart: '4px solid var(--brand)',
        }}
      >
        <div
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            flexWrap: 'wrap',
            gap: 12,
          }}
        >
          <div>
            <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--brand)' }}>
              🏛️ الرقابة الزمنية وجدول أعمال الإدارة العليا
            </h3>
            <p style={{ margin: '4px 0 0 0', fontSize: 12.5, color: 'var(--muted)' }}>
              متابعة حضور جلسات المحاكم، استشارات المستشارين، واعتماد المواعيد المقترحة لكافة فروع المكتب.
            </p>
          </div>

          {/* المؤشرات السريعة */}
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
            <div className="chip b-blue" style={{ fontSize: 12 }}>
              🏛️ {hearingsCount} جلسة محكمة
            </div>
            <div className="chip b-green" style={{ fontSize: 12 }}>
              ⚖️ {consultsCount} استشارة مكتبية
            </div>
            {pendingApprovalsCount > 0 && (
              <div
                className="chip b-amber"
                style={{ fontSize: 12, fontWeight: 700, cursor: 'pointer' }}
                onClick={() => setView('schedule')}
                title="انقر لمراجعة واعتماد المواعيد"
              >
                ⏳ {pendingApprovalsCount} مواعيد بانتظار الاعتماد
              </div>
            )}
          </div>
        </div>
      </div>

      {/* 2. أزرار التبديل بين التقويم والأجندة وشبكة التفرغ والجدولة */}
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
          <Icon name="calgrid" /> التقويم والأجندة الشاملة ({events.length})
        </button>

        <button
          type="button"
          role="tab"
          aria-selected={view === 'schedule'}
          className={view === 'schedule' ? 'btn pri sm' : 'btn soft sm'}
          onClick={() => setView('schedule')}
        >
          <Icon name="cal" /> جدولة وتفرغ المستشارين ({appointments.length})
        </button>
      </div>

      {/* 3. العرض المختار */}
      {view === 'calendar' ? (
        <UnifiedCalendar
          items={calendarItems}
          title="تقويم ارتباطات المكتب"
          subtitle="متابعة شاملة لجلسات القضايا والاستشارات واجتماعات الفريق القانوني في مكان واحد."
          emptyMessage="لا توجد ارتباطات أو جلسات مسجلة في تقويم المكتب"
        />
      ) : (
        <EmployeeSchedule
          clients={clients}
          lawyers={lawyers}
          appointments={appointments}
          counts={counts}
          awaitingConsults={awaitingConsults}
          can={can}
        />
      )}
    </>
  );
};

export default AdminCalendar;
