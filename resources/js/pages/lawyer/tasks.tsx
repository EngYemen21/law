import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';

// مهام المحامي — بيانات حقيقية من الخادم (جدول tasks)؛ الإضافة والإنجاز تُحفظ فعلاً

interface Task { id: number; title: string; ref: string; owner: string; due: string; status: string; tone: string; }
interface Props { tasks: Task[]; }

const LawyerTasks: React.FC<Props> = ({ tasks }) => {
  const toast = useToast();
  const [title, setTitle] = useState('');
  const [ref, setRef] = useState('');
  const [due, setDue] = useState('');

  const open = tasks.filter((t) => t.status !== 'منجزة').length;

  const add = () => {
    if (!title.trim()) { toast('اكتب عنوان المهمة'); return; }
    router.post('/lawyer/tasks', { title: title.trim(), ref: ref.trim(), due: due.trim() }, {
      preserveScroll: true,
      onSuccess: () => { setTitle(''); setRef(''); setDue(''); toast('تمت إضافة المهمة'); },
    });
  };

  const done = (id: number) => {
    router.post(`/lawyer/tasks/${id}/complete`, {}, { preserveScroll: true, onSuccess: () => toast('تم إنجاز المهمة') });
  };

  return (
    <>
      <div className="card">
        <div className="card-h"><h3>إضافة مهمة</h3></div>
        <div className="card-b" style={{ padding: 16 }}>
          <div className="picker-grid">
            <div className="field">
              <label>عنوان المهمة</label>
              <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="مثال: صياغة خطاب مطالبة" />
            </div>
            <div className="field">
              <label>المرجع (تذكرة/قضية)</label>
              <input value={ref} onChange={(e) => setRef(e.target.value)} placeholder="اختياري" />
            </div>
            <div className="field">
              <label>الاستحقاق</label>
              <input className="input" type="date" value={due} onChange={(e) => setDue(e.target.value)} />
            </div>
          </div>
          <button className="btn" onClick={add} type="button"><Icon name="check" /> إضافة</button>
        </div>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>المهام</h3>
          <span className="sub">{open} مفتوحة من {tasks.length}</span>
        </div>
        <div className="card-b t-wrap">
          {tasks.length ? (
            <table className="tbl">
              <thead>
                <tr>
                  <th>المهمة</th>
                  <th>المرجع</th>
                  <th>الاستحقاق</th>
                  <th>الحالة</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {tasks.map((t) => (
                  <tr key={t.id}>
                    <td>{t.title}</td>
                    <td className="mono">{t.ref}</td>
                    <td className="muted">{t.due}</td>
                    <td><Badge text={t.status} tone={t.tone} /></td>
                    <td>
                      {t.status !== 'منجزة' && (
                        <button className="btn soft sm" onClick={() => done(t.id)} type="button">
                          <Icon name="check" /> إنجاز
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="empty"><Icon name="check" /><b>لا مهام بعد — أضف مهمتك الأولى</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default LawyerTasks;
