import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import QuickTicketModal, { type TicketPreviewData } from '@/components/babylon/QuickTicketModal';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { useCan } from '@/lib/permissions';

// ============================================================
// منصة فرز ودراسة تذاكر المستشار القانوني 360° (Lawyer Ticket Study Desk)
// فحص التذاكر المحالة، مراجعة ملخصات الذكاء الاصطناعي، وتحويل الملفات لقضايا
// ============================================================

export interface EmpTicket {
  no: string;
  client: string;
  type: string;
  dept: string;
  lawyer: string;
  status: string;
  tone: string;
  subject?: string;
  priority?: string;
  hasSummary?: boolean;
  summaryStatus?: string;
  summaryRecommendation?: string;
  summaryFacts?: string;
  converted?: boolean;
  caseRef?: string;
  awaitingSummary?: boolean;
  updatedAgo?: string;
  createdAgo?: string;
}

interface Counts {
  total: number;
  needStudy: number;
  awaitingSummary: number;
  urgent: number;
  missingDocs: number;
  converted: number;
  completed: number;
}

interface Props {
  tickets: EmpTicket[];
  counts?: Counts;
  departments?: string[];
}

const LawyerTickets: React.FC<Props> = ({
  tickets = [],
  counts,
  departments = [],
}) => {
  const toast = useToast();
  const can = useCan();
  const canManageCases = can('إدارة القضايا والأتعاب');
  const canApproveSummaries = can('اعتماد الملخصات');

  // التبويب النشط ونمط العرض
  const [activeTab, setActiveTab] = useState<'all' | 'needStudy' | 'awaitingSummary' | 'urgent' | 'missingDocs' | 'converted' | 'completed'>('all');
  const [viewMode, setViewMode] = useState<'table' | 'cards'>('table');

  // البحث والفلاتر المتقدمة
  const [searchQuery, setSearchQuery] = useState('');
  const [filterDept, setFilterDept] = useState('all');
  const [filterPriority, setFilterPriority] = useState('all');
  const [filterAi, setFilterAi] = useState('all');

  // نافذة المعاينة السريعة
  const [previewTicket, setPreviewTicket] = useState<TicketPreviewData | null>(null);

  // التنقلات
  const openTicket = (no: string) => router.visit(`/lawyer/tickets/${encodeURIComponent(no)}`);
  const openSummaries = () => router.visit('/lawyer/summaries');
  const openAssistant = () => router.visit('/lawyer/assistant');

  // تحويل التذكرة إلى قضية
  const convert = (no: string) => {
    router.post(
      `/lawyer/tickets/${encodeURIComponent(no)}/convert`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => toast('✅ تم تحويل التذكرة إلى قضية رسمية بنجاح'),
        onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر تحويل التذكرة لقضية'}`),
      }
    );
  };

  // حساب الإحصائيات إذا لم تُمرر من الخادم
  const calculatedCounts: Counts = useMemo(() => {
    if (counts) return counts;
    return {
      total: tickets.length,
      needStudy: tickets.filter((t) => !['مكتملة', 'مغلقة', 'محولة لقضية'].includes(t.status)).length,
      awaitingSummary: tickets.filter((t) => t.summaryStatus === 'awaiting_lawyer').length,
      urgent: tickets.filter((t) => ['عاجلة', 'طارئة', 'عاجل جداً', 'عالية'].includes(t.priority || '')).length,
      missingDocs: tickets.filter((t) => t.status === 'بانتظار مستندات').length,
      converted: tickets.filter((t) => t.converted).length,
      completed: tickets.filter((t) => ['مكتملة', 'مغلقة'].includes(t.status)).length,
    };
  }, [tickets, counts]);

  // استخراج قائمة الأقسام الفريدة
  const deptList = useMemo(() => {
    if (departments.length > 0) return departments;
    return Array.from(new Set(tickets.map((t) => t.dept).filter(Boolean)));
  }, [tickets, departments]);

  // تصفية التذاكر بناءً على التبويب والبحث والفلاتر
  const filteredTickets = useMemo(() => {
    return tickets.filter((t) => {
      // 1. تصفية التبويب
      if (activeTab === 'needStudy' && ['مكتملة', 'مغلقة', 'محولة لقضية'].includes(t.status)) return false;
      if (activeTab === 'awaitingSummary' && t.summaryStatus !== 'awaiting_lawyer') return false;
      if (activeTab === 'urgent' && !['عاجلة', 'طارئة', 'عاجل جداً', 'عالية'].includes(t.priority || '')) return false;
      if (activeTab === 'missingDocs' && t.status !== 'بانتظار مستندات') return false;
      if (activeTab === 'converted' && !t.converted) return false;
      if (activeTab === 'completed' && !['مكتملة', 'مغلقة'].includes(t.status)) return false;

      // 2. فلتر القسم
      if (filterDept !== 'all' && t.dept !== filterDept) return false;

      // 3. فلتر الأولوية
      if (filterPriority !== 'all' && (t.priority || 'عادية') !== filterPriority) return false;

      // 4. فلتر حالة دراسة الذكاء الاصطناعي
      if (filterAi === 'awaiting' && t.summaryStatus !== 'awaiting_lawyer') return false;
      if (filterAi === 'approved' && t.summaryStatus !== 'approved') return false;
      if (filterAi === 'none' && t.hasSummary) return false;

      // 5. البحث بالكلمات المفتاحية
      if (searchQuery.trim()) {
        const q = searchQuery.trim().toLowerCase();
        const matches =
          t.no.toLowerCase().includes(q) ||
          t.client.toLowerCase().includes(q) ||
          t.type.toLowerCase().includes(q) ||
          t.dept.toLowerCase().includes(q) ||
          t.status.toLowerCase().includes(q) ||
          (t.subject && t.subject.toLowerCase().includes(q));
        if (!matches) return false;
      }

      return true;
    });
  }, [tickets, activeTab, filterDept, filterPriority, filterAi, searchQuery]);

  // إحصائيات قمرة النبض
  const statItems: StatItem[] = [
    ['t-blue', 'folder', calculatedCounts.total, 'إجمالي التذاكر'],
    ['t-green', 'scale', calculatedCounts.needStudy, 'قيد الدراسة والمتابعة'],
    ['t-amber', 'doc', calculatedCounts.awaitingSummary, 'بانتظار اعتماد الملخص'],
    ['t-red', 'clock', calculatedCounts.urgent, 'تذاكر عاجلة وطارئة'],
    ['t-cyan', 'upload', calculatedCounts.missingDocs, 'بانتظار مستندات'],
    ['t-blue', 'exec', calculatedCounts.converted, 'محوّلة لقضايا'],
  ];

  return (
    <>
      {/* 1. الترويسة وشريط الاختصارات السريعة */}
      <div className="hero" style={{ padding: '24px 26px', marginBottom: 20 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 16 }}>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
              <span className="chip" style={{ background: 'rgba(255,255,255,0.18)', color: '#fff', borderColor: 'rgba(255,255,255,0.3)', fontWeight: 700 }}>
                <Icon name="folder" cls="ic" /> تذاكر المستشار القانوني
              </span>
              <span style={{ fontSize: 12, opacity: 0.9, color: '#e0f2fe' }}>
                منصة الفحص والدراسة والاعتماد
              </span>
            </div>
            <h2>تذاكري المحالة للدراسة والاستشارة ⚖️</h2>
            <p>
              متابعة التذاكر القانونية المسندة إليك، مراجعة ملخصات التحليل الفوري للذكاء الاصطناعي، والرد على الموكلين أو تحويل الطلب لقضية.
            </p>
          </div>

          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <button
              className="hero-b ghost"
              onClick={() => router.reload()}
              title="تحديث البيانات"
              type="button"
            >
              <Icon name="clock" /> تحديث القائمة
            </button>
            {canApproveSummaries && (
              <button
                className="hero-b"
                onClick={openSummaries}
                type="button"
              >
                <Icon name="doc" /> اعتماد الملخصات ({calculatedCounts.awaitingSummary})
              </button>
            )}
            <button
              className="hero-b ghost"
              onClick={openAssistant}
              type="button"
            >
              <Icon name="sparkles" /> المساعد الذكي
            </button>
          </div>
        </div>
      </div>

      {/* 2. شريط مؤشرات نبض التذاكر (KPI StatRow) */}
      <StatRow
        items={statItems}
        onSelect={(idx) => {
          if (idx === 0) setActiveTab('all');
          if (idx === 1) setActiveTab('needStudy');
          if (idx === 2) setActiveTab('awaitingSummary');
          if (idx === 3) setActiveTab('urgent');
          if (idx === 4) setActiveTab('missingDocs');
          if (idx === 5) setActiveTab('converted');
        }}
      />

      {/* 3. حاوية مساحة التذاكر الرئيسية */}
      <div className="card">
        {/* شريط التبويبات حسب المرحلة */}
        <div className="card-h" style={{ padding: '14px 18px', borderBottom: '1px solid var(--line-soft)', flexWrap: 'wrap', gap: 12 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
            <button
              className={`btn sm ${activeTab === 'all' ? '' : 'soft'}`}
              onClick={() => setActiveTab('all')}
              type="button"
            >
              <Icon name="folder" /> الكل ({calculatedCounts.total})
            </button>
            <button
              className={`btn sm ${activeTab === 'needStudy' ? '' : 'soft'}`}
              onClick={() => setActiveTab('needStudy')}
              type="button"
            >
              <Icon name="scale" /> قيد الدراسة ({calculatedCounts.needStudy})
            </button>
            <button
              className={`btn sm ${activeTab === 'awaitingSummary' ? '' : 'soft'}`}
              onClick={() => setActiveTab('awaitingSummary')}
              type="button"
            >
              <Icon name="doc" /> اعتماد الملخص ({calculatedCounts.awaitingSummary})
            </button>
            <button
              className={`btn sm ${activeTab === 'urgent' ? '' : 'soft'}`}
              onClick={() => setActiveTab('urgent')}
              type="button"
            >
              <Icon name="clock" /> عاجلة ({calculatedCounts.urgent})
            </button>
            <button
              className={`btn sm ${activeTab === 'missingDocs' ? '' : 'soft'}`}
              onClick={() => setActiveTab('missingDocs')}
              type="button"
            >
              <Icon name="upload" /> نواقص مستندات ({calculatedCounts.missingDocs})
            </button>
            <button
              className={`btn sm ${activeTab === 'converted' ? '' : 'soft'}`}
              onClick={() => setActiveTab('converted')}
              type="button"
            >
              <Icon name="exec" /> محوّلة لقضايا ({calculatedCounts.converted})
            </button>
            <button
              className={`btn sm ${activeTab === 'completed' ? '' : 'soft'}`}
              onClick={() => setActiveTab('completed')}
              type="button"
            >
              <Icon name="check" /> مكتملة ({calculatedCounts.completed})
            </button>
          </div>

          {/* تبديل طريقة العرض (جدول / بطاقات) */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 4, background: 'var(--paper-2)', padding: 3, borderRadius: 8, border: '1px solid var(--line)' }}>
            <button
              onClick={() => setViewMode('table')}
              style={{
                padding: '5px 9px',
                borderRadius: 6,
                background: viewMode === 'table' ? '#fff' : 'transparent',
                boxShadow: viewMode === 'table' ? '0 1px 3px rgba(0,0,0,0.1)' : 'none',
                color: viewMode === 'table' ? '#0E5C9C' : '#607689',
                display: 'flex',
                alignItems: 'center',
                gap: 4,
                fontSize: 12,
                fontWeight: 700,
              }}
              type="button"
            >
              <Icon name="file" /> جدول
            </button>
            <button
              onClick={() => setViewMode('cards')}
              style={{
                padding: '5px 9px',
                borderRadius: 6,
                background: viewMode === 'cards' ? '#fff' : 'transparent',
                boxShadow: viewMode === 'cards' ? '0 1px 3px rgba(0,0,0,0.1)' : 'none',
                color: viewMode === 'cards' ? '#0E5C9C' : '#607689',
                display: 'flex',
                alignItems: 'center',
                gap: 4,
                fontSize: 12,
                fontWeight: 700,
              }}
              type="button"
            >
              <Icon name="calgrid" /> بطاقات
            </button>
          </div>
        </div>

        {/* شريط البحث والفلترة المتقدمة */}
        <div style={{ padding: '12px 18px', background: 'var(--paper-2)', borderBottom: '1px solid var(--line)', display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between' }}>
          {/* حقل البحث */}
          <div className="search" style={{ flex: '1 1 260px', width: 'auto', background: '#fff' }}>
            <Icon name="search" />
            <input
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="بحث برقم التذكرة، اسم الموكل، الموضوع، أو القسم…"
            />
            {searchQuery && (
              <button onClick={() => setSearchQuery('')} type="button" style={{ opacity: 0.6 }}>
                <Icon name="close" />
              </button>
            )}
          </div>

          {/* الفلاتر المنسدلة */}
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
            {/* فلتر القسم */}
            {deptList.length > 0 && (
              <select
                value={filterDept}
                onChange={(e) => setFilterDept(e.target.value)}
                style={{
                  padding: '7px 10px',
                  borderRadius: 8,
                  border: '1px solid var(--line)',
                  background: '#fff',
                  fontSize: 12.5,
                  color: '#13314F',
                  outline: 'none',
                  fontWeight: 600,
                }}
              >
                <option value="all">جميع الأقسام</option>
                {deptList.map((d) => (
                  <option key={d} value={d}>{d}</option>
                ))}
              </select>
            )}

            {/* فلتر الأولوية */}
            <select
              value={filterPriority}
              onChange={(e) => setFilterPriority(e.target.value)}
              style={{
                padding: '7px 10px',
                borderRadius: 8,
                border: '1px solid var(--line)',
                background: '#fff',
                fontSize: 12.5,
                color: '#13314F',
                outline: 'none',
                fontWeight: 600,
              }}
            >
              <option value="all">كل الأولويات</option>
              <option value="عاجلة">عاجلة / طارئة</option>
              <option value="عادية">عادية</option>
              <option value="منخفضة">منخفضة</option>
            </select>

            {/* فلتر الذكاء الاصطناعي */}
            <select
              value={filterAi}
              onChange={(e) => setFilterAi(e.target.value)}
              style={{
                padding: '7px 10px',
                borderRadius: 8,
                border: '1px solid var(--line)',
                background: '#fff',
                fontSize: 12.5,
                color: '#13314F',
                outline: 'none',
                fontWeight: 600,
              }}
            >
              <option value="all">دراسة الذكاء الاصطناعي (الكل)</option>
              <option value="awaiting">بانتظار اعتمادي 🟡</option>
              <option value="approved">معتمدة ✅</option>
              <option value="none">بدون ملخص</option>
            </select>

            {/* زر تصفير الفلاتر */}
            {(searchQuery || filterDept !== 'all' || filterPriority !== 'all' || filterAi !== 'all') && (
              <button
                onClick={() => {
                  setSearchQuery('');
                  setFilterDept('all');
                  setFilterPriority('all');
                  setFilterAi('all');
                }}
                style={{ fontSize: 12, color: '#ef4444', fontWeight: 700, padding: '4px 8px' }}
                type="button"
              >
                تصفير الفلاتر ✕
              </button>
            )}
          </div>
        </div>

        {/* 4. مساحة العرض (جدول أو بطاقات) */}
        <div className="card-b t-wrap" style={{ padding: 0 }}>
          {filteredTickets.length > 0 ? (
            viewMode === 'table' ? (
              /* نمط الجدول التفاعلي المتقدم */
              <table className="tbl">
                <thead>
                  <tr>
                    <th>رقم التذكرة</th>
                    <th>الموكل</th>
                    <th>النوع والقسم</th>
                    <th>الأولوية</th>
                    <th>دراسة الذكاء الاصطناعي</th>
                    <th>الحالة</th>
                    <th style={{ textAlign: 'end', paddingInlineEnd: 20 }}>إجراءات المستشار</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredTickets.map((t) => (
                    <tr key={t.no} className="click" onClick={() => openTicket(t.no)}>
                      <td className="mono" style={{ fontWeight: 800, color: '#0E5C9C' }}>
                        {t.no}
                      </td>
                      <td>
                        <div style={{ fontWeight: 700, color: '#13314F' }}>{t.client}</div>
                        <div className="muted" style={{ fontSize: 11.5 }}>
                          {t.updatedAgo ? `تحديث: ${t.updatedAgo}` : t.createdAgo}
                        </div>
                      </td>
                      <td>
                        <div style={{ fontWeight: 600 }}>{t.type}</div>
                        <div className="muted" style={{ fontSize: 11.5 }}>{t.dept || 'القسم القانوني'}</div>
                      </td>
                      <td>
                        {['عاجلة', 'طارئة', 'عاجل جداً', 'عالية'].includes(t.priority || '') ? (
                          <span className="badge-s b-red" style={{ fontSize: 11 }}>
                            <span className="d" /> عاجلة ⚡
                          </span>
                        ) : (
                          <span className="chip" style={{ fontSize: 11 }}>
                            {t.priority || 'عادية'}
                          </span>
                        )}
                      </td>
                      <td>
                        {t.summaryStatus === 'awaiting_lawyer' ? (
                          <span className="badge-s b-amber" style={{ fontSize: 11 }}>
                            <span className="d" /> بانتظار اعتمادك 🟡
                          </span>
                        ) : t.summaryStatus === 'approved' ? (
                          <span className="badge-s b-green" style={{ fontSize: 11 }}>
                            <span className="d" /> معتمد ومغلق ✅
                          </span>
                        ) : t.hasSummary ? (
                          <span className="badge-s b-cyan" style={{ fontSize: 11 }}>
                            <span className="d" /> محلل
                          </span>
                        ) : (
                          <span className="badge-s b-grey" style={{ fontSize: 11 }}>
                            قيد التحضير
                          </span>
                        )}
                      </td>
                      <td>
                        <Badge text={t.status} tone={t.tone} />
                      </td>
                      <td>
                        <div
                          style={{ display: 'flex', gap: 6, flexWrap: 'wrap', justifyContent: 'flex-end', paddingInlineEnd: 12 }}
                          onClick={(e) => e.stopPropagation()}
                        >
                          <button
                            className="btn soft sm"
                            onClick={() => setPreviewTicket(t)}
                            title="معاينة سريعة للتفاصيل"
                            type="button"
                          >
                            <Icon name="doc" /> معاينة
                          </button>
                          <button
                            className="btn sm"
                            onClick={() => openTicket(t.no)}
                            type="button"
                          >
                            <Icon name="scale" /> دراسة ومحادثة
                          </button>
                          {t.status === 'مكتملة' && (
                            t.converted ? (
                              <span className="badge-s b-cyan" style={{ fontSize: 11 }}>
                                <span className="d" /> محوّلة لقضية
                              </span>
                            ) : canManageCases && (
                              <button
                                className="btn sm"
                                style={{ background: '#0A2A55' }}
                                onClick={() => convert(t.no)}
                                type="button"
                              >
                                <Icon name="scale" /> تحويل لقضية
                              </button>
                            )
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              /* نمط بطاقات الفرز الذكية (Card / Grid View) */
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))', gap: 16, padding: 18 }}>
                {filteredTickets.map((t) => (
                  <div
                    key={t.no}
                    onClick={() => openTicket(t.no)}
                    className="card"
                    style={{
                      margin: 0,
                      cursor: 'pointer',
                      transition: 'transform 0.15s ease, box-shadow 0.15s ease',
                      border: t.summaryStatus === 'awaiting_lawyer' ? '1.5px solid #f59e0b' : '1px solid var(--line)',
                    }}
                  >
                    <div className="card-h" style={{ padding: '12px 14px' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span className="mono" style={{ fontWeight: 800, fontSize: 13, color: '#0E5C9C' }}>
                          {t.no}
                        </span>
                        {['عاجلة', 'طارئة', 'عاجل جداً', 'عالية'].includes(t.priority || '') && (
                          <span className="badge-s b-red" style={{ fontSize: 10, padding: '2px 6px' }}>عاجلة ⚡</span>
                        )}
                      </div>
                      <Badge text={t.status} tone={t.tone} />
                    </div>

                    <div className="card-b" style={{ padding: '12px 14px' }}>
                      <div style={{ fontWeight: 800, fontSize: 14, color: '#13314F', marginBottom: 4 }}>
                        {t.client}
                      </div>
                      <div style={{ fontSize: 12.5, color: '#607689', marginBottom: 8 }}>
                        {t.type} · <span className="chip" style={{ fontSize: 11 }}>{t.dept || 'القسم القانوني'}</span>
                      </div>

                      {t.subject && (
                        <div style={{ fontSize: 12, color: '#475569', background: 'var(--paper-2)', padding: '6px 8px', borderRadius: 6, marginBottom: 8 }}>
                          {t.subject}
                        </div>
                      )}

                      {/* ملخص الذكاء الاصطناعي إن وجد */}
                      {t.summaryRecommendation && (
                        <div style={{ background: '#fef3c7', border: '1px solid #fde68a', borderRadius: 8, padding: '8px 10px', fontSize: 11.5, color: '#92400e', marginBottom: 10 }}>
                          <b>💡 توصية الذكاء الاصطناعي:</b>
                          <div style={{ marginTop: 2, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                            {t.summaryRecommendation}
                          </div>
                        </div>
                      )}

                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 10, paddingTop: 10, borderTop: '1px solid var(--line-soft)' }}>
                        <span className="muted" style={{ fontSize: 11 }}>
                          {t.updatedAgo ? `تحديث: ${t.updatedAgo}` : t.createdAgo}
                        </span>

                        <div style={{ display: 'flex', gap: 6 }} onClick={(e) => e.stopPropagation()}>
                          <button
                            className="btn soft sm"
                            onClick={() => setPreviewTicket(t)}
                            type="button"
                          >
                            <Icon name="doc" /> معاينة
                          </button>
                          <button
                            className="btn sm"
                            onClick={() => openTicket(t.no)}
                            type="button"
                          >
                            <Icon name="scale" /> دراسة
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )
          ) : (
            <div className="empty" style={{ padding: '50px 20px', textAlign: 'center' }}>
              <Icon name="folder" />
              <b style={{ display: 'block', marginTop: 12, fontSize: 15, color: '#13314F' }}>
                لا توجد تذاكر مطابقة لخيارات الفلترة أو البحث
              </b>
              <p style={{ fontSize: 13, color: '#607689', marginTop: 4 }}>
                جرّب تصفير الفلاتر أو البحث بكلمة مفتاحية مختلفة.
              </p>
              {(searchQuery || filterDept !== 'all' || filterPriority !== 'all' || filterAi !== 'all' || activeTab !== 'all') && (
                <button
                  className="btn soft sm"
                  style={{ marginTop: 12 }}
                  onClick={() => {
                    setActiveTab('all');
                    setSearchQuery('');
                    setFilterDept('all');
                    setFilterPriority('all');
                    setFilterAi('all');
                  }}
                  type="button"
                >
                  إعادة ضبط الفلاتر وعرض كل التذاكر
                </button>
              )}
            </div>
          )}
        </div>
      </div>

      {/* مودال المعاينة السريعة */}
      <QuickTicketModal
        ticket={previewTicket}
        open={Boolean(previewTicket)}
        role="lawyer"
        onClose={() => setPreviewTicket(null)}
      />
    </>
  );
};

export default LawyerTickets;
