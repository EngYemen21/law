import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { ASSIST, ASSIST_REFS, lwGenerate } from '@/lib/lawyer-data';

// يطابق lwAssistant + lwGenerate في index (82).html

const LawyerAssistant: React.FC = () => {
  const toast = useToast();
  const [tab, setTab] = useState(ASSIST[0].key);
  const [type, setType] = useState(ASSIST[0].items[0]);
  const [ref, setRef] = useState(ASSIST_REFS[0] || '');
  const [ctx, setCtx] = useState('');
  const [draft, setDraft] = useState<string | null>(null);

  const active = ASSIST.find((a) => a.key === tab) || ASSIST[0];

  const switchTab = (key: string) => {
    const t = ASSIST.find((a) => a.key === key) || ASSIST[0];
    setTab(key);
    setType(t.items[0]);
  };

  // يطابق lwGenerate
  const generate = () => {
    setDraft(lwGenerate(tab, type, ref));
    toast('تم توليد المسودة — يمكنك تعديلها');
  };

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p><b>المساعد القانوني الذكي</b> — يكتب اللوائح والمذكرات، يحلّل المستندات، ويقترح الدفوع. (مخرجات نموذجية للعرض)</p>
      </div>

      <div className="tabs">
        {ASSIST.map((a) => (
          <button
            key={a.key}
            className={`tab ${a.key === tab ? 'on' : ''}`}
            onClick={() => switchTab(a.key)}
            type="button"
          >
            {a.label}
          </button>
        ))}
      </div>

      <div className="card">
        <div className="card-b" style={{ padding: 18 }}>
          <div className="picker-grid">
            <div className="field">
              <label>{active.label}</label>
              <select value={type} onChange={(e) => setType(e.target.value)}>
                {active.items.map((x) => <option key={x}>{x}</option>)}
              </select>
            </div>
            <div className="field">
              <label>التذكرة / القضية</label>
              <select value={ref} onChange={(e) => setRef(e.target.value)}>
                {ASSIST_REFS.map((x) => <option key={x}>{x}</option>)}
              </select>
            </div>
          </div>
          <div className="field">
            <label>تفاصيل الحالة (سياق)</label>
            <textarea
              value={ctx}
              onChange={(e) => setCtx(e.target.value)}
              placeholder="ألصق الوقائع أو نص العقد/الحكم هنا…"
            />
          </div>
          <button className="btn" onClick={generate} type="button">
            <Icon name="doc" /> توليد المسودة
          </button>
        </div>
      </div>

      {draft !== null && (
        <div className="card">
          <div className="card-h">
            <h3>المسودة المقترحة — قابلة للتعديل</h3>
            <div style={{ display: 'flex', gap: 7 }}>
              <button className="btn soft sm" onClick={() => toast('تم نسخ المسودة')} type="button">نسخ</button>
              <button className="btn sm" onClick={() => toast('تم اعتماد الرد بعد التعديل وحفظه في التذكرة')} type="button">
                <Icon name="check" /> اعتماد الرد
              </button>
            </div>
          </div>
          <div className="card-b" style={{ padding: 16 }}>
            <div className="ai-banner">
              <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
              <p>يمكنك تعديل نص المساعد القانوني قبل اعتماده.</p>
            </div>
            <textarea
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              style={{ width: '100%', minHeight: 240, fontFamily: 'inherit', fontSize: '13.7px', border: '1.4px solid var(--line)', borderRadius: 12, padding: 14, lineHeight: 1.9 }}
            />
          </div>
        </div>
      )}
    </>
  );
};

export default LawyerAssistant;
