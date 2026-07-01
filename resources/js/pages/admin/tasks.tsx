import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { type Task, TASKS, LAWYERS } from '@/lib/admin-data';

// يطابق adTasksMgr + addTask في index (82).html

const AdminTasks: React.FC = () => {
  const toast = useToast();
  const [tasks, setTasks] = useState<Task[]>(() => TASKS.map((t) => ({ ...t })));
  const [title, setTitle] = useState('');
  const [ref, setRef] = useState('');
  const [owner, setOwner] = useState(LAWYERS[0].name);
  const [due, setDue] = useState('');

  const add = () => {
    if (!title.trim()) { toast('أدخل وصف المهمة'); return; }
    setTasks((p) => [{ title: title.trim(), ref: ref || '—', owner, due: due || '—', status: 'مفتوحة', tone: 'b-amber' }, ...p]);
    setTitle(''); setRef(''); setDue('');
    toast('تم إسناد المهمة للمحامي');
  };

  const openCount = tasks.filter((t) => t.status !== 'منجزة').length;

  return (
    <>
      <div className="greet">
        <h2>مهام العمل</h2>
        <p>إسناد المهام للمحامين ومتابعتها.</p>
      </div>
      <div className="card">
        <div className="card-h"><h3>إسناد مهمة</h3></div>
        <div className="card-b" style={{ padding: 18 }}>
          <div className="picker-grid">
            <div className="field">
              <label>المهمة</label>
              <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="وصف المهمة" />
            </div>
            <div className="field">
              <label>المرجع</label>
              <input className="input" value={ref} onChange={(e) => setRef(e.target.value)} placeholder="رقم التذكرة/القضية" />
            </div>
          </div>
          <div className="picker-grid">
            <div className="field">
              <label>المحامي</label>
              <select value={owner} onChange={(e) => setOwner(e.target.value)}>
                {LAWYERS.map((l) => <option key={l.name}>{l.name}</option>)}
              </select>
            </div>
            <div className="field">
              <label>الاستحقاق</label>
              <input className="input" value={due} onChange={(e) => setDue(e.target.value)} placeholder="مثال: 05 يوليو" />
            </div>
          </div>
          <button className="btn" onClick={add} type="button"><Icon name="exec" /> إسناد المهمة</button>
        </div>
      </div>
      <div className="card">
        <div className="card-h"><h3>المهام</h3><span className="sub">{openCount} مفتوحة</span></div>
        <div className="card-b t-wrap">
          <table className="tbl">
            <thead>
              <tr>
                <th>المهمة</th>
                <th>المرجع</th>
                <th>المحامي</th>
                <th>الاستحقاق</th>
                <th>الحالة</th>
              </tr>
            </thead>
            <tbody>
              {tasks.map((t, i) => (
                <tr key={i}>
                  <td>{t.title}</td>
                  <td className="mono">{t.ref}</td>
                  <td className="muted">{t.owner}</td>
                  <td className="muted">{t.due}</td>
                  <td><Badge text={t.status} tone={t.tone} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
};

export default AdminTasks;
