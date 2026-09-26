import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { dateISOAfter } from '@/lib/local-date';
import { truncateWords } from '@/lib/utils';

// مهام الإدارة — إسناد مهام حقيقية للمحامين ومتابعة مؤشرات الإنجاز
interface Task {
  id: number;
  title: string;
  ref: string;
  owner: string;
  due: string;
  overdue?: boolean;
  /** منجزة؟ من الخادم (`Task::toData`) — لا مقارنة بنصّ الحالة هنا */
  done: boolean;
  status: string;
  tone: string;
}

interface Props {
  tasks: Task[];
  lawyers: { id: number; name: string }[];
}

/** استخراج الحروف الأولى لرمز المستشار/المحامي */
const getInitials = (name: string): string => {
  const parts = name.trim().split(/\s+/);
  if (parts.length >= 2) return `${parts[0][0]}${parts[1][0]}`;
  return name.slice(0, 2);
};

const AdminTasks: React.FC<Props> = ({ tasks = [], lawyers = [] }) => {
  const toast = useToast();

  // نمط العرض: جدول منظم | بطاقات كانبان
  const [viewMode, setViewMode] = useState<'table' | 'kanban'>('table');

  // نافذة إسناد مهمة جديدة
  const [modalOpen, setModalOpen] = useState(false);
  const [assignedTo, setAssignedTo] = useState<number | ''>(lawyers[0]?.id ?? '');
  const [title, setTitle] = useState('');
  const [ref, setRef] = useState('');
  const [due, setDue] = useState('');

  // نافذة عرض تفاصيل المهمة
  const [selectedTask, setSelectedTask] = useState<Task | null>(null);

  // البحث والتصفية
  const [searchQ, setSearchQ] = useState('');
  const [filterLawyer, setFilterLawyer] = useState('all');
  const [filterStatus, setFilterStatus] = useState('all');

  // إحصائيات المهام
  const completedTasks = tasks.filter((t) => t.done);
  const overdueTasks = tasks.filter((t) => Boolean(t.overdue));
  const activeOpenTasks = tasks.filter((t) => !t.done && !t.overdue);
  const completionRate = tasks.length ? Math.round((completedTasks.length / tasks.length) * 100) : 0;

  const stats: StatItem[] = [
    ['t-blue', 'exec', tasks.length, 'إجمالي المهام'],
    ['t-cyan', 'clock', activeOpenTasks.length, 'مهام جارية'],
    ['t-red', 'out', overdueTasks.length, 'متأخرة الاستحقاق'],
    ['t-green', 'check', completedTasks.length, 'مهام مكتملة'],
    ['t-green', 'user', `${completionRate}%`, 'معدل الإنجاز العام'],
  ];

  // فلترة المهام للعرض
  const filteredTasks = useMemo(() => {
    return tasks.filter((t) => {
      if (filterLawyer !== 'all' && t.owner !== filterLawyer) {
        return false;
      }

      if (filterStatus === 'open' && (t.done || t.overdue)) {
        return false;
      }

      if (filterStatus === 'overdue' && !t.overdue) {
        return false;
      }

      if (filterStatus === 'done' && !t.done) {
        return false;
      }

      if (searchQ.trim()) {
        const q = searchQ.trim().toLowerCase();
        const hay = `${t.title} ${t.ref} ${t.owner} ${t.id}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }

      return true;
    });
  }, [tasks, filterLawyer, filterStatus, searchQ]);

  /*
   * **أعمدة كانبان من القائمة المصفّاة** — كانت تُبنى من كلّ المهام فيتجاهل منظرُ البطاقات البحثَ
   * والمرشّحات التي يطبّقها الجدول. والعمود المنجز مقصوص (أقدم المنجزات أرشيف)، فعدّاده يقول
   * «المعروض من المجموع» لا المجموع وحده فوق شريحة.
   */
  const KANBAN_DONE_CAP = 15;
  const kanbanOpen = filteredTasks.filter((t) => !t.done && !t.overdue);
  const kanbanOverdue = filteredTasks.filter((t) => Boolean(t.overdue));
  const kanbanDone = filteredTasks.filter((t) => t.done);

  // إرسال مهمة جديدة
  const submitNewTask = () => {
    if (!assignedTo) {
      toast('يرجى اختيار المحامي المسند إليه');
      return;
    }
    if (!title.trim()) {
      toast('يرجى كتابة وصف المهمة');
      return;
    }

    router.post(
      '/admin/tasks',
      { assigned_to: assignedTo, title: title.trim(), ref: ref.trim(), due: due.trim() },
      {
        preserveScroll: true,
        onSuccess: () => {
          setTitle('');
          setRef('');
          setDue('');
          setModalOpen(false);
        },
        onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر إسناد المهمة'}`, 'error'),
      }
    );
  };

  // إنجاز المهمة
  const completeTask = (id: number) => {
    router.post(
      `/admin/tasks/${id}/complete`,
      {},
      {
        preserveScroll: true,
        // نصّ النجاح من الخادم (flash) — لا إشعار ثانٍ هنا
        onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر إنجاز المهمة'}`, 'error'),
      }
    );
  };

  // إعادة إسناد المهمة
  const reassignTask = (taskId: number, newLawyerId: number) => {
    if (!newLawyerId) return;
    router.post(
      `/admin/tasks/${taskId}/reassign`,
      { assigned_to: newLawyerId },
      {
        preserveScroll: true,
        onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّرت إعادة الإسناد'}`, 'error'),
      }
    );
  };

  return (
    <>
      <style>{`
        .tasks-toolbar select, .tasks-toolbar input {
          width: auto !important;
        }
        @media (max-width: 900px) {
          .tasks-toolbar {
            flex-direction: column;
            align-items: stretch !important;
          }
          .tasks-toolbar select, .tasks-toolbar input {
            width: 100% !important;
          }
        }
        .kanban-col {
          background: var(--paper-2);
          border-radius: 12px;
          border: 1px solid var(--line-soft);
          padding: 14px;
          display: flex;
          flex-direction: column;
          gap: 12px;
          min-height: 380px;
        }
        .kanban-card {
          background: #fff;
          border-radius: 10px;
          border: 1px solid var(--line-soft);
          padding: 12px 14px;
          box-shadow: 0 1px 3px rgba(0,0,0,0.02);
          transition: transform .15s ease, box-shadow .15s ease;
        }
        .kanban-card:hover {
          transform: translateY(-2px);
          box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
      `}</style>

      {/* ── الترويسة الرئيسية والإجراءات السريعة ── */}
      <div
        className="greet"
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'flex-start',
          flexWrap: 'wrap',
          gap: 14,
          marginBottom: 16,
        }}
      >
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div
              style={{
                width: 38,
                height: 38,
                borderRadius: 10,
                background: 'rgba(14, 92, 156, 0.1)',
                color: 'var(--primary)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: 18,
              }}
            >
              <Icon name="exec" />
            </div>
            <h2 style={{ margin: 0 }}>مهام العمل والإسناد القانوني</h2>
          </div>
          <p style={{ marginTop: 6 }}>
            لوحة الإشراف والمتابعة: إسناد المهام للمحامين، تتبع المهل الزمنية، وإعادة التوزيع لضمان سرعة الإنجاز.
          </p>
        </div>

        {/* أزرار العمليات ونمط العرض */}
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
          {/* مبدل نمط العرض: جدول / كانبان */}
          <div
            style={{
              display: 'inline-flex',
              background: 'var(--paper)',
              border: '1px solid var(--line)',
              borderRadius: 10,
              padding: 3,
            }}
          >
            <button
              type="button"
              className={`btn sm ${viewMode === 'table' ? '' : 'soft'}`}
              style={{ border: 'none', boxShadow: viewMode === 'table' ? undefined : 'none' }}
              onClick={() => setViewMode('table')}
              title="عرض المهام كجدول منظم"
            >
              <Icon name="calgrid" /> جدول
            </button>
            <button
              type="button"
              className={`btn sm ${viewMode === 'kanban' ? '' : 'soft'}`}
              style={{ border: 'none', boxShadow: viewMode === 'kanban' ? undefined : 'none' }}
              onClick={() => setViewMode('kanban')}
              title="عرض المهام كلوحة كانبان مقسمة حسب الحالة"
            >
              <Icon name="folder" /> كانبان
            </button>
          </div>

          <button
            type="button"
            className="btn"
            onClick={() => setModalOpen(true)}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontWeight: 700 }}
          >
            <Icon name="calplus" /> + إسناد مهمة جديدة
          </button>
        </div>
      </div>

      {/* ── شريط مؤشرات الأداء الحية للمهام ── */}
      <StatRow items={stats} />

      {/* ── شريط البحث والتصفية الموحد ── */}
      <div
        className="card"
        style={{
          marginBottom: 16,
          borderRadius: 12,
          border: '1px solid var(--line-soft)',
          boxShadow: '0 2px 6px rgba(0,0,0,0.02)',
        }}
      >
        <div
          className="card-b tasks-toolbar"
          style={{
            padding: '12px 16px',
            display: 'flex',
            alignItems: 'center',
            gap: 12,
            flexWrap: 'wrap',
          }}
        >
          {/* حقل البحث الفوري */}
          <div style={{ position: 'relative', flex: 1, minWidth: 220 }}>
            <input
              className="input"
              value={searchQ}
              onChange={(e) => setSearchQ(e.target.value)}
              placeholder="ابحث بوصف المهمة، رقم المرجع (تذكرة/قضية)، أو اسم المحامي…"
              style={{ width: '100%', fontSize: 13, paddingRight: 32 }}
            />
            <span
              style={{
                position: 'absolute',
                right: 10,
                top: '50%',
                transform: 'translateY(-50%)',
                color: 'var(--muted)',
                fontSize: 14,
                pointerEvents: 'none',
              }}
            >
              🔍
            </span>
          </div>

          {/* تصفية المحامي المسند إليه */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>المحامي:</span>
            <select
              value={filterLawyer}
              onChange={(e) => setFilterLawyer(e.target.value)}
              style={{ width: 140, fontSize: 13 }}
            >
              <option value="all">كل المحامين</option>
              {lawyers.map((l) => (
                <option key={l.id} value={l.name}>
                  {l.name}
                </option>
              ))}
            </select>
          </div>

          {/* تصفية الحالة */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>الحالة:</span>
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
              style={{ width: 130, fontSize: 13 }}
            >
              <option value="all">كل الحالات</option>
              <option value="open">مهام جارية ({activeOpenTasks.length})</option>
              <option value="overdue">متأخرة ⚠️ ({overdueTasks.length})</option>
              <option value="done">مكتملة ({completedTasks.length})</option>
            </select>
          </div>

          {/* زر مسح الفلاتر */}
          {(searchQ || filterLawyer !== 'all' || filterStatus !== 'all') && (
            <button
              type="button"
              className="btn soft sm"
              onClick={() => {
                setSearchQ('');
                setFilterLawyer('all');
                setFilterStatus('all');
              }}
              style={{ fontSize: 12 }}
            >
              <Icon name="reply" /> مسح
            </button>
          )}
        </div>
      </div>

      {/* ── العرض 1: نمط الجدول المنظم (Table View) ── */}
      {viewMode === 'table' && (
        <div className="card" style={{ marginBottom: 24, overflow: 'hidden' }}>
          <div
            className="card-h"
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              flexWrap: 'wrap',
              gap: 10,
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Icon name="exec" />
              <div>
                <h3 style={{ margin: 0 }}>سجل المهام</h3>
                <span className="sub">
                  عرض {filteredTasks.length} من إجمالي {tasks.length} مهمة
                </span>
              </div>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12 }}>
              <span style={{ color: '#047857', fontWeight: 600 }}>🟢 {completedTasks.length} منجزة</span>
              <span>•</span>
              <span style={{ color: overdueTasks.length ? '#b91c1c' : 'var(--muted)', fontWeight: 600 }}>
                ⚠️ {overdueTasks.length} متأخرة
              </span>
            </div>
          </div>

          <div className="card-b t-wrap" style={{ padding: 0 }}>
            {filteredTasks.length === 0 ? (
              <div className="empty" style={{ padding: 48 }}>
                <Icon name="check" />
                <b>لا توجد مهام مطابقة للشروط الحالية</b>
                <p style={{ fontSize: 12, color: 'var(--muted)' }}>
                  يمكنك إسناد مهمة جديدة بالنقر على زر «+ إسناد مهمة جديدة» أعلاه
                </p>
              </div>
            ) : (
              <table className="tbl" style={{ minWidth: 840 }}>
                <thead>
                  <tr style={{ background: 'var(--paper-2)' }}>
                    <th style={{ width: 50, textAlign: 'center' }}>#</th>
                    <th>المهمة</th>
                    <th style={{ width: 140 }}>المرجع القانوني</th>
                    <th style={{ width: 160 }}>المحامي المسند إليه</th>
                    <th style={{ width: 130 }}>تاريخ الاستحقاق</th>
                    <th style={{ width: 110, textAlign: 'center' }}>الحالة</th>
                    <th style={{ width: 220, textAlign: 'center' }}>الإجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredTasks.map((t) => {
                    const isDone = t.done;
                    const isLate = Boolean(t.overdue);

                    return (
                      <tr
                        key={t.id}
                        style={{
                          background: isLate && !isDone ? 'rgba(239, 68, 68, 0.02)' : undefined,
                        }}
                      >
                        {/* معرف المهمة والمؤشر اللوني */}
                        <td style={{ textAlign: 'center', verticalAlign: 'middle' }}>
                          <span
                            style={{
                              display: 'inline-block',
                              width: 8,
                              height: 8,
                              borderRadius: '50%',
                              background: isDone ? '#10b981' : isLate ? '#ef4444' : '#f59e0b',
                            }}
                            title={isDone ? 'منجزة' : isLate ? 'متأخرة' : 'جارية'}
                          />
                        </td>

                        {/* وصف المهمة */}
                        <td style={{ verticalAlign: 'middle' }}>
                          <div
                            onClick={() => setSelectedTask(t)}
                            style={{ cursor: 'pointer' }}
                            title="انقر لعرض تفاصيل المهمة كاملة"
                          >
                            <span
                              style={{
                                fontWeight: 700,
                                color: isDone ? 'var(--muted)' : 'var(--deep)',
                                textDecoration: isDone ? 'line-through' : 'none',
                                fontSize: 13.5,
                              }}
                            >
                              {truncateWords(t.title, 9)}
                            </span>
                          </div>
                        </td>

                        {/* المرجع */}
                        <td style={{ verticalAlign: 'middle' }}>
                          {t.ref && t.ref !== '—' ? (
                            <span
                              className="mono"
                              style={{
                                fontSize: 11.5,
                                background: 'var(--paper-2)',
                                padding: '3px 8px',
                                borderRadius: 6,
                                border: '1px solid var(--line-soft)',
                                fontWeight: 600,
                              }}
                            >
                              {t.ref}
                            </span>
                          ) : (
                            <span style={{ color: 'var(--line)' }}>—</span>
                          )}
                        </td>

                        {/* المحامي المسند إليه */}
                        <td style={{ verticalAlign: 'middle' }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <div
                              style={{
                                width: 28,
                                height: 28,
                                borderRadius: '50%',
                                background: 'linear-gradient(135deg, var(--primary) 0%, #1e40af 100%)',
                                color: '#fff',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                fontSize: 11,
                                fontWeight: 800,
                                flexShrink: 0,
                              }}
                            >
                              {getInitials(t.owner)}
                            </div>
                            <span style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--deep)' }}>
                              {t.owner}
                            </span>
                          </div>
                        </td>

                        {/* الاستحقاق */}
                        <td style={{ verticalAlign: 'middle' }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
                            <span style={{ color: isLate && !isDone ? '#b91c1c' : 'var(--muted)', fontWeight: isLate && !isDone ? 700 : 500 }}>
                              {t.due || '—'}
                            </span>
                            {isLate && !isDone && (
                              <span
                                style={{
                                  fontSize: 10,
                                  background: 'rgba(239, 68, 68, 0.1)',
                                  color: '#b91c1c',
                                  padding: '1px 5px',
                                  borderRadius: 4,
                                  fontWeight: 700,
                                }}
                              >
                                متأخرة
                              </span>
                            )}
                          </div>
                        </td>

                        {/* الحالة */}
                        <td style={{ textAlign: 'center', verticalAlign: 'middle' }}>
                          <Badge text={t.status} tone={t.tone} />
                        </td>

                        {/* الإجراءات: إنجاز + إعادة إسناد */}
                        <td style={{ textAlign: 'center', verticalAlign: 'middle' }}>
                          <div style={{ display: 'flex', gap: 6, alignItems: 'center', justifyContent: 'center' }}>
                            {!isDone && (
                              <button
                                className="btn sm"
                                type="button"
                                onClick={() => completeTask(t.id)}
                                style={{
                                  background: '#10b981',
                                  borderColor: '#10b981',
                                  color: '#fff',
                                  padding: '5px 10px',
                                  fontSize: 11.5,
                                  display: 'inline-flex',
                                  alignItems: 'center',
                                  gap: 4,
                                }}
                                title="تحديد المهمة كمنجزة"
                              >
                                <Icon name="check" /> إنجاز
                              </button>
                            )}

                            <select
                              className="input"
                              style={{ width: 125, padding: '4px 6px', fontSize: 11.5 }}
                              defaultValue=""
                              onChange={(e) => {
                                const newId = Number(e.target.value);
                                if (newId) {
                                  reassignTask(t.id, newId);
                                  e.target.value = '';
                                }
                              }}
                              title="إعادة إسناد المهمة لمحامٍ آخر"
                            >
                              <option value="">تحويل إلى…</option>
                              {lawyers
                                .filter((l) => l.name !== t.owner)
                                .map((l) => (
                                  <option key={l.id} value={l.id}>
                                    {l.name}
                                  </option>
                                ))}
                            </select>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </div>
        </div>
      )}

      {/* ── العرض 2: نمط بطاقات كانبان (Kanban View) ── */}
      {viewMode === 'kanban' && (
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(290px, 1fr))',
            gap: 16,
            marginBottom: 24,
          }}
        >
          {/* العمود 1: مهام جارية */}
          <div className="kanban-col">
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontWeight: 800, color: 'var(--deep)' }}>
                <span style={{ width: 10, height: 10, borderRadius: '50%', background: '#3b82f6' }} />
                <span>مهام جارية</span>
              </div>
              <span style={{ fontSize: 12, background: 'rgba(59, 130, 246, 0.1)', color: '#1d4ed8', padding: '2px 8px', borderRadius: 10, fontWeight: 700 }}>
                {kanbanOpen.length}
              </span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {kanbanOpen.length === 0 ? (
                <div style={{ textAlign: 'center', padding: '30px 10px', color: 'var(--muted)', fontSize: 12 }}>
                  لا توجد مهام جارية حالياً
                </div>
              ) : (
                kanbanOpen.map((t) => (
                  <div key={t.id} className="kanban-card">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                      {t.ref && t.ref !== '—' ? (
                        <span className="mono" style={{ fontSize: 10.5, background: 'var(--paper-2)', padding: '2px 6px', borderRadius: 4 }}>
                          {t.ref}
                        </span>
                      ) : <span />}
                      <span style={{ fontSize: 11, color: 'var(--muted)' }}>📅 {t.due || '—'}</span>
                    </div>

                    <div style={{ fontWeight: 700, fontSize: 13, color: 'var(--deep)', marginBottom: 8, cursor: 'pointer' }} onClick={() => setSelectedTask(t)}>
                      {t.title}
                    </div>

                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingTop: 8, borderTop: '1px solid var(--line-soft)' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <div style={{ width: 22, height: 22, borderRadius: '50%', background: 'var(--primary)', color: '#fff', fontSize: 9.5, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800 }}>
                          {getInitials(t.owner)}
                        </div>
                        <span style={{ fontSize: 11.5, color: 'var(--muted)', fontWeight: 600 }}>{t.owner}</span>
                      </div>

                      <button
                        type="button"
                        className="btn sm"
                        onClick={() => completeTask(t.id)}
                        style={{ padding: '3px 8px', fontSize: 11, background: '#10b981', color: '#fff', borderColor: '#10b981' }}
                      >
                        ✓ إنجاز
                      </button>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          {/* العمود 2: مهام متأخرة الاستحقاق */}
          <div className="kanban-col" style={{ background: 'rgba(239, 68, 68, 0.03)', borderColor: 'rgba(239, 68, 68, 0.2)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontWeight: 800, color: '#b91c1c' }}>
                <span style={{ width: 10, height: 10, borderRadius: '50%', background: '#ef4444' }} />
                <span>متأخرة الاستحقاق</span>
              </div>
              <span style={{ fontSize: 12, background: 'rgba(239, 68, 68, 0.1)', color: '#b91c1c', padding: '2px 8px', borderRadius: 10, fontWeight: 700 }}>
                {kanbanOverdue.length}
              </span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {kanbanOverdue.length === 0 ? (
                <div style={{ textAlign: 'center', padding: '30px 10px', color: '#047857', fontSize: 12 }}>
                  ✨ ممتاز! لا توجد أي مهام متأخرة
                </div>
              ) : (
                kanbanOverdue.map((t) => (
                  <div key={t.id} className="kanban-card" style={{ borderRight: '3px solid #ef4444' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                      {t.ref && t.ref !== '—' ? (
                        <span className="mono" style={{ fontSize: 10.5, background: 'rgba(239, 68, 68, 0.08)', color: '#b91c1c', padding: '2px 6px', borderRadius: 4 }}>
                          {t.ref}
                        </span>
                      ) : <span />}
                      <span style={{ fontSize: 11, color: '#b91c1c', fontWeight: 700 }}>⚠️ {t.due}</span>
                    </div>

                    <div style={{ fontWeight: 700, fontSize: 13, color: 'var(--deep)', marginBottom: 8, cursor: 'pointer' }} onClick={() => setSelectedTask(t)}>
                      {t.title}
                    </div>

                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingTop: 8, borderTop: '1px solid var(--line-soft)' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <div style={{ width: 22, height: 22, borderRadius: '50%', background: '#ef4444', color: '#fff', fontSize: 9.5, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800 }}>
                          {getInitials(t.owner)}
                        </div>
                        <span style={{ fontSize: 11.5, color: '#b91c1c', fontWeight: 600 }}>{t.owner}</span>
                      </div>

                      <button
                        type="button"
                        className="btn sm"
                        onClick={() => completeTask(t.id)}
                        style={{ padding: '3px 8px', fontSize: 11, background: '#10b981', color: '#fff', borderColor: '#10b981' }}
                      >
                        ✓ إنجاز
                      </button>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          {/* العمود 3: مهام منجزة */}
          <div className="kanban-col" style={{ background: 'rgba(16, 185, 129, 0.03)', borderColor: 'rgba(16, 185, 129, 0.2)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontWeight: 800, color: '#047857' }}>
                <span style={{ width: 10, height: 10, borderRadius: '50%', background: '#10b981' }} />
                <span>مهام منجزة</span>
              </div>
              <span style={{ fontSize: 12, background: 'rgba(16, 185, 129, 0.1)', color: '#047857', padding: '2px 8px', borderRadius: 10, fontWeight: 700 }}>
                {kanbanDone.length > KANBAN_DONE_CAP ? `${KANBAN_DONE_CAP} من ${kanbanDone.length}` : kanbanDone.length}
              </span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {kanbanDone.length === 0 ? (
                <div style={{ textAlign: 'center', padding: '30px 10px', color: 'var(--muted)', fontSize: 12 }}>
                  لا توجد مهام منجزة بعد
                </div>
              ) : (
                kanbanDone.slice(0, KANBAN_DONE_CAP).map((t) => (
                  <div key={t.id} className="kanban-card" style={{ opacity: 0.85 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                      {t.ref && t.ref !== '—' ? (
                        <span className="mono" style={{ fontSize: 10.5, background: 'var(--paper-2)', padding: '2px 6px', borderRadius: 4 }}>
                          {t.ref}
                        </span>
                      ) : <span />}
                      <span style={{ fontSize: 11, color: '#047857' }}>✓ تم الإنجاز</span>
                    </div>

                    <div style={{ fontWeight: 600, fontSize: 13, color: 'var(--muted)', textDecoration: 'line-through', marginBottom: 8, cursor: 'pointer' }} onClick={() => setSelectedTask(t)}>
                      {t.title}
                    </div>

                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingTop: 8, borderTop: '1px solid var(--line-soft)' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <div style={{ width: 22, height: 22, borderRadius: '50%', background: '#10b981', color: '#fff', fontSize: 9.5, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800 }}>
                          {getInitials(t.owner)}
                        </div>
                        <span style={{ fontSize: 11.5, color: 'var(--muted)' }}>{t.owner}</span>
                      </div>
                      <Badge text="منجزة" tone="b-green" />
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      )}

      {/* ── مودال إسناد مهمة جديدة ── */}
      <Modal
        title="إسناد مهمة عمل جديدة"
        subtitle="حدد المحامي المسؤول ووصف المهمة وتاريخ الاستحقاق لتظهر فورياً في لوحته"
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        maxWidth={580}
      >
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14, padding: '4px 0' }}>
          {/* وصف المهمة */}
          <div className="field">
            <label style={{ fontWeight: 700, fontSize: 13, marginBottom: 4, display: 'block' }}>
              وصف المهمة المطلوبة <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <input
              className="input"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="مثال: صياغة مذكرة رد على الدعوى، إعداد تقرير خبير…"
              style={{ width: '100%', fontSize: 13.5 }}
            />
          </div>

          {/* المحامي المسند إليه */}
          <div className="field">
            <label style={{ fontWeight: 700, fontSize: 13, marginBottom: 4, display: 'block' }}>
              المحامي المسند إليه <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <select
              value={assignedTo}
              onChange={(e) => setAssignedTo(Number(e.target.value))}
              style={{ width: '100%', fontSize: 13.5, padding: '8px 12px' }}
            >
              {lawyers.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.name}
                </option>
              ))}
            </select>
          </div>

          {/* المرجع القانوني */}
          <div className="field">
            <label style={{ fontWeight: 700, fontSize: 13, marginBottom: 4, display: 'block' }}>
              المرجع القانوني (تذكرة أو قضية) <span style={{ fontSize: 11, color: 'var(--muted)', fontWeight: 400 }}>(اختياري)</span>
            </label>
            <input
              className="input"
              value={ref}
              onChange={(e) => setRef(e.target.value)}
              placeholder="مثال: TK-1045 أو CS-2026-90"
              style={{ width: '100%', fontSize: 13.5 }}
            />
          </div>

          {/* تاريخ الاستحقاق مع أزرار سريعة */}
          <div className="field">
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
              <label style={{ fontWeight: 700, fontSize: 13 }}>تاريخ الاستحقاق</label>
              <div style={{ display: 'flex', gap: 6 }}>
                <button
                  type="button"
                  className="btn soft sm"
                  style={{ padding: '2px 8px', fontSize: 11 }}
                  onClick={() => setDue(dateISOAfter(0))}
                >
                  اليوم
                </button>
                <button
                  type="button"
                  className="btn soft sm"
                  style={{ padding: '2px 8px', fontSize: 11 }}
                  onClick={() => setDue(dateISOAfter(1))}
                >
                  غداً
                </button>
                <button
                  type="button"
                  className="btn soft sm"
                  style={{ padding: '2px 8px', fontSize: 11 }}
                  onClick={() => setDue(dateISOAfter(3))}
                >
                  3 أيام
                </button>
                <button
                  type="button"
                  className="btn soft sm"
                  style={{ padding: '2px 8px', fontSize: 11 }}
                  onClick={() => setDue(dateISOAfter(7))}
                >
                  أسبوع
                </button>
              </div>
            </div>
            <input
              className="input"
              type="date"
              value={due}
              onChange={(e) => setDue(e.target.value)}
              style={{ width: '100%', fontSize: 13.5 }}
            />
          </div>

          {/* أزرار الإرسال والإلغاء */}
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 10, paddingTop: 12, borderTop: '1px solid var(--line-soft)' }}>
            <button
              type="button"
              className="btn soft"
              onClick={() => setModalOpen(false)}
            >
              إلغاء
            </button>
            <button
              type="button"
              className="btn"
              onClick={submitNewTask}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontWeight: 700 }}
            >
              <Icon name="check" /> حفظ وإسناد المهمة
            </button>
          </div>
        </div>
      </Modal>

      {/* ── مودال عرض تفاصيل المهمة ── */}
      {selectedTask && (
        <Modal
          title={`تفاصيل المهمة #${selectedTask.id}`}
          subtitle={`المسندة إلى ${selectedTask.owner}`}
          open={Boolean(selectedTask)}
          onClose={() => setSelectedTask(null)}
          maxWidth={500}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
            <div style={{ background: 'var(--paper-2)', padding: '14px 16px', borderRadius: 10, border: '1px solid var(--line-soft)' }}>
              <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 4 }}>وصف المهمة:</div>
              <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--deep)', lineHeight: 1.6 }}>
                {selectedTask.title}
              </div>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, fontSize: 13 }}>
              <div style={{ background: '#fff', border: '1px solid var(--line-soft)', padding: 10, borderRadius: 8 }}>
                <span style={{ color: 'var(--muted)', display: 'block', fontSize: 11 }}>المحامي المسند إليه</span>
                <b>{selectedTask.owner}</b>
              </div>

              <div style={{ background: '#fff', border: '1px solid var(--line-soft)', padding: 10, borderRadius: 8 }}>
                <span style={{ color: 'var(--muted)', display: 'block', fontSize: 11 }}>المرجع القانوني</span>
                <b className="mono">{selectedTask.ref || '—'}</b>
              </div>

              <div style={{ background: '#fff', border: '1px solid var(--line-soft)', padding: 10, borderRadius: 8 }}>
                <span style={{ color: 'var(--muted)', display: 'block', fontSize: 11 }}>تاريخ الاستحقاق</span>
                <b style={{ color: selectedTask.overdue ? '#b91c1c' : undefined }}>
                  {selectedTask.due || 'غير محدد'} {selectedTask.overdue && '⚠️ متأخرة'}
                </b>
              </div>

              <div style={{ background: '#fff', border: '1px solid var(--line-soft)', padding: 10, borderRadius: 8 }}>
                <span style={{ color: 'var(--muted)', display: 'block', fontSize: 11 }}>حالة المهمة</span>
                <Badge text={selectedTask.status} tone={selectedTask.tone} />
              </div>
            </div>

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8, paddingTop: 12, borderTop: '1px solid var(--line-soft)' }}>
              <button
                type="button"
                className="btn soft"
                onClick={() => setSelectedTask(null)}
              >
                إغلاق
              </button>

              {!selectedTask.done && (
                <button
                  type="button"
                  className="btn"
                  onClick={() => {
                    completeTask(selectedTask.id);
                    setSelectedTask(null);
                  }}
                  style={{ background: '#10b981', borderColor: '#10b981' }}
                >
                  <Icon name="check" /> تحديد كمنجزة
                </button>
              )}
            </div>
          </div>
        </Modal>
      )}
    </>
  );
};

export default AdminTasks;
