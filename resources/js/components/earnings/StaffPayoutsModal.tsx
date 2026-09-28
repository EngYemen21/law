import axios from 'axios';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { usePrompt } from '@/components/babylon/ConfirmDialog';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import EarningsView from '@/components/earnings/EarningsView';
import Icon from '@/lib/icons';
import type { PayoutKindId, PayoutKindOption, PayoutRow, StaffEarnings } from '@/types';

/**
 * **درج «المستحقّات والصرف» للإدارة** — يعرض ما يراه الموظّف في «مستحقاتي» (`EarningsView` نفسه)،
 * ويضيف تسجيل الصرف وإلغاء القيد بسببٍ (`Admin\StaffPayoutController`). لا تعديل ولا حذف.
 */
interface Props {
  staff: { id: number; name: string } | null;
  onClose: () => void;
}

interface Payload {
  earnings: StaffEarnings;
  kinds: PayoutKindOption[];
  message?: string;
  warning?: string | null;
}

const serverMessage = (err: unknown, fallback: string): string =>
  (axios.isAxiosError(err) && (err.response?.data as { message?: string } | undefined)?.message) || fallback;

const PayoutsPanel: React.FC<{ staffId: number }> = ({ staffId }) => {
  const toast = useToast();
  const ask = usePrompt();
  const [data, setData] = useState<Payload | null>(null);
  const [kind, setKind] = useState<PayoutKindId>('salary');
  const [amount, setAmount] = useState('');
  const [period, setPeriod] = useState('');
  const [fileId, setFileId] = useState('');
  const [note, setNote] = useState('');
  const busy = useRef(false);
  const [sending, setSending] = useState(false);

  const load = useCallback(
    (m: string | null) => {
      axios
        .get<Payload>(`/admin/staff/${staffId}/earnings`, {
          params: m ? { month: m } : {},
        })
        .then((r) => {
          setData(r.data);
          setPeriod((p) => p || r.data.earnings.month);
        })
        .catch((err) => toast(`⚠️ ${serverMessage(err, 'تعذّر تحميل المستحقّات')}`));
    },
    [staffId, toast],
  );

  useEffect(() => {
    load(null);
  }, [load]);

  const apply = (r: Payload) => {
    setData(r);
    toast(r.warning ? `⚠️ ${r.warning}` : `✅ ${r.message ?? 'تمّ'}`);
  };

  const needsFile = data?.kinds.find((k) => k.id === kind)?.needsFile ?? false;
  const files = (data?.earnings.shares ?? []).filter((s) => (kind === 'case_share' ? s.kind === 'case' : s.kind === 'exec'));

  const submit = () => {
    if (busy.current) {
      return;
    }

    busy.current = true;
    setSending(true);
    axios
      .post<Payload>(`/admin/staff/${staffId}/payouts`, {
        kind,
        amount: Number(amount),
        period,
        note: note.trim() || null,
        file_id: needsFile ? Number(fileId) || null : null,
      })
      .then((r) => {
        apply(r.data);
        setAmount('');
        setNote('');
      })
      .catch((err) => toast(`⚠️ ${serverMessage(err, 'تعذّر تسجيل الصرف')}`))
      .finally(() => {
        busy.current = false;
        setSending(false);
      });
  };

  const voidPayout = async (p: PayoutRow) => {
    if (busy.current) {
      return;
    }

    const reason = (
      await ask({
        title: `إلغاء قيد ${p.kindLabel} — ${p.amount.toLocaleString('en-US')} ر.س`,
        message: 'القيد لا يُحذف: يبقى في السجلّ مشطوباً بسبب إلغائه، ويعود مبلغه إلى الرصيد المتبقّي.',
        label: 'سبب الإلغاء',
        confirmLabel: 'إلغاء القيد',
      })
    )?.trim();

    if (!reason) {
      return;
    }

    busy.current = true;
    axios
      .post<Payload>(`/admin/staff/${staffId}/payouts/${p.id}/void`, {
        reason,
      })
      .then((r) => apply(r.data))
      .catch((err) => toast(`⚠️ ${serverMessage(err, 'تعذّر إلغاء القيد')}`))
      .finally(() => {
        busy.current = false;
      });
  };

  const ready = Number(amount) > 0 && period !== '' && (!needsFile || fileId !== '');

  if (!data) {
    return <p style={{ color: 'var(--muted)' }}>جارٍ التحميل…</p>;
  }

  return (
    <>
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>تسجيل صرف</h3>
        </div>
        <div className="card-b">
          <div className="picker-grid">
            <div className="field">
              <label>البند</label>
              <select
                className="input"
                value={kind}
                onChange={(e) => {
                  setKind(e.target.value as PayoutKindId);
                  setFileId('');
                }}
              >
                {data.kinds.map((k) => (
                  <option key={k.id} value={k.id}>
                    {k.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="field">
              <label>المبلغ (ر.س)</label>
              <input className="input" inputMode="numeric" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="مثال: 8000" />
            </div>
            <div className="field">
              <label>عن شهر</label>
              <input className="input" type="month" value={period} onChange={(e) => setPeriod(e.target.value)} />
            </div>
            {needsFile && (
              <div className="field">
                <label>{kind === 'case_share' ? 'القضيّة' : 'ملفّ التنفيذ'}</label>
                <select className="input" value={fileId} onChange={(e) => setFileId(e.target.value)}>
                  <option value="">اختر…</option>
                  {files.map((f) => (
                    <option key={f.id} value={String(f.id)}>
                      {f.ref} — متبقٍّ {f.balance.toLocaleString('en-US')} ر.س
                    </option>
                  ))}
                </select>
              </div>
            )}
          </div>
          <div className="field">
            <label>ملاحظة (اختياري)</label>
            <input className="input" value={note} onChange={(e) => setNote(e.target.value)} placeholder="مثال: تحويل بنكي رقم …" />
          </div>
          <button className="btn" type="button" disabled={!ready || sending} onClick={submit}>
            <Icon name="check" /> {sending ? 'جارٍ التسجيل…' : 'تسجيل الصرف'}
          </button>
        </div>
      </div>

      <EarningsView
        earnings={data.earnings}
        base="/admin"
        onMonth={load}
        payoutAction={(p) => (
          <button className="btn soft sm" type="button" onClick={() => voidPayout(p)}>
            <Icon name="close" /> إلغاء القيد
          </button>
        )}
      />
    </>
  );
};

const StaffPayoutsModal: React.FC<Props> = ({ staff, onClose }) => (
  <Modal title={`المستحقّات والصرف — ${staff?.name ?? ''}`} open={staff !== null} onClose={onClose} maxWidth={1100}>
    {/* المحتوى يُركَّب من جديد لكلّ موظّف (`key`) — فلا تبقى قيم الموظّف السابق في النموذج */}
    {staff && <PayoutsPanel key={staff.id} staffId={staff.id} />}
  </Modal>
);

export default StaffPayoutsModal;
