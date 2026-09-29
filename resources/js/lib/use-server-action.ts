import type { Page, VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';
import type { ConfirmRequest } from '@/components/babylon/ConfirmDialog';
import { useConfirm } from '@/components/babylon/ConfirmDialog';
import { useToast } from '@/components/babylon/Toast';
import { firstError } from '@/lib/server-message';

type Method = 'post' | 'put' | 'patch' | 'delete';

/** مفتاح «فعلٌ جارٍ» حين لا يُسمّى عنصر. */
const ANY = '*';

export interface ServerActionOptions {
    data?: Record<string, unknown>;
    method?: Method;
    /** يُسأل المستخدم قبل الإرسال — نصوصٌ مشتركة `CONFIRM_*` في `lib/consult-ui.tsx`. */
    confirm?: ConfirmRequest;
    /** رسالة الرفض حين لا يحمل الخادم سبباً. */
    fallback?: string;
    /** رسالة النجاح (إن لم يُعلنها الخادم بـ`flash`). */
    success?: string;
    /** مفتاح العنصر الجاري عليه الفعل (رقم فاتورة، معرّف استشارة) — يُعطّل زرّه وحده في القوائم. */
    key?: string | number;
    preserveScroll?: boolean;
    /** `'errors'` يُبقي حالة الصفحة (نموذجٌ مفتوح وما كُتب فيه) حين يرفض الخادم، ويُعيد تركيبها عند النجاح. */
    preserveState?: VisitOptions['preserveState'];
    only?: string[];
    onSuccess?: (page: Page) => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
}

/**
 * **فعلٌ على الخادم لا يُرسَل مرّتين** — القفل الموحّد لأزرار الأفعال (خطّة ١-د).
 *
 * `disabled={busy}` وحده لا يمنع النقر المزدوج: حالة React تُطبَّق بعد إعادة الرسم، فتمرّ نقرتان
 * في الإطار نفسه وتصلان الخادم معاً — الثانية تُرفض ٤٢٢ برسالةٍ تُربك المستخدم وقد نجح فعله.
 * القفل هنا `useRef` يُغلق **متزامناً** قبل أيّ انتظار (ومنه نافذة التأكيد، فلا تُفتح نافذتان)،
 * ويُفتح في `onFinish` نجح الطلب أو فشل.
 *
 * @returns `run` ترسل (و`false` إن رُفضت النقرة أو التأكيد)، و`busy`/`busyKey` لتعطيل الزرّ.
 */
export function useServerAction() {
    const lock = useRef(false);
    const [busyKey, setBusyKey] = useState<string | number | null>(null);
    const ask = useConfirm();
    const toast = useToast();

    const run = useCallback(
        async (
            url: string,
            opts: ServerActionOptions = {},
        ): Promise<boolean> => {
            if (lock.current) {
                return false;
            }

            lock.current = true;

            if (opts.confirm && !(await ask(opts.confirm))) {
                lock.current = false;

                return false;
            }

            setBusyKey(opts.key ?? ANY);

            const visit: VisitOptions = {
                method: opts.method ?? 'post',
                data: opts.data as VisitOptions['data'],
                preserveScroll: opts.preserveScroll ?? true,
                ...(opts.preserveState !== undefined
                    ? { preserveState: opts.preserveState }
                    : {}),
                ...(opts.only ? { only: opts.only } : {}),
                onSuccess: (page) => {
                    if (opts.success) {
                        toast(opts.success, 'success');
                    }

                    opts.onSuccess?.(page);
                },
                onError: (errors) => {
                    toast(
                        firstError(
                            errors as Record<string, string>,
                            opts.fallback ?? 'تعذّر تنفيذ الإجراء',
                        ),
                        'error',
                    );
                    opts.onError?.(errors as Record<string, string>);
                },
                onFinish: () => {
                    lock.current = false;
                    setBusyKey(null);
                    opts.onFinish?.();
                },
            };

            router.visit(url, visit);

            return true;
        },
        [ask, toast],
    );

    return { run, busy: busyKey !== null, busyKey };
}
