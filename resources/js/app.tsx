import { createInertiaApp } from '@inertiajs/react';
import React from 'react';
import ReactDOM from 'react-dom/client';
import AppLayout from '@/components/layouts/AppLayout';
import { ConfirmDialogProvider } from '@/components/babylon/ConfirmDialog';
import { ToastProvider } from '@/components/babylon/Toast';
import '@/lib/echo';

const appName = import.meta.env.VITE_APP_NAME || 'النظام الإداري لمكاتب المحاماة';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
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
                </ConfirmDialogProvider>
            </ToastProvider>
        );
    },
    progress: {
        color: '#0e5c9c',
        showSpinner: true,
    },
});
