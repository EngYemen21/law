import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Modal from '@/components/babylon/Modal';
import Icon from '@/lib/icons';

/**
 * **إنشاء حساب عميل من المكتب** — الحقول الأربعة نفسها في تسجيل العميل بنفسه، وقواعدها في الخادم
 * (`ClientAccount`): لا هويّة ولا جوال ولا بريد مكرَّر. لا كلمة مرور: يدخل العميل بهويّته ورمزٍ إلى جواله.
 * بعد الحفظ يُنقل المستخدم إلى ملفّ العميل الجديد.
 */
interface Props {
  open: boolean;
  onClose: () => void;
  /** مسار الحفظ — `/admin/clients` للإدارة */
  action: string;
}

const EMPTY = { name: '', national_id: '', phone: '', email: '' };

const FIELDS: Array<{ key: keyof typeof EMPTY; label: string; type: string; placeholder: string; mono?: boolean; maxLength?: number }> = [
  { key: 'name', label: 'الاسم الكامل', type: 'text', placeholder: 'الاسم الأوّل واسم العائلة على الأقلّ' },
  { key: 'national_id', label: 'رقم الهوية الوطنية / الإقامة', type: 'text', placeholder: '10 أرقام', mono: true, maxLength: 10 },
  { key: 'phone', label: 'رقم الجوال', type: 'tel', placeholder: '05xxxxxxxx', mono: true },
  { key: 'email', label: 'البريد الإلكتروني', type: 'email', placeholder: 'client@domain.com' },
];

const CreateClientModal: React.FC<Props> = ({ open, onClose, action }) => {
  const [form, setForm] = useState(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const close = () => {
    if (busy) {
      return;
    }

    setForm(EMPTY);
    setErrors({});
    onClose();
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    router.post(action, form, {
      onError: (errs) => setErrors(errs),
      onSuccess: () => {
        setForm(EMPTY);
        onClose();
      },
      onFinish: () => setBusy(false),
    });
  };

  return (
    <Modal title="إنشاء حساب عميل" subtitle="يدخل العميل برقم هويّته ورمزٍ يصل إلى جواله — لا كلمة مرور." open={open} onClose={close} maxWidth={560}>
      <form onSubmit={submit} noValidate>
        {FIELDS.map((f) => (
          <div className="field" key={f.key}>
            <label htmlFor={`new-client-${f.key}`}>
              {f.label} <span style={{ color: 'var(--red)' }}>*</span>
            </label>
            <input
              id={`new-client-${f.key}`}
              type={f.type}
              className={`input${f.mono ? ' mono' : ''}`}
              dir={f.mono || f.type === 'email' ? 'ltr' : undefined}
              value={form[f.key]}
              onChange={(e) => setForm({ ...form, [f.key]: e.target.value })}
              placeholder={f.placeholder}
              maxLength={f.maxLength}
              required
            />
            {errors[f.key] && <div style={{ color: 'var(--red)', fontSize: 12, marginTop: 4 }}>{errors[f.key]}</div>}
          </div>
        ))}

        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 14, gap: 8 }}>
          <button type="button" className="btn soft" onClick={close} disabled={busy}>
            إلغاء
          </button>
          <button type="submit" className="btn" disabled={busy} style={{ minWidth: 140 }}>
            <Icon name="check" /> {busy ? 'جارٍ الإنشاء...' : 'إنشاء الحساب'}
          </button>
        </div>
      </form>
    </Modal>
  );
};

export default CreateClientModal;
