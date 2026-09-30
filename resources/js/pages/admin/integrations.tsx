import { Link } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Icon from '@/lib/icons';
import { useServerAction } from '@/lib/use-server-action';

/** وصف المفتاح كما يعلنه `IntegrationRegistry` — الشاشة لا تعرّف مفتاحاً ولا قاعدة. */
interface Field {
  service: string;
  label: string;
  env: string;
  secret: boolean;
  options?: string[];
}

/** حالة المفتاح من الخادم: مصدره وقيمته **مقنّعةً للأسرار** — السرّ نفسه لا يصل المتصفّح. */
interface State {
  source: 'screen' | 'env' | 'none';
  display: string;
}

interface Props {
  services: Record<string, { label: string; hint: string }>;
  fields: Record<string, Field>;
  states: Record<string, State>;
}

const SOURCE: Record<State['source'], [string, string]> = {
  screen: ['من الشاشة', 'b-green'],
  env: ['من ‎.env', 'b-blue'],
  none: ['غير مضبوط', 'b-amber'],
};

/**
 * **مفاتيح الخدمات الخارجيّة** — ميسّر، Zoom، البريد، تقنيات، الذكاء. الحقل الفارغ يُبقي الحاليّ، و«إرجاع إلى
 * ‎.env» يحذف قيمة الشاشة. والحفظ بعد نافذة تأكيدٍ تسرد ما سيتغيّر (قرار المالك 2026-09-30).
 */
const AdminIntegrations: React.FC<Props> = ({ services, fields, states }) => {
  const [values, setValues] = useState<Record<string, string>>({});
  const [clear, setClear] = useState<Record<string, boolean>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const action = useServerAction();

  const keysOf = (service: string) => Object.keys(fields).filter((k) => fields[k].service === service);

  const save = (service: string) => {
    const keys = keysOf(service);
    const changes = keys.filter((k) => (values[k] ?? '').trim() !== '').map((k) => ({ key: k, value: values[k].trim() }));
    const cleared = keys.filter((k) => clear[k] && !changes.some((c) => c.key === k));

    if (changes.length === 0 && cleared.length === 0) {
      setErrors({ [service]: 'لم يُدخَل مفتاحٌ جديد في هذه البطاقة.' });

      return;
    }

    void action.run('/admin/integrations', {
      key: service,
      data: { changes, clear: cleared },
      confirm: {
        title: `تحديث مفاتيح «${services[service].label}»؟`,
        message: (
          <div>
            <p style={{ marginTop: 0 }}>تسري القيم على النظام فور الحفظ — مفتاحٌ خاطئ يوقف الخدمة حتى يُصحَّح.</p>
            <ul style={{ margin: 0, paddingInlineStart: 18 }}>
              {changes.map((c) => <li key={c.key}>{fields[c.key].label} — قيمة جديدة</li>)}
              {cleared.map((k) => <li key={k}>{fields[k].label} — يُعاد إلى ‎.env</li>)}
            </ul>
          </div>
        ),
        confirmLabel: 'حفظ المفاتيح',
      },
      onSuccess: () => {
        setValues((v) => Object.fromEntries(Object.entries(v).filter(([k]) => !keys.includes(k))));
        setClear((c) => Object.fromEntries(Object.entries(c).filter(([k]) => !keys.includes(k))));
        setErrors({});
      },
      onError: (e) => setErrors(e),
    });
  };

  const renderField = (key: string) => {
    const field = fields[key];
    const state = states[key];
    const [sourceLabel, sourceTone] = SOURCE[state.source];
    const error = errors[`changes.${key}`];

    return (
      <div key={key} style={{ marginBottom: 14 }}>
        <label htmlFor={key} style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', fontWeight: 600, fontSize: 13 }}>
          {field.label}
          <Badge text={sourceLabel} tone={sourceTone} />
          {state.display !== '' && <span dir="ltr" style={{ color: 'var(--muted)', fontWeight: 400, fontFamily: 'monospace' }}>{state.display}</span>}
        </label>

        <div style={{ display: 'flex', gap: 8, marginTop: 6, flexWrap: 'wrap' }}>
          {field.options ? (
            <select id={key} value={values[key] ?? ''} onChange={(e) => setValues({ ...values, [key]: e.target.value })} style={{ flex: '1 1 220px' }}>
              <option value="">— بلا تغيير —</option>
              {field.options.map((o) => <option key={o} value={o}>{o}</option>)}
            </select>
          ) : (
            <input
              id={key}
              dir="ltr"
              type={field.secret ? 'password' : 'text'}
              autoComplete="off"
              placeholder={state.source === 'none' ? 'أدخل القيمة' : 'اتركه فارغاً لإبقاء الحاليّ'}
              value={values[key] ?? ''}
              onChange={(e) => setValues({ ...values, [key]: e.target.value })}
              style={{ flex: '1 1 220px' }}
            />
          )}

          {state.source === 'screen' && (
            <button
              className={`btn sm ${clear[key] ? '' : 'soft'}`}
              type="button"
              aria-pressed={!!clear[key]}
              onClick={() => setClear({ ...clear, [key]: !clear[key] })}
              title={`يحذف قيمة الشاشة فتعود الخدمة إلى ${field.env}`}
            >
              <Icon name="reply" /> إرجاع إلى ‎.env
            </button>
          )}
        </div>

        <div style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }} dir="ltr">{field.env}</div>
        {error && <div style={{ color: 'var(--red)', fontSize: 12, marginTop: 4 }}>{error}</div>}
      </div>
    );
  };

  return (
    <div className="admin-settings-root" style={{ paddingBottom: 60, width: '100%' }}>
      <div className="greet">
        <h1>مفاتيح الخدمات الخارجيّة</h1>
        <p>
          تُحفظ مشفّرةً ولا تُعرض الأسرار إلّا مقنّعة. قيمة الشاشة تتقدّم على ملفّ ‎.env متى ضُبطت، وحذفها يُعيد
          الخدمة إليه. كلّ حفظٍ يُقيَّد في سجلّ التدقيق ويُشعَر به المديرون.{' '}
          <Link href="/admin/settings">← إعدادات النظام</Link>
        </p>
      </div>

      {Object.entries(services).map(([service, meta]) => (
        <div key={service} className="card" style={{ marginBottom: 12 }}>
          <div className="card-h"><h3>{meta.label}</h3></div>
          <div className="card-b" style={{ padding: '14px 16px' }}>
            <p style={{ color: 'var(--muted)', fontSize: 12.5, marginTop: 0 }}>{meta.hint}</p>
            {keysOf(service).map(renderField)}
            {errors[service] && <div style={{ color: 'var(--red)', fontSize: 12, marginBottom: 8 }}>{errors[service]}</div>}
            <button className="btn sm" type="button" disabled={action.busyKey !== null} onClick={() => save(service)}>
              <Icon name="check" /> {action.busyKey === service ? 'جارٍ الحفظ…' : 'حفظ'}
            </button>
          </div>
        </div>
      ))}
    </div>
  );
};

export default AdminIntegrations;
