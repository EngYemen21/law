import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { dateISOAfter } from '@/lib/local-date';
import { useServerAction } from '@/lib/use-server-action';
import { truncateWords } from '@/lib/utils';

// مهام المحامي — متابعة المهام المسندة والذاتية وإنجازها
interface Task {
  id: number;
  title: string;
  ref: string;
  owner: string;
  due: string;
  overdue?: boolean;
  status: string;
  tone: string;
}

interface Props {
  tasks: Task[];
}


const LawyerTasks: React.FC<Props> = ({ tasks = [] }) => {
  const toast = useToast();
  const action = useServerAction();

  // نمط العرض: جدول | كانبان
  const [viewMode, setViewMode] = useState<'table' | 'kanban'>('table');

  // نافذة إضافة مهمة جديدة
  const [modalOpen, setModalOpen] = useState(false);
  const [title, setTitle] = useState('');
  const [ref, setRef] = useState('');
  const [due, setDue] = useState('');

  // تفاصيل المهمة
  const [selectedTask, setSelectedTask] = useState<Task | null>(null);

  // التصفية والبحث
  const [searchQ, setSearchQ] = useState('');
  const [filterStatus, setFilterStatus] = useState('all');

  // إحصائيات المهام
  const completedTasks = tasks.filter((t) => t.status === 'منجزة');
  const overdueTasks = tasks.filter((t) => Boolean(t.overdue));
  const activeOpenTasks = tasks.filter((t) => t.status !== 'منجزة' && !t.overdue);
  const completionRate = tasks.length ? Math.round((completedTasks.length / tasks.length) * 100) : 0;

  const stats: StatItem[] = [
    ['t-blue', 'exec', tasks.length, 'إجمالي مهامي'],
    ['t-cyan', 'clock', activeOpenTasks.length, 'مهام جارية'],
    ['t-red', 'out', overdueTasks.length, 'متأخرة الاستحقاق'],
    ['t-green', 'check', completedTasks.length, 'مهام مكتملة'],
    ['t-green', 'user', `${completionRate}%`, 'معدل إنجازي'],
  ];

  // تصفية المهام
  const filteredTasks = useMemo(() => {
    return tasks.filter((t) => {
      if (filterStatus === 'open' && (t.status === 'منجزة' || t.overdue)) return false;
      if (filterStatus === 'overdue' && !t.overdue) return false;
      if (filterStatus === 'done' && t.status !== 'منجزة') return false;

      if (searchQ.trim()) {
        const q = searchQ.trim().toLowerCase();
        const hay = `${t.title} ${t.ref} ${t.id}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }

      return true;
    });
  }, [tasks, filterStatus, searchQ]);

  const addTask = () => {
    if (!title.trim()) {
      toast('يرجى كتابة عنوان ووصف المهمة');
      return;
    }

    // قفلٌ ورسالة رفض (`useServerAction`) — كانت بلا أيّهما: نقرتان تُنشئان مهمّتين، والرفض صامت
    action.run('/lawyer/tasks', {
      data: { title: title.trim(), ref: ref.trim(), due: due.trim() },
      fallback: 'تعذّر إضافة المهمة',
      success: 'تمت إضافة المهمة بنجاح إلى جدول مهامك',
      onSuccess: () => {
        setTitle('');
        setRef('');
        setDue('');
        setModalOpen(false);
      },
    });
  };

  const completeTask = (id: number) => {
    action.run(`/lawyer/tasks/${id}/complete`, { key: id, fallback: 'تعذّر إنجاز المهمة', success: 'تم إنجاز المهمة بنجاح! أحسنت' });
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
          min-height: 360px;
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

      {/* ── الترويسة الرئيسية ── */}
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
            <h2 style={{ margin: 0 }}>مهام العمل القانوني</h2>
          </div>
          <p style={{ marginTop: 6 }}>
            جدول مهامك القانونية والإجرائية: متابعة متطلبات القضايا والتذاكر ومواعيد الاستحقاق.
          </p>
        </div>

        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
          {/* مبدل نمط العرض */}
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
              title="عرض كجدول"
            >
              <Icon name="calgrid" /> جدول
            </button>
            <button
              type="button"
              className={`btn sm ${viewMode === 'kanban' ? '' : 'soft'}`}
              style={{ border: 'none', boxShadow: viewMode === 'kanban' ? undefined : 'none' }}
              onClick={() => setViewMode('kanban')}
              title="عرض كانبان"
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
            <Icon name="calplus" /> + إضافة مهمة
          </button>
        </div>
      </div>

      {/* ── شريط مؤشرات الأداء الحية للمهام ── */}
      <StatRow items={stats} />

      {/* ── شريط البحث والتصفية ── */}
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
          {/* حقل البحث */}
          <div style={{ position: 'relative', flex: 1, minWidth: 220 }}>
            <input
              className="input"
              value={searchQ}
              onChange={(e) => setSearchQ(e.target.value)}
              placeholder="ابحث بوصف المهمة أو رقم المرجع…"
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

          {(searchQ || filterStatus !== 'all') && (
            <button
              type="button"
              className="btn soft sm"
              onClick={() => {
                setSearchQ('');
                setFilterStatus('all');
              }}
              style={{ fontSize: 12 }}
            >
              <Icon name="reply" /> مسح
            </button>
          )}
        </div>
      </div>

      {/* ── العرض 1: نمط الجدول (Table View) ── */}
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
                <h3 style={{ margin: 0 }}>قائمة المهام</h3>
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
                <b>لا توجد مهام مطابقة للفرز الحالي</b>
                <p style={{ fontSize: 12, color: 'var(--muted)' }}>
                  يمكنك إضافة مهمة عمل جديدة بالنقر على زر «+ إضافة مهمة»
                </p>
              </div>
            ) : (
              <table className="tbl" style={{ minWidth: 740 }}>
                <thead>
                  <tr style={{ background: 'var(--paper-2)' }}>
                    <th style={{ width: 45, textAlign: 'center' }}>#</th>
                    <th>المهمة</th>
                    <th style={{ width: 140 }}>المرجع</th>
                    <th style={{ width: 140 }}>الاستحقاق</th>
                    <th style={{ width: 100, textAlign: 'center' }}>الحالة</th>
                    <th style={{ width: 120, textAlign: 'center' }}>الإجراء</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredTasks.map((t) => {
                    const isDone = t.status === 'منجزة';
                    const isLate = Boolean(t.overdue);

                    return (
                      <tr
                        key={t.id}
                        style={{
                          background: isLate && !isDone ? 'rgba(239, 68, 68, 0.02)' : undefined,
                        }}
                      >
                        <td style={{ textAlign: 'center', verticalAlign: 'middle' }}>
                          <span
                            style={{
                              display: 'inline-block',
                              width: 8,
                              height: 8,
                              borderRadius: '50%',
                              background: isDone ? '#10b981' : isLate ? '#ef4444' : '#f59e0b',
                            }}
                          />
                        </td>

                        <td style={{ verticalAlign: 'middle' }}>
                          <div
                            onClick={() => setSelectedTask(t)}
                            style={{ cursor: 'pointer' }}
                            title="عرض تفاصيل المهمة"
                          >
                            <span
                              style={{
                                fontWeight: 700,
                                color: isDone ? 'var(--muted)' : 'var(--deep)',
                                textDecoration: isDone ? 'line-through' : 'none',
                                fontSize: 13.5,
                              }}
                            >
                              {truncateWords(t.title, 10)}
                            </span>
                          </div>
                        </td>

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
                              }}
                            >
                              {t.ref}
                            </span>
                          ) : (
                            <span style={{ color: 'var(--line)' }}>—</span>
                          )}
                        </td>

                        <td style={{ verticalAlign: 'middle' }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
                            <span style={{ color: isLate && !isDone ? '#b91c1c' : 'var(--muted)', fontWeight: isLate && !isDone ? 700 : 500 }}>
                              {t.due || '—'}
                            </span>
                            {isLate && !isDone && (
                              <span style={{ fontSize: 10, background: 'rgba(239, 68, 68, 0.1)', color: '#b91c1c', padding: '1px 5px', borderRadius: 4, fontWeight: 700 }}>
                                متأخرة
                              </span>
                            )}
                          </div>
                        </td>

                        <td style={{ textAlign: 'center', verticalAlign: 'middle' }}>
                          <Badge text={t.status} tone={t.tone} />
                        </td>

                        <td style={{ textAlign: 'center', verticalAlign: 'middle' }}>
                          {!isDone ? (
                            <button
                              className="btn sm"
                              type="button"
                              onClick={() => completeTask(t.id)}
                              style={{
                                background: '#10b981',
                                borderColor: '#10b981',
                                color: '#fff',
                                padding: '5px 12px',
                                fontSize: 11.5,
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 4,
                              }}
                            >
                              <Icon name="check" /> إنجاز
                            </button>
                          ) : (
                            <span style={{ fontSize: 11, color: 'var(--muted)' }}>✓ مكتملة</span>
                          )}
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
            gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 280px), 1fr))',
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
                {activeOpenTasks.length}
              </span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {activeOpenTasks.length === 0 ? (
                <div style={{ textAlign: 'center', padding: '30px 10px', color: 'var(--muted)', fontSize: 12 }}>
                  لا توجد مهام جارية حالياً
                </div>
              ) : (
                activeOpenTasks.map((t) => (
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

                    <div style={{ display: 'flex', justifyContent: 'flex-end', paddingTop: 8, borderTop: '1px solid var(--line-soft)' }}>
                      <button
                        type="button"
                        className="btn sm"
                        onClick={() => completeTask(t.id)}
                        style={{ padding: '3px 10px', fontSize: 11, background: '#10b981', color: '#fff', borderColor: '#10b981' }}
                      >
                        ✓ إنجاز
                      </button>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          {/* العمود 2: مهام متأخرة */}
          <div className="kanban-col" style={{ background: 'rgba(239, 68, 68, 0.03)', borderColor: 'rgba(239, 68, 68, 0.2)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontWeight: 800, color: '#b91c1c' }}>
                <span style={{ width: 10, height: 10, borderRadius: '50%', background: '#ef4444' }} />
                <span>متأخرة الاستحقاق</span>
              </div>
              <span style={{ fontSize: 12, background: 'rgba(239, 68, 68, 0.1)', color: '#b91c1c', padding: '2px 8px', borderRadius: 10, fontWeight: 700 }}>
                {overdueTasks.length}
              </span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {overdueTasks.length === 0 ? (
                <div style={{ textAlign: 'center', padding: '30px 10px', color: '#047857', fontSize: 12 }}>
                  ✨ جدولك منضبط! لا مهام متأخرة
                </div>
              ) : (
                overdueTasks.map((t) => (
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

                    <div style={{ display: 'flex', justifyContent: 'flex-end', paddingTop: 8, borderTop: '1px solid var(--line-soft)' }}>
                      <button
                        type="button"
                        className="btn sm"
                        onClick={() => completeTask(t.id)}
                        style={{ padding: '3px 10px', fontSize: 11, background: '#10b981', color: '#fff', borderColor: '#10b981' }}
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
                {completedTasks.length}
              </span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {completedTasks.length === 0 ? (
                <div style={{ textAlign: 'center', padding: '30px 10px', color: 'var(--muted)', fontSize: 12 }}>
                  لا توجد مهام منجزة بعد
                </div>
              ) : (
                completedTasks.slice(0, 15).map((t) => (
                  <div key={t.id} className="kanban-card" style={{ opacity: 0.85 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                      {t.ref && t.ref !== '—' ? (
                        <span className="mono" style={{ fontSize: 10.5, background: 'var(--paper-2)', padding: '2px 6px', borderRadius: 4 }}>
                          {t.ref}
                        </span>
                      ) : <span />}
                      <span style={{ fontSize: 11, color: '#047857' }}>✓ مكتملة</span>
                    </div>

                    <div style={{ fontWeight: 600, fontSize: 13, color: 'var(--muted)', textDecoration: 'line-through', marginBottom: 8, cursor: 'pointer' }} onClick={() => setSelectedTask(t)}>
                      {t.title}
                    </div>

                    <div style={{ display: 'flex', justifyContent: 'flex-end', paddingTop: 8, borderTop: '1px solid var(--line-soft)' }}>
                      <Badge text="منجزة" tone="b-green" />
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      )}

      {/* ── مودال إضافة مهمة جديدة ── */}
      <Modal
        title="إضافة مهمة عمل جديدة"
        subtitle="أدخل وصف المهمة وتاريخ الاستحقاق لتنظيم جدول أعمالك"
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        maxWidth={540}
      >
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14, padding: '4px 0' }}>
          <div className="field">
            <label style={{ fontWeight: 700, fontSize: 13, marginBottom: 4, display: 'block' }}>
              عنوان ووصف المهمة <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <input
              className="input"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="مثال: صياغة مذكرة رد، حضور جلسة استماع، إعداد لائحة…"
              style={{ width: '100%', fontSize: 13.5 }}
            />
          </div>

          <div className="field">
            <label style={{ fontWeight: 700, fontSize: 13, marginBottom: 4, display: 'block' }}>
              المرجع (تذكرة أو قضية) <span style={{ fontSize: 11, color: 'var(--muted)', fontWeight: 400 }}>(اختياري)</span>
            </label>
            <input
              className="input"
              value={ref}
              onChange={(e) => setRef(e.target.value)}
              placeholder="مثال: TK-1045 أو CS-2026-90"
              style={{ width: '100%', fontSize: 13.5 }}
            />
          </div>

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
              onClick={addTask}
              disabled={action.busy}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontWeight: 700 }}
            >
              <Icon name="check" /> حفظ المهمة
            </button>
          </div>
        </div>
      </Modal>

      {/* ── مودال تفاصيل المهمة ── */}
      {selectedTask && (
        <Modal
          title={`تفاصيل المهمة #${selectedTask.id}`}
          open={Boolean(selectedTask)}
          onClose={() => setSelectedTask(null)}
          maxWidth={480}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
            <div style={{ background: 'var(--paper-2)', padding: '14px 16px', borderRadius: 10, border: '1px solid var(--line-soft)' }}>
              <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 4 }}>وصف المهمة:</div>
              <div style={{ fontSize: 15, fontWeight: 700, color: 'var(--deep)', lineHeight: 1.6 }}>
                {selectedTask.title}
              </div>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 160px), 1fr))', gap: 12, fontSize: 13 }}>
              <div style={{ background: '#fff', border: '1px solid var(--line-soft)', padding: 10, borderRadius: 8 }}>
                <span style={{ color: 'var(--muted)', display: 'block', fontSize: 11 }}>المرجع</span>
                <b className="mono">{selectedTask.ref || '—'}</b>
              </div>

              <div style={{ background: '#fff', border: '1px solid var(--line-soft)', padding: 10, borderRadius: 8 }}>
                <span style={{ color: 'var(--muted)', display: 'block', fontSize: 11 }}>الاستحقاق</span>
                <b style={{ color: selectedTask.overdue ? '#b91c1c' : undefined }}>
                  {selectedTask.due || 'غير محدد'} {selectedTask.overdue && '⚠️ متأخرة'}
                </b>
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

              {selectedTask.status !== 'منجزة' && (
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

export default LawyerTasks;
