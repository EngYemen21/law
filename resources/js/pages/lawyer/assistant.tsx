import axios from 'axios';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

// المساعد القانوني الذكي — يولّد المسودات عبر الذكاء الاصطناعي الحقيقي (LegalAiService)

interface AssistTab { key: string; label: string; items: string[]; }
const ASSIST: AssistTab[] = [
  { key: 'lawahe', label: 'كتابة اللوائح', items: ['لائحة دعوى', 'لائحة جوابية', 'لائحة اعتراض', 'لائحة استئناف', 'التماس إعادة نظر'] },
  { key: 'mems', label: 'كتابة المذكرات', items: ['مذكرة دفاع', 'مذكرة رد', 'مذكرة تعقيب', 'مذكرة قانونية'] },
  { key: 'analyze', label: 'التحليل القانوني', items: ['تحليل العقود', 'تحليل الأحكام', 'تحليل الأدلة', 'تحليل المستندات'] },
  { key: 'defense', label: 'اقتراح الدفوع', items: ['استخراج الوقائع', 'استخراج الطلبات', 'اقتراح الدفوع القانونية'] },
];

interface Props { refs: string[]; }

const LawyerAssistant: React.FC<Props> = ({ refs }) => {
  const toast = useToast();
  const [tab, setTab] = useState(ASSIST[0].key);
  const [type, setType] = useState(ASSIST[0].items[0]);
  const [ref, setRef] = useState(refs[0] || '');
  const [ctx, setCtx] = useState('');
  const [draft, setDraft] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const active = ASSIST.find((a) => a.key === tab) || ASSIST[0];

  const switchTab = (key: string) => {
    const t = ASSIST.find((a) => a.key === key) || ASSIST[0];
    setTab(key);
    setType(t.items[0]);
  };

  const generate = async () => {
    setBusy(true);
    try {
      const { data } = await axios.post('/lawyer/assistant/generate', { kind: tab, docType: type, ref, context: ctx });
      setDraft(data.draft);
      toast('تم توليد المسودة — يمكنك تعديلها');
    } catch {
      toast('تعذّر توليد المسودة، حاول مجدداً');
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p><b>المساعد القانوني الذكي</b> — يكتب اللوائح والمذكرات، يحلّل المستندات، ويقترح الدفوع بالذكاء الاصطناعي. راجِع المخرجات قبل الاعتماد.</p>
      </div>

      <div className="tabs">
        {ASSIST.map((a) => (
          <button key={a.key} className={`tab ${a.key === tab ? 'on' : ''}`} onClick={() => switchTab(a.key)} type="button">
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
                {refs.length ? refs.map((x) => <option key={x}>{x}</option>) : <option value="">— لا مراجع —</option>}
              </select>
            </div>
          </div>
          <div className="field">
            <label>تفاصيل الحالة (سياق)</label>
            <textarea value={ctx} onChange={(e) => setCtx(e.target.value)} placeholder="ألصق الوقائع أو نص العقد/الحكم هنا…" />
          </div>
          <button className="btn" onClick={generate} type="button" disabled={busy}>
            <Icon name="doc" /> {busy ? 'جارٍ التوليد…' : 'توليد المسودة'}
          </button>
        </div>
      </div>

      {draft !== null && (
        <div className="card">
          <div className="card-h">
            <h3>المسودة المقترحة — قابلة للتعديل</h3>
            <div style={{ display: 'flex', gap: 7 }}>
              <button className="btn soft sm" onClick={() => { navigator.clipboard?.writeText(draft); toast('تم نسخ المسودة'); }} type="button">نسخ</button>
            </div>
          </div>
          <div className="card-b" style={{ padding: 16 }}>
            <div className="ai-banner">
              <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
              <p>يمكنك تعديل نص المساعد القانوني قبل استخدامه.</p>
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
