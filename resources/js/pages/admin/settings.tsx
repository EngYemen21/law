import { Link, router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';

/** وصف المتغيّر كما يعلنه `SettingsRegistry` — الشاشة لا تعرّف حقلاً ولا افتراضاً. */
interface Field {
  group: string;
  label: string;
  hint: string;
  type: 'int' | 'string' | 'date';
  default: number | string;
  min?: number;
  max?: number;
  forwardOnly?: boolean;
}

interface Props {
  groups: Record<string, string>;
  fields: Record<string, Field>;
  values: Record<string, number | string>;
}

/**
 * إعدادات النظام — متغيّرات كانت ثوابتَ في الشيفرة أو صفوفاً في الجدول بلا شاشة.
 *
 * لكلّ بطاقةٍ زرُّ حفظٍ مستقلّ ترسل حقولها وحدها: حفظُ مهلةٍ لا يلزمه المرور على بيانات
 * المكتب. والتفسير تحت كلّ حقل من السجلّ نفسه لا نسخةً منقوشةً هنا — نسختان تتباعدان.
 */
const AdminSettings: React.FC<Props> = ({ groups, fields, values }) => {
  const toast = useToast();

  const [form, setForm] = useState<Record<string, string>>(
    Object.fromEntries(Object.keys(fields).map((key) => [key, String(values[key] ?? fields[key].default)])),
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  // البطاقة المشغولة وحدها تُعطَّل — لا الشاشة كلّها
  const [busy, setBusy] = useState<string | null>(null);

  const keysOf = (group: string): string[] => Object.keys(fields).filter((key) => fields[key].group === group);

  const save = (group: string): void => {
    const keys = keysOf(group);
    setBusy(group);

    router.post(
      '/admin/settings',
      Object.fromEntries(keys.map((key) => [key, form[key]])),
      {
        preserveScroll: true,
        onSuccess: () => {
          setErrors({});
          toast('حُفظت إعدادات «' + groups[group] + '»');
        },
        onError: (errs) => {
          setErrors(errs as Record<string, string>);
          toast('⚠️ تعذّر الحفظ — راجع القيم المدخلة');
        },
        onFinish: () => setBusy(null),
      },
    );
  };

  const renderField = (key: string): React.ReactElement => {
    const field = fields[key];
    const isDefault = form[key] === String(field.default);

    return (
      <div key={key} className="field">
        <label htmlFor={`set-${key}`}>
          {field.label}
          {field.forwardOnly && (
            <span style={{ marginInlineStart: 8 }}>
              <Badge text="يسري على الجديد وحده" tone="b-amber" />
            </span>
          )}
        </label>

        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <input
            id={`set-${key}`}
            className="input"
            type={field.type === 'int' ? 'number' : field.type === 'date' ? 'date' : 'text'}
            min={field.min}
            max={field.max}
            value={form[key]}
            onChange={(e) => setForm({ ...form, [key]: e.target.value })}
            style={{ maxWidth: field.type === 'string' ? 340 : 200 }}
          />

          <button
            className="btn soft sm"
            type="button"
            disabled={busy !== null || isDefault}
            onClick={() => setForm({ ...form, [key]: String(field.default) })}
            title={`الافتراض: ${field.default}`}
          >
            <Icon name="reply" /> إعادة إلى الافتراض
          </button>
        </div>

        <div style={{ color: 'var(--muted)', fontSize: 12, marginTop: 5 }}>{field.hint}</div>

        {errors[key] && <div style={{ color: 'var(--red)', fontSize: 12, marginTop: 4 }}>{errors[key]}</div>}
      </div>
    );
  };

  return (
    <div className="admin-settings-root" style={{ paddingBottom: 60, width: '100%' }}>
      <div className="greet">
        <h1>إعدادات النظام</h1>
        <p>
          متغيّرات التشغيل التي كانت تحتاج تعديل شيفرةٍ ونشراً لتُضبط: مهل التنفيذ والأتعاب،
          ومهل التنبيهات الآليّة، وبيانات المكتب في المستندات والبريد. ولا مفاتيح أسرار هنا —
          تلك في بيئة الخادم وحدها.
        </p>
      </div>

      {Object.entries(groups).map(([group, title]) => (
        <div key={group} className="card" style={{ marginBottom: 12 }}>
          <div className="card-h"><h3>{title}</h3></div>
          <div className="card-b" style={{ padding: '14px 16px' }}>
            {keysOf(group).map((key) => renderField(key))}

            <button className="btn sm" type="button" disabled={busy !== null} onClick={() => save(group)}>
              <Icon name="check" /> {busy === group ? 'جارٍ الحفظ…' : 'حفظ'}
            </button>
          </div>
        </div>
      ))}

      {/* ── إعدادات أخرى: روابط لا نسخ حقول ──
          حقلان يكتبان المفتاح نفسه من موضعين يتباعدان عند أوّل تعديل، فتبقى شاشتا الأسعار
          والذكاء مالكتين لمفاتيحهما ويصير هذا التبويب مدخلاً واحداً للإعدادات. */}
      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>إعدادات أخرى</h3></div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          <p style={{ color: 'var(--muted)', fontSize: 12.5, marginTop: 0 }}>
            هذه المتغيّرات تُضبط من شاشاتها المتخصّصة — ولا تُكرَّر هنا كي لا يكتب حقلان قيمةً واحدة.
          </p>

          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            <Link className="btn soft sm" href="/admin/prices">
              <Icon name="card" /> أسعار الاستشارات والضريبة
            </Link>
            <Link className="btn soft sm" href="/admin/ai-ops">
              <Icon name="compass" /> حوكمة الذكاء الاصطناعي
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
};

export default AdminSettings;
