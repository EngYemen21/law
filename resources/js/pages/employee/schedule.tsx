import { router } from '@inertiajs/react';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import StatRow from '@/components/babylon/StatRow';
import type {StatItem} from '@/components/babylon/StatRow';
import TimeSlotPicker from '@/components/babylon/TimeSlotPicker';
import type { TimeSlotItem } from '@/components/babylon/TimeSlotPicker';
import { useToast } from '@/components/babylon/Toast';
import ConsultOnBehalfButton from '@/components/consult/ConsultOnBehalfButton';
import { todayISO } from '@/lib/local-date';
import { bookingClientId, periodOf } from '@/lib/booking-time';
import { useConsultReschedule } from '@/lib/consult-reschedule';
import { slotEnd, useConsultSlots } from '@/lib/consult-slots';
import { CONFIRM_NO_SHOW } from '@/lib/consult-ui';
import { foldSearch } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { useCan, useMasker } from '@/lib/permissions';
import { inSessionSuffix, PresenceBadge, useInSession } from '@/lib/staff-presence';
import { useServerAction } from '@/lib/use-server-action';
import { truncateWords } from '@/lib/utils';

// الشاشة تُعرض داخل تقويم الموظف وتقويم الإدارة معًا — العناوين تُشتق من اللوحة الحالية
// (لم يعد الأدمن يمرّ عبر بوابة دور الموظف — قرار 2026-08-28، له مسارات admin مطابقة)
const apiBase = () => (window.location.pathname.startsWith('/admin') ? '/admin' : '/employee');

// موعدٌ اقترحه موظّف ولم تعتمده الإدارة بعد (`AppointmentStatus::PendingApproval`) — لا يُعاد جدولته
// ولا يُوسم «لم يحضر» (الخادم يرفضهما لطلبٍ في دورة الحجز)، وله خيار ترشيحٍ مستقلّ
const APPT_PENDING = 'بانتظار الاعتماد';
// نتيجتا الموعد بعد الجلسة (`AppointmentStatus::Attended` / `::NoShow`) — لفلتري «حضر» و«لم يحضر»
const APPT_ATTENDED = 'تم الحضور';
const APPT_NO_SHOW = 'لم يحضر';

// ============================================================
// لوحة جدولة وإدارة مواعيد المكتب للموظف (Enterprise Scheduling Hub)
// تتيح متابعة تفرغ المستشارين، شبكة المواعيد، الجدولة الذكية، وتفاصيل المواعيد
// ============================================================

export interface ClientItem {
  id: number;
  name: string;
  phone?: string;
  email?: string;
}

export interface LawyerItem {
  id: number;
  name: string;
  dept?: string;
}

export interface AppointmentItem {
  id: string; // ext_id
  type: string;
  ico: string;
  lawyer: string;
  lawyerId?: number | null;
  clientId?: number | null;
  day: string;
  time: string;
  place: string;
  status: string;
  tone: string;
  when: string; // 'up' | 'past'
  client?: string;
  consultRef?: string;
  consultId?: number;
  consultRescheduleCount?: number;
  /** حارسا الخادم (`RescheduleConsult` و`MarkNoShow`) — يُظهران زرّيهما */
  consultCanReschedule?: boolean;
  consultCanMarkNoShow?: boolean;
  pay?: string;
  joinLink?: string;
  rawStartsAt?: string | null;
  rawDate?: string | null;
  rawTime?: string | null;
  phone?: string;
  channel?: string;
  subject?: string;
}

interface Counts {
  today?: number;
  upcoming?: number;
  video?: number;
  office?: number;
}

/** استشارةٌ مدفوعة بانتظار موعدها — ما يُحجز له الموعد فعلاً (AppointmentBoard::data). */
export interface AwaitingConsultItem {
  id: number;
  ref: string;
  clientId: number;
  subject: string;
  type: 'office' | 'video' | 'phone';
  specialty?: string | null;
  lawyerId?: number | null;
  ticketNo?: string | null;
}

export interface ScheduleCan {
  book?: boolean;
  manage?: boolean;
  enterRoom?: boolean;
  court?: boolean;
  meetings?: boolean;
  approve?: boolean;
}

interface Props {
  clients: ClientItem[];
  lawyers: LawyerItem[];
  appointments?: AppointmentItem[];
  counts?: Counts;
  awaitingConsults?: AwaitingConsultItem[];
  can?: ScheduleCan;
}

const TYPES: [string, string, string][] = [
  ['office', 'حضورية', 'office'],
  ['video', 'مرئية', 'video'],
  ['phone', 'هاتفية', 'phone'],
];

/*
 * شبكة التقويم اليوميّ للمستشارين من إعدادات الخادم (`useConsultSlots`) — كانت ٠٩–٢٢ منقوشة
 * هنا والمحرّك يولّد ٠٠–٢٣، فساعاتٌ يحجزها العميل لا تظهر للموظّف في شبكته.
 */

/** استخراج الحروف الأولى لرمز المستشار */
const getInitials = (name: string): string => {
  const parts = name.trim().split(/\s+/);
  if (parts.length >= 2) {
    return `${parts[0][0]}${parts[1][0]}`;
  }
  return name.slice(0, 2);
};

const EmployeeSchedule: React.FC<Props> = ({
  clients = [],
  lawyers = [],
  appointments = [],
  counts,
  awaitingConsults = [],
  can,
}) => {
  const inSession = useInSession();
  const toast = useToast();
  // قفلٌ موحّد لفعل «لم يحضر» من بطاقة الموعد
  const action = useServerAction();
  const reschedule = useConsultReschedule(apiBase());
  // الشبكة وطول الشريحة وأيّام الدوام من الخادم — ما يولّده المحرّك نفسه
  const { gridOn, allowOverlap, slotMinutes } = useConsultSlots();
  const userCan = useCan();
  const mask = useMasker();
  const isSuper = window.location.pathname.startsWith('/admin');

  // حوكمة الصلاحيات التفصيلية
  const canBook = can?.book ?? (isSuper || userCan('جدولة المواعيد'));
  const canManage = can?.manage ?? (isSuper || userCan('إدارة المواعيد والحجوزات'));
  const canVideo = can?.enterRoom ?? (isSuper || userCan('إجراء الجلسات المرئية') || userCan('استقبال الاستشارات'));
  const canApprove = can?.approve ?? isSuper;

  // نمط العرض: تقويم وتفرغ الفريق | قائمة المواعيد
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');

  // اليوم المختار في عرض الشبكة / التقويم
  const [selectedDay, setSelectedDay] = useState(todayISO());
  // شبكة اليوم المختار — فارغةٌ في يوم العطلة
  const dayHours = gridOn(selectedDay);

  // التصفية والبحث
  const [searchQuery, setSearchQuery] = useState('');
  const [filterLawyer, setFilterLawyer] = useState<string>('all');
  const [filterChannel, setFilterChannel] = useState<string>('all');
  const [filterStatus, setFilterStatus] = useState<string>('all');

  // موعد مختار لعرض التفاصيل
  const [selectedAppt, setSelectedAppt] = useState<AppointmentItem | null>(null);

  // مودال حجز موعد جديد
  const [bookOpen, setBookOpen] = useState(false);
  // فارغٌ حتى تُفتح النافذة فيختار `bookingClientId` — كان أوّل عميلٍ في القائمة فلا يعمل اختيار صاحب الاستشارة المدفوعة
  const [clientId, setClientId] = useState<number | ''>('');
  const [clientSearch, setClientSearch] = useState('');
  const [lawyerId, setLawyerId] = useState<number | ''>(lawyers[0]?.id ?? '');
  const [type, setType] = useState('office');
  const [subject, setSubject] = useState('');
  const [date, setDate] = useState(todayISO());
  const [time, setTime] = useState('');
  const [busy, setBusy] = useState(false);

  // الاستشارة المدفوعة التي يُحجز لها الموعد — يُرسَل معرّفها فلا يختار الخادم أقدمها صامتاً
  const [consultId, setConsultId] = useState<number | ''>('');
  const clientConsults = useMemo(
    () => awaitingConsults.filter((c) => c.clientId === clientId),
    [awaitingConsults, clientId],
  );

  /** يختار استشارةً ويملأ قناتها، ومحاميها حين يُطلب (لا حين يُفتح النموذج من خانة محامٍ في الشبكة). */
  const selectConsult = (consult: AwaitingConsultItem | undefined, withLawyer: boolean) => {
    setConsultId(consult?.id ?? '');

    if (!consult) {
      return;
    }

    setType(consult.type);

    if (withLawyer && consult.lawyerId) {
      setLawyerId(consult.lawyerId);
      setTime('');
    }
  };

  /** يختار العميل وأوّل استشارةٍ مدفوعة له بانتظار موعد. */
  const pickClient = (id: number, withLawyer: boolean) => {
    setClientId(id);
    selectConsult(awaitingConsults.find((c) => c.clientId === id), withLawyer);
  };

  /** العميل الذي تُفتح عليه النافذة: المختار إن كانت له استشارة مدفوعة بانتظار موعد، وإلّا أوّل من له ذلك. */
  const initialClientId = (): number | '' =>
    bookingClientId(clientId, awaitingConsults.map((c) => c.clientId), clients.map((c) => c.id));

  // الفترات المتاحة للمستشار في المودال
  const [slots, setSlots] = useState<TimeSlotItem[]>([]);
  const [slotsLoading, setSlotsLoading] = useState(false);

  // جلب الفترات عند تغيير المستشار أو التاريخ في المودال
  useEffect(() => {
    if (!bookOpen || !lawyerId || !date) {
      setSlots([]);

      return;
    }

    let cancelled = false;
    setSlotsLoading(true);
    fetch(`${apiBase()}/schedule/slots?lawyer_id=${lawyerId}&date=${date}`, {
      headers: { Accept: 'application/json' },
    })
      .then((r) => r.json())
      .then((j: { slots?: TimeSlotItem[] }) => {
        if (!cancelled) {
setSlots(j.slots ?? []);
}
      })
      .catch(() => {
        if (!cancelled) {
setSlots([]);
}
      })
      .finally(() => {
        if (!cancelled) {
setSlotsLoading(false);
}
      });

    return () => {
      cancelled = true;
    };
  }, [bookOpen, lawyerId, date]);

  // أوقات اليوم المنقضية
  const nowHM = () => new Date().toTimeString().slice(0, 5);
  const isPast = date === todayISO() && time !== '' && time <= nowHM();

  // فتح نافذة الحجز مع تحديد المحامي واليوم والوقت مسبقاً من الشبكة
  const openBookingForSlot = (targetLawyerId: number, targetDate: string, targetTime: string) => {
    // المحامي والوقت من خانة الشبكة — الاستشارة لا تغيّر المحامي هنا
    const initialClient = initialClientId();

    if (initialClient !== '') {
      pickClient(initialClient, false);
    }

    setLawyerId(targetLawyerId);
    setDate(targetDate);
    setTime(targetTime);
    setBookOpen(true);
  };

  const openNewBooking = () => {
    const initialClient = initialClientId();

    if (lawyers.length > 0 && lawyerId === '') {
      setLawyerId(lawyers[0].id);
    }

    if (initialClient !== '') {
      pickClient(initialClient, true);
    }

    setDate(todayISO());
    setTime('');
    setSubject('');
    setBookOpen(true);
  };

  // تأكيد الجدولة
  const submitBooking = () => {
    if (!clientId || !date || !time) {
      toast('يرجى اختيار العميل والتاريخ والوقت');

      return;
    }

    if (!consultId) {
      toast('لا توجد لهذا العميل استشارة مدفوعة بانتظار موعد — تُطلب الاستشارة وتُسعَّر وتُسدَّد أوّلاً');

      return;
    }

    if (isPast) {
      toast('لا يمكن اختيار وقت ماضٍ، فضلاً اختر وقتاً لاحقاً');

      return;
    }

    setBusy(true);
    router.post(
      `${apiBase()}/schedule`,
      {
        client_id: clientId,
        consult_id: consultId,
        lawyer_id: lawyerId || null,
        type,
        subject: subject.trim(),
        date,
        time,
      },
      {
        preserveScroll: true,
        onFinish: () => setBusy(false),
        onSuccess: () => {
          // الموظّف يقترح والإدارة تعتمد قبل أن يصل العميل؛ حجزُ الإدارة يُرسل مباشرةً
          toast(apiBase() === '/admin' ? '✅ تم تحديد الموعد وإرساله للعميل' : '✅ أُرسل الموعد لاعتماد الإدارة قبل إرساله للعميل');
          setBookOpen(false);
          setSubject('');
          setTime('');
        },
        onError: (e) => toast(e.consult_id || e.client_id || e.lawyer_id || e.starts_at || e.time || e.date || e.message || 'تعذّر حفظ الموعد'),
      }
    );
  };

  // التنقل بين الأيام في التقويم
  const shiftDay = (deltaDays: number) => {
    const curr = new Date(selectedDay);
    curr.setDate(curr.getDate() + deltaDays);
    const y = curr.getFullYear();
    const m = String(curr.getMonth() + 1).padStart(2, '0');
    const d = String(curr.getDate()).padStart(2, '0');
    setSelectedDay(`${y}-${m}-${d}`);
  };

  // تنسيق التاريخ العربي المقروء
  const formattedSelectedDay = useMemo(() => {
    try {
      const parts = selectedDay.split('-');

      if (parts.length === 3) {
        const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));

        return d.toLocaleDateString('ar-SA', {
          weekday: 'long',
          year: 'numeric',
          month: 'long',
          day: 'numeric',
        });
      }
    } catch {
      // fallback
    }

    return selectedDay;
  }, [selectedDay]);

  // فلترة قائمة العملاء داخل المودال بالاسم أو الجوال
  const filteredModalClients = useMemo(() => {
    if (!clientSearch.trim()) {
return clients;
}

    const q = foldSearch(clientSearch);

    return clients.filter(
      (c) =>
        foldSearch(c.name).includes(q) ||
        (c.phone && c.phone.includes(q)) ||
        (c.email && foldSearch(c.email).includes(q))
    );
  }, [clients, clientSearch]);

  // فلترة المواعيد للعرض بالقائمة أو الشبكة
  const filteredAppointments = useMemo(() => {
    return appointments.filter((a) => {
      // تصفية المحامي
      if (filterLawyer !== 'all' && String(a.lawyerId) !== filterLawyer && a.lawyer !== filterLawyer) {
        return false;
      }

      // تصفية القناة
      if (filterChannel !== 'all' && (a.channel || a.type) !== filterChannel) {
        return false;
      }

      // تصفية الحالة
      if (filterStatus !== 'all') {
        if (filterStatus === 'up' && a.when !== 'up') {
return false;
}

        if (filterStatus === 'past' && a.when !== 'past') {
return false;
}

        if (filterStatus === 'pending' && a.status !== APPT_PENDING) {
return false;
}

        if (filterStatus === 'attended' && a.status !== APPT_ATTENDED) {
return false;
}

        if (filterStatus === 'noshow' && a.status !== APPT_NO_SHOW) {
return false;
}
      }

      // البحث النصي
      if (searchQuery.trim()) {
        const q = foldSearch(searchQuery);
        const clientMatch = a.client?.toLowerCase().includes(q) ?? false;
        const lawyerMatch = a.lawyer?.toLowerCase().includes(q) ?? false;
        const subMatch = a.subject?.toLowerCase().includes(q) ?? false;
        const idMatch = a.id?.toLowerCase().includes(q) ?? false;
        const phoneMatch = a.phone?.includes(q) ?? false;

        if (!clientMatch && !lawyerMatch && !subMatch && !idMatch && !phoneMatch) {
          return false;
        }
      }

      return true;
    });
  }, [appointments, filterLawyer, filterChannel, filterStatus, searchQuery]);

  // إحصائيات المواعيد
  const calculatedCounts = useMemo(() => {
    const todayCount = appointments.filter((a) => a.rawDate === todayISO()).length;
    const upCount = appointments.filter((a) => a.when === 'up').length;
    const vidCount = appointments.filter((a) => a.channel === 'مرئية' || a.ico === 'video').length;
    const offCount = appointments.filter((a) => a.channel === 'حضورية' || a.ico === 'office').length;

    return {
      today: counts?.today ?? todayCount,
      upcoming: counts?.upcoming ?? upCount,
      video: counts?.video ?? vidCount,
      office: counts?.office ?? offCount,
    };
  }, [appointments, counts]);

  const stats: StatItem[] = [
    ['t-cyan', 'cal', calculatedCounts.today, 'مواعيد اليوم'],
    ['t-blue', 'clock', calculatedCounts.upcoming, 'مواعيد قادمة'],
    ['t-green', 'video', calculatedCounts.video, 'جلسات مرئية'],
    ['t-amber', 'office', calculatedCounts.office, 'استشارات حضورية'],
  ];

  // المحامون المعروضون في شبكة التقويم (حسب الفلتر)
  const gridLawyers = useMemo(() => {
    if (filterLawyer === 'all') {
return lawyers;
}

    return lawyers.filter((l) => String(l.id) === filterLawyer || l.name === filterLawyer);
  }, [lawyers, filterLawyer]);

  // مواعيد اليوم المختار لكلّ [lawyerId_HH:MM] — **قائمة** لا موعدٌ واحد: بخيار الحجز المتداخل قد يحمل
  // الوقت نفسه موعدين للمحامي، وكان الثاني يمحو الأوّل من الشبكة
  const dayAppointmentsMap = useMemo(() => {
    const map = new Map<string, AppointmentItem[]>();
    appointments.forEach((a) => {
      if (a.rawDate === selectedDay && a.rawTime && a.lawyerId) {
        const key = `${a.lawyerId}_${a.rawTime.slice(0, 5)}`;
        map.set(key, [...(map.get(key) ?? []), a]);
      }
    });

    return map;
  }, [appointments, selectedDay]);

  /*
   * **صفوف الشبكة: شبكة الدوام + أوقات مواعيد اليوم القائمة.** الشبكة وحدها كانت تُخفي موعداً في يوم
   * عطلة أو خارج الساعات أو على دقيقةٍ غير ساعيّة (14:30) — فيقول الجدول «لا مواعيد» وهي قائمة.
   */
  const dayRows = useMemo(() => {
    const ids = new Set(gridLawyers.map((l) => l.id));
    const times = new Set(dayHours);
    appointments.forEach((a) => {
      if (a.rawDate === selectedDay && a.rawTime && a.lawyerId && ids.has(a.lawyerId)) {
        times.add(a.rawTime.slice(0, 5));
      }
    });

    return [...times].sort();
  }, [dayHours, appointments, selectedDay, gridLawyers]);

  /*
   * **شرائح اليوم كما يحسبها المحرّك** (`LawyerAvailability::daySlotsForMany`) — الشبكة كانت تعرف المواعيد
   * وحدها، فتعرض وقت اجتماعٍ أو جلسة محكمة «احجز الآن» ثمّ يرفضه الخادم «مشغول».
   */
  const [daySlots, setDaySlots] = useState<Record<number, TimeSlotItem[]>>({});
  const gridLawyerIds = gridLawyers.map((l) => l.id).join(',');

  useEffect(() => {
    // بلا مستشارين معروضين لا تُرسم الشبكة أصلاً — فلا طلب
    if (gridLawyerIds === '') {
      return;
    }

    let cancelled = false;
    const query = gridLawyerIds.split(',').map((id) => `lawyer_ids[]=${id}`).join('&');
    fetch(`${apiBase()}/schedule/day-slots?date=${selectedDay}&${query}`, { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : { slots: {} }))
      .then((j: { slots?: Record<number, TimeSlotItem[]> }) => {
        if (!cancelled) {
          setDaySlots(j.slots ?? {});
        }
      })
      .catch(() => {
        if (!cancelled) {
          setDaySlots({});
        }
      });

    return () => {
      cancelled = true;
    };
  }, [selectedDay, gridLawyerIds, appointments]);

  /** شريحة المحرّك لمحامٍ في وقتٍ من اليوم المختار. */
  const engineSlot = useCallback(
    (lawyerId: number, time: string): TimeSlotItem | undefined => daySlots[lawyerId]?.find((x) => x.time === time),
    [daySlots],
  );

  // إحصائيات تفرغ المستشارين لليوم المختار
  const lawyerDailyStats = useMemo(() => {
    const statsMap = new Map<number, { booked: number; free: number; past: number; total: number }>();
    const isToday = selectedDay === todayISO();
    const currentHM = nowHM();

    gridLawyers.forEach((l) => {
      let free = 0;
      let past = 0;

      // الشاغر والمنقضي على شبكة الدوام وحدها؛ والمحجوز عددُ مواعيد اليوم كلّها (ولو خارجها)
      dayHours.forEach((h) => {
        // الموعد وارتباطات المحرّك (اجتماع، جلسة محكمة) كلاهما ليس شاغراً
        if (dayAppointmentsMap.has(`${l.id}_${h}`)) {
          return;
        }

        if (isToday && h <= currentHM) {
          past++;
        } else if (!engineSlot(l.id, h)?.taken) {
          free++;
        }
      });

      const booked = dayRows.reduce((n, h) => n + (dayAppointmentsMap.get(`${l.id}_${h}`)?.length ?? 0), 0);
      statsMap.set(l.id, { booked, free, past, total: dayHours.length });
    });

    return statsMap;
  }, [gridLawyers, dayAppointmentsMap, selectedDay, dayHours, dayRows, engineSlot]);

  /**
   * بطاقة موعدٍ في خانة الشبكة — دالّةٌ واحدة لأنّ الخانة قد تحمل أكثر من موعد (خيار الحجز المتداخل).
   */
  const renderApptCard = (appt: AppointmentItem) => {
    const isVid = appt.channel === 'مرئية' || appt.ico === 'video';
    const isPhone = appt.channel === 'هاتفية' || appt.ico === 'phone';
    const cardBg = isVid
      ? 'linear-gradient(135deg, rgba(6, 182, 212, 0.1) 0%, rgba(6, 182, 212, 0.03) 100%)'
      : isPhone
      ? 'linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.03) 100%)'
      : 'linear-gradient(135deg, rgba(14, 92, 156, 0.09) 0%, rgba(14, 92, 156, 0.02) 100%)';

    const borderCol = isVid ? 'var(--cyan)' : isPhone ? 'var(--amber)' : 'var(--primary)';
    const channelText = isVid ? '🎥 جلسة مرئية' : isPhone ? '📞 مكالمة هاتفية' : '🏢 استشارة حضورية';

    return (
      <div
        key={appt.id}
        onClick={() => setSelectedAppt(appt)}
        style={{
          maxWidth: gridLawyers.length === 1 ? 580 : '100%',
          margin: gridLawyers.length === 1 ? '0 auto' : undefined,
          background: cardBg,
          border: `1px solid ${borderCol}44`,
          borderRight: `4px solid ${borderCol}`,
          borderRadius: 10,
          padding: '9px 14px',
          cursor: 'pointer',
          boxShadow: '0 2px 6px rgba(0,0,0,0.02)',
          transition: 'transform .15s ease, box-shadow .15s ease',
        }}
        title="انقر لعرض تفاصيل الموعد والتحكم به"
        onMouseEnter={(e) => {
          e.currentTarget.style.transform = 'translateY(-2px)';
          e.currentTarget.style.boxShadow = '0 6px 16px rgba(0,0,0,0.06)';
        }}
        onMouseLeave={(e) => {
          e.currentTarget.style.transform = 'translateY(0)';
          e.currentTarget.style.boxShadow = '0 2px 6px rgba(0,0,0,0.02)';
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, marginBottom: 4 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--deep)' }}>
              👤 {mask(appt.client || 'عميل')}
            </span>
          </div>
          <span style={{
            fontSize: 11,
            fontWeight: 700,
            color: borderCol,
            background: '#fff',
            padding: '2px 8px',
            borderRadius: 6,
            border: `1px solid ${borderCol}33`,
          }}>
            {channelText}
          </span>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, fontSize: 11.5 }}>
          <span style={{ color: 'var(--muted)', fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 280 }}>
            📌 {appt.subject || appt.type || 'استشارة قانونية'}
          </span>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexShrink: 0 }}>
            <Badge text={appt.status} tone={appt.tone} />
            <span style={{ fontSize: 10, color: 'var(--muted)', fontWeight: 600 }}>تفاصيل ↗</span>
          </div>
        </div>
      </div>
    );
  };

  return (
    <>
      {reschedule.dialog}
      {/* ── الترويسة الرئيسية والإجراءات السريعة ── */}
      <div className="greet" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 14 }}>
        <div>
          <h2>جدولة وإدارة المواعيد 📅</h2>
          <p>لوحة العمليات التشغيلية لمتابعة تفرغ المستشارين، حجز الاستشارات، ومتابعة الحضور.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
          <div style={{ display: 'inline-flex', background: 'var(--paper)', border: '1px solid var(--line)', borderRadius: 10, padding: 3 }}>
            <button
              type="button"
              className={`btn sm ${viewMode === 'grid' ? '' : 'soft'}`}
              style={{ border: 'none', boxShadow: viewMode === 'grid' ? undefined : 'none' }}
              onClick={() => setViewMode('grid')}
            >
              <Icon name="calgrid" /> شبكة التفرغ والتقويم
            </button>
            <button
              type="button"
              className={`btn sm ${viewMode === 'list' ? '' : 'soft'}`}
              style={{ border: 'none', boxShadow: viewMode === 'list' ? undefined : 'none' }}
              onClick={() => setViewMode('list')}
            >
              <Icon name="ticket" /> قائمة المواعيد
            </button>
          </div>
          {canBook && (
            <>
              <button className="btn" type="button" onClick={openNewBooking}>
                <Icon name="calplus" /> + حجز موعد جديد
              </button>
              {/* عميلٌ بلا استشارةٍ مدفوعة لا يُحجز له — يُطلب له أوّلاً فيُسعَّر ويُسدَّد */}
              <ConsultOnBehalfButton />
            </>
          )}
        </div>
      </div>

      {/* ── شريط المؤشرات والإحصائيات ── */}
      <StatRow items={stats} />

      {/* ── شريط الفلاتر والبحث والتنقل ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-b" style={{ padding: '14px 18px', display: 'flex', flexWrap: 'wrap', gap: 12, alignItems: 'center', justifyContent: 'space-between' }}>
          {/* محدد اليوم في عرض الشبكة */}
          {viewMode === 'grid' && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <button className="btn soft sm" type="button" onClick={() => shiftDay(-1)} title="اليوم السابق">
                ◀
              </button>
              <button
                className={`btn sm ${selectedDay === todayISO() ? '' : 'soft'}`}
                type="button"
                onClick={() => setSelectedDay(todayISO())}
              >
                اليوم
              </button>
              <button className="btn soft sm" type="button" onClick={() => shiftDay(1)} title="اليوم التالي">
                ▶
              </button>
              <input
                type="date"
                className="input"
                style={{ width: 145, padding: '6px 10px', fontSize: 13 }}
                value={selectedDay}
                onChange={(e) => setSelectedDay(e.target.value)}
              />
              <span style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--deep)', marginInlineStart: 4 }}>
                {formattedSelectedDay}
              </span>
            </div>
          )}

          {/* محدد البحث العام في عرض القائمة */}
          {viewMode === 'list' && (
            <div className="search" style={{ width: 280 }}>
              <Icon name="search" />
              <input
                placeholder="بحث بالعميل، المستشار، الموضوع..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              />
              {searchQuery && (
                <button type="button" onClick={() => setSearchQuery('')} style={{ color: 'var(--faint)' }}>
                  <Icon name="close" />
                </button>
              )}
            </div>
          )}

          {/* الفلاتر المنسدلة المشتركة */}
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center', marginInlineStart: 'auto' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>المستشار:</span>
              <select
                value={filterLawyer}
                onChange={(e) => setFilterLawyer(e.target.value)}
                style={{ width: 150, padding: '6px 28px 6px 10px', fontSize: 13 }}
              >
                <option value="all">كل المستشارين</option>
                {lawyers.map((l) => (
                  <option key={l.id} value={String(l.id)}>
                    {l.name}
                  </option>
                ))}
              </select>
            </div>

            {viewMode === 'list' && (
              <>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>النوع:</span>
                  <select
                    value={filterChannel}
                    onChange={(e) => setFilterChannel(e.target.value)}
                    style={{ width: 130, padding: '6px 28px 6px 10px', fontSize: 13 }}
                  >
                    <option value="all">كل الأنواع</option>
                    <option value="حضورية">حضورية 🏢</option>
                    <option value="مرئية">مرئية 🎥</option>
                    <option value="هاتفية">هاتفية 📞</option>
                  </select>
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>الحالة:</span>
                  <select
                    value={filterStatus}
                    onChange={(e) => setFilterStatus(e.target.value)}
                    style={{ width: 130, padding: '6px 28px 6px 10px', fontSize: 13 }}
                  >
                    <option value="all">كل الحالات</option>
                    <option value="up">مواعيد قادمة</option>
                    <option value="pending">بانتظار اعتماد الإدارة</option>
                    <option value="attended">تم الحضور</option>
                    <option value="noshow">لم يحضر</option>
                    <option value="past">مواعيد سابقة</option>
                  </select>
                </div>
              </>
            )}
          </div>
        </div>
      </div>

      {/* ── العرض 1: شبكة تفرغ الفريق والتقويم التفاعلي (Resource Grid) ── */}
      {viewMode === 'grid' && (
        <div className="card" style={{ overflow: 'hidden' }}>
          {/* رأس البطاقة والشرح ودليل الحالات */}
          <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 14 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <div style={{
                width: 38,
                height: 38,
                borderRadius: 10,
                background: 'rgba(14, 92, 156, 0.1)',
                color: 'var(--primary)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: 18,
              }}>
                <Icon name="calgrid" />
              </div>
              <div>
                <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800 }}>شبكة تفرغ المستشارين</h3>
                <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2, display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span>📅 ليوم {formattedSelectedDay}</span>
                  <span>•</span>
                  <span>انقر على أي فترة شاغرة لحجز موعد فوري</span>
                </div>
              </div>
            </div>

            {/* دليل الحالات (Status Legend) */}
            <div style={{
              display: 'flex',
              alignItems: 'center',
              gap: 10,
              flexWrap: 'wrap',
              background: 'var(--paper-2)',
              padding: '6px 12px',
              borderRadius: 8,
              border: '1px solid var(--line-soft)',
              fontSize: 11.5,
              fontWeight: 600,
            }}>
              <span style={{ display: 'flex', alignItems: 'center', gap: 5, color: '#047857' }}>
                <span style={{ width: 8, height: 8, borderRadius: '50%', background: '#10b981' }} />
                متاح للحجز
              </span>
              <span style={{ color: 'var(--line)' }}>|</span>
              <span style={{ display: 'flex', alignItems: 'center', gap: 5, color: 'var(--primary)' }}>
                <span>🏢</span> حضورية
              </span>
              <span style={{ color: 'var(--line)' }}>|</span>
              <span style={{ display: 'flex', alignItems: 'center', gap: 5, color: 'var(--cyan)' }}>
                <span>🎥</span> مرئية
              </span>
              <span style={{ color: 'var(--line)' }}>|</span>
              <span style={{ display: 'flex', alignItems: 'center', gap: 5, color: 'var(--amber)' }}>
                <span>📞</span> هاتفية
              </span>
              <span style={{ color: 'var(--line)' }}>|</span>
              <span style={{ display: 'flex', alignItems: 'center', gap: 5, color: 'var(--muted)' }}>
                <span style={{ width: 8, height: 8, borderRadius: '50%', background: 'var(--line)' }} />
                منقضية
              </span>
            </div>
          </div>

          {/* في حالة اختيار مستشار واحد: بطاقة هوية المستشار وإحصائيات طاقة اليوم */}
          {gridLawyers.length === 1 && (() => {
            const singleLawyer = gridLawyers[0];
            const stats = lawyerDailyStats.get(singleLawyer.id) || { booked: 0, free: 0, past: 0, total: dayHours.length };
            // يوم العطلة بلا شرائح (`total` = 0) — لا قسمة على صفر تُظهر «NaN%»
            const freePercent = stats.total > 0 ? Math.round((stats.free / stats.total) * 100) : 0;

            return (
              <div style={{
                background: 'linear-gradient(135deg, rgba(14, 92, 156, 0.04) 0%, rgba(17, 160, 200, 0.06) 100%)',
                borderBottom: '1px solid var(--line-soft)',
                padding: '14px 20px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                flexWrap: 'wrap',
                gap: 16,
              }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
                  <div style={{
                    width: 48,
                    height: 48,
                    borderRadius: '50%',
                    background: 'linear-gradient(135deg, var(--primary) 0%, #1e40af 100%)',
                    color: '#fff',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: 16,
                    fontWeight: 800,
                    boxShadow: '0 4px 10px rgba(14, 92, 156, 0.25)',
                    border: '2px solid #fff',
                  }}>
                    {getInitials(singleLawyer.name)}
                  </div>
                  <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <span style={{ fontSize: 16, fontWeight: 800, color: 'var(--deep)' }}>{singleLawyer.name}</span>
                      <span style={{
                        fontSize: 11,
                        background: 'rgba(14, 92, 156, 0.1)',
                        color: 'var(--primary)',
                        padding: '2px 8px',
                        borderRadius: 12,
                        fontWeight: 700,
                      }}>
                        {singleLawyer.dept || 'القسم القانوني'}
                      </span>
                    </div>
                    <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 3 }}>
                      جدول التفرغ وساعات الاستشارات لليوم المحدد ({dayHours.length} فترات زمنية)
                    </div>
                  </div>
                </div>

                {/* إحصائيات التفرغ اليومي لهذا المستشار */}
                <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                  <div style={{
                    background: '#fff',
                    border: '1px solid #10b98133',
                    padding: '6px 14px',
                    borderRadius: 8,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8,
                    boxShadow: '0 1px 3px rgba(0,0,0,0.02)',
                  }}>
                    <span style={{ width: 8, height: 8, borderRadius: '50%', background: '#10b981' }} />
                    <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>المتاح للحجز:</span>
                    <b style={{ fontSize: 14, color: '#047857' }}>{stats.free} فترة</b>
                  </div>

                  <div style={{
                    background: '#fff',
                    border: '1px solid var(--primary)33',
                    padding: '6px 14px',
                    borderRadius: 8,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8,
                    boxShadow: '0 1px 3px rgba(0,0,0,0.02)',
                  }}>
                    <span style={{ width: 8, height: 8, borderRadius: '50%', background: 'var(--primary)' }} />
                    <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>المحجوز:</span>
                    <b style={{ fontSize: 14, color: 'var(--primary)' }}>{stats.booked} موعد</b>
                  </div>

                  {stats.past > 0 && (
                    <div style={{
                      background: '#fff',
                      border: '1px solid var(--line-soft)',
                      padding: '6px 14px',
                      borderRadius: 8,
                      display: 'flex',
                      alignItems: 'center',
                      gap: 8,
                      boxShadow: '0 1px 3px rgba(0,0,0,0.02)',
                    }}>
                      <span style={{ width: 8, height: 8, borderRadius: '50%', background: 'var(--line)' }} />
                      <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>منقضية:</span>
                      <b style={{ fontSize: 14, color: 'var(--muted)' }}>{stats.past}</b>
                    </div>
                  )}

                  <div style={{
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'center',
                    minWidth: 100,
                    padding: '4px 8px',
                  }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: 'var(--muted)', fontWeight: 600, marginBottom: 3 }}>
                      <span>نسبة الشغور</span>
                      <b style={{ color: freePercent > 0 ? '#047857' : 'var(--muted)' }}>{freePercent}%</b>
                    </div>
                    <div style={{ width: '100%', height: 6, background: '#e2e8f0', borderRadius: 3, overflow: 'hidden' }}>
                      <div style={{ width: `${freePercent}%`, height: '100%', background: freePercent > 20 ? '#10b981' : '#f59e0b', borderRadius: 3, transition: 'width .3s' }} />
                    </div>
                  </div>
                </div>
              </div>
            );
          })()}

          {/* في حالة عرض كافة المستشارين: شريط ملخص إجمالي الفريق */}
          {gridLawyers.length > 1 && (() => {
            let totalFree = 0;
            let totalBooked = 0;
            gridLawyers.forEach((l) => {
              const st = lawyerDailyStats.get(l.id);
              if (st) {
                totalFree += st.free;
                totalBooked += st.booked;
              }
            });

            return (
              <div style={{
                background: 'var(--paper-2)',
                borderBottom: '1px solid var(--line-soft)',
                padding: '10px 20px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                flexWrap: 'wrap',
                gap: 12,
                fontSize: 12.5,
              }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--deep)', fontWeight: 600 }}>
                  <span>👥 إجمالي المستشارين المعروضين: <b>{gridLawyers.length}</b></span>
                  <span>•</span>
                  <span>إجمالي فترات الحجز المتاحة اليوم: <b style={{ color: '#047857' }}>{totalFree} فترة شاغرة</b></span>
                  <span>•</span>
                  <span>المواعيد المحجوزة: <b style={{ color: 'var(--primary)' }}>{totalBooked} موعد</b></span>
                </div>
                <div style={{ fontSize: 11.5, color: 'var(--muted)' }}>
                  مرّر أفقياً لاستعراض جدول كافة المستشارين
                </div>
              </div>
            );
          })()}

          <div className="card-b t-wrap" style={{ padding: 0 }}>
            {gridLawyers.length === 0 ? (
              <div className="empty" style={{ padding: 48 }}>
                <Icon name="user" />
                <b>لا يوجد مستشارون مسجلون مطابقون للفلترة</b>
                <p style={{ fontSize: 12, color: 'var(--muted)' }}>يرجى تغيير خيارات الفلترة أو إضافة مستشارين للنظام</p>
              </div>
            ) : (
              <table className="tbl" style={{ borderCollapse: 'separate', borderSpacing: 0, minWidth: gridLawyers.length === 1 ? '100%' : 780 }}>
                <thead>
                  <tr style={{ background: 'var(--paper-2)' }}>
                    <th style={{
                      width: 110,
                      minWidth: 110,
                      maxWidth: 120,
                      textAlign: 'center',
                      borderRight: '1px solid var(--line-soft)',
                      padding: '14px 10px',
                      background: 'var(--paper-2)',
                    }}>
                      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6, color: 'var(--deep)', fontSize: 13, fontWeight: 800 }}>
                        <Icon name="clock" />
                        <span>الوقت</span>
                      </div>
                    </th>

                    {gridLawyers.map((l) => {
                      const st = lawyerDailyStats.get(l.id);
                      const freeCount = st?.free ?? 0;

                      return (
                        <th key={l.id} style={{
                          minWidth: gridLawyers.length === 1 ? 'auto' : 240,
                          padding: '12px 16px',
                          borderRight: '1px solid var(--line-soft)',
                        }}>
                          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                              <div style={{
                                width: 34,
                                height: 34,
                                borderRadius: '50%',
                                background: 'linear-gradient(135deg, var(--primary) 0%, #1e40af 100%)',
                                color: '#fff',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                fontSize: 12.5,
                                fontWeight: 800,
                                flexShrink: 0,
                              }}>
                                {getInitials(l.name)}
                              </div>
                              <div>
                                <div style={{ fontWeight: 800, color: 'var(--deep)', fontSize: 13.5 }}>{l.name}</div>
                                <div style={{ fontSize: 11, color: 'var(--muted)', fontWeight: 500 }}>{l.dept || 'القسم القانوني'}</div>
                                <PresenceBadge userId={l.id} showFree />
                              </div>
                            </div>

                            {/* شارة التفرغ لليوم — ويوم العطلة (شبكته فارغة من `gridOn`) ليس «مكتملاً» */}
                            {dayHours.length === 0 ? (
                              <span style={{
                                fontSize: 11,
                                fontWeight: 700,
                                background: 'var(--paper-2)',
                                color: 'var(--muted)',
                                border: '1px solid var(--line-soft)',
                                padding: '3px 8px',
                                borderRadius: 12,
                                whiteSpace: 'nowrap',
                              }}>
                                {st?.booked ? `عطلة · ${st.booked} موعد` : 'عطلة'}
                              </span>
                            ) : freeCount > 0 ? (
                              <span style={{
                                fontSize: 11,
                                fontWeight: 700,
                                background: 'rgba(16, 185, 129, 0.1)',
                                color: '#047857',
                                border: '1px solid rgba(16, 185, 129, 0.25)',
                                padding: '3px 8px',
                                borderRadius: 12,
                                whiteSpace: 'nowrap',
                              }}>
                                🟢 {freeCount} متاحة
                              </span>
                            ) : (
                              <span style={{
                                fontSize: 11,
                                fontWeight: 700,
                                background: 'rgba(239, 68, 68, 0.1)',
                                color: '#b91c1c',
                                border: '1px solid rgba(239, 68, 68, 0.25)',
                                padding: '3px 8px',
                                borderRadius: 12,
                                whiteSpace: 'nowrap',
                              }}>
                                🔴 مكتمل اليوم
                              </span>
                            )}
                          </div>
                        </th>
                      );
                    })}
                  </tr>
                </thead>
                <tbody>
                  {dayRows.map((hourStr) => {
                    const isOfficeRow = dayHours.includes(hourStr);
                    const isSlotPast = selectedDay === todayISO() && hourStr <= nowHM();
                    const endH = slotEnd(hourStr, slotMinutes);
                    // فترة **وقت النهاية المعروض** — كانت من ساعة البداية فتُكتب «12:20 صباحاً»
                    const period = periodOf(endH);

                    return (
                      <tr key={hourStr} style={{ borderBottom: '1px solid var(--line-soft)' }}>
                        {/* عمود الوقت الأنيق */}
                        <td
                          style={{
                            textAlign: 'center',
                            background: 'var(--paper-2)',
                            borderRight: '1px solid var(--line-soft)',
                            padding: '12px 8px',
                            verticalAlign: 'middle',
                          }}
                        >
                          <div style={{ fontWeight: 800, fontSize: 13.5, color: 'var(--deep)' }}>
                            {hourStr}
                          </div>
                          <div style={{ fontSize: 10.5, color: 'var(--muted)', fontWeight: 600, marginTop: 2 }}>
                            {endH} {period}
                          </div>
                        </td>

                        {gridLawyers.map((l) => {
                          const appts = dayAppointmentsMap.get(`${l.id}_${hourStr}`) ?? [];

                          if (appts.length > 0) {
                            // ── الخانة محجوزة — بكلّ مواعيدها ──
                            return (
                              <td
                                key={l.id}
                                style={{
                                  padding: '8px 12px',
                                  verticalAlign: 'middle',
                                  borderRight: '1px solid var(--line-soft)',
                                }}
                              >
                                <div style={{ display: 'grid', gap: 6 }}>{appts.map(renderApptCard)}</div>
                              </td>
                            );
                          }

                          // صفٌّ خارج شبكة الدوام (أُضيف لموعدٍ قائم): الخانة الفارغة بلا زرّ حجز
                          if (!isOfficeRow) {
                            return <td key={l.id} style={{ borderRight: '1px solid var(--line-soft)' }} />;
                          }

                          // ── وقتٌ يشغله ارتباطٌ غير الموعد (اجتماع، جلسة محكمة) — من المحرّك ──
                          const engine = engineSlot(l.id, hourStr);

                          if (!isSlotPast && engine?.taken) {
                            // `hard` لوقتٍ لم يمضِ = جلسة محكمة: لا تُتجاوز ولو سُمح بالحجز المتداخل
                            const inCourt = engine.hard === true;

                            return (
                              <td key={l.id} style={{ padding: '8px 12px', verticalAlign: 'middle', borderRight: '1px solid var(--line-soft)' }}>
                                <div
                                  style={{
                                    maxWidth: gridLawyers.length === 1 ? 580 : '100%',
                                    margin: gridLawyers.length === 1 ? '0 auto' : undefined,
                                    padding: '9px 14px',
                                    borderRadius: 10,
                                    border: '1px dashed var(--amber, #d97706)',
                                    background: 'var(--paper-2)',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'space-between',
                                    gap: 10,
                                    fontSize: 12.5,
                                    fontWeight: 700,
                                    color: 'var(--muted)',
                                  }}
                                >
                                  <span>{inCourt ? '⚖️ جلسة محكمة' : '⏳ مشغول — ارتباطٌ آخر'}</span>
                                  {canBook && allowOverlap && !inCourt && (
                                    <button className="btn soft sm" type="button" onClick={() => openBookingForSlot(l.id, selectedDay, hourStr)}>
                                      حجز رغم الانشغال
                                    </button>
                                  )}
                                </div>
                              </td>
                            );
                          }

                          // ── الخانة شاغرة (متاحة للحجز) ──
                          return (
                            <td
                              key={l.id}
                              style={{
                                padding: '8px 12px',
                                verticalAlign: 'middle',
                                borderRight: '1px solid var(--line-soft)',
                              }}
                            >
                              {canBook ? (
                                <button
                                  type="button"
                                  onClick={() => openBookingForSlot(l.id, selectedDay, hourStr)}
                                  disabled={isSlotPast}
                                  style={{
                                    width: '100%',
                                    maxWidth: gridLawyers.length === 1 ? 580 : '100%',
                                    margin: gridLawyers.length === 1 ? '0 auto' : undefined,
                                    padding: '9px 14px',
                                    borderRadius: 10,
                                    border: isSlotPast
                                      ? '1px dashed #cbd5e1'
                                      : '1.5px dashed rgba(16, 185, 129, 0.45)',
                                    background: isSlotPast
                                      ? '#f8fafc'
                                      : 'linear-gradient(135deg, rgba(16, 185, 129, 0.04) 0%, #ffffff 100%)',
                                    cursor: isSlotPast ? 'not-allowed' : 'pointer',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'space-between',
                                    gap: 10,
                                    transition: 'all .18s ease',
                                    boxShadow: isSlotPast ? 'none' : '0 1px 2px rgba(16, 185, 129, 0.05)',
                                  }}
                                  onMouseEnter={(e) => {
                                    if (!isSlotPast) {
                                      e.currentTarget.style.borderColor = '#10b981';
                                      e.currentTarget.style.background = 'linear-gradient(135deg, rgba(16, 185, 129, 0.12) 0%, rgba(16, 185, 129, 0.04) 100%)';
                                      e.currentTarget.style.transform = 'translateY(-1px)';
                                      e.currentTarget.style.boxShadow = '0 4px 12px rgba(16, 185, 129, 0.15)';
                                    }
                                  }}
                                  onMouseLeave={(e) => {
                                    if (!isSlotPast) {
                                      e.currentTarget.style.borderColor = 'rgba(16, 185, 129, 0.45)';
                                      e.currentTarget.style.background = 'linear-gradient(135deg, rgba(16, 185, 129, 0.04) 0%, #ffffff 100%)';
                                      e.currentTarget.style.transform = 'translateY(0)';
                                      e.currentTarget.style.boxShadow = '0 1px 2px rgba(16, 185, 129, 0.05)';
                                    }
                                  }}
                                  title={isSlotPast ? 'هذه الفترة الزمنية انقضت اليوم' : `انقر لحجز استشارة مع ${l.name} في تمام الساعة ${hourStr}`}
                                >
                                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                    <span style={{
                                      width: 8,
                                      height: 8,
                                      borderRadius: '50%',
                                      background: isSlotPast ? '#94a3b8' : '#10b981',
                                      flexShrink: 0,
                                    }} />
                                    <div style={{ textAlign: 'right' }}>
                                      <div style={{
                                        fontSize: 12.5,
                                        fontWeight: 700,
                                        color: isSlotPast ? '#94a3b8' : '#047857',
                                      }}>
                                        {isSlotPast ? 'فترة منقضية' : 'متاح للحجز الفوري'}
                                      </div>
                                      <div style={{
                                        fontSize: 10.5,
                                        color: isSlotPast ? '#cbd5e1' : '#059669',
                                        fontWeight: 500,
                                      }}>
                                        {/* بدء الموعد وحده: الجلسة تنتهي بإنهائها لا بطول الشريحة (قرار المالك 2026-09-26) */}
                                        يبدأ {hourStr}
                                      </div>
                                    </div>
                                  </div>

                                  {!isSlotPast && (
                                    <span style={{
                                      fontSize: 11.5,
                                      fontWeight: 700,
                                      color: '#047857',
                                      background: '#fff',
                                      border: '1px solid rgba(16, 185, 129, 0.35)',
                                      padding: '4px 10px',
                                      borderRadius: 6,
                                      boxShadow: '0 1px 2px rgba(0,0,0,0.03)',
                                    }}>
                                      + احجز الآن
                                    </span>
                                  )}
                                </button>
                              ) : (
                                <div
                                  style={{
                                    width: '100%',
                                    maxWidth: gridLawyers.length === 1 ? 580 : '100%',
                                    margin: gridLawyers.length === 1 ? '0 auto' : undefined,
                                    padding: '8px 12px',
                                    borderRadius: 10,
                                    border: '1px solid var(--line-soft)',
                                    background: isSlotPast ? 'var(--paper-2)' : 'var(--paper)',
                                    color: isSlotPast ? 'var(--faint)' : 'var(--muted)',
                                    fontSize: 11.5,
                                    fontWeight: 600,
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'space-between',
                                  }}
                                >
                                  <span>{isSlotPast ? '— فترة منقضية' : 'شاغر للمواعيد'}</span>
                                  <span style={{ fontSize: 10.5, color: 'var(--muted)' }}>{hourStr} - {endH}</span>
                                </div>
                              )}
                            </td>
                          );
                        })}
                      </tr>
                    );
                  })}
                  {dayRows.length === 0 && (
                    <tr>
                      <td colSpan={gridLawyers.length + 1} style={{ textAlign: 'center', padding: 32, color: 'var(--muted)' }}>
                        يوم عطلة — خارج أيّام دوام المكتب، فلا مواعيد للحجز فيه.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            )}
          </div>
        </div>
      )}

      {/* ── العرض 2: جدول قائمة المواعيد الشامل ── */}
      {viewMode === 'list' && (
        <div className="card">
          <div className="card-h">
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <Icon name="ticket" />
              <h3>سجل المواعيد والارتباطات</h3>
            </div>
            <span className="sub">{filteredAppointments.length} موعد</span>
          </div>

          <div className="card-b t-wrap" style={{ padding: 0 }}>
            {filteredAppointments.length > 0 ? (
              <table className="tbl" style={{ minWidth: 780 }}>
                <thead>
                  <tr>
                    <th style={{ width: 150 }}>النوع والقناة</th>
                    <th style={{ minWidth: 150, maxWidth: 220 }}>العميل</th>
                    <th>المستشار المكلف</th>
                    <th>التاريخ والوقت</th>
                    <th style={{ minWidth: 140, maxWidth: 220 }}>المكان / الرابط</th>
                    <th>الحالة والسداد</th>
                    <th style={{ width: 100, textAlign: 'center' }}>الإجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredAppointments.map((a) => (
                    <tr key={a.id} className="click" onClick={() => setSelectedAppt(a)}>
                      <td style={{ width: 150 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                          <div
                            style={{
                              width: 32,
                              height: 32,
                              borderRadius: 8,
                              display: 'grid',
                              placeItems: 'center',
                              background:
                                a.channel === 'مرئية' || a.ico === 'video'
                                  ? 'rgba(17,160,200,.12)'
                                  : a.channel === 'هاتفية' || a.ico === 'phone'
                                  ? 'var(--amber-bg)'
                                  : 'rgba(14,92,156,.1)',
                              color:
                                a.channel === 'مرئية' || a.ico === 'video'
                                  ? 'var(--cyan)'
                                  : a.channel === 'هاتفية' || a.ico === 'phone'
                                  ? 'var(--amber)'
                                  : 'var(--primary)',
                            }}
                          >
                            <Icon name={a.channel === 'مرئية' || a.ico === 'video' ? 'video' : a.channel === 'هاتفية' || a.ico === 'phone' ? 'phone' : 'office'} />
                          </div>
                          <div>
                            <b style={{ fontSize: 13, color: 'var(--ink)' }}>{a.type}</b>
                            {a.consultRef && <div className="sub" style={{ fontSize: 11 }}>{a.consultRef}</div>}
                          </div>
                        </div>
                      </td>
                      <td style={{ minWidth: 150, maxWidth: 220 }}>
                        <b title={a.client || '—'}>{truncateWords(mask(a.client || '—'), 4)}</b>
                        {a.phone && <div className="sub">{a.phone}</div>}
                      </td>
                      <td className="nowrap">
                        <b title={a.lawyer || '—'}>{truncateWords(a.lawyer || '—', 4)}</b>
                      </td>
                      <td className="nowrap">
                        <div><b>{a.day || '—'}</b></div>
                        <span className="muted">{a.time || '—'}</span>
                      </td>
                      <td style={{ minWidth: 140, maxWidth: 220 }}>
                        <span className="muted" title={a.place || (a.channel === 'مرئية' ? 'جلسة إلكترونية' : '—')}>
                          {truncateWords(a.place || (a.channel === 'مرئية' ? 'جلسة إلكترونية' : '—'), 4)}
                        </span>
                      </td>
                      <td className="nowrap">
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 4, alignItems: 'flex-start' }}>
                          <Badge text={a.status} tone={a.tone} />
                          {a.pay && (
                            <span style={{ fontSize: 11, color: a.pay === 'مدفوع' ? 'var(--success)' : 'var(--amber)', fontWeight: 700 }}>
                              {a.pay}
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="nowrap" style={{ textAlign: 'center' }}>
                        <div style={{ display: 'flex', gap: 6 }} onClick={(e) => e.stopPropagation()}>
                          <button
                            className="btn soft sm"
                            type="button"
                            onClick={() => setSelectedAppt(a)}
                            title="عرض التفاصيل"
                          >
                            <Icon name="info" /> التفاصيل
                          </button>
                          {canVideo && a.joinLink && (
                            <a
                              className="btn sm"
                              href={a.joinLink}
                              target="_blank"
                              rel="noopener noreferrer"
                              title="الدخول إلى غرفة الجلسة المرئية"
                            >
                              <Icon name="video" /> الغرفة
                            </a>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <div className="empty">
                <Icon name="cal" />
                <b>{appointments.length === 0 ? 'لا توجد مواعيد في هذه النافذة' : 'لا توجد مواعيد مطابقة لخيارات البحث والتصفية'}</b>
                {(searchQuery || filterLawyer !== 'all' || filterChannel !== 'all' || filterStatus !== 'all') && (
                  <button
                    className="btn soft sm"
                    type="button"
                    style={{ marginTop: 10 }}
                    onClick={() => {
                      setSearchQuery('');
                      setFilterLawyer('all');
                      setFilterChannel('all');
                      setFilterStatus('all');
                    }}
                  >
                    إعادة ضبط الفلاتر
                  </button>
                )}
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── مودال 1: نافذة حجز موعد جديد الذكية ── */}
      <Modal
        title="جدولة موعد استشارة جديد"
        subtitle="إنشاء وحجز موعد حقيقي في النظام وإسناده للمستشار"
        open={bookOpen}
        onClose={() => setBookOpen(false)}
        maxWidth={620}
      >
        <div style={{ padding: '4px 0' }}>
          {clients.length === 0 && (
            <div className="empty" style={{ padding: 14 }}>
              <Icon name="user" />
              <b>لا يوجد عملاء مسجلون بعد</b>
            </div>
          )}

          {/* العميل مع ميزة البحث السريع */}
          <div className="field">
            <label>
              العميل <span className="req">*</span>
            </label>
            <div style={{ display: 'flex', gap: 8, marginBottom: 6 }}>
              <input
                className="input"
                style={{ padding: '7px 10px', fontSize: 13 }}
                placeholder="🔍 تصفية العملاء بالاسم أو الجوال..."
                value={clientSearch}
                onChange={(e) => setClientSearch(e.target.value)}
              />
            </div>
            <select
              value={clientId}
              onChange={(e) => pickClient(Number(e.target.value), true)}
            >
              <option value="" disabled>-- اختر العميل --</option>
              {filteredModalClients.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} {c.phone ? `(${c.phone})` : ''}
                </option>
              ))}
            </select>
          </div>

          {/* الاستشارة المدفوعة التي يُحجز لها الموعد — الخادم لا يخمّنها */}
          <div className="field">
            <label>الاستشارة المدفوعة <span className="req">*</span></label>
            {clientConsults.length > 0 ? (
              <select
                value={consultId}
                onChange={(e) => selectConsult(clientConsults.find((c) => c.id === Number(e.target.value)), true)}
              >
                {clientConsults.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.ref} — {c.subject || 'بلا موضوع'}{c.ticketNo ? ` (تذكرة ${c.ticketNo})` : ''}
                  </option>
                ))}
              </select>
            ) : (
              <div style={{ color: 'var(--red)', fontSize: 12.5 }}>
                ⚠️ لا توجد لهذا العميل استشارة مدفوعة بانتظار موعد — تُطلب الاستشارة وتُسعَّر وتُسدَّد أوّلاً.
              </div>
            )}
          </div>

          {/* المستشار ونوع الاستشارة */}
          <div className="grid-2" style={{ gap: 14 }}>
            <div className="field">
              <label>المستشار المكلف</label>
              <select
                value={lawyerId}
                onChange={(e) => {
                  setLawyerId(Number(e.target.value));
                  setTime('');
                }}
              >
                {lawyers.map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.name} {l.dept ? `(${l.dept})` : ''}{inSessionSuffix(inSession, l.id)}
                  </option>
                ))}
              </select>
            </div>

            <div className="field">
              <label>قناة ونوع الاستشارة <span className="req">*</span></label>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 110px), 1fr))', gap: 6 }}>
                {TYPES.map(([v, l, icon]) => (
                  <button
                    key={v}
                    type="button"
                    className={`btn sm ${type === v ? '' : 'soft'}`}
                    style={{ padding: '8px 4px', fontSize: 12.5 }}
                    onClick={() => setType(v)}
                  >
                    <Icon name={icon} /> {l}
                  </button>
                ))}
              </div>
            </div>
          </div>

          {/* موضوع الاستشارة والتاريخ */}
          <div className="grid-2" style={{ gap: 14 }}>
            <div className="field">
              <label>موضوع الاستشارة</label>
              <input
                className="input"
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
                placeholder="مثال: استشارة عقارية / مراجعة عقد"
              />
            </div>

            <div className="field">
              <label>تاريخ الموعد <span className="req">*</span></label>
              <input
                className="input"
                type="date"
                min={todayISO()}
                value={date}
                onChange={(e) => {
                  setDate(e.target.value);
                  setTime('');
                }}
              />
            </div>
          </div>

          {/*
            منتقي الفترات الزمنية التفاعلية الحقيقية — ومعه الدقيقة المخصّصة (`allowCustom`).
            الخادم يقبل أيّ دقيقة صالحة لا شبكةً ساعيّة (`Employee\ScheduleController` ⇐ `date_format:H:i`،
            ويحرسه `ConsultBookingCustomTimeTest`)، وكان المنتقي يحصر الموظّف في الفترات المعروضة فيتعذّر
            موعدٌ اتُّفق عليه مع العميل في 11:20 مثلاً. والحارسان باقيان: الماضي مرفوضٌ هنا، والتعارض مع
            موعدٍ آخر يردّه الخادم برسالة صريحة. (قرار المالك 2026-09-20)
          */}
          <TimeSlotPicker
            value={time}
            onChange={setTime}
            date={date}
            slots={slots.length > 0 ? slots : gridOn(date) /* قبل اختيار المستشار: شبكة الحجز لا شبكة المنتقي العامّة */}
            label="الوقت المتاح للموعد"
            emptyText="لا مواعيد للحجز في هذا اليوم — خارج أيّام دوام المكتب."
            allowTaken={allowOverlap}
            helperText={slotsLoading ? 'جارٍ فحص الأوقات المتاحة لدى المستشار…' : undefined}
            required
            allowCustom
/>

          {isPast && (
            <div style={{ color: 'var(--red)', fontSize: 12.5, margin: '4px 0 10px' }}>
              ⚠️ الوقت المختار مضى — يرجى اختيار وقت لاحق.
            </div>
          )}

          {/* أزرار الإجراءات */}
          <div style={{ display: 'flex', gap: 10, marginTop: 18, borderTop: '1px solid var(--line-soft)', paddingTop: 14 }}>
            <button
              className="btn soft block"
              type="button"
              onClick={() => setBookOpen(false)}
            >
              إلغاء
            </button>
            <button
              className="btn block"
              type="button"
              onClick={submitBooking}
              disabled={busy || isPast || !clientId || !consultId || !date || !time}
            >
              <Icon name="calplus" /> {busy ? 'جارٍ الحفظ…' : 'تأكيد الجدولة وحفظ الموعد'}
            </button>
          </div>
        </div>
      </Modal>

      {/* ── مودال 2: بطاقة تفاصيل الموعد والإجراءات ── */}
      {selectedAppt && (
        <Modal
          title={`تفاصيل الموعد: ${selectedAppt.id}`}
          badge={<Badge text={selectedAppt.status} tone={selectedAppt.tone} />}
          open={!!selectedAppt}
          onClose={() => setSelectedAppt(null)}
          maxWidth={540}
        >
          <div style={{ padding: '6px 0' }}>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12, background: 'var(--paper-2)', padding: 14, borderRadius: 12, marginBottom: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span className="muted">العميل:</span>
                <b>{mask(selectedAppt.client || '—')}</b>
              </div>
              {selectedAppt.phone && (
                <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                  <span className="muted">الهاتف:</span>
                  <a href={`tel:${selectedAppt.phone}`} style={{ color: 'var(--primary)', fontWeight: 700 }}>
                    {selectedAppt.phone}
                  </a>
                </div>
              )}
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span className="muted">المستشار المكلف:</span>
                <b>{selectedAppt.lawyer || '—'}</b>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span className="muted">تاريخ ووقت الموعد:</span>
                <b>{selectedAppt.day} · {selectedAppt.time}</b>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span className="muted">القناة والمكان:</span>
                <span>{selectedAppt.place || (selectedAppt.channel === 'مرئية' ? 'جلسة إلكترونية' : '—')}</span>
              </div>
              {selectedAppt.pay && (
                <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                  <span className="muted">حالة السداد:</span>
                  <b style={{ color: selectedAppt.pay === 'مدفوع' ? 'var(--success)' : 'var(--amber)' }}>
                    {selectedAppt.pay}
                  </b>
                </div>
              )}
            </div>

            {canVideo && selectedAppt.joinLink && (
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 10 }}>
                <a
                  className="btn block"
                  href={selectedAppt.joinLink}
                  target="_blank"
                  rel="noopener noreferrer"
                  style={{ flex: 1 }}
                >
                  <Icon name="video" /> دخول غرفة الجلسة المرئية
                </a>
              </div>
            )}

            {/* أزرار إدارة الموعد — إعادة الجدولة ووسم لم يحضر لمن يملك صلاحية إدارة المواعيد */}
            {canManage && selectedAppt.consultId && selectedAppt.status !== APPT_PENDING
              && (selectedAppt.consultCanReschedule || selectedAppt.consultCanMarkNoShow) && (
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 10 }}>
                {selectedAppt.consultCanReschedule && (
                <button
                  className="btn soft sm"
                  type="button"
                  style={{ flex: 1 }}
                  // كانت تُرسل بلا تأكيد، وتقول «طُلب من العميل اختيار موعد» والعميل لا يختاره
                  onClick={() => reschedule.open({
                    id: selectedAppt.consultId as number,
                    ref: selectedAppt.consultRef ?? '',
                    channel: selectedAppt.channel,
                    rescheduleCount: selectedAppt.consultRescheduleCount,
                  }, () => setSelectedAppt(null))}
                >
                  <Icon name="cal" /> إعادة جدولة الموعد
                </button>
                )}
                {selectedAppt.consultCanMarkNoShow && (
                <button
                  className="btn ghost sm"
                  type="button"
                  style={{ flex: 1 }}
                  disabled={action.busy}
                  onClick={() => action.run(`${apiBase()}/consults/${selectedAppt.consultId}/no-show`, {
                    confirm: CONFIRM_NO_SHOW,
                    success: 'وُسم الموعد «لم يحضر»',
                    fallback: 'تعذّر الوسم',
                    onSuccess: () => setSelectedAppt(null),
                  })}
                >
                  <Icon name="clock" /> لم يحضر
                </button>
                )}
              </div>
            )}

            {/* اعتماد الموعد المقترح للإدارة العليا */}
            {canApprove && selectedAppt.consultId && selectedAppt.status === APPT_PENDING && (
              <div style={{ marginTop: 12, padding: 12, borderRadius: 10, background: 'rgba(245, 158, 11, 0.08)', border: '1px solid rgba(245, 158, 11, 0.3)' }}>
                <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--amber-deep)', marginBottom: 8 }}>
                  ⏳ هذا الموعد اقترحه الموظف وبانتظار اعتماد الإدارة العليا لتبليغ العميل:
                </div>
                <button
                  className="btn block"
                  type="button"
                  onClick={() => router.post(`/admin/consults/${selectedAppt.consultId}/appointment/approve`, {}, {
                    preserveScroll: true,
                    onSuccess: () => {
                      toast('✅ تم اعتماد الموعد وإرساله للعميل بنجاح');
                      setSelectedAppt(null);
                    },
                    onError: (e) => toast(String(Object.values(e)[0] ?? 'تعذّر اعتماد الموعد')),
                  })}
                >
                  <Icon name="check" /> اعتماد الموعد وإرساله للعميل فوراً
                </button>
              </div>
            )}

            {!canApprove && selectedAppt.status === APPT_PENDING && (
              <div style={{ marginTop: 10, padding: '10px 12px', borderRadius: 8, background: 'rgba(245, 158, 11, 0.08)', color: 'var(--amber-deep)', fontSize: 12 }}>
                ⏳ الموعد مقترح وبانتظار مراجعة واعتماد الإدارة العليا قبل تبليغ العميل.
              </div>
            )}
          </div>
        </Modal>
      )}
    </>
  );
};

export default EmployeeSchedule;
