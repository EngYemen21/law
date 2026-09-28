import { Link, router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { WEEK_DAY_NAMES } from '@/lib/local-date';

/** وصف المتغيّر كما يعلنه `SettingsRegistry` — الشاشة لا تعرّف حقلاً ولا افتراضاً. */
interface Field {
  group: string;
  label: string;
  hint: string;
  type: 'int' | 'string' | 'date' | 'days' | 'bool';
  default: number | string;
  min?: number;
  max?: number;
  forwardOnly?: boolean;
  /** وصف الافتراض حين لا يقول نصّه شيئاً (الفراغ ذو المعنى) — من السجلّ */
  defaultLabel?: string;
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

/** إعداد «نعم/لا» — يُكتب «1» أو «0» كما يقرؤه `SettingsRegistry::bool`. */
const YesNoInput: React.FC<{ id: string; value: string; onChange: (v: string) => void }> = ({ id, value, onChange }) => (
  <div id={id} role="group" style={{ display: 'flex', gap: 6 }}>
    {[['1', 'نعم'], ['0', 'لا']].map(([v, label]) => (
      <button key={v} type="button" aria-pressed={value === v} className={`btn sm ${value === v ? '' : 'soft'}`} onClick={() => onChange(v)}>
        {label}
      </button>
    ))}
  </div>
);

/** إعداد من نوع «أيّام»: أزرار تبديل تكتب النصّ «0,1,4» مرتّباً — ولا يُفرَّغ آخر يوم. */
const DaysInput: React.FC<{ id: string; value: string; onChange: (v: string) => void }> = ({ id, value, onChange }) => {
  const on = new Set(value.split(',').filter((d) => d !== '').map(Number));
  const toggle = (d: number) => {
    const next = new Set(on);

    if (next.has(d)) {
      next.delete(d);
    } else {
      next.add(d);
    }

    if (next.size > 0) {
      onChange([...next].sort((a, b) => a - b).join(','));
    }
  };

  return (
    <div id={id} role="group" style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
      {WEEK_DAY_NAMES.map((name, d) => (
        <button key={d} type="button" aria-pressed={on.has(d)} className={`btn sm ${on.has(d) ? '' : 'soft'}`} onClick={() => toggle(d)}>
          {name}
        </button>
      ))}
    </div>
  );
};

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
        // رسالة النجاح من الخادم وحده («حُفظت…» أو «لا تغيير…») — كان هنا توستٌ ثانٍ يقول «حُفظت»
        // حتى حين ردّ الخادم بأنّ شيئاً لم يتغيّر.
        onSuccess: (page) => {
          setErrors({});
          // الحقول المحفوظة تُقرأ من جديد ممّا حفظه الخادم (بعد التشذيب، والفارغ ⇦ افتراضه)،
          // ولا تُمسّ البطاقات الأخرى — تعديلٌ لم يُحفظ فيها يبقى كما هو.
          const saved = (page.props as unknown as Props).values;
          setForm((prev) => ({
            ...prev,
            ...Object.fromEntries(keys.map((key) => [key, String(saved[key] ?? fields[key].default)])),
          }));
        },
        onError: (errs) => {
          setErrors(errs as Record<string, string>);
          toast(`⚠️ ${Object.values(errs)[0] ?? 'تعذّر الحفظ — راجع القيم المدخلة'}`, 'error');
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
          {field.type === 'days' ? (
            <DaysInput id={`set-${key}`} value={form[key]} onChange={(v) => setForm({ ...form, [key]: v })} />
          ) : field.type === 'bool' ? (
            <YesNoInput id={`set-${key}`} value={form[key]} onChange={(v) => setForm({ ...form, [key]: v })} />
          ) : (
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
          )}

          <button
            className="btn soft sm"
            type="button"
            disabled={busy !== null || isDefault}
            onClick={() => setForm({ ...form, [key]: String(field.default) })}
            title={field.defaultLabel ?? `الافتراض: ${field.default}`}
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
          ومهل سداد الفواتير، وساعات حجز الاستشارات وسياسة إعادة جدولتها، ومهل التنبيهات الآليّة، وبيانات المكتب في المستندات والبريد، ومسمّيات المتحدّثين كما يراها
          العميل في محادثاته. ولا مفاتيح أسرار هنا —
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
