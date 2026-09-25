import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import Modal from '@/components/babylon/Modal';
import Icon from '@/lib/icons';

/**
 * **نوافذ التأكيد والإدخال — مصدرٌ واحد بدل نوافذ المتصفّح الأصليّة.**
 *
 * كانت ثمانيَ عشرةَ نافذةً أصليّة (`window.confirm` · `window.prompt`) موزّعةً على تسعة ملفّات.
 * وعِلّتها ليست الشكل وحده:
 *
 * - **تُحجَب:** المتصفّح يكتم نوافذ الصفحة بعد تكرارها («لا تسمح لهذه الصفحة بإنشاء مربّعات
 *   حوار») — فيرجع `confirm` بـ`false` صامتاً، فيبدو الزرّ ميّتاً بلا أيّ رسالة.
 * - **تُجمّد الصفحة:** نداءٌ متزامن يوقف كلّ شيء — فلا بثٌّ لحظيّ ولا مؤقّت يعمل أثناء فتحها.
 * - **لا عربيّة ولا اتّجاه:** عنوانها ونصّ أزرارها من المتصفّح ونظام التشغيل، إنجليزيّةً
 *   يساريّة الاتّجاه وسط واجهةٍ عربيّة.
 * - **لا تُختبر:** لا سبيل لمحاكاتها في اختبار تصيير.
 *
 * فالبديل واحدٌ لكلّ الشاشات، مبنيٌّ على `Modal` القائم (لا مكوّن ثانٍ)، ويُقدَّم بالنمط نفسه
 * الذي يقدَّم به `ToastProvider`: مزوّدٌ واحد في جذر التطبيق، وخطّافٌ في موضع الاستعمال.
 *
 * **ويحفظ شكل النداء القديم** ليبقى الفرق في موضع الاستعمال سطراً واحداً:
 *
 *     const ask = useConfirm();
 *     if (!(await ask({ title: '…', message: '…' }))) return;
 *
 *     const ask = usePrompt();
 *     const what = (await ask({ title: '…' }))?.trim();
 *
 * والوعد **يُحلّ دائماً**: بالإلغاء، وبمفتاح الهروب، وبنقر الخلفيّة، وبتفكيك المزوّد — فلا
 * يبقى نداءٌ معلّقاً يوقف الدالّة المستدعية إلى الأبد.
 */

export interface ConfirmRequest {
  title: string;
  /** نصّ الشرح تحت العنوان — ما الذي سيقع، وهل يُتراجع عنه. */
  message?: React.ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  /** `danger` للأفعال التي لا تُتراجع (إلغاء · حذف · اعتماد نهائيّ). */
  tone?: 'default' | 'danger';
}

export interface PromptRequest {
  title: string;
  message?: React.ReactNode;
  label?: string;
  placeholder?: string;
  defaultValue?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  /** يمنع التأكيد حتى يُكتب نصّ — افتراضه صحيح، فأغلب المواضع تطلب قيمةً لازمة. */
  required?: boolean;
}

type Pending =
  | { kind: 'confirm'; req: ConfirmRequest; resolve: (v: boolean) => void }
  | { kind: 'prompt'; req: PromptRequest; resolve: (v: string | null) => void };

interface DialogApi {
  confirm: (req: ConfirmRequest) => Promise<boolean>;
  prompt: (req: PromptRequest) => Promise<string | null>;
}

/**
 * الاحتياطيّ حين لا مزوّد (صفحاتُ الطباعة والمعاينة تُصيَّر خارج جذر التطبيق):
 * يُرفض الفعل بصدق بدل تنفيذه بلا تأكيد — فالتأكيد حارسٌ لا زينة.
 */
const DialogCtx = createContext<DialogApi>({
  confirm: async () => false,
  prompt: async () => null,
});

export const useConfirm = (): DialogApi['confirm'] => useContext(DialogCtx).confirm;
export const usePrompt = (): DialogApi['prompt'] => useContext(DialogCtx).prompt;

/** حلُّ الوعد المعلّق بقيمته الملغاة — موضعٌ واحد فلا يُنسى فرعٌ منهما. */
function cancel(open: Pending | null): void {
  if (open?.kind === 'confirm') {
    open.resolve(false);
  } else if (open?.kind === 'prompt') {
    open.resolve(null);
  }
}

export const ConfirmDialogProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [pending, setPending] = useState<Pending | null>(null);
  const [value, setValue] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);

  /*
   * الوعد الحيّ يُمسك في مرجع لا في الحالة وحدها، لسببين:
   * ١) حلُّه داخل مُحدِّث الحالة يقع مرّتين في الوضع الصارم — وحلُّ وعدٍ مرّتين خطأٌ صامت.
   * ٢) عند تفكيك المزوّد لا تبقى الدالّة المستدعية موقوفةً إلى الأبد.
   */
  const live = useRef<Pending | null>(null);

  useEffect(
    () => () => {
      cancel(live.current);
      live.current = null;
    },
    [],
  );

  const settle = useCallback((accepted: boolean, text: string) => {
    const open = live.current;
    live.current = null;
    setPending(null);

    if (open?.kind === 'confirm') {
      open.resolve(accepted);
    } else if (open?.kind === 'prompt') {
      open.resolve(accepted ? text : null);
    }
  }, []);

  /** نافذةٌ ثانية تُفتح ونافذةٌ معلّقة: تُلغى الأولى صراحةً فلا يضيع وعدها. */
  const start = useCallback((next: Pending, initial: string) => {
    cancel(live.current);
    live.current = next;
    setValue(initial);
    setPending(next);
  }, []);

  const confirm = useCallback(
    (req: ConfirmRequest) => new Promise<boolean>((resolve) => start({ kind: 'confirm', req, resolve }, '')),
    [start],
  );

  const prompt = useCallback(
    (req: PromptRequest) =>
      new Promise<string | null>((resolve) => start({ kind: 'prompt', req, resolve }, req.defaultValue ?? '')),
    [start],
  );

  const api = useMemo<DialogApi>(() => ({ confirm, prompt }), [confirm, prompt]);

  // تحديدُ النصّ القائم فور الفتح — النافذة الأصليّة كانت تفعله، ففقدُه تراجعٌ في سهولة الاستعمال
  useEffect(() => {
    if (pending?.kind !== 'prompt') {
      return undefined;
    }

    const id = window.setTimeout(() => inputRef.current?.select(), 30);

    return () => window.clearTimeout(id);
  }, [pending]);

  const danger = pending?.kind === 'confirm' && pending.req.tone === 'danger';
  const blocked = pending?.kind === 'prompt' && (pending.req.required ?? true) && value.trim() === '';

  return (
    <DialogCtx.Provider value={api}>
      {children}
      {pending && (
        <Modal title={pending.req.title} open onClose={() => settle(false, value)} maxWidth={460}>
          <form
            onSubmit={(e) => {
              e.preventDefault();

              if (!blocked) {
                settle(true, value);
              }
            }}
          >
            {pending.req.message && (
              <div className="action-hint" style={{ marginBottom: 14 }}>
                <Icon name={danger ? 'alert' : 'info'} />
                <span>{pending.req.message}</span>
              </div>
            )}

            {pending.kind === 'prompt' && (
              <div className="field" style={{ marginBottom: 14 }}>
                {pending.req.label && (
                  <label style={{ fontWeight: 700, fontSize: 13, marginBottom: 5, display: 'block' }}>
                    {pending.req.label}
                  </label>
                )}
                <input
                  ref={inputRef}
                  value={value}
                  onChange={(e) => setValue(e.target.value)}
                  placeholder={pending.req.placeholder}
                  autoFocus
                  style={{
                    width: '100%',
                    padding: '9px 12px',
                    borderRadius: 8,
                    border: '1px solid var(--line, #e2e8f0)',
                    fontSize: 13.5,
                  }}
                />
              </div>
            )}

            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
              <button type="button" className="btn soft" onClick={() => settle(false, value)}>
                {pending.req.cancelLabel ?? 'إلغاء'}
              </button>
              <button
                type="submit"
                className="btn"
                disabled={blocked}
                style={{
                  minWidth: 120,
                  justifyContent: 'center',
                  ...(danger ? { background: 'var(--red, #ef4444)', borderColor: 'var(--red, #ef4444)' } : null),
                }}
              >
                <Icon name="check" /> {pending.req.confirmLabel ?? 'متابعة'}
              </button>
            </div>
          </form>
        </Modal>
      )}
    </DialogCtx.Provider>
  );
};

export default ConfirmDialogProvider;
