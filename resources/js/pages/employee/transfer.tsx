import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import LawyerSuggestionHint, { type LawyerSuggestionData } from '@/components/babylon/LawyerSuggestionHint';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import { foldSearch, isUrgentTicket } from '@/lib/employee-data';
import { inSessionSuffix, PresenceBadge, useInSession } from '@/lib/staff-presence';
import type { EmployeeTicketCard } from '@/types';

// ============================================================
// لوحة تحويل التذاكر وتوزيع أعباء العمل للموظف (Smart Re-assignment Hub)
// رادار سعة المستشارين، تحويل جماعي وفردي، سجل تدقيق، وفلاتر ذكية
// ============================================================

/** `Ticket::toEmployeeCard` (`@/types`) وما تُلحقه هذه الصفحة. */
export interface EmpTransferTicket extends EmployeeTicketCard {
  isUnassigned?: boolean;
  /** اقتراح النظام لغير المسنَدة، موسوماً بالتخصّص (يؤكّده الموظّف) */
  suggestion?: LawyerSuggestionData | null;
  updatedAgo?: string;
  createdAgo?: string;
}

export interface LawyerWorkload {
  id: number;
  name: string;
  activeTickets: number;
  activeCases: number;
  capacity: 'available' | 'moderate' | 'busy';
}

export interface TransferAuditItem {
  id: number;
  ticketNo: string;
  staff: string;
  text: string;
  date: string;
}

interface Counts {
  total?: number;
  unassigned?: number;
  assigned?: number;
  urgent?: number;
}

interface Props {
  tickets: EmpTransferTicket[];
  lawyers: LawyerWorkload[];
  counts?: Counts;
  departments?: string[];
  recentTransfers?: TransferAuditItem[];
}

const EmployeeTransfer: React.FC<Props> = ({
  tickets = [],
  lawyers = [],
  counts,
  departments = [],
  recentTransfers = [],
}) => {
  const inSession = useInSession();
  const toast = useToast();

  // التبويب النشط
  const [activeTab, setActiveTab] = useState<'unassigned' | 'all' | 'urgent' | 'audit'>('unassigned');

  // البحث والفلاتر
  const [searchQuery, setSearchQuery] = useState('');
  const [filterDept, setFilterDept] = useState('all');
  const [filterLawyer, setFilterLawyer] = useState('all');

  // تحديد التذاكر للتحويل الجماعي
  const [selectedNos, setSelectedNos] = useState<string[]>([]);
  const [bulkLawyerId, setBulkLawyerId] = useState<number>(lawyers[0]?.id ?? 0);
  const [bulkReason, setBulkReason] = useState('');
  const [bulkBusy, setBulkBusy] = useState(false);

  // إعدادات التحويل الفردي للصفوف
  const [selLawyer, setSelLawyer] = useState<Record<string, number>>(() =>
    // المبدئيّ اقتراح النظام إن وُجد — لا أوّل اسمٍ في الترتيب الأبجديّ
    Object.fromEntries(tickets.map((t) => [t.no, t.suggestion?.lawyerId ?? lawyers[0]?.id ?? 0]))
  );
  const [rowReason, setRowReason] = useState<Record<string, string>>({});
  const [transferringNo, setTransferringNo] = useState<string | null>(null);

  // حساب الإحصائيات
  const calculatedCounts = useMemo(() => {
    const unassigned = tickets.filter((t) => t.isUnassigned || !t.lawyerId || t.lawyer === '—').length;
    const assigned = tickets.filter((t) => !t.isUnassigned && t.lawyerId && t.lawyer !== '—').length;
    const urgent = tickets.filter((t) => isUrgentTicket(t.priority)).length;

    return {
      total: counts?.total ?? tickets.length,
      unassigned: counts?.unassigned ?? unassigned,
      assigned: counts?.assigned ?? assigned,
      urgent: counts?.urgent ?? urgent,
    };
  }, [tickets, counts]);

  const stats: StatItem[] = [
    ['t-red', 'alert', calculatedCounts.unassigned, 'تذاكر بانتظار توجيه'],
    ['t-blue', 'folder', calculatedCounts.assigned, 'تذاكر قيد المعالجة'],
    ['t-amber', 'sparkles', calculatedCounts.urgent, 'تذاكر عالية الأولوية'],
    ['t-cyan', 'reply', calculatedCounts.total, 'إجمالي التذاكر المفتوحة'],
  ];

  // تصفية التذاكر
  const filteredTickets = useMemo(() => {
    return tickets.filter((t) => {
      const isUn = t.isUnassigned || !t.lawyerId || t.lawyer === '—';

      // فلترة التبويب
      if (activeTab === 'unassigned' && !isUn) return false;
      if (activeTab === 'urgent' && !isUrgentTicket(t.priority)) return false;

      // فلترة القسم
      if (filterDept !== 'all' && t.dept !== filterDept) return false;

      // فلترة المستشار الحالي
      if (filterLawyer !== 'all' && String(t.lawyerId) !== filterLawyer && t.lawyer !== filterLawyer) return false;

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
  }, [tickets, activeTab, filterDept, filterLawyer, searchQuery]);

  // إدارة التحديد الجماعي
  const toggleSelectAll = () => {
    if (selectedNos.length === filteredTickets.length) {
      setSelectedNos([]);
    } else {
      setSelectedNos(filteredTickets.map((t) => t.no));
    }
  };

  const toggleSelectOne = (no: string) => {
    setSelectedNos((prev) =>
      prev.includes(no) ? prev.filter((x) => x !== no) : [...prev, no]
    );
  };

  // تنفيذ التحويل الفردي
  const doSingleTransfer = (no: string) => {
    const targetLawyerId = selLawyer[no] || lawyers[0]?.id;
    if (!targetLawyerId) {
      toast('⚠️ يُرجى اختيار المستشار المحال إليه');
      return;
    }

    setTransferringNo(no);
    router.post(
      `/employee/transfer/${encodeURIComponent(no)}`,
      {
        lawyer_id: targetLawyerId,
        reason: rowReason[no] || 'إعادة توزيع عبر لوحة التحويلات',
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setTransferringNo(null);
          toast(`تم تحويل التذكرة ${no} بنجاح`);
          setSelectedNos((prev) => prev.filter((x) => x !== no));
        },
        onError: (errors) => {
          setTransferringNo(null);
          toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر تحويل التذكرة'}`);
        },
      }
    );
  };

  // تنفيذ التحويل الجماعي
  const doBulkTransfer = (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedNos.length) {
      toast('⚠️ يُرجى تحديد تذكرة واحدة على الأقل');
      return;
    }
    if (!bulkLawyerId) {
      toast('⚠️ يُرجى اختيار المستشار المستهدف');
      return;
    }

    setBulkBusy(true);
    router.post(
      '/employee/transfer/bulk',
      {
        tickets: selectedNos,
        lawyer_id: bulkLawyerId,
        reason: bulkReason || 'تحويل جماعي لتوزيع أعباء العمل',
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setBulkBusy(false);
          toast(`تم تحويل ${selectedNos.length} تذكرة بنجاح`);
          setSelectedNos([]);
          setBulkReason('');
        },
        onError: (errors) => {
          setBulkBusy(false);
          toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر إتمام التحويل الجماعي'}`);
        },
      }
    );
  };

  return (
    <>
      {/* ── الترويسة الرئيسية ── */}
      <div className="greet" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 14 }}>
        <div>
          <h2>إدارة تحويل التذاكر وتوزيع الأعباء 🔄</h2>
          <p>موازنة ضغط العمل على المستشارين، إسناد التذاكر غير الموزعة، ومتابعة سجل التحويلات.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
          <button className="btn ghost" onClick={() => router.visit('/employee/tickets')} type="button">
            <Icon name="ticket" /> قائمة كل التذاكر
          </button>
          <button className="btn" onClick={() => router.visit('/employee/schedule')} type="button">
            <Icon name="calplus" /> جدول المواعيد
          </button>
        </div>
      </div>

      {/* ── شريط مؤشرات الأداء ── */}
      <StatRow items={stats} />

      {/* ── رادار تفرغ وأعباء المستشارين (Lawyer Load Radar) ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-h">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon name="user" />
            <h3>رادار تفرغ وسعة فريق المستشارين</h3>
          </div>
          <span className="sub">استرشد بمعدل العبء لتوزيع التذاكر بعدالة</span>
        </div>

        <div className="card-b" style={{ padding: 16 }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 12 }}>
            {lawyers.map((l) => {
              const capTone = l.capacity === 'available' ? 'b-green' : l.capacity === 'moderate' ? 'b-amber' : 'b-red';
              const capLabel = l.capacity === 'available' ? '🟢 متاح للإسناد' : l.capacity === 'moderate' ? '🟡 عبء متوسط' : '🔴 عبء مرتفع';

              return (
                <div
                  key={l.id}
                  style={{
                    background: 'var(--paper-2)',
                    border: '1px solid var(--line-soft)',
                    borderRadius: 10,
                    padding: '12px 14px',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'space-between',
                    gap: 8,
                  }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                    <b style={{ fontSize: 13.5, color: 'var(--ink)' }}>{l.name}</b>
                    <PresenceBadge userId={l.id} showFree />
                    <Badge text={capLabel} tone={capTone} />
                  </div>

                  <div style={{ display: 'flex', gap: 14, fontSize: 12, color: 'var(--muted)' }}>
                    <span>التذاكر: <b style={{ color: 'var(--ink)' }}>{l.activeTickets}</b></span>
                    <span>القضايا: <b style={{ color: 'var(--ink)' }}>{l.activeCases}</b></span>
                  </div>

                  {selectedNos.length > 0 && (
                    <button
                      className="btn soft sm"
                      type="button"
                      style={{ marginTop: 4, width: '100%' }}
                      onClick={() => {
                        setBulkLawyerId(l.id);
                        toast(`تم اختيار المستشار ${l.name} للتحويل الجماعي`);
                      }}
                    >
                      إسناد {selectedNos.length} محددة إليه
                    </button>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* ── شريط التبويبات والبحث والفلاتر ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-b" style={{ padding: '14px 18px', display: 'flex', flexDirection: 'column', gap: 12 }}>
          {/* التبويبات */}
          <div className="filter-pills" style={{ display: 'flex', gap: 8, flexWrap: 'wrap', borderBottom: '1px solid var(--line-soft)', paddingBottom: 12 }}>
            <button
              type="button"
              className={`btn sm ${activeTab === 'unassigned' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'unassigned' ? undefined : 'none' }}
              onClick={() => setActiveTab('unassigned')}
            >
              <Icon name="alert" /> بانتظار توجيه ({calculatedCounts.unassigned})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'all' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'all' ? undefined : 'none' }}
              onClick={() => setActiveTab('all')}
            >
              <Icon name="folder" /> كل التذاكر النشطة ({tickets.length})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'urgent' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'urgent' ? undefined : 'none' }}
              onClick={() => setActiveTab('urgent')}
            >
              <Icon name="sparkles" /> عاجلة ({calculatedCounts.urgent})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'audit' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'audit' ? undefined : 'none', marginInlineStart: 'auto' }}
              onClick={() => setActiveTab('audit')}
            >
              <Icon name="reply" /> سجل التحويلات السابقة ({recentTransfers.length})
            </button>
          </div>

          {/* البحث والفلاتر */}
          {activeTab !== 'audit' && (
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
                  <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>المستشار الحالي:</span>
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
              </div>
            </div>
          )}
        </div>
      </div>

      {/* ── عرض التبويبات والمحتوى ── */}
      {activeTab === 'audit' ? (
        /* ── سجل التحويلات السابقة ── */
        <div className="card">
          <div className="card-h">
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Icon name="reply" />
              <h3>سجل التحويلات وإعادة التوزيع الأخيرة</h3>
            </div>
            <span className="sub">{recentTransfers.length} عملية مسجلة</span>
          </div>

          <div className="card-b" style={{ padding: 14 }}>
            {recentTransfers.length ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                {recentTransfers.map((item) => (
                  <div
                    key={item.id}
                    style={{
                      background: 'var(--paper-2)',
                      border: '1px solid var(--line-soft)',
                      borderRadius: 9,
                      padding: '10px 14px',
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                      flexWrap: 'wrap',
                      gap: 8,
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                      <span className="mono" style={{ fontWeight: 700, fontSize: 13 }}>{item.ticketNo}</span>
                      <span style={{ fontSize: 13, color: 'var(--ink)' }}>{item.text}</span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 12, color: 'var(--muted)' }}>
                      <span>بواسطة: <b>{item.staff}</b></span>
                      <span>·</span>
                      <span className="muted">{item.date}</span>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="empty">
                <Icon name="reply" />
                <b>لا توجد عمليات تحويل مسجلة مؤخراً</b>
              </div>
            )}
          </div>
        </div>
      ) : (
        /* ── جدول التذاكر والتحويلات ── */
        <div className="card">
          <div className="card-h">
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Icon name="folder" />
              <h3>قائمة التذاكر للتحويل والتوزيع ({filteredTickets.length})</h3>
            </div>
            {selectedNos.length > 0 && (
              <Badge text={`تم تحديد ${selectedNos.length} تذكرة`} tone="b-cyan" />
            )}
          </div>

          <div className="card-b t-wrap" style={{ padding: 0 }}>
            {filteredTickets.length ? (
              <table className="tbl">
                <thead>
                  <tr>
                    <th style={{ width: 40, textAlign: 'center' }}>
                      <input
                        type="checkbox"
                        checked={selectedNos.length === filteredTickets.length && filteredTickets.length > 0}
                        onChange={toggleSelectAll}
                        title="تحديد الكل"
                      />
                    </th>
                    <th>التذكرة</th>
                    <th>العميل والموضوع</th>
                    <th>القسم والنوع</th>
                    <th>المستشار الحالي</th>
                    <th>تحويل إلى</th>
                    <th>سبب التحويل</th>
                    <th>إجراء</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredTickets.map((t) => {
                    const isSelected = selectedNos.includes(t.no);
                    const isUn = t.isUnassigned || !t.lawyerId || t.lawyer === '—';

                    return (
                      <tr
                        key={t.no}
                        style={{
                          background: isSelected ? 'var(--paper-2)' : undefined,
                        }}
                      >
                        <td style={{ textAlign: 'center' }}>
                          <input
                            type="checkbox"
                            checked={isSelected}
                            onChange={() => toggleSelectOne(t.no)}
                          />
                        </td>
                        <td>
                          <div className="mono" style={{ fontWeight: 800, fontSize: 13.5 }}>{t.no}</div>
                          <div className="muted" style={{ fontSize: 11 }}>{t.updatedAgo || t.createdAgo || 'الآن'}</div>
                        </td>
                        <td>
                          <b>{t.client}</b>
                          {t.subject && <div className="muted" style={{ fontSize: 11.5 }}>{t.subject}</div>}
                        </td>
                        <td>
                          <div style={{ fontWeight: 600 }}>{t.type}</div>
                          <div className="muted" style={{ fontSize: 11 }}>{t.dept}</div>
                        </td>
                        <td>
                          {isUn ? (
                            <Badge text="⚠️ غير مسندة" tone="b-red" />
                          ) : (
                            <b style={{ color: 'var(--ink)' }}>{t.lawyer}</b>
                          )}
                        </td>
                        <td>
                          <select
                            value={selLawyer[t.no] || lawyers[0]?.id || 0}
                            onChange={(e) =>
                              setSelLawyer((p) => ({ ...p, [t.no]: Number(e.target.value) }))
                            }
                            style={{ minWidth: 140, padding: '5px 8px', fontSize: 12.5 }}
                          >
                            {lawyers.map((l) => (
                              <option key={l.id} value={l.id}>
                                {l.name} ({l.activeTickets} تذاكر){inSessionSuffix(inSession, l.id)}
                              </option>
                            ))}
                          </select>
                          {isUn && <LawyerSuggestionHint suggestion={t.suggestion} compact />}
                        </td>
                        <td>
                          <input
                            value={rowReason[t.no] || ''}
                            onChange={(e) =>
                              setRowReason((p) => ({ ...p, [t.no]: e.target.value }))
                            }
                            placeholder="سبب التحويل (اختياري)"
                            style={{ minWidth: 130, padding: '5px 8px', fontSize: 12.5 }}
                          />
                        </td>
                        <td>
                          <button
                            className="btn soft sm"
                            onClick={() => doSingleTransfer(t.no)}
                            disabled={transferringNo === t.no}
                            type="button"
                          >
                            <Icon name="reply" /> {transferringNo === t.no ? 'جاري التحويل…' : 'تحويل'}
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            ) : (
              <div className="empty">
                <Icon name="folder" />
                <b>{tickets.length === 0 ? 'لا توجد تذاكر بعد' : 'لا توجد تذاكر تطابق خيارات العرض المحددة'}</b>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── شريط التحويل الجماعي العائم عند التحديد ── */}
      {selectedNos.length > 0 && activeTab !== 'audit' && (
        <div
          style={{
            position: 'sticky',
            bottom: 20,
            background: 'var(--card-bg, #fff)',
            border: '2px solid var(--primary)',
            borderRadius: 14,
            padding: '14px 20px',
            boxShadow: '0 8px 30px rgba(0,0,0,0.15)',
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            flexWrap: 'wrap',
            gap: 12,
            zIndex: 90,
            marginTop: 20,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <span style={{ fontWeight: 800, fontSize: 14, color: 'var(--primary)' }}>
              تم تحديد ({selectedNos.length}) تذكرة
            </span>
            <span style={{ color: 'var(--muted)', fontSize: 13 }}>تطبيق تحويل موحد لكافة التذاكر المختارة:</span>
          </div>

          <form onSubmit={doBulkTransfer} style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <span style={{ fontSize: 12.5, fontWeight: 600 }}>إلى المستشار:</span>
              <select
                value={bulkLawyerId}
                onChange={(e) => setBulkLawyerId(Number(e.target.value))}
                style={{ width: 170, padding: '6px 10px', fontSize: 13 }}
              >
                {lawyers.map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.name} ({l.activeTickets} تذاكر){inSessionSuffix(inSession, l.id)}
                  </option>
                ))}
              </select>
            </div>

            <input
              placeholder="سبب التحويل الجماعي (اختياري)..."
              value={bulkReason}
              onChange={(e) => setBulkReason(e.target.value)}
              style={{ width: 200, padding: '6px 10px', fontSize: 13 }}
            />

            <button className="btn" type="submit" disabled={bulkBusy}>
              <Icon name="reply" /> {bulkBusy ? 'جاري التحويل…' : `تنفيذ التحويل لـ ${selectedNos.length} تذكرة`}
            </button>

            <button
              className="btn ghost sm"
              type="button"
              onClick={() => setSelectedNos([])}
            >
              إلغاء التحديد
            </button>
          </form>
        </div>
      )}
    </>
  );
};

export default EmployeeTransfer;

