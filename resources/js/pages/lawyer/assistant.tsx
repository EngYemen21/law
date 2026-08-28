import axios from 'axios';
import React, { useState } from 'react';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';

// مختبر التحليل والصياغة القانونية للمحامي والمستشار — Legal Analysis & Drafting Lab

interface QuickAction {
  id: string;
  kind: string;
  docType: string;
  title: string;
  sub: string;
  icon: string;
  tone: string;
  placeholder: string;
}

const QUICK_ACTIONS: QuickAction[] = [
  {
    id: 'reply_memo',
    kind: 'reply_memo',
    docType: 'مذكرة رد وجوابية',
    title: 'صياغة مذكرة رد',
    sub: 'إعداد مذكرة جوابية تفند ادعاءات الخصم وتستند للأنظمة السعودية',
    icon: 'reply',
    tone: 'b-blue',
    placeholder: 'ألصق هنا ادعاءات الخصم أو ملخص لائحة الدعوى المراد الرد عليها…',
  },
  {
    id: 'contract_check',
    kind: 'contract_check',
    docType: 'فحص وتدقيق عقد',
    title: 'فحص وتدقيق عقد',
    sub: 'كشف الثغرات والشروط الباطلة ومطابقة العقد مع نظام المعاملات المدنية',
    icon: 'doc',
    tone: 'b-amber',
    placeholder: 'ألصق بنود العقد أو الاتفاقية المراد فحصها وتدقيقها قانونياً…',
  },
  {
    id: 'strengths_weaknesses',
    kind: 'strengths_weaknesses',
    docType: 'تحليل نقاط القوة والضعف',
    title: 'نقاط القوة والضعف',
    sub: 'تحليل الموقف القضائي ومطابقة الأدلة واستخراج خطة الترافع',
    icon: 'scale',
    tone: 'b-green',
    placeholder: 'ألصق وقائع النزاع وقائمة الأدلة والمستندات المتاحة للموكل…',
  },
  {
    id: 'qualification',
    kind: 'qualification',
    docType: 'تكييف النزاع وتحديد الاختصاص',
    title: 'تكييف النزاع القانوني',
    sub: 'تحديد التكييف الفقهي والنظامي والمحكمة المختصة والمواد الحاكمة',
    icon: 'compass',
    tone: 'b-cyan',
    placeholder: 'اشرح طبيعة النزاع والعلاقة بين الأطراف لتحديد التكييف والمحكمة المختصة…',
  },
];

interface AssistTab {
  key: string;
  label: string;
  icon: string;
  items: string[];
}

const ASSIST_TABS: AssistTab[] = [
  {
    key: 'lawahe',
    label: 'اللوائح وصحائف الدعوى',
    icon: 'scale',
    items: ['صحيفة دعوى (ناجز)', 'لائحة جوابية', 'لائحة اعتراضية', 'لائحة استئناف', 'التماس إعادة نظر'],
  },
  {
    key: 'mems',
    label: 'المذكرات القضائية',
    icon: 'doc',
    items: ['مذكرة رد ودفاع', 'مذكرة تعقيب', 'مذكرة إدخال وضمان', 'مذكرة ختامية', 'مذكرة دفوع شكلية'],
  },
  {
    key: 'analyze',
    label: 'الفحص وتدقيق العقود',
    icon: 'folder',
    items: ['تدقيق عقد تجاري', 'فحص عقد مقاولة', 'فحص اتفاقية شراكة', 'تدقيق صك حكم قضائي', 'تحليل بينات ومستندات'],
  },
  {
    key: 'defense',
    label: 'الاستراتيجية والتكييف',
    icon: 'sparkles',
    items: ['التكييف النظامي للنزاع', 'نقاط القوة والضعف', 'اقتراح الدفوع الجوهرية', 'حساب المهل والتقادم'],
  },
];

interface Props {
  refs: string[];
}

const LawyerAssistant: React.FC<Props> = ({ refs }) => {
  const toast = useToast();
  const [selectedQuick, setSelectedQuick] = useState<string | null>(null);
  const [tab, setTab] = useState(ASSIST_TABS[0].key);
  const [type, setType] = useState(ASSIST_TABS[0].items[0]);
  const [ref, setRef] = useState(refs[0] || '');
  const [ctx, setCtx] = useState('');
  const [draft, setDraft] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const activeTab = ASSIST_TABS.find((a) => a.key === tab) || ASSIST_TABS[0];

  // كل إجراء سريع ينتمي لتبويب: النقر ينقل المستخدم إليه ويثبّت نوع الوثيقة الخاص به
  const QUICK_TAB: Record<string, string> = {
    reply_memo: 'mems',
    contract_check: 'analyze',
    strengths_weaknesses: 'defense',
    qualification: 'defense',
  };

  const handleQuickAction = (qa: QuickAction) => {
    setSelectedQuick(qa.id);
    setTab(QUICK_TAB[qa.kind] ?? tab);
    setType(qa.docType); // نوع الوثيقة الخاص بالإجراء — كان يُهمَل ويُرسل نوع التبويب
    toast(`تم اختيار: ${qa.title} — أدخل التفاصيل واضغط توليد`);
  };

  const switchTab = (key: string) => {
    const t = ASSIST_TABS.find((a) => a.key === key) || ASSIST_TABS[0];
    setTab(key);
    setType(t.items[0]);
    setSelectedQuick(null);
  };

  const generate = async () => {
    if (!ctx.trim() && !ref) {
      toast('يرجى كتابة سياق الحالة أو اختيار مرجع تذكرة/قضية');

      return;
    }

    setBusy(true);
    const kindToSend = selectedQuick || tab;

    try {
      // الصفحة تُعرض من لوحتي المحامي والإدارة — كل لوحة تنادي مسارها (قرار 2026-08-28)
      const { data } = await axios.post(`${window.location.pathname.startsWith('/admin') ? '/admin' : '/lawyer'}/assistant/generate`, {
        kind: kindToSend,
        docType: type,
        ref,
        context: ctx,
      });
      setDraft(data.draft);
      toast('✨ تم توليد الصياغة القانونية بنجاح — يمكنك مراجعتها وتعديلها');
    } catch {
      toast('تعذّر توليد المسودة حالياً، يرجى المحاولة لاحقاً');
    } finally {
      setBusy(false);
    }
  };

  const downloadDraft = () => {
    if (!draft) {
return;
}

    const blob = new Blob([draft], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `مسودة_${type.replace(/\s+/g, '_')}_${Date.now()}.txt`;
    a.click();
    URL.revokeObjectURL(url);
    toast('تم تنزيل المسودة');
  };

  return (
    <>
      {/* الترويسة الرئيسية للمختبر */}
      <div className="hero" style={{ marginBottom: 18 }}>
        <h2>مختبر التحليل والصياغة القانونية ⚖️</h2>
        <p>
          محرك الذكاء الاصطناعي المتخصص في الأنظمة والقضاء السعودي (المعاملات المدنية، الإثبات، الشركات، والمحاكم التجارية).
          اختر الإجراء السريع أو خصص اللائحة المطلوبة.
        </p>
      </div>

      {/* بطاقات الإجراءات السريعة الأربعة */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 12, marginBottom: 18 }}>
        {QUICK_ACTIONS.map((qa) => {
          const isSelected = selectedQuick === qa.id;

          return (
            <div
              key={qa.id}
              onClick={() => handleQuickAction(qa)}
              className={`card click ${isSelected ? 'active' : ''}`}
              style={{
                padding: '16px 18px',
                border: isSelected ? '2px solid var(--primary)' : '1px solid var(--line)',
                background: isSelected ? 'rgba(14,92,156,.06)' : 'var(--paper)',
                transition: '.15s',
                cursor: 'pointer',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
                <div style={{ width: 34, height: 34, borderRadius: 10, background: 'var(--primary)', color: '#fff', display: 'grid', placeItems: 'center' }}>
                  <Icon name={qa.icon} />
                </div>
                <b style={{ fontSize: 14.5, color: 'var(--deep)' }}>{qa.title}</b>
              </div>
              <p style={{ margin: 0, fontSize: 12.5, color: 'var(--muted)', lineHeight: 1.6 }}>{qa.sub}</p>
            </div>
          );
        })}
      </div>

      {/* تبويبات التخصيص التفصيلية */}
      <div className="tabs" style={{ marginBottom: 14 }}>
        {ASSIST_TABS.map((a) => (
          <button
            key={a.key}
            className={`tab ${a.key === tab && !selectedQuick ? 'on' : ''}`}
            onClick={() => switchTab(a.key)}
            type="button"
          >
            <Icon name={a.icon} /> {a.label}
          </button>
        ))}
      </div>

      {/* استمارة الإدخال والتوليد */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-b" style={{ padding: 20 }}>
          <div className="picker-grid" style={{ marginBottom: 14 }}>
            <div className="field">
              <label>نوع الصياغة / الوثيقة</label>
              <select value={type} onChange={(e) => {
 setType(e.target.value); setSelectedQuick(null); 
}}>
                {(activeTab.items.includes(type) ? activeTab.items : [type, ...activeTab.items]).map((x) => (
                  <option key={x} value={x}>{x}</option>
                ))}
              </select>
            </div>
            <div className="field">
              <label>مرجع التذكرة أو القضية (اختياري لملء السياق آلياً)</label>
              <select value={ref} onChange={(e) => setRef(e.target.value)}>
                <option value="">— إدخال يدوي حر بدون مرجع —</option>
                {refs.map((x) => (
                  <option key={x} value={x}>{x}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="field">
            <label>تفاصيل الحالة والوقائع أو نصوص المواد والعقد المراد تحليله</label>
            <textarea
              value={ctx}
              onChange={(e) => setCtx(e.target.value)}
              placeholder={selectedQuick ? QUICK_ACTIONS.find((q) => q.id === selectedQuick)?.placeholder : 'ألصق الوقائع، نصوص بنود العقد، دفوع الخصم، أو تفاصيل النزاع هنا…'}
              style={{ minHeight: 130, lineHeight: 1.8, fontSize: 13.5 }}
            />
          </div>

          <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 14 }}>
            <button className="btn" onClick={generate} type="button" disabled={busy} style={{ minWidth: 160 }}>
              <Icon name="sparkles" /> {busy ? 'جارٍ التحليل والصياغة…' : 'توليد الصياغة القانونية'}
            </button>
            {selectedQuick && (
              <button className="btn soft sm" onClick={() => setSelectedQuick(null)} type="button">
                إلغاء التحديد السريع
              </button>
            )}
          </div>
        </div>
      </div>

      {/* قسم عرض المسودة والنتائج */}
      {draft !== null && (
        <div className="card">
          <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10 }}>
            <div>
              <h3 style={{ margin: 0 }}>المسودة والتحليل القانوني — {type}</h3>
              <span className="crumb" style={{ fontSize: 11.5, color: 'var(--muted)' }}>مستندة للأنظمة والقضاء السعودي · قابلة للمراجعة والتحرير</span>
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <button
                className="btn soft sm"
                onClick={() => {
                  navigator.clipboard?.writeText(draft);
                  toast('تم نسخ المسودة كاملة');
                }}
                type="button"
              >
                <Icon name="check" /> نسخ المسودة
              </button>
              <button className="btn soft sm" onClick={downloadDraft} type="button">
                <Icon name="upload" /> تنزيل TXT
              </button>
              <button className="btn soft sm" onClick={() => setDraft(null)} type="button">
                إغلاق
              </button>
            </div>
          </div>
          <div className="card-b" style={{ padding: 18 }}>
            <textarea
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              style={{
                width: '100%',
                minHeight: 340,
                fontFamily: 'inherit',
                fontSize: '14px',
                border: '1.5px solid var(--line)',
                borderRadius: 12,
                padding: 16,
                lineHeight: 2.1,
                background: '#FAFCFE',
                color: 'var(--ink)',
              }}
            />
          </div>
        </div>
      )}
    </>
  );
};

export default LawyerAssistant;
