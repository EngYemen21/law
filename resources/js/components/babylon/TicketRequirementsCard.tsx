import axios from 'axios';
import React, { useCallback, useEffect, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useConfirm } from '@/components/babylon/ConfirmDialog';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';

// «قائمة مستندات القسم» للتذكرة — للطاقم وحده (موظّف/محامٍ/إدارة) في جانب المحادثة.
// المصدر خادميّ واحد (TicketDocumentRequirements عبر {base}/tickets/{no}/requirements): ما استُوفي
// وبأيّ مرفق ومن حكم به، وما لم يُتحقّق منه. والتأكيد/الإلغاء اليدويّ حين يتعذّر الفحص الآليّ أو يخطئ.
// العميل لا يرى هذا — يصله ما بقي مطلوباً في رسالة طلب النواقص فقط.

export interface RequirementItem {
  name: string;
  required: boolean;
  satisfied: boolean;
  document: { id: number; name: string } | null;
  checkedBy: 'ai' | 'staff' | null;
  checkedByLabel: string | null;
}

export interface RequirementsData {
  department: string | null;
  usesDefault: boolean;
  items: RequirementItem[];
  documents: { id: number; name: string; checked: boolean }[];
  uncheckedCount: number;
}

/** يجلب حالة القائمة لتذكرة — يستعمله البطاقة ومودال طلب النواقص معاً. */
export const fetchTicketRequirements = (base: string, ticketNo: string) =>
  axios.get<RequirementsData>(`${base}/tickets/${encodeURIComponent(ticketNo)}/requirements`).then((r) => r.data);

const muted: React.CSSProperties = { color: 'var(--muted)', fontSize: 12 };
const rowStyle: React.CSSProperties = { padding: '8px 0', borderBottom: '1px solid var(--line-soft)', display: 'grid', gap: 4 };

interface Props {
  base: string;
  ticketNo: string;
  /** يتغيّر مع كلّ رسالة جديدة — فتُعاد القراءة بعد إرفاقٍ أو فحصٍ آليّ */
  refreshKey?: number;
  /** التأكيد/الإلغاء اليدويّ — يُخفى للتذكرة النهائيّة أو المجمّدة (الخادم يرفضه أيضاً) */
  canEdit: boolean;
}

const TicketRequirementsCard: React.FC<Props> = ({ base, ticketNo, refreshKey = 0, canEdit }) => {
  const toast = useToast();
  const ask = useConfirm();
  const [data, setData] = useState<RequirementsData | null>(null);
  const [picked, setPicked] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    fetchTicketRequirements(base, ticketNo).then(setData).catch(() => setData(null));
  }, [base, ticketNo]);

  useEffect(() => {
    load();
  }, [load, refreshKey]);

  const save = (requirement: string, documentId: number | null) => {
    setBusy(true);
    axios.post<RequirementsData>(`${base}/tickets/${encodeURIComponent(ticketNo)}/requirements`, { requirement, document_id: documentId })
      .then((r) => {
        setData(r.data);
        toast(documentId ? 'حُفظ الاستيفاء' : 'أُلغي الاستيفاء — يعود البند ناقصاً');
      })
      .catch((err) => toast(`⚠️ ${err.response?.data?.message ?? 'تعذّر الحفظ'}`))
      .finally(() => setBusy(false));
  };

  // إلغاء الاستيفاء بالحوار المشترك (Escape = إلغاء) — أثره أن يُطلب البند من العميل ثانيةً
  const unmark = async (item: RequirementItem) => {
    const ok = await ask({
      title: `إلغاء استيفاء «${item.name}»؟`,
      message: `سيعود البند ناقصاً ويُطلب من العميل في طلب النواقص التالي، ولن يُعدّ «${item.document?.name ?? 'المرفق'}» مستوفياً له. يمكن تأكيده من جديد لاحقاً.`,
      confirmLabel: 'إلغاء الاستيفاء',
      cancelLabel: 'تراجع',
      tone: 'danger',
    });

    if (ok) {
      save(item.name, null);
    }
  };

  if (!data) {
    return null;
  }

  const satisfied = data.items.filter((i) => i.satisfied).length;

  return (
    <div className="card">
      <div className="card-h" style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
        <Icon name="doc" />
        <h3 style={{ margin: 0 }}>قائمة مستندات القسم</h3>
        <span style={muted}>{satisfied}/{data.items.length}</span>
      </div>
      <div className="card-b" style={{ padding: '10px 16px' }}>
        {data.usesDefault && (
          <div style={{ ...muted, marginBottom: 6 }}>لا قائمة محرَّرة لقسم التذكرة بعد — هذه القائمة العامّة.</div>
        )}
        {data.items.map((item) => (
          <div key={item.name} style={rowStyle}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
              <span aria-hidden style={{ color: item.satisfied ? 'var(--success)' : 'var(--muted)', fontWeight: 800 }}>{item.satisfied ? '✓' : '○'}</span>
              <b style={{ fontSize: 13 }}>{item.name}</b>
              <Badge text={item.required ? 'إلزاميّ' : 'اختياريّ'} tone={item.required ? 'b-blue' : 'b-grey'} />
            </div>
            {item.satisfied && item.document ? (
              <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap', ...muted }}>
                <span>{item.document.name} — {item.checkedByLabel}</span>
                {canEdit && (
                  <button type="button" className="btn soft sm" disabled={busy} onClick={() => unmark(item)}>إلغاء</button>
                )}
              </div>
            ) : canEdit && data.documents.length > 0 ? (
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                <select
                  className="input"
                  aria-label={`المرفق الذي يستوفي ${item.name}`}
                  value={picked[item.name] ?? ''}
                  onChange={(e) => setPicked((prev) => ({ ...prev, [item.name]: e.target.value }))}
                  style={{ flex: 1, minWidth: 140, fontSize: 12.5 }}
                >
                  <option value="">اختر المرفق الذي يستوفيه…</option>
                  {data.documents.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </select>
                <button type="button" className="btn soft sm" disabled={busy || !picked[item.name]} onClick={() => save(item.name, Number(picked[item.name]))}>
                  <Icon name="check" /> تأكيد
                </button>
              </div>
            ) : (
              <div style={muted}>لم يُرفق بعد</div>
            )}
          </div>
        ))}
        {data.uncheckedCount > 0 && (
          <div style={{ ...muted, marginTop: 8, color: '#8a6d2f' }}>
            {data.uncheckedCount} مرفق لم يُتحقّق من مطابقته للقائمة (الفحص الآليّ متوقّف أو تعذّر) — لا يُعدّ مستوفياً حتى يؤكَّد.
          </div>
        )}
      </div>
    </div>
  );
};

export default TicketRequirementsCard;
