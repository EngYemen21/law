import { createInertiaApp } from '@inertiajs/react';
import React from 'react';
import ReactDOM from 'react-dom/client';
import AppLayout from '@/components/layouts/AppLayout';
import { ConfirmDialogProvider } from '@/components/babylon/ConfirmDialog';
import { ServerFeedback } from '@/components/babylon/ServerFeedback';
import { ToastProvider } from '@/components/babylon/Toast';
import '@/lib/echo';
import type { SharedSettings } from '@/lib/settings';
import { RoomDock } from '@/lib/zoom-room';

const HTML_ESCAPES: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };

createInertiaApp({
    // **عنوان التبويب من اسم المكتب الذي تضبطه الإدارة** (`office_name`) وقت التشغيل — كان من
    // `VITE_APP_NAME` المنقوش في الحزمة عند البناء، فتغيير الاسم يحتاج بناءً ونشراً. Inertia 3 تمرّر
    // الصفحة إلى الدالّة، فيُقرأ الاسم من الخاصيّة المشتركة نفسها التي يقرؤها `useSettings()`، ويتحدّث
    // مع أوّل تنقّلٍ بعد الحفظ. والعنوان قبل تحميل الواجهة يكتبه `app.blade.php` من الإعداد نفسه.
    title: (title, page) => {
        // يُهرَّب لأنّ Inertia تُدرج الناتج في `<title>` نصّاً من HTML (كما تهرّب هي `title` في `<Head>`)
        const settings = (page?.props as { settings?: Partial<SharedSettings> } | undefined)?.settings;
        const office = (settings?.office_name ?? '').replace(/[&<>"]/g, (c: string) => HTML_ESCAPES[c] ?? c);
        if (!office) return title;
        return title ? `${title} - ${office}` : office;
    },
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });
        const page = pages[`./pages/${name}.tsx`] as any;

        // الصفحة الترويجية (welcome) وصفحات المصادقة وصفحات الطباعة والمعاينة مستقلة تماماً بلا تخطيط لوحة التحكم
        const isStandalone =
            name.toLowerCase() === 'welcome' ||
            name.startsWith('auth/') ||
            name.startsWith('Auth/') ||
            name.endsWith('-print') ||
            name.endsWith('/print') ||
            name.includes('editor-print');
        if (page && !isStandalone && page.default?.layout === undefined) {
            page.default.layout = (children: React.ReactNode) => (
                <AppLayout>{children}</AppLayout>
            );
        }

        return page;
    },
    setup({ el, App, props }) {
        const root = ReactDOM.createRoot(el);
        root.render(
            <ToastProvider>
                {/* نوافذ التأكيد والإدخال داخل التنبيهات: بعضها يُطلق تنبيهاً بعد التأكيد */}
                <ConfirmDialogProvider>
                    <App {...props} />
                    {/* رسائل الخادم (رفضٌ/نجاح/خطأ) إشعاراً لكلّ الصفحات — خارج <App> ليشمل صفحات
                        الدخول وصفحة الخطأ، لا تخطيط اللوحة وحده (انظر `ServerFeedback.tsx`) */}
                    <ServerFeedback initialPage={props.initialPage} />
                    {/* الشريط العائم للجلسة المرئيّة — خارج <App> عمداً: لا يُفكَّك مع أيّ تنقّل أو
                        تبدّل تخطيط، فتبقى المكالمة حيّةً بين الصفحات (انظر `lib/room-session.ts`) */}
                    <RoomDock />
                </ConfirmDialogProvider>
            </ToastProvider>
        );
    },
    progress: {
        color: '#0e5c9c',
        showSpinner: true,
    },
});
