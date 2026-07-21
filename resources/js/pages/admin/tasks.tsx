import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';

// مهام الإدارة — إسناد مهام حقيقية للمحامين (موديل Task)

interface Task { id: number; title: string; ref: string; owner: string; due: string; status: string; tone: string; }
interface Props { tasks: Task[]; lawyers: { id: number; name: string }[]; }

const AdminTasks: React.FC<Props> = ({ tasks, lawyers }) => {
  const toast = useToast();
  const [assignedTo, setAssignedTo] = useState<number | ''>(lawyers[0]?.id ?? '');
  const [title, setTitle] = useState('');
  const [ref, setRef] = useState('');
  const [due, setDue] = useState('');

  const openCount = tasks.filter((t) => t.status !== 'منجزة').length;

  const add = () => {
    if (!assignedTo || !title.trim()) { toast('اختر المحامي واكتب وصف المهمة'); return; }
    router.post('/admin/tasks', { assigned_to: assignedTo, title: title.trim(), ref: ref.trim(), due: due.trim() }, {
      preserveScroll: true,
      onSuccess: () => { setTitle(''); setRef(''); setDue(''); toast('تم إسناد المهمة للمحامي'); },
    });
  };

  return (
    <>
      <div className="greet">
        <h2>مهام العمل</h2>
        <p>إسناد المهام للمحامين ومتابعتها — تُحفظ فعلياً وتظهر في لوحة المحامي.</p>
      </div>
      <div className="card">
        <div className="card-h"><h3>إسناد مهمة</h3></div>
        <div className="card-b" style={{ padding: 18 }}>
          <div className="picker-grid">
            <div className="field"><label>المهمة</label><input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="وصف المهمة" /></div>
            <div className="field"><label>المرجع</label><input value={ref} onChange={(e) => setRef(e.target.value)} placeholder="رقم التذكرة/القضية" /></div>
          </div>
          <div className="picker-grid">
            <div className="field">
              <label>المحامي</label>
              <select value={assignedTo} onChange={(e) => setAssignedTo(Number(e.target.value))}>
                {lawyers.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
              </select>
            </div>
            <div className="field"><label>الاستحقاق</label><input className="input" type="date" value={due} onChange={(e) => setDue(e.target.value)} /></div>
          </div>
          <button className="btn" onClick={add} type="button"><Icon name="exec" /> إسناد المهمة</button>
        </div>
      </div>
      <div className="card">
        <div className="card-h"><h3>المهام</h3><span className="sub">{openCount} مفتوحة من {tasks.length}</span></div>
        <div className="card-b t-wrap">
          {tasks.length ? (
            <table className="tbl">
              <thead>
                <tr><th>المهمة</th><th>المرجع</th><th>المحامي</th><th>الاستحقاق</th><th>الحالة</th></tr>
              </thead>
              <tbody>
                {tasks.map((t) => (
                  <tr key={t.id}>
                    <td>{t.title}</td>
                    <td className="mono">{t.ref}</td>
                    <td className="muted">{t.owner}</td>
                    <td className="muted">{t.due}</td>
                    <td><Badge text={t.status} tone={t.tone} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="empty"><Icon name="check" /><b>لا مهام بعد — أسنِد مهمة لمحامٍ</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default AdminTasks;
