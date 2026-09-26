import axios from 'axios';
import React, { useEffect, useState } from 'react';
import Icon from '@/lib/icons';
import LawyerSuggestionHint, { type LawyerSuggestionData } from '@/components/babylon/LawyerSuggestionHint';
import Modal from '@/components/babylon/Modal';
import { fetchTicketRequirements } from '@/components/babylon/TicketRequirementsCard';
import type { RequirementItem } from '@/components/babylon/TicketRequirementsCard';
import { useToast } from '@/components/babylon/Toast';

// مودالا «تحويل التذكرة» و«طلب النواقص» — نسخة واحدة عاملة تُصيب المسارات الحقيقية،
// تحلّ محلّ النسخة المكرّرة في محادثة الموظف والنسخة الديكورية القديمة في قائمة التذاكر.
// أقسام التحويل من كتالوج الخادم (الفعّال) — الخادم يرفض أيّ قسمٍ ليس فيه.

export interface LawyerOption { id: number; name: string }
export type TicketOpsKind = 'transfer' | 'reqdocs' | null;

interface Props {
  kind: TicketOpsKind;
  ticketNo: string;
  dept?: string;
  lawyerId?: number | null;
  lawyers: LawyerOption[];
  /** اقتراح النظام لتذكرةٍ غير مسنَدة (مختصّ/غير مختصّ) — يملأ الاختيار المبدئيّ ولا يُسنِد */
  suggestion?: LawyerSuggestionData | null;
  departments: string[]; // أسماء الأقسام الفعّالة من الكتالوج
  onClose: () => void;
  onDone?: () => void; // إعادة تحميل/تحديث بعد نجاح فعلي
}

/** القسم المبدئيّ: قسم التذكرة إن كان في الكتالوج، وإلّا أوّل قسم. */
const initialDept = (dept: string | undefined, departments: string[]) =>
  (dept && departments.includes(dept) ? dept : departments[0]) ?? '';

const TicketOpsModals: React.FC<Props> = ({ kind, ticketNo, dept, lawyerId, lawyers, suggestion, departments, onClose, onDone }) => {
  const toast = useToast();

  // ── تحويل التذكرة ──
  const [trDept, setTrDept] = useState(initialDept(dept, departments));
  // المبدئيّ: المحامي الحاليّ، وإلّا اقتراح النظام — والتأكيد بيد الموظّف
  const initialLawyer = lawyerId ?? suggestion?.lawyerId ?? null;
  const [trLawyerId, setTrLawyerId] = useState<string>(initialLawyer ? String(initialLawyer) : '');
  const [trReason, setTrReason] = useState('');
  const [trBusy, setTrBusy] = useState(false);

  // ── طلب النواقص ──
  const [reqChosen, setReqChosen] = useState<Record<string, boolean>>({});
  // الخيارات = نواقص قائمة قسم التذكرة (ما لم يُستوفَ بعد) — لا قائمة ثابتة، ولا يُعرض ما ثبت إرفاقه
  const [reqOptions, setReqOptions] = useState<RequirementItem[]>([]);
  const [reqExtra, setReqExtra] = useState('');
  const [reqBusy, setReqBusy] = useState(false);

  // إعادة الضبط عند فتح مودال لتذكرة أخرى (القائمة تفتح تذاكر مختلفة بنفس المكوّن)
  useEffect(() => {
    if (kind === 'transfer') {
      setTrDept(initialDept(dept, departments));
      setTrLawyerId(initialLawyer ? String(initialLawyer) : '');
      setTrReason('');
      setTrBusy(false);
    }
    if (kind === 'reqdocs') {
      setReqChosen({});
      setReqExtra('');
      setReqBusy(false);
      setReqOptions([]);
      fetchTicketRequirements('/employee', ticketNo)
        .then((d) => setReqOptions(d.items.filter((i) => !i.satisfied)))
        .catch(() => setReqOptions([]));
    }
  }, [kind, ticketNo, dept, initialLawyer, departments]);

  const submitTransfer = () => {
    if (!trLawyerId) { toast('يرجى اختيار المستشار'); return; }
    setTrBusy(true);
    axios.post(`/employee/transfer/${encodeURIComponent(ticketNo)}`, {
      lawyer_id: trLawyerId,
      department: trDept,
      reason: trReason.trim() || null,
    }).then((r) => {
      toast(`✅ تم تحويل التذكرة إلى ${r.data?.lawyer ?? 'المستشار المحدّد'}`);
      onClose();
      onDone?.();
    }).catch((err) => {
      const msg = err.response?.data?.message || 'تعذّر التحويل، تحقق من البيانات';
      toast(`⚠️ ${msg}`);
    }).finally(() => setTrBusy(false));
  };

  const submitReqDocs = () => {
    const picked = reqOptions.map((r) => r.name).filter((r) => reqChosen[r]);
    const extras = reqExtra.split('\n').map((s) => s.trim()).filter(Boolean);
    const allDocs = [...picked, ...extras];
    if (!allDocs.length) { toast('يرجى اختيار أو كتابة مستند واحد على الأقل'); return; }
    setReqBusy(true);
    // تُرسَل أسماء المستندات فقط؛ الخادم يبني الرسالة (تهريب آمن) ويبثّها ويضبط «بانتظار مستندات» ويشعر العميل
    axios.post(`/employee/tickets/${encodeURIComponent(ticketNo)}/request-docs`, { docs: allDocs })
      .then(() => {
        toast(`✅ تم إرسال طلب النواقص للعميل (${allDocs.length} مستند)`);
        onClose();
        onDone?.();
      })
      .catch((err) => {
        const msg = err.response?.data?.message || 'تعذّر إرسال طلب النواقص';
        toast(`⚠️ ${msg}`);
      })
      .finally(() => setReqBusy(false));
  };

  return (
    <>
      <Modal title={`تحويل التذكرة — ${ticketNo}`} open={kind === 'transfer'} onClose={onClose}>
        <div className="field">
          <label>القسم المختص</label>
          <select value={trDept} onChange={(e) => setTrDept(e.target.value)}>
            {departments.map((d) => <option key={d} value={d}>{d}</option>)}
          </select>
        </div>
        <div className="field">
          <label>المستشار</label>
          <select value={trLawyerId} onChange={(e) => setTrLawyerId(e.target.value)}>
            <option value="">اختر المستشار…</option>
            {lawyers.map((l) => <option key={l.id} value={String(l.id)}>{l.name}</option>)}
          </select>
          {!lawyerId && <LawyerSuggestionHint suggestion={suggestion} />}
        </div>
        <div className="field">
          <label>سبب التحويل (اختياري)</label>
          <input
            type="text"
            value={trReason}
            onChange={(e) => setTrReason(e.target.value)}
            placeholder="اكتب سبب التحويل…"
          />
        </div>
        <button className="btn block" type="button" onClick={submitTransfer} disabled={trBusy || !trLawyerId}>
          <Icon name="reply" /> {trBusy ? 'جاري التحويل…' : 'تأكيد التحويل'}
        </button>
      </Modal>

      <Modal title={`طلب نواقص — ${ticketNo}`} open={kind === 'reqdocs'} onClose={onClose}>
        <p style={{ fontSize: '13.5px', color: '#2b4a68', marginBottom: 10 }}>
          اختر من نواقص قائمة القسم (ما لم يُرفق بعد)، ويمكنك أيضاً كتابة مستندات إضافية:
        </p>
        {reqOptions.length === 0 && (
          <p style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 10 }}>لا نواقص في قائمة القسم — اكتب ما تحتاجه أدناه.</p>
        )}
        <div className="chips" style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 14 }}>
          {reqOptions.map(({ name: r, required }) => (
            <span
              key={r}
              className={`chip sel-toggle${reqChosen[r] ? ' on' : ''}`}
              onClick={() => setReqChosen((prev) => ({ ...prev, [r]: !prev[r] }))}
              style={{
                padding: '5px 11px',
                borderRadius: 8,
                fontSize: 12.5,
                fontWeight: 600,
                border: reqChosen[r] ? '1.5px solid var(--primary)' : '1px solid var(--line)',
                background: reqChosen[r] ? 'rgba(14,92,156,.08)' : '#fff',
                color: reqChosen[r] ? 'var(--primary)' : 'var(--muted)',
                cursor: 'pointer',
              }}
            >
              {reqChosen[r] ? '✓ ' : ''}{r}{required ? '' : ' (اختياريّ)'}
            </span>
          ))}
        </div>
        <div className="field">
          <label>مستندات إضافية (كتابة)</label>
          <textarea
            value={reqExtra}
            onChange={(e) => setReqExtra(e.target.value)}
            placeholder="اكتب أي مستندات أخرى مطلوبة، كل مستند في سطر…"
            rows={3}
          />
        </div>
        <button className="btn block" type="button" onClick={submitReqDocs} disabled={reqBusy}>
          <Icon name="send" /> {reqBusy ? 'جاري الإرسال…' : 'إرسال طلب النواقص'}
        </button>
      </Modal>
    </>
  );
};

export default TicketOpsModals;
