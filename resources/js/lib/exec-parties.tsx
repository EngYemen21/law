import React, { useState } from 'react';
import CellRow from '@/components/babylon/CellRow';
import { execMoney } from '@/lib/exec-flow';
import type { ExecReq } from '@/lib/exec-flow';
import Icon from '@/lib/icons';
import { useServerAction } from '@/lib/use-server-action';

/** أقلّ طول لسبب تصحيح اسمٍ قائم — نظير `SetExecutionDefendant::REASON_MIN`. */
const REASON_MIN = 10;

/**
 * **بيانات السند والأطراف** — ومعها تحديد المنفَّذ ضده أو تصحيحه (قرار المالك 2026-10-02).
 * الزرّ يتبع علَم الخادم `canEditParties` (المحامي المسنَد أو الإدارة على ملفٍّ مفتوح)، والحرّاس في
 * `SetExecutionDefendant`: ملءُ الفارغ بلا سبب، واستبدالُ اسمٍ قائم بسببٍ مكتوب.
 */
export const ExecPartiesCard: React.FC<{ r: ExecReq; staff: boolean }> = ({ r, staff }) => {
  const action = useServerAction();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState(r.defendant);
  const [reason, setReason] = useState('');

  const current = r.defendant.trim();
  const missing = current === '';
  const nameOk = name.trim().length >= 2 && name.trim() !== current;
  const reasonOk = missing || reason.trim().length >= REASON_MIN;

  const cancel = () => {
    setOpen(false);
    setName(r.defendant);
    setReason('');
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    void action.run(`/exec-flow/${encodeURIComponent(r.id)}/action`, {
      data: { action: 'setDefendant', defendant: name.trim(), reason: reason.trim() },
      success: missing ? 'حُدِّد المنفَّذ ضده' : 'صُحّح المنفَّذ ضده',
      fallback: 'تعذّر حفظ المنفَّذ ضده',
      onSuccess: () => {
        setOpen(false);
        setReason('');
      },
    });
  };

  return (
    <div className="card" style={{ marginBottom: 14 }}>
      <div className="card-h"><h3>بيانات السند والأطراف</h3></div>
      <div className="card-b">
        <CellRow cells={[['نوع السند', r.sanad || '—'], ['قيمة المطالبة', execMoney(r.amount) + ' ريال']]} />
        <CellRow cells={[['طالب التنفيذ', r.client], ['المنفَّذ ضده', current || '—']]} />
        {staff && r.lawyer && <CellRow cells={[['محامي التنفيذ', r.lawyer], ['حالة القرار', r.decision || 'قيد الدراسة']]} />}
        {r.execNo && <CellRow cells={[['رقم ملف التنفيذ', r.execNo], ['المرحلة', r.stageLabel]]} />}

        {staff && r.canEditParties && !open && (
          <button className="btn soft sm" type="button" style={{ marginTop: 10 }} onClick={() => setOpen(true)}>
            <Icon name="user" /> {missing ? 'تحديد المنفَّذ ضده' : 'تصحيح المنفَّذ ضده'}
          </button>
        )}

        {staff && r.canEditParties && open && (
          <form onSubmit={submit} style={{ marginTop: 10 }}>
            <div className="field">
              <label htmlFor="exec-defendant">المنفَّذ ضده</label>
              <input id="exec-defendant" className="input" maxLength={190} value={name} onChange={(e) => setName(e.target.value)} placeholder="اسم الفرد أو الجهة كما في السند" />
            </div>
            {!missing && (
              <div className="field">
                <label htmlFor="exec-defendant-reason">سبب التصحيح</label>
                <input id="exec-defendant-reason" className="input" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="مثال: الاسم كما ورد في منطوق الحكم" />
              </div>
            )}
            <div style={{ display: 'flex', gap: 6 }}>
              <button className="btn sm" type="submit" disabled={action.busy || !nameOk || !reasonOk}>حفظ</button>
              <button className="btn soft sm" type="button" onClick={cancel}>تراجع</button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
};
