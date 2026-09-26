import type { SharedSettings } from '@/lib/settings';
import type { Auth } from '@/types/auth';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /** متغيّرات النظام — `HandleInertiaRequests::SHARED_SETTINGS`؛ اقرأها بـ`useSettings()`. */
            settings: SharedSettings;
            [key: string]: unknown;
        };
    }
}
