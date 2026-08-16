import { createInertiaApp } from '@inertiajs/react';
import React from 'react';
import ReactDOM from 'react-dom/client';
import AppLayout from '@/components/layouts/AppLayout';
import { ToastProvider } from '@/components/babylon/Toast';
import '@/lib/echo';

const appName = import.meta.env.VITE_APP_NAME || 'النظام الإداري لمكاتب المحاماة';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });
        const page = pages[`./pages/${name}.tsx`] as any;

        // الصفحة الترويجية (welcome) وصفحات المصادقة مستقلة تماماً بلا تخطيط لوحة التحكم
        const isStandalone = name.toLowerCase() === 'welcome' || name.startsWith('auth/') || name.startsWith('Auth/');
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
                <App {...props} />
            </ToastProvider>
        );
    },
    progress: {
        color: '#0e5c9c',
        showSpinner: true,
    },
});
