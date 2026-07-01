import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { type LawyerTask, TASKS } from '@/lib/lawyer-data';

// يطابق lwTasks + taskDone في index (82).html

const LawyerTasks: React.FC = () => {
  const toast = useToast();
  const [tasks, setTasks] = useState<LawyerTask[]>(() => TASKS.map((t) => ({ ...t })));

  const open = tasks.filter((t) => t.status !== 'منجزة').length;

  // يطابق taskDone
  const done = (i: number) => {
    setTasks((p) => p.map((t, idx) => idx === i ? { ...t, status: 'منجزة', tone: 'b-green', due: 'مكتملة' } : t));
    toast('تم إنجاز المهمة');
  };

  return (
    <div className="card">
      <div className="card-h">
        <h3>المهام</h3>
        <span className="sub">{open} مفتوحة من {tasks.length}</span>
      </div>
      <div className="card-b t-wrap">
        <table className="tbl">
          <thead>
            <tr>
              <th>المهمة</th>
              <th>المرجع</th>
              <th>المسؤول</th>
              <th>الاستحقاق</th>
              <th>الحالة</th>
              <th></th>
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
                <td>
                  {t.status !== 'منجزة' && (
                    <button className="btn soft sm" onClick={() => done(i)} type="button">
                      <Icon name="check" /> إنجاز
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default LawyerTasks;
