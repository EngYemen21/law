import { router } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { RichText } from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import Icon from '@/lib/icons';
import { useJoinOpensText, useSettings } from '@/lib/settings';

// ============================================================
// لوحة اجتماعات وجلسات العميل 360 درجة (360° Client Meetings Command Center)
// منصة سلاسل بابل لإدارة مكاتب المحاماة
// ============================================================

export interface ClientMeeting {
  id: number;
  title: string;
  when: string;
  up: boolean;
  status: string;
  tone: string;
  canJoin: boolean;
  /** يطلب تغيير الموعد الآن (`Meeting::changeRequestBlocker` = null) */
  canRequestChange: boolean;
  /** سبب المنع حين يعني العميل: طلبٌ قيد المراجعة أو سقفٌ بُلغ */
  changeRequestNote: string | null;
  approved: boolean;
  ref: string;
  link: string;
  minutes: string | null;
  summary: string | null;
  lawyer?: string;
  caseRef?: string | null;
  type?: string;
  decisions?: string[];
  startsAt?: string | null;
}

export interface VideoConsult {
  id: number;
  ref: string;
  subject: string;
  channel: string;
  when: string;
  canJoin: boolean;
  joinLink: string;
  status: string;
  session: string;
  /** `SessionState::tone()` من الخادم. */
  sessionTone: string;
  lawyer: string;
}

export interface MeetingStats {
  total: number;
  upcoming: number;
  past: number;
  approvedMinutes: number;
  videoConsultsCount: number;
}

interface Props {
  meetings: ClientMeeting[];
  stats?: MeetingStats;
  nextMeeting?: ClientMeeting | null;
  videoConsults?: VideoConsult[];
}

const Meetings: React.FC<Props> = ({
  meetings = [],
  stats,
  nextMeeting: initialNextMeeting,
  videoConsults = [],
}) => {
  const toast = useToast();
  // اسم المكتب من الإعدادات — كان رأس المحضر يحمل اسماً ثالثاً منقوشاً لا يطابق مستندات المكتب
  const { office_name: officeName } = useSettings();
  const joinOpens = useJoinOpensText();
  const [items, setItems] = useState<ClientMeeting[]>(meetings);
  const [activeTab, setActiveTab] = useState<'upcoming' | 'past' | 'consults'>('upcoming');
  const [searchQuery, setSearchQuery] = useState('');
  const [activeDoc, setActiveDoc] = useState<{
    title: string;
    ref: string;
    type: 'minutes' | 'summary';
    body: string;
    decisions?: string[];
    when?: string;
    lawyer?: string;
  } | null>(null);

  // تحديث القائمة عند تغير props
  useEffect(() => {
    setItems(meetings);
  }, [meetings]);

  // الاشتراك في قنوات البث اللحظي لتحديث الجلسات فور اعتمادها أو بدء الانعقاد
  useEffect(() => {
    items.forEach((m) => {
      if (!m.id) {
return;
}

      echo
        .private(`meeting.${m.id}`)
        .listen(
          '.status',
          (e: {
            status: string;
            liveStatus?: string;
            tone?: string;
            up?: boolean;
            canJoin?: boolean;
            summary: string | null;
            minutes: string | null;
            approve?: string;
          }) => {
            setItems((prev) =>
              prev.map((x) =>
                x.id === m.id
                  ? {
                      ...x,
                      summary: e.summary ?? x.summary,
                      minutes: e.minutes ?? x.minutes,
                      up: e.up ?? (e.status === 'قادم' || e.status === 'جارٍ'),
                      status: e.liveStatus ?? x.status,
                      tone: e.tone ?? x.tone,
                      canJoin: e.canJoin ?? x.canJoin,
                      /*
                       * **الاعتماد يُقرأ من البثّ لا يُستنتَج من وجود نصّ.** كان
                       * `!!(minutes || summary)` — سليمٌ اليوم لأنّ البثّ يحجب النصّ
                       * قبل الاعتماد، لكنّه يربط حقيقةً بأثرها: يوم يُبثّ نصٌّ غير
                       * معتمد لسببٍ آخر تصير الشارة كاذبة. والحمولة تحمل `approve`.
                       */
                      approved: e.approve !== undefined ? e.approve === 'معتمد' : x.approved,
                    }
                  : x
              )
            );
          }
        );
    });

    return () => {
      meetings.forEach((m) => m.id && echo.leave(`meeting.${m.id}`));
    };
    // التبعية prop ثابتة لا items المتغيّرة: كانت كل رسالة بثّ تُعيد الاشتراك بكل القنوات (+auth لكل واحدة)
  }, [meetings]);

  // تقسيم الاجتماعات
  const upcomingMeetings = useMemo(() => items.filter((m) => m.up), [items]);
  const pastMeetings = useMemo(() => items.filter((m) => !m.up), [items]);

  // الجلسة الأقرب القادمة
  const nextUp = useMemo(() => {
    return upcomingMeetings.find((m) => m.canJoin) || upcomingMeetings[0] || initialNextMeeting || null;
  }, [upcomingMeetings, initialNextMeeting]);

  // فلترة العناصر بالبحث
  const filteredUpcoming = useMemo(() => {
    if (!searchQuery.trim()) {
return upcomingMeetings;
}

    const q = searchQuery.toLowerCase();

    return upcomingMeetings.filter(
      (m) =>
        m.title.toLowerCase().includes(q) ||
        (m.lawyer && m.lawyer.toLowerCase().includes(q)) ||
        (m.caseRef && m.caseRef.toLowerCase().includes(q)) ||
        m.when.toLowerCase().includes(q)
    );
  }, [upcomingMeetings, searchQuery]);

  const filteredPast = useMemo(() => {
    if (!searchQuery.trim()) {
return pastMeetings;
}

    const q = searchQuery.toLowerCase();

    return pastMeetings.filter(
      (m) =>
        m.title.toLowerCase().includes(q) ||
        (m.lawyer && m.lawyer.toLowerCase().includes(q)) ||
        (m.caseRef && m.caseRef.toLowerCase().includes(q)) ||
        m.when.toLowerCase().includes(q)
    );
  }, [pastMeetings, searchQuery]);

  const filteredConsults = useMemo(() => {
    if (!searchQuery.trim()) {
return videoConsults;
}

    const q = searchQuery.toLowerCase();

    return videoConsults.filter(
      (c) =>
        c.subject.toLowerCase().includes(q) ||
        c.ref.toLowerCase().includes(q) ||
        c.lawyer.toLowerCase().includes(q)
    );
  }, [videoConsults, searchQuery]);

  // نسخ المحضر
  const copyDoc = () => {
    if (!activeDoc) {
return;
}

    const text = `${activeDoc.title} (${activeDoc.ref})\n\n${activeDoc.body}${
      activeDoc.decisions?.length ? `\n\nالقرارات:\n- ${activeDoc.decisions.join('\n- ')}` : ''
    }`;

    // النجاح يُعلن بعد وعد النسخ لا قبله — alert المتزامن كان يكذب عند فشل الحافظة (http/بلا صلاحية)
    if (!navigator.clipboard) {
      toast('النسخ غير متاح في هذا المتصفح');

      return;
    }

    navigator.clipboard.writeText(text)
      .then(() => toast('تم نسخ محتوى الوثيقة للحافظة'))
      .catch(() => toast('تعذّر النسخ — انسخ النص يدوياً'));
  };

  return (
    <>
      {/* 1. الهيدر التنفيذي وشريط الإجراءات 360° */}
      <div className="hero" style={{ padding: '24px 26px', marginBottom: 20 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 16 }}>
          <div style={{ maxWidth: 640 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
              <span className="chip" style={{ background: 'rgba(255,255,255,0.18)', color: '#fff', borderColor: 'rgba(255,255,255,0.3)', fontWeight: 700 }}>
                <Icon name="video" cls="ic" /> منظومة الاجتماعات المرئية 360°
              </span>
              <span style={{ fontSize: 12, color: '#e0f2fe' }}>
                ربط آمن عبر Zoom
              </span>
            </div>
            <h2>مركز الاجتماعات والجلسات المرئية 🏛️</h2>
            <p>
              متابعة مواعيد جلسات القضايا واللقاءات الاستشارية، الانضمام المباشر للقاعات الافتراضية، والاطلاع على المحاضر والقرارات المعتمدة رسمياً.
            </p>
          </div>

          <div className="hero-cta" style={{ margin: 0 }}>
            <button className="hero-b" onClick={() => router.visit('/calendar')} type="button">
              <Icon name="cal" /> المواعيد والتقويم
            </button>
            <button className="hero-b ghost" onClick={() => router.visit('/cases')} type="button">
              <Icon name="scale" /> قضاياي وملفاتي
            </button>
          </div>
        </div>
      </div>

      {/* 2. شريط المؤشرات والإحصائيات 360° */}
      <div className="stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', marginBottom: 22 }}>
        <div className="stat t-blue" onClick={() => setActiveTab('upcoming')}>
          <div className="si"><Icon name="video" /></div>
          <div className="num">{stats?.upcoming ?? upcomingMeetings.length}</div>
          <div className="lbl">اجتماعات قادمة مجدولة</div>
          <div className="go"><Icon name="out" /></div>
        </div>

        <div className="stat t-cyan" onClick={() => setActiveTab('consults')}>
          <div className="si"><Icon name="clock" /></div>
          <div className="num">{stats?.videoConsultsCount ?? videoConsults.length}</div>
          <div className="lbl">استشارات مرئية نشطة</div>
          <div className="go"><Icon name="out" /></div>
        </div>

        <div className="stat t-green" onClick={() => setActiveTab('past')}>
          <div className="si"><Icon name="doc" /></div>
          <div className="num">{stats?.approvedMinutes ?? items.filter((m) => m.approved).length}</div>
          <div className="lbl">محاضر وملخصات معتمدة</div>
          <div className="go"><Icon name="out" /></div>
        </div>

        <div className="stat t-amber" onClick={() => setActiveTab('past')}>
          <div className="si"><Icon name="check" /></div>
          <div className="num">{stats?.past ?? pastMeetings.length}</div>
          <div className="lbl">جلسات سابقة مكتملة</div>
          <div className="go"><Icon name="out" /></div>
        </div>
      </div>

      {/* 3. بطاقة الجلسة القادمة المميزة / الدخول الفوري (Spotlight Card) */}
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
                {nextUp.canJoin ? '🔴 جلستك القادمة جاهزة للدخول الآن' : '🕒 موعد اجتماعك القادم'}
              </h3>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              {nextUp.status && <Badge text={nextUp.status} tone={nextUp.tone || 'b-blue'} />}
              <span className="chip" style={{ fontSize: 11 }}>
                المرجع: {nextUp.ref}
              </span>
            </div>
          </div>

          <div className="card-b" style={{ padding: '18px 20px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 16 }}>
              <div style={{ minWidth: 260, flex: 1 }}>
                <h4 style={{ fontSize: 17, fontWeight: 800, color: 'var(--deep)', marginBottom: 6 }}>
                  {nextUp.title}
                </h4>
                <div style={{ display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap', color: 'var(--muted)', fontSize: 13 }}>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontWeight: 600, color: 'var(--ink)' }}>
                    <Icon name="cal" /> {nextUp.when}
                  </span>
                  <span>·</span>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
                    <Icon name="user" /> المستشار: {nextUp.lawyer || 'مستشار المكتب'}
                  </span>
                  <span>·</span>
                  {/* لا «المدة: 60 دقيقة» — الاجتماع لا مدّة له تُعلَن، ينتهي حين يُنهيه المستشار */}
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
                    <Icon name="clock" /> ينتهي بإنهاء المستشار له
                  </span>
                  {nextUp.caseRef && (
                    <>
                      <span>·</span>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, color: 'var(--primary)', fontWeight: 600 }}>
                        <Icon name="scale" /> القضية: {nextUp.caseRef}
                      </span>
                    </>
                  )}
                </div>
              </div>

              <div>
                {nextUp.canJoin ? (
                  <button
                    className="btn"
                    type="button"
                    onClick={() => router.visit(`/meetingroom?ref=${encodeURIComponent(nextUp.ref)}`)}
                    style={{
                      padding: '12px 24px',
                      fontSize: 14.5,
                      fontWeight: 800,
                      boxShadow: '0 8px 24px -6px rgba(14, 92, 156, 0.6)',
                    }}
                  >
                    <Icon name="video" /> دخول الجلسة المرئية الآن (Zoom)
                  </button>
                ) : (
                  <div
                    style={{
                      padding: '10px 18px',
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
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* 4. مساحة استعراض الاجتماعات والتبويبات التفاعلية */}
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
              <Icon name="video" /> الاجتماعات القادمة ({upcomingMeetings.length})
            </button>
            <button
              className={`btn sm ${activeTab === 'consults' ? '' : 'ghost'}`}
              type="button"
              onClick={() => setActiveTab('consults')}
              style={{ fontWeight: 700 }}
            >
              <Icon name="clock" /> الاستشارات المرئية ({videoConsults.length})
            </button>
            <button
              className={`btn sm ${activeTab === 'past' ? '' : 'ghost'}`}
              type="button"
              onClick={() => setActiveTab('past')}
              style={{ fontWeight: 700 }}
            >
              <Icon name="doc" /> الاجتماعات السابقة والمحاضر ({pastMeetings.length})
            </button>
          </div>

          {/* شريط البحث */}
          <div className="page-search" style={{ width: 250 }}>
            <Icon name="search" />
            <input
              type="text"
              placeholder="بحث بالعنوان، المستشار، القضية…"
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
          {/* تبويب الاجتماعات القادمة */}
          {activeTab === 'upcoming' && (
            <div>
              {filteredUpcoming.length > 0 ? (
                <div style={{ display: 'flex', flexDirection: 'column' }}>
                  {filteredUpcoming.map((m) => (
                    <div key={m.id} className="item" style={{ padding: '14px 0' }}>
                      <div className="item-top">
                        <div
                          className="iico"
                          style={{
                            background: m.canJoin ? 'rgba(239, 68, 68, 0.12)' : 'rgba(14, 92, 156, 0.08)',
                            color: m.canJoin ? '#dc2626' : 'var(--primary)',
                          }}
                        >
                          <Icon name={m.canJoin ? 'video' : 'cal'} />
                        </div>
                        <div className="imeta">
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 3 }}>
                            <b>{m.title}</b>
                            {m.type && (
                              <span className="chip" style={{ fontSize: 11 }}>
                                {m.type}
                              </span>
                            )}
                            <span className="chip" style={{ fontSize: 11, color: 'var(--faint)' }}>
                              {m.ref}
                            </span>
                          </div>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 12.5, color: 'var(--muted)', flexWrap: 'wrap' }}>
                            <span style={{ fontWeight: 600, color: 'var(--ink)' }}>
                              <Icon name="clock" /> {m.when}
                            </span>
                            <span>·</span>
                            <span>المستشار: {m.lawyer || 'مستشار المكتب'}</span>
                            {m.caseRef && (
                              <>
                                <span>·</span>
                                <span style={{ color: 'var(--primary)', fontWeight: 600 }}>
                                  ملف قضية: {m.caseRef}
                                </span>
                              </>
                            )}
                          </div>
                        </div>
                      </div>

                      <div className="iact">
                        {m.status && <Badge text={m.status} tone={m.tone || 'b-blue'} />}
                        {m.canJoin ? (
                          <button
                            className="btn sm"
                            type="button"
                            onClick={() => router.visit(`/meetingroom?ref=${encodeURIComponent(m.ref)}`)}
                            style={{ fontWeight: 800 }}
                          >
                            <Icon name="video" /> دخول الجلسة الآن
                          </button>
                        ) : (
                          <button
                            className="btn sm ghost"
                            type="button"
                            disabled
                            style={{ opacity: 0.65, cursor: 'not-allowed', fontSize: 12 }}
                            title={`يُفعَّل الدخول قبل الموعد بـ${joinOpens}`}
                          >
                            <Icon name="clock" /> الدخول (قبل الموعد بـ{joinOpens})
                          </button>
                        )}
                        {/* طلب تغيير الموعد — الخادم يقرّر إتاحته (لا طلبَ قائم ولا سقفَ بُلغ)، ويُعلَن سبب المنع */}
                        {m.changeRequestNote && (
                          <span className="sub" style={{ fontSize: 12 }}><Icon name="clock" /> {m.changeRequestNote}</span>
                        )}
                        {m.canRequestChange && (
                          <button
                            className="btn soft sm"
                            type="button"
                            onClick={() => router.post(`/meetings/${m.id}/change-request`, {}, {
                              preserveScroll: true,
                              onSuccess: () => toast('أُرسل طلبك للمكتب — سيتواصل معك فريقنا بشأن الموعد'),
                              onError: (e) => toast(String(Object.values(e)[0] ?? 'تعذّر إرسال الطلب')),
                            })}
                          >
                            <Icon name="cal" /> طلب تغيير الموعد
                          </button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="empty" style={{ padding: '36px 16px' }}>
                  <Icon name="video" />
                  <b>لا توجد اجتماعات قادمة مجدولة</b>
                  <p style={{ fontSize: 13, color: 'var(--muted)', marginTop: 4 }}>
                    يمكنك طلب حجز موعد استشارة جديدة أو التواصل مع فريق المكتب.
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
          )}

          {/* تبويب الاستشارات المرئية */}
          {activeTab === 'consults' && (
            <div>
              {filteredConsults.length > 0 ? (
                <div style={{ display: 'flex', flexDirection: 'column' }}>
                  {filteredConsults.map((c) => (
                    <div key={c.id} className="item" style={{ padding: '14px 0' }}>
                      <div className="item-top">
                        <div className="iico file-ico">
                          <Icon name="video" />
                        </div>
                        <div className="imeta">
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 3 }}>
                            <b>{c.subject}</b>
                            <span className="chip" style={{ fontSize: 11 }}>
                              {c.channel || 'مرئية'}
                            </span>
                            <span className="chip" style={{ fontSize: 11, color: 'var(--faint)' }}>
                              {c.ref}
                            </span>
                          </div>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 12.5, color: 'var(--muted)' }}>
                            <span style={{ fontWeight: 600, color: 'var(--ink)' }}>
                              <Icon name="cal" /> {c.when}
                            </span>
                            <span>·</span>
                            <span>المستشار: {c.lawyer}</span>
                          </div>
                        </div>
                      </div>

                      <div className="iact">
                        <Badge text={c.session || c.status} tone={c.sessionTone} />
                        {c.canJoin ? (
                          <button
                            className="btn sm"
                            type="button"
                            onClick={() => router.visit(c.joinLink || `/consults/room?ref=${encodeURIComponent(c.ref)}`)}
                            style={{ fontWeight: 800 }}
                          >
                            <Icon name="video" /> دخول الاستشارة
                          </button>
                        ) : (
                          <button
                            className="btn sm soft"
                            type="button"
                            onClick={() => router.visit('/myconsults')}
                          >
                            تفاصيل الاستشارة
                          </button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="empty" style={{ padding: '36px 16px' }}>
                  <Icon name="clock" />
                  <b>لا توجد استشارات مرئية نشطة حالياً</b>
                  <p style={{ fontSize: 13, color: 'var(--muted)', marginTop: 4 }}>
                    جميع طلبات الاستشارة الخاصة بك ستظهر هنا مع روابط الدخول والتقارير.
                  </p>
                </div>
              )}
            </div>
          )}

          {/* تبويب الاجتماعات السابقة والمحاضر المعتمدة */}
          {activeTab === 'past' && (
            <div>
              {filteredPast.length > 0 ? (
                <div style={{ display: 'flex', flexDirection: 'column' }}>
                  {filteredPast.map((m) => (
                    <div key={m.id} className="item" style={{ padding: '14px 0' }}>
                      <div className="item-top">
                        <div className="iico" style={{ background: 'var(--paper-2)', color: 'var(--muted)' }}>
                          <Icon name="doc" />
                        </div>
                        <div className="imeta">
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 3 }}>
                            <b>{m.title}</b>
                            <span className="chip" style={{ fontSize: 11, color: 'var(--faint)' }}>
                              {m.ref}
                            </span>
                            {m.approved && <Badge text="معتمد رسمياً" tone="b-green" />}
                          </div>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 12.5, color: 'var(--muted)', flexWrap: 'wrap' }}>
                            <span>
                              <Icon name="cal" /> {m.when}
                            </span>
                            <span>·</span>
                            <span>المستشار: {m.lawyer || 'مستشار المكتب'}</span>
                            {m.caseRef && (
                              <>
                                <span>·</span>
                                <span>قضية: {m.caseRef}</span>
                              </>
                            )}
                          </div>
                          {/* عرض موجز القرارات إن وجدت */}
                          {m.decisions && m.decisions.length > 0 && (
                            <div style={{ marginTop: 6, display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                              {m.decisions.slice(0, 2).map((d, di) => (
                                <span
                                  key={di}
                                  className="chip"
                                  style={{
                                    fontSize: 11,
                                    backgroundColor: '#f0fdf4',
                                    borderColor: '#bbf7d0',
                                    color: '#166534',
                                  }}
                                >
                                  ✓ {d}
                                </span>
                              ))}
                              {m.decisions.length > 2 && (
                                <span style={{ fontSize: 11, color: 'var(--faint)', alignSelf: 'center' }}>
                                  +{m.decisions.length - 2} قرارات أخرى
                                </span>
                              )}
                            </div>
                          )}
                        </div>
                      </div>

                      <div className="iact">
                        {m.status && <Badge text={m.status} tone={m.tone || 'b-grey'} />}
                        {m.minutes && (
                          <button
                            className="btn soft sm"
                            type="button"
                            onClick={() =>
                              setActiveDoc({
                                title: `محضر الاجتماع الرسمي`,
                                ref: m.ref,
                                type: 'minutes',
                                body: m.minutes!,
                                decisions: m.decisions,
                                when: m.when,
                                lawyer: m.lawyer,
                              })
                            }
                            style={{ fontWeight: 700 }}
                          >
                            <Icon name="doc" /> المحضر الرسمي
                          </button>
                        )}
                        {m.summary && (
                          <button
                            className="btn soft sm"
                            type="button"
                            onClick={() =>
                              setActiveDoc({
                                title: `ملخص وقرارات الاجتماع`,
                                ref: m.ref,
                                type: 'summary',
                                body: m.summary!,
                                decisions: m.decisions,
                                when: m.when,
                                lawyer: m.lawyer,
                              })
                            }
                          >
                            <Icon name="out" /> ملخص القرارات
                          </button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="empty" style={{ padding: '36px 16px' }}>
                  <Icon name="doc" />
                  <b>لا توجد اجتماعات سابقة مسجلة</b>
                  <p style={{ fontSize: 13, color: 'var(--muted)', marginTop: 4 }}>
                    المحاضر والملخصات المعتمدة بعد انتهاء جلساتك ستظهر هنا بشكل دائم.
                  </p>
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      {/* 5. نافذة استعراض الوثائق والمحاضر المعتمدة (Legal Document Modal) */}
      <Modal
        title={activeDoc?.title || 'وثيقة الاجتماع'}
        subtitle={`مرجع الاجتماع: ${activeDoc?.ref || '—'}`}
        open={!!activeDoc}
        onClose={() => setActiveDoc(null)}
        maxWidth={680}
      >
        {activeDoc && (
          <div style={{ padding: '6px 4px' }}>
            {/* هيدر الوثيقة الرسمية */}
            <div
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                padding: '12px 16px',
                borderRadius: 10,
                backgroundColor: '#f8fafc',
                border: '1px solid var(--line-soft)',
                marginBottom: 16,
                fontSize: 12.5,
              }}
            >
              <div>
                <b style={{ color: 'var(--ink)', display: 'block' }}>{officeName}</b>
                <span style={{ color: 'var(--faint)' }}>التاريخ: {activeDoc.when || '—'} · المستشار: {activeDoc.lawyer || 'مستشار المكتب'}</span>
              </div>
              <span className="badge-s b-green">
                <span className="d" /> معتمد رسمياً
              </span>
            </div>

            {/* نص المحضر أو الملخص */}
            <div
              style={{
                padding: '16px',
                borderRadius: 10,
                backgroundColor: '#ffffff',
                border: '1px solid var(--line)',
                fontSize: 14,
                lineHeight: 1.8,
                color: '#1e293b',
                maxHeight: 340,
                overflowY: 'auto',
                marginBottom: 16,
              }}
            >
              {/* المحضر/الملخص نصّ نموذجٍ توليديّ بنجوم Markdown — RichText يصيّرها بلا حقن HTML */}
              <RichText text={activeDoc.body} />
            </div>

            {/* القرارات المستخلصة إن وجدت */}
            {activeDoc.decisions && activeDoc.decisions.length > 0 && (
              <div
                style={{
                  padding: '12px 16px',
                  borderRadius: 10,
                  backgroundColor: '#f0fdf4',
                  border: '1px solid #bbf7d0',
                  marginBottom: 16,
                }}
              >
                <b style={{ color: '#166534', fontSize: 13, display: 'block', marginBottom: 8 }}>
                  ✓ القرارات والتوصيات المعتمدة:
                </b>
                <ul style={{ margin: 0, paddingRight: 20, fontSize: 13, color: '#14532d', lineHeight: 1.7 }}>
                  {activeDoc.decisions.map((dec, i) => (
                    <li key={i}>{dec}</li>
                  ))}
                </ul>
              </div>
            )}

            {/* أزرار التحكم بالوثيقة */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, borderTop: '1px solid var(--line-soft)', paddingTop: 14 }}>
              <button className="btn ghost sm" type="button" onClick={copyDoc}>
                <Icon name="doc" /> نسخ المحتوى
              </button>
              <button className="btn sm" type="button" onClick={() => setActiveDoc(null)}>
                إغلاق
              </button>
            </div>
          </div>
        )}
      </Modal>
    </>
  );
};

export default Meetings;
