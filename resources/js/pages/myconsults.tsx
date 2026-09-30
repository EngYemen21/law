import { router } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import BookingActions from '@/components/babylon/BookingActions';
import { usePrompt } from '@/components/babylon/ConfirmDialog';
import { useToast } from '@/components/babylon/Toast';
import { SummaryModal } from '@/lib/consult-ui';
import type { ClientConsultCard } from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import { crChannelIcon, crChannelTone, foldSearch } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { useJoinOpensText } from '@/lib/settings';

// ============================================================
// لوحة استشارات العميل 360 درجة (360° Client Consultations Command Center)
// منصة سلاسل بابل لإدارة مكاتب المحاماة
// ============================================================

export interface ConsultStats {
  total: number;
  upcoming: number;
  completed: number;
  pendingBooking: number;
  reportsCount: number;
}

interface Props {
  consults: ClientConsultCard[];
  stats?: ConsultStats;
  nextConsult?: ClientConsultCard | null;
}

/**
 * **طلب تغيير الموعد — من العميل للمكتب.** (قرار المالك 2026-09-25)
 *
 * كان زرّاً في فرع «الفائتة» وحده، يبقى فعّالاً بعد الضغط فيُرسَل مرّاتٍ بلا حدّ، وكلُّ مرّةٍ
 * تُنبّه الإدارة كلّها. صار: يظهر حيث يسمح الخادم (`rescheduleRequest.canRequest`)، ويصير بعد
 * الإرسال شارةَ «قيد المعالجة» حتى يعيد المكتب الجدولة أو يرفض بسبب. والعميل **يطلب** ولا
 * يختار — المكتب يحدّد الموعد (قرار 2026-09-14).
 */
const RescheduleRequestControl: React.FC<{ consult: ClientConsultCard }> = ({ consult }) => {
  const toast = useToast();
  const askFor = usePrompt();
  const state = consult.rescheduleRequest;

  if (state?.pending) {
    return <Badge text="طلب تغيير الموعد قيد المعالجة" tone="b-amber" />;
  }

  if (!state?.canRequest) {
    return null;
  }

  const request = async () => {
    const note = await askFor({
      title: 'طلب تغيير موعد الجلسة',
      message: 'يصل طلبك للمكتب فيحدّد لك موعداً جديداً ويُبلغك به. ويبقى موعدك الحاليّ قائماً حتى ذلك — فاحضر فيه ما لم يصلك غيره.',
      label: 'ما سبب الطلب؟ (اختياريّ)',
      placeholder: 'مثلاً: لديّ سفر في هذا الموعد',
      confirmLabel: 'إرسال الطلب',
      required: false,
    });

    if (note === null) {
      return;
    }

    router.post(`/consults/${consult.id}/reschedule-request`, { note }, {
      preserveScroll: true,
      onSuccess: () => toast('أُرسل طلبك للمكتب — سيتواصل معك بموعدٍ جديد', 'success'),
      onError: (e) => toast(String(Object.values(e)[0] ?? 'تعذّر إرسال الطلب'), 'error'),
    });
  };

  return (
    <button className="btn soft sm" type="button" onClick={request}>
      <Icon name="cal" /> طلب تغيير الموعد
    </button>
  );
};

const MyConsults: React.FC<Props> = ({
  consults = [],
  stats,
  nextConsult: initialNextConsult,
}) => {
  const toast = useToast();
  const joinOpens = useJoinOpensText();
  const [items, setItems] = useState<ClientConsultCard[]>(consults);
  // الافتراضي يُشتق من البيانات: القادم من /book حالته «بانتظار التسعير/السداد» — فتح
  // upcoming دائماً كان يخفي طلبه الجديد وزرّ الدفع خلف تبويب آخر ويريه «لا توجد استشارات»
  const [activeTab, setActiveTab] = useState<'upcoming' | 'pending' | 'completed' | 'all'>(
    () => (consults.some((c) => Boolean(c.inBooking)) ? 'pending' : 'upcoming'),
  );
  const [searchQuery, setSearchQuery] = useState('');
  const [summaryOf, setSummaryOf] = useState<ClientConsultCard | null>(null);

  // تحديث القائمة عند تغير props
  useEffect(() => {
    setItems(consults);
  }, [consults]);

  // بثّ لحظي: «جارية الآن»، التسعير، الدفع، والملخص يظهرون فور تحديث الخادم
  useEffect(() => {
    items.forEach((c) => {
      if (!c.id) {
return;
}

      echo
        .private(`consult.${c.id}`)
        .listen(
          '.status',
          (e: {
            session: string;
            status: string;
            summary: string | null;
            summaryHtml?: string | null;
            summaryPending?: boolean;
            summaryApproved?: boolean;
            duration: string | null;
            canJoin?: boolean;
            price?: number;
            vat?: number;
            total?: number;
            priced?: boolean;
            paid?: boolean;
            invoiceNo?: string | null;
            when?: string | null;
          }) => {
            setItems((prev) =>
              prev.map((x) =>
                x.id === c.id
                  ? {
                      ...x,
                      session: e.session ?? x.session,
                      status: e.status ?? x.status,
                      // `??` يُبقي القيمة البائتة: لو بُثّ سحبُ الاعتماد (summary=null)
                      // بقي النصّ المعروض في المتصفّح. الحضور في الحمولة هو الحكم.
                      summary: 'summary' in e ? e.summary : x.summary,
                      summaryHtml: 'summaryHtml' in e ? e.summaryHtml : x.summaryHtml,
                      summaryPending: e.summaryPending ?? x.summaryPending,
                      summaryApproved: e.summaryApproved ?? x.summaryApproved,
                      duration: e.duration ?? x.duration,
                      canJoin: e.canJoin ?? x.canJoin,
                      price: e.price ?? x.price,
                      vat: e.vat ?? x.vat,
                      total: e.total ?? x.total,
                      priced: e.priced ?? x.priced,
                      paid: e.paid ?? x.paid,
                      invoiceNo: e.invoiceNo ?? x.invoiceNo,
                      when: e.when ?? x.when,
                    }
                  : x
              )
            );
          }
        );
    });

    return () => {
      consults.forEach((c) => c.id && echo.leave(`consult.${c.id}`));
    };
    // التبعية prop ثابتة لا items المتغيّرة: كانت كل رسالة بثّ تُعيد الاشتراك بكل القنوات (+auth لكل واحدة)
  }, [consults]);

  // الانضمام يفتح غرفة الجلسة المضمّنة داخل المنصّة (Zoom Meeting SDK)
  const enterRoom = (c: ClientConsultCard) => router.visit(`/consults/room?ref=${encodeURIComponent(c.ref)}`);

  const copyLink = (c: ClientConsultCard) => {
    if (navigator.clipboard && c.slink) {
      navigator.clipboard.writeText(c.slink);
      toast('تم نسخ رابط الجلسة للحافظة بنجاح');
    }
  };

  // تقسيم الاستشارات
  const upcomingConsults = useMemo(() => {
    return items.filter(
      (c) =>
        in_array_sessions(c.session) &&
        !c.missed &&
        !c.inBooking &&
        // ما ينتظر مستنداً من الموكّل ليس «قادماً مؤكداً» — هو موقوفٌ عليه
        c.status !== 'بانتظار استكمال البيانات' &&
        c.status !== 'ملغاة'
    );
  }, [items]);

  const pendingBookingConsults = useMemo(() => {
    // الفائتة تحتاج إجراءً (طلب إعادة جدولة) — كانت لا تظهر إلا في «الكل» فتضيع
    return items.filter((c) => Boolean(c.inBooking)
      || c.status === 'بانتظار استكمال البيانات'
      // الفائتة (`missed`) والمسجّلة «لم تُعقد» (`notHeld`) علمان منفصلان من الخادم — يُجمعان هنا كما كانا
      || c.missed || c.notHeld);
  }, [items]);

  const completedConsults = useMemo(() => {
    return items.filter((c) => c.session === 'منتهية');
  }, [items]);

  function in_array_sessions(s: string) {
    return s === 'بانتظار الجلسة' || s === 'جلسة جارية';
  }

  // الاستشارة الأقرب القادمة
  const nextUp = useMemo(() => {
    return upcomingConsults.find((c) => c.canJoin) || upcomingConsults[0] || initialNextConsult || null;
  }, [upcomingConsults, initialNextConsult]);

  // فلترة حسب التبويب والبحث
  const displayedConsults = useMemo(() => {
    let list = items;

    if (activeTab === 'upcoming') {
list = upcomingConsults;
} else if (activeTab === 'pending') {
list = pendingBookingConsults;
} else if (activeTab === 'completed') {
list = completedConsults;
}

    if (!searchQuery.trim()) {
return list;
}

    const q = foldSearch(searchQuery);

    return list.filter(
      (c) =>
        foldSearch(c.ref).includes(q) ||
        foldSearch(c.subject).includes(q) ||
        (c.lawyer && foldSearch(c.lawyer).includes(q)) ||
        (c.channel && foldSearch(c.channel).includes(q)) ||
        (c.specialty && foldSearch(c.specialty).includes(q)) ||
        (c.when && foldSearch(c.when).includes(q))
    );
  }, [items, activeTab, upcomingConsults, pendingBookingConsults, completedConsults, searchQuery]);

  return (
    <>
      {/* 1. الهيدر التنفيذي 360° وشريط الإجراءات */}
      <div className="hero" style={{ padding: '24px 26px', marginBottom: 20 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 16 }}>
          <div style={{ maxWidth: 650 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
              <span className="chip" style={{ background: 'rgba(255,255,255,0.18)', color: '#fff', borderColor: 'rgba(255,255,255,0.3)', fontWeight: 700 }}>
                <Icon name="scale" cls="ic" /> منظومة الاستشارات القانونية 360°
              </span>
              <span style={{ fontSize: 12, color: '#e0f2fe' }}>
                جلساتك ومواعيدها
              </span>
            </div>
            <h2>مركز إدارة الاستشارات والجلسات القانونية 🏛️</h2>
            <p>
              متابعة مباشرة لجميع استشاراتك: التسعير والسداد ثم الموعد الذي يحدّده المكتب، والانضمام للجلسات المرئية عبر Zoom، وتحميل التقارير والآراء القانونية المعتمدة.
            </p>
          </div>

          <div className="hero-cta" style={{ margin: 0 }}>
            <button className="hero-b" onClick={() => router.visit('/book')} type="button">
              <Icon name="calplus" /> حجز استشارة جديدة
            </button>
            <button className="hero-b ghost" onClick={() => router.visit('/calendar')} type="button">
              <Icon name="cal" /> التقويم والمواعيد
            </button>
            <button className="hero-b ghost" onClick={() => router.visit('/tickets')} type="button">
              <Icon name="ticket" /> طلباتي وتذاكري
            </button>
          </div>
        </div>
      </div>

      {/* 2. شريط المؤشرات والإحصائيات 360° */}
      <div className="stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', marginBottom: 22 }}>
        <div className="stat t-blue" onClick={() => setActiveTab('upcoming')}>
          <div className="si"><Icon name="video" /></div>
          <div className="num">{upcomingConsults.length}</div>
          <div className="lbl">استشارات قادمة مؤكدة</div>
          <div className="go"><Icon name="out" /></div>
        </div>

        <div className="stat t-amber" onClick={() => setActiveTab('pending')}>
          <div className="si"><Icon name="card" /></div>
          <div className="num">{pendingBookingConsults.length}</div>
          <div className="lbl">طلبات بانتظار الإجراء/السداد</div>
          <div className="go"><Icon name="out" /></div>
        </div>

        <div className="stat t-green" onClick={() => setActiveTab('completed')}>
          <div className="si"><Icon name="doc" /></div>
          <div className="num">{completedConsults.length}</div>
          <div className="lbl">استشارات منتهية</div>
          <div className="go"><Icon name="out" /></div>
        </div>

        <div className="stat t-cyan" onClick={() => setActiveTab('all')}>
          <div className="si"><Icon name="download" /></div>
          <div className="num">{items.length}</div>
          <div className="lbl">إجمالي الاستشارات والتقارير</div>
          <div className="go"><Icon name="out" /></div>
        </div>
      </div>

      {/* 3. بطاقة الاستشارة القادمة المميزة / الدخول الفوري (Spotlight Card) */}
      {nextUp && (
        <div
          className="card"
          style={{
            marginBottom: 22,
            border: nextUp.canJoin ? '1.5px solid #0e5c9c' : '1px solid var(--line)',
            boxShadow: nextUp.canJoin ? '0 8px 30px -10px rgba(14, 92, 156, 0.3)' : 'var(--shadow)',
            background: nextUp.canJoin
              ? 'linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%)'
              : '#ffffff',
          }}
        >
          <div
            className="card-h"
            style={{
              backgroundColor: nextUp.canJoin ? 'rgba(14, 92, 156, 0.06)' : 'var(--paper-2)',
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <div
                style={{
                  width: 10,
                  height: 10,
                  borderRadius: '50%',
                  backgroundColor: nextUp.canJoin ? '#ef4444' : '#0e5c9c',
                  boxShadow: nextUp.canJoin ? '0 0 0 4px rgba(239, 68, 68, 0.25)' : 'none',
                }}
              />
              <h3 style={{ fontSize: 15, fontWeight: 800, color: 'var(--ink)' }}>
                {nextUp.canJoin ? '🔴 موعد جلستك الاستشارية جاهز للدخول الآن' : '🕒 موعد استشارتك القادمة'}
              </h3>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Badge text={nextUp.channel} tone={crChannelTone(nextUp.channel)} />
              <span className="chip" style={{ fontSize: 11 }}>
                المرجع: {nextUp.ref}
              </span>
            </div>
          </div>

          <div className="card-b" style={{ padding: '18px 20px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 16 }}>
              <div style={{ minWidth: 260, flex: 1 }}>
                <h4 style={{ fontSize: 17, fontWeight: 800, color: 'var(--deep)', marginBottom: 6 }}>
                  {nextUp.subject || 'استشارة قانونية متخصصة'}
                </h4>
                <div style={{ display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap', color: 'var(--muted)', fontSize: 13 }}>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontWeight: 600, color: 'var(--ink)' }}>
                    <Icon name="cal" /> {nextUp.when}
                  </span>
                  <span>·</span>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
                    <Icon name="user" /> المستشار: {nextUp.lawyer || 'مستشار المكتب'}
                  </span>
                  {nextUp.place && (
                    <>
                      <span>·</span>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
                        <Icon name="pin" /> {nextUp.place}
                      </span>
                    </>
                  )}
                  {nextUp.specialty && (
                    <>
                      <span>·</span>
                      <span className="chip" style={{ fontSize: 11.5 }}>
                        {nextUp.specialty}
                      </span>
                    </>
                  )}
                </div>
              </div>

              <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                {nextUp.channel === 'مرئية' && (
                  nextUp.canJoin ? (
                    <button
                      className="btn"
                      type="button"
                      onClick={() => enterRoom(nextUp)}
                      style={{
                        padding: '12px 22px',
                        fontSize: 14,
                        fontWeight: 800,
                        boxShadow: '0 8px 24px -6px rgba(14, 92, 156, 0.6)',
                      }}
                    >
                      <Icon name="video" /> انضم لجلسة Zoom الآن
                    </button>
                  ) : (
                    <div
                      style={{
                        padding: '10px 16px',
                        borderRadius: 10,
                        backgroundColor: 'var(--paper-2)',
                        border: '1px solid var(--line-soft)',
                        color: 'var(--muted)',
                        fontSize: 13,
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        fontWeight: 600,
                      }}
                    >
                      <Icon name="clock" />
                      <span>يُفعَّل رابط الدخول التلقائي قبل الموعد بـ{joinOpens}</span>
                    </div>
                  )
                )}
                {nextUp.channel === 'هاتفية' && (
                  <span className="badge-s b-amber" style={{ padding: '8px 14px', fontSize: 12.5 }}>
                    <Icon name="phone" /> سيتصل بك المستشار في الموعد المحدد
                  </span>
                )}
                {nextUp.channel === 'حضورية' && (
                  <span className="badge-s b-green" style={{ padding: '8px 14px', fontSize: 12.5 }}>
                    <Icon name="office" /> نتشرف بحضورك لمقر المكتب بالموعد
                  </span>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* 4. مساحة استعراض الاستشارات والتبويبات التفاعلية */}
      <div className="card" style={{ marginBottom: 24 }}>
        <div
          className="card-h"
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            flexWrap: 'wrap',
            gap: 12,
            padding: '12px 18px',
          }}
        >
          {/* أزرار التبويبات */}
          <div className="filter-pills" style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button
              className={`btn sm ${activeTab === 'upcoming' ? '' : 'ghost'}`}
              type="button"
              onClick={() => setActiveTab('upcoming')}
              style={{ fontWeight: 700 }}
            >
              <Icon name="video" /> الاستشارات القادمة ({upcomingConsults.length})
            </button>
            <button
              className={`btn sm ${activeTab === 'pending' ? '' : 'ghost'}`}
              type="button"
              onClick={() => setActiveTab('pending')}
              style={{ fontWeight: 700 }}
            >
              <Icon name="card" /> بانتظار الإجراء والسداد ({pendingBookingConsults.length})
            </button>
            <button
              className={`btn sm ${activeTab === 'completed' ? '' : 'ghost'}`}
              type="button"
              onClick={() => setActiveTab('completed')}
              style={{ fontWeight: 700 }}
            >
              <Icon name="doc" /> المنتهية والتقارير ({completedConsults.length})
            </button>
            <button
              className={`btn sm ${activeTab === 'all' ? '' : 'ghost'}`}
              type="button"
              onClick={() => setActiveTab('all')}
              style={{ fontWeight: 700 }}
            >
              <Icon name="folder" /> الكل ({items.length})
            </button>
          </div>

          {/* شريط البحث */}
          <div className="page-search" style={{ width: 250 }}>
            <Icon name="search" />
            <input
              type="text"
              placeholder="بحث بالرقم، الموضوع، المستشار…"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
            />
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                style={{ color: 'var(--faint)' }}
              >
                ✕
              </button>
            )}
          </div>
        </div>

        <div className="card-b" style={{ padding: '8px 18px 18px' }}>
          {displayedConsults.length > 0 ? (
            <div style={{ display: 'flex', flexDirection: 'column' }}>
              {displayedConsults.map((c) => {
                const isBookingFlow = Boolean(c.inBooking);
                const isLive = c.session === 'جلسة جارية';
                const isCompleted = c.session === 'منتهية';

                return (
                  <div key={c.ref} className="item" style={{ padding: '15px 0' }}>
                    <div className="item-top">
                      <div className={`iico ${crChannelTone(c.channel)}`}>
                        <Icon name={crChannelIcon(c.channel)} />
                      </div>
                      <div className="imeta">
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 3, flexWrap: 'wrap' }}>
                          <b>{c.subject || 'استشارة قانونية'}</b>
                          <span className="chip" style={{ fontSize: 11, color: 'var(--faint)' }}>
                            {c.ref}
                          </span>
                          <Badge text={c.channel} tone={crChannelTone(c.channel)} />
                          {c.specialty && (
                            <span className="chip" style={{ fontSize: 11 }}>
                              {c.specialty}
                            </span>
                          )}
                        </div>

                        <div style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 12.5, color: 'var(--muted)', flexWrap: 'wrap' }}>
                          <span style={{ fontWeight: 600, color: 'var(--ink)' }}>
                            <Icon name="cal" /> {c.when}
                          </span>
                          <span>·</span>
                          <span>المستشار: {c.lawyer || 'مستشار المكتب'}</span>
                          {c.place && (
                            <>
                              <span>·</span>
                              <span><Icon name="pin" /> {c.place}</span>
                            </>
                          )}
                          {c.total > 0 && (
                            <>
                              <span>·</span>
                              <span style={{ color: 'var(--deep)', fontWeight: 700 }}>
                                الرسوم: {c.total} ر.س {c.paid ? '✓ مسدد' : 'بانتظار السداد'}
                              </span>
                            </>
                          )}
                        </div>
                      </div>
                    </div>

                    <div className="iact" style={{ gap: 8 }}>
                      {/* 1. دورة الحجز (تسعير/سداد/اختيار موعد) */}
                      {isBookingFlow ? (
                        <BookingActions c={c} toast={toast} />
                      ) : c.status === 'ملغاة' ? (
                        <Badge text="ملغاة" tone="b-red" />
                      ) : c.status === 'بانتظار استكمال البيانات' ? (
                        /* **الفعلُ على الموكّل — فليَقُله له النظام.** كانت تسقط في الفرع
                           الجامع فتُعرض «بانتظار الجلسة»، فيقرأ أنّ لا شيء عليه وملفُّه
                           موقوفٌ به. و`missing` يحمل ما طلبه المكتب بالنصّ. */
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, alignItems: 'flex-end' }}>
                          <Badge text="بانتظار مستنداتك" tone="b-amber" />
                          {(c.missing?.length ?? 0) > 0 && (
                            <span style={{ fontSize: 12, color: 'var(--muted)', textAlign: 'left' }}>
                              المطلوب: {c.missing!.join('، ')}
                            </span>
                          )}
                          <button
                            className="btn sm"
                            type="button"
                            onClick={() => router.visit(`/consults/${c.id}/documents`)}
                          >
                            <Icon name="upload" /> رفع المستندات
                          </button>
                        </div>
                      ) : isLive ? (
                        c.channel === 'مرئية' ? (
                          <>
                            <span
                              style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6,
                                background: '#FDEAE7',
                                color: '#C0392B',
                                fontWeight: 800,
                                fontSize: 12,
                                padding: '5px 12px',
                                borderRadius: 99,
                              }}
                            >
                              <span
                                style={{
                                  width: 7,
                                  height: 7,
                                  borderRadius: '50%',
                                  background: '#C0392B',
                                  animation: 'vrp 1.2s infinite',
                                }}
                              />
                              جلسة جارية الآن 🔴
                            </span>
                            <button
                              className="btn sm"
                              onClick={() => enterRoom(c)}
                              type="button"
                              disabled={!c.canJoin}
                              style={{ fontWeight: 800 }}
                            >
                              <Icon name="video" /> انضم لجلسة Zoom الآن
                            </button>
                          </>
                        ) : (
                          <Badge text="جلسة جارية" tone="b-amber" />
                        )
                      ) : isCompleted ? (
                        <>
                          {/* **الشارة تتبع الاعتماد لا انتهاء الجلسة.** كانت مشروطةً
                              بـ`session === 'منتهية'` وحدها، بينما `toClientCard` يحجب
                              النصّ حتى يعتمده محامٍ — فيقرأ الموكّل «معتمدة» ولا نصَّ تحتها. */}
                          {c.summaryApproved
                            ? <Badge text="منتهية ومعتمدة" tone="b-green" />
                            : <Badge text="منتهية — بانتظار اعتماد الملخّص" tone="b-amber" />}
                          {/* الشرط على حالة الاعتماد لا على وجود النصّ: النصّ قد يصل
                              المتصفّح بائتاً، والحالة هي ما يقرّره الخادم. والمعلّق
                              يفتح النافذة أيضاً ليقرأ العميل سبب الانتظار. */}
                          {(c.summaryApproved || c.summaryPending) && (
                            <button
                              className="btn soft sm"
                              onClick={() => setSummaryOf(c)}
                              type="button"
                              style={{ fontWeight: 700 }}
                            >
                              <Icon name="doc" /> الملخص والقرارات
                            </button>
                          )}
                        </>
                      ) : (c.missed || c.notHeld) ? (
                        <Badge text="فائتة — لم تنعقد" tone="b-red" />
                      ) : c.channel === 'مرئية' ? (
                        <>
                          <Badge text="بانتظار الجلسة" tone="b-grey" />
                          {c.canJoin ? (
                            <>
                              <button
                                className="btn sm"
                                onClick={() => enterRoom(c)}
                                type="button"
                                style={{ fontWeight: 800 }}
                              >
                                <Icon name="video" /> دخول جلسة Zoom
                              </button>
                              <button
                                className="btn soft sm"
                                onClick={() => copyLink(c)}
                                type="button"
                              >
                                <Icon name="link" /> نسخ الرابط
                              </button>
                            </>
                          ) : (
                            <button
                              className="btn sm ghost"
                              type="button"
                              disabled
                              style={{ opacity: 0.65, cursor: 'not-allowed', fontSize: 12 }}
                              title={`يُفعَّل قبل الموعد بـ${joinOpens}`}
                            >
                              <Icon name="clock" /> الدخول (قبل الموعد بـ{joinOpens})
                            </button>
                          )}
                        </>
                      ) : c.channel === 'هاتفية' ? (
                        <Badge text="سيتصل بك المكتب في الموعد" tone="b-amber" />
                      ) : (
                        <Badge text="بانتظار الحضور للمكتب" tone="b-grey" />
                      )}

                      {/* طلب تغيير الموعد — للقادم قبل ٢٤ ساعة وللفائت، مرّةً حتى يُقضى (الحكم في الخادم) */}
                      <RescheduleRequestControl consult={c} />

                      {/* زر تحميل تقرير الاستشارة الرسمي PDF */}
                      {!isBookingFlow && c.status !== 'ملغاة' && (
                        <a
                          className="btn soft sm"
                          href={`/consults/${c.id}/report.pdf`}
                          download
                          title="تحميل التقرير الرسمي للاستشارة"
                        >
                          <Icon name="download" /> التقرير (PDF)
                        </a>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          ) : (
            <div className="empty" style={{ padding: '36px 16px' }}>
              <Icon name="scale" />
              <b>{items.length === 0 ? 'لا توجد استشارات بعد' : 'لا توجد استشارات مطابقة'}</b>
              <p style={{ fontSize: 13, color: 'var(--muted)', marginTop: 4 }}>
                يمكنك حجز موعد استشارة جديدة فوراً بضغطة زر.
              </p>
              <button
                className="btn sm"
                onClick={() => router.visit('/book')}
                type="button"
                style={{ marginTop: 12 }}
              >
                <Icon name="calplus" /> حجز استشارة جديدة
              </button>
            </div>
          )}
        </div>
      </div>

      {/* 5. نافذة استعراض الملخص والقرارات المعتمدة — مكون مركزي موحد */}
      <SummaryModal consult={summaryOf} onClose={() => setSummaryOf(null)} />
    </>
  );
};

export default MyConsults;
