import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import TicketOpsModals, { type LawyerOption, type TicketOpsKind } from '@/components/babylon/TicketOpsModals';
import QuickTicketModal, { type TicketPreviewData } from '@/components/babylon/QuickTicketModal';
import { foldSearch, isUrgentTicket } from '@/lib/employee-data';
import { useCan } from '@/lib/permissions';
import { truncateWords } from '@/lib/utils';
import type { EmployeeTicketCard } from '@/types';

// ============================================================
// لوحة إدارة وتوزيع التذاكر للموظف (Legal Ticket Triage Desk)
// فلاتر ذكية، مؤشرات أولوية، بحث متعدد الحقول، وإجراءات سريعة
// ============================================================

/** `Ticket::toEmployeeCard` (`@/types`) وما تُلحقه هذه الصفحة. */
export interface EmpTicket extends EmployeeTicketCard {
  needsDoc?: boolean;
  converted?: boolean;
  updatedAgo?: string;
  createdAgo?: string;
}

interface Counts {
  total?: number;
  needAction?: number;
  missingDocs?: number;
  referred?: number;
  urgent?: number;
  completed?: number;
}

interface Props {
  tickets: EmpTicket[];
  lawyers: LawyerOption[];
  counts?: Counts;
  departments?: string[];
  catalogueDepartments?: string[]; // أقسام مودال التحويل من الكتالوج الفعّال
  /** ما ينتظر فيه الموظّف طرفاً آخر — `TicketJourney::AWAITING_OTHERS` من الخادم، فيعدّ العدّاد ما يعرضه التبويب */
  awaitingOthers?: string[];
}

const NO_STATUSES: string[] = [];

const openTicket = (no: string) => router.visit(`/employee/tickets/${encodeURIComponent(no)}`);

const EmployeeTickets: React.FC<Props> = ({
  tickets = [],
  lawyers = [],
  counts,
  departments = [],
  catalogueDepartments = [],
  awaitingOthers = NO_STATUSES,
}) => {
  const toast = useToast();
  const can = useCan();
  const canTransfer = can('تحويل التذاكر');
  const canReqDocs = can('الرد على العملاء');
  const canSchedule = can('جدولة المواعيد');

  // التبويب النشط
  const [activeTab, setActiveTab] = useState<'active' | 'urgent' | 'needAction' | 'missingDocs' | 'referred' | 'completed'>('active');

  // البحث والفلاتر
  const [searchQuery, setSearchQuery] = useState('');
  const [filterDept, setFilterDept] = useState('all');
  const [filterLawyer, setFilterLawyer] = useState('all');
  const [filterPriority, setFilterPriority] = useState('all');

  // المودالات
  const [previewTicket, setPreviewTicket] = useState<TicketPreviewData | null>(null);
  const [opsKind, setOpsKind] = useState<TicketOpsKind>(null);
  const [opsTicket, setOpsTicket] = useState<EmpTicket | null>(null);

  const openTransfer = (t: EmpTicket) => {
    setOpsTicket(t);
    setOpsKind('transfer');
  };

  const openReqDocs = (t: EmpTicket) => {
    setOpsTicket(t);
    setOpsKind('reqdocs');
  };

  // حساب الإحصائيات
  const calculatedCounts = useMemo(() => {
    const needAction = tickets.filter((t) => !t.isTerminal && !awaitingOthers.includes(t.status)).length;
    const missingDocs = tickets.filter((t) => t.statusCode === 'AwaitingDocs').length;
    const referred = tickets.filter((t) => t.statusCode === 'Referred').length;
    const urgent = tickets.filter((t) => isUrgentTicket(t.priority)).length;
    const completed = tickets.filter((t) => Boolean(t.isTerminal)).length;

    return {
      total: counts?.total ?? tickets.length,
      needAction: counts?.needAction ?? needAction,
      missingDocs: counts?.missingDocs ?? missingDocs,
      referred: counts?.referred ?? referred,
      urgent: counts?.urgent ?? urgent,
      completed: counts?.completed ?? completed,
    };
  }, [tickets, counts, awaitingOthers]);

  const stats: StatItem[] = [
    ['t-blue', 'folder', calculatedCounts.needAction, 'تذاكر بانتظار إجراء'],
    ['t-red', 'alert', calculatedCounts.urgent, 'تذاكر عالية الأولوية'],
    ['t-amber', 'upload', calculatedCounts.missingDocs, 'بانتظار مستندات'],
    ['t-cyan', 'reply', calculatedCounts.referred, 'محالة للقسم القانوني'],
    ['t-green', 'check', calculatedCounts.completed, 'مكتملة ومغلقة'],
  ];

  // تصفية التذاكر بحسب التبويب والفلاتر والبحث
  const filteredTickets = useMemo(() => {
    return tickets.filter((t) => {
      // فلترة التبويب
      if (activeTab === 'active' && t.isTerminal) return false;
      if (activeTab === 'urgent' && !isUrgentTicket(t.priority)) return false;
      if (activeTab === 'needAction' && (t.isTerminal || awaitingOthers.includes(t.status))) return false;
      if (activeTab === 'missingDocs' && t.statusCode !== 'AwaitingDocs') return false;
      if (activeTab === 'referred' && t.statusCode !== 'Referred') return false;
      if (activeTab === 'completed' && !t.isTerminal) return false;

      // فلترة القسم
      if (filterDept !== 'all' && t.dept !== filterDept) return false;

      // فلترة المستشار
      if (filterLawyer !== 'all' && String(t.lawyerId) !== filterLawyer && t.lawyer !== filterLawyer) return false;

      // فلترة الأولوية
      if (filterPriority !== 'all' && t.priority !== filterPriority) return false;

      // البحث النصي
      if (searchQuery.trim()) {
        const q = foldSearch(searchQuery);
        const noMatch = foldSearch(t.no).includes(q);
        const clientMatch = foldSearch(t.client).includes(q);
        const typeMatch = foldSearch(t.type).includes(q);
        const deptMatch = foldSearch(t.dept).includes(q);
        const lawyerMatch = foldSearch(t.lawyer).includes(q);
        const subMatch = t.subject?.toLowerCase().includes(q) ?? false;
        if (!noMatch && !clientMatch && !typeMatch && !deptMatch && !lawyerMatch && !subMatch) {
          return false;
        }
      }

      return true;
    });
  }, [tickets, activeTab, filterDept, filterLawyer, filterPriority, searchQuery, awaitingOthers]);

  return (
    <>
      {/* ── الترويسة الرئيسية ── */}
      <div className="greet" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 14 }}>
        <div>
          <h2>إدارة وتوزيع التذاكر 🎫</h2>
          <p>مركز الفرز والمتابعة لطلبات العملاء، توجيه المعاملات للمستشارين، وطلب استكمال المستندات.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
          {/* الزرّ لمن يفتح له الخادم وجهته — كانا يظهران للجميع ثمّ يُردّ من لا صلاحيّة له (تدقيق 2026-09-29) */}
          {canTransfer && (
            <button className="btn ghost" onClick={() => router.visit('/employee/transfer')} type="button">
              <Icon name="reply" /> تحويل التذاكر
            </button>
          )}
          {canSchedule && (
            <button className="btn" onClick={() => router.visit('/employee/schedule')} type="button">
              <Icon name="calplus" /> حجز موعد استشارة
            </button>
          )}
        </div>
      </div>

      {/* ── شريط مؤشرات الأداء (KPIs) ── */}
      <StatRow items={stats} />

      {/* ── شريط التبويبات الذكية والبحث والفلاتر ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-b" style={{ padding: '14px 18px', display: 'flex', flexDirection: 'column', gap: 12 }}>
          {/* التبويبات العلوية */}
          <div className="filter-pills" style={{ display: 'flex', gap: 8, flexWrap: 'wrap', borderBottom: '1px solid var(--line-soft)', paddingBottom: 12 }}>
            <button
              type="button"
              className={`btn sm ${activeTab === 'active' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'active' ? undefined : 'none' }}
              onClick={() => setActiveTab('active')}
            >
              <Icon name="folder" /> كل النشطة ({tickets.filter((t) => !t.isTerminal).length})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'urgent' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'urgent' ? undefined : 'none' }}
              onClick={() => setActiveTab('urgent')}
            >
              <Icon name="alert" /> عاجلة وحرجة ({calculatedCounts.urgent})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'needAction' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'needAction' ? undefined : 'none' }}
              onClick={() => setActiveTab('needAction')}
            >
              <Icon name="clock" /> بانتظار إجراء ({calculatedCounts.needAction})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'missingDocs' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'missingDocs' ? undefined : 'none' }}
              onClick={() => setActiveTab('missingDocs')}
            >
              <Icon name="upload" /> نواقص مطلوبة ({calculatedCounts.missingDocs})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'referred' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'referred' ? undefined : 'none' }}
              onClick={() => setActiveTab('referred')}
            >
              <Icon name="reply" /> محالة للمستشارين ({calculatedCounts.referred})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'completed' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'completed' ? undefined : 'none', marginInlineStart: 'auto' }}
              onClick={() => setActiveTab('completed')}
            >
              <Icon name="check" /> المكتملة ({calculatedCounts.completed})
            </button>
          </div>

          {/* شريط البحث والفلاتر */}
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between' }}>
            <div className="search" style={{ width: 280, padding: '7px 12px' }}>
              <Icon name="search" />
              <input
                placeholder="بحث برقم التذكرة، العميل، الموضوع..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              />
              {searchQuery && (
                <button type="button" onClick={() => setSearchQuery('')} style={{ color: 'var(--faint)' }}>
                  <Icon name="close" />
                </button>
              )}
            </div>

            <div className="filter-selects" style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
              {departments.length > 0 && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>القسم:</span>
                  <select
                    value={filterDept}
                    onChange={(e) => setFilterDept(e.target.value)}
                    style={{ width: 140, padding: '6px 28px 6px 10px', fontSize: 13 }}
                  >
                    <option value="all">كل الأقسام</option>
                    {departments.map((d) => (
                      <option key={d} value={d}>{d}</option>
                    ))}
                  </select>
                </div>
              )}

              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>المستشار:</span>
                <select
                  value={filterLawyer}
                  onChange={(e) => setFilterLawyer(e.target.value)}
                  style={{ width: 140, padding: '6px 28px 6px 10px', fontSize: 13 }}
                >
                  <option value="all">كل المستشارين</option>
                  {lawyers.map((l) => (
                    <option key={l.id} value={String(l.id)}>{l.name}</option>
                  ))}
                </select>
              </div>

              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>الأولوية:</span>
                <select
                  value={filterPriority}
                  onChange={(e) => setFilterPriority(e.target.value)}
                  style={{ width: 120, padding: '6px 28px 6px 10px', fontSize: 13 }}
                >
                  <option value="all">الكل</option>
                  <option value="عالية">عالية 🔴</option>
                  <option value="متوسطة">متوسطة 🟡</option>
                  <option value="منخفضة">منخفضة 🟢</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* ── جدول التذاكر الرئيسي ── */}
      <div className="card">
        <div className="card-h">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon name="ticket" />
            <h3>قائمة التذاكر ({filteredTickets.length})</h3>
          </div>
          <span className="sub">انقر على أي صف لفتح المحادثة الفورية</span>
        </div>

        <div className="card-b t-wrap" style={{ padding: 0 }}>
          {filteredTickets.length ? (
            <table className="tbl" style={{ minWidth: 780 }}>
              <thead>
                <tr>
                  <th style={{ width: 130 }}>التذكرة</th>
                  <th style={{ minWidth: 180, maxWidth: 300 }}>العميل والموضوع</th>
                  <th>النوع والقسم</th>
                  <th>الأولوية</th>
                  <th>المستشار المكلف</th>
                  <th>الحالة</th>
                  <th style={{ width: 150 }}>الإجراءات</th>
                </tr>
              </thead>
              <tbody>
                {filteredTickets.map((t) => {
                  const isUrgent = isUrgentTicket(t.priority);
                  const pTone = isUrgent ? 'b-red' : t.priority === 'منخفضة' ? 'b-green' : 'b-amber';

                  return (
                    <tr key={t.no} className="click" onClick={() => openTicket(t.no)}>
                      <td className="nowrap">
                        <div className="mono" style={{ fontWeight: 800, fontSize: 13.5 }}>{t.no}</div>
                        <div className="muted" style={{ fontSize: 11 }}>{t.updatedAgo || t.createdAgo || 'الآن'}</div>
                      </td>
                      <td style={{ minWidth: 180, maxWidth: 300 }}>
                        <b title={t.client}>{truncateWords(t.client, 4)}</b>
                        {t.subject && (
                          <div className="muted" title={t.subject} style={{ fontSize: 11.5, marginTop: 2, lineHeight: 1.4 }}>
                            {truncateWords(t.subject, 8)}
                          </div>
                        )}
                      </td>
                      <td>
                        <div style={{ fontWeight: 600, color: 'var(--ink)' }}>{t.type}</div>
                        <div className="muted" style={{ fontSize: 11 }}>{t.dept}</div>
                      </td>
                      <td className="nowrap">
                        <Badge text={t.priority || 'متوسطة'} tone={pTone} />
                      </td>
                      <td className="nowrap">
                        <b title={t.lawyer}>{truncateWords(t.lawyer, 4)}</b>
                        {t.handler && (
                          <div className="muted" style={{ fontSize: 11 }} title="الموظّف المسؤول عن المحادثة الآن">
                            المحادثة: {truncateWords(t.handler, 3)}
                          </div>
                        )}
                      </td>
                      <td className="nowrap">
                        <Badge text={t.status} tone={t.tone} />
                      </td>
                      <td>
                        <div
                          style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}
                          onClick={(e) => e.stopPropagation()}
                        >
                          <button
                            className="btn soft sm"
                            onClick={() => setPreviewTicket(t)}
                            type="button"
                            title="معاينة سريعة لبيانات التذكرة"
                          >
                            <Icon name="doc" /> معاينة
                          </button>
                          <button
                            className="btn sm"
                            onClick={() => openTicket(t.no)}
                            type="button"
                            title="فتح المحادثة"
                          >
                            <Icon name="reply" /> المحادثة
                          </button>

                          {/* طلب نواقص بصلاحية الرد على العملاء */}
                          {canReqDocs && !t.isTerminal && (
                            <button
                              className="btn soft sm"
                              onClick={() => openReqDocs(t)}
                              type="button"
                              title="طلب استكمال المستندات من العميل"
                            >
                              <Icon name="upload" /> نواقص
                            </button>
                          )}

                          {t.isTerminal && (
                            t.converted ? (
                              <Badge text="محوّلة لقضية" tone="b-cyan" />
                            ) : (
                              <button
                                className="btn soft sm"
                                onClick={() => openTicket(t.no)}
                                type="button"
                                title="عرض التذكرة لمتابعة قرار المآل عبر بطاقة الحوكمة"
                              >
                                <Icon name="scale" /> قرار المآل
                              </button>
                            )
                          )}

                          {canTransfer && t.isReassignable && (
                            <button
                              className="btn soft sm"
                              onClick={() => openTransfer(t)}
                              type="button"
                              title="تحويل لمستشار آخر"
                            >
                              <Icon name="reply" /> تحويل
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          ) : (
            <div className="empty">
              <Icon name="folder" />
              <b>{tickets.length === 0 ? 'لا توجد تذاكر بعد' : 'لا توجد تذاكر مطابقة لخيارات البحث والتصفية'}</b>
              {(searchQuery || filterDept !== 'all' || filterLawyer !== 'all' || filterPriority !== 'all' || activeTab !== 'active') && (
                <button
                  className="btn soft sm"
                  style={{ marginTop: 10 }}
                  onClick={() => {
                    setSearchQuery('');
                    setFilterDept('all');
                    setFilterLawyer('all');
                    setFilterPriority('all');
                    setActiveTab('active');
                  }}
                  type="button"
                >
                  إعادة ضبط الفلاتر
                </button>
              )}
            </div>
          )}
        </div>
      </div>

      {/* ── مودال المعاينة السريعة ── */}
      <QuickTicketModal
        ticket={previewTicket}
        open={Boolean(previewTicket)}
        role="employee"
        onClose={() => setPreviewTicket(null)}
        onTransfer={canTransfer ? (no) => {
          const t = tickets.find((x) => x.no === no);
          // حارس الخادم نفسه (`TicketAssignment::assertReassignable`) — المجمّدة والنهائيّة لا تُحوَّل
          if (t && t.isReassignable) {
            setPreviewTicket(null);
            openTransfer(t);
          }
        } : undefined}
      />

      {/* ── مودالا التحويل وطلب النواقص ── */}
      <TicketOpsModals
        kind={opsKind}
        ticketNo={opsTicket?.no ?? ''}
        dept={opsTicket?.dept}
        lawyerId={opsTicket?.lawyerId ?? null}
        lawyers={lawyers}
        departments={catalogueDepartments}
        onClose={() => setOpsKind(null)}
        onDone={() => router.reload({ only: ['tickets', 'counts'] })}
      />
    </>
  );
};

export default EmployeeTickets;

