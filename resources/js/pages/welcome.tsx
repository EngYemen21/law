import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';

interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    home?: string;
}

export default function Welcome() {
    const { auth } = usePage<{ auth: { user: AuthUser | null } }>().props;
    const user = auth?.user;

    return (
        <div className="min-h-screen bg-[#FAFBFD] flex flex-col items-center justify-center p-4 font-['Tajawal',sans-serif] text-slate-800 antialiased" dir="rtl">
            <Head>
                <title>النظام الإداري لمكاتب المحاماة</title>
            </Head>
            <div className="text-center max-w-lg mx-auto">
                <h1 className="text-2xl sm:text-4xl md:text-5xl font-black text-[#0A2A55] tracking-tight mb-6">
                    النظام الإداري لمكاتب المحاماة
                </h1>
                <div className="flex items-center justify-center gap-3">
                    <Link
                        href={user ? (user.home || '/dashboard') : '/login'}
                        className="inline-flex items-center justify-center px-6 py-3 rounded-xl bg-[#0E5C9C] hover:bg-[#0A2A55] text-white font-black text-sm shadow-md transition-all"
                    >
                        {user ? 'الانتقال إلى لوحة التحكم' : 'تسجيل الدخول'}
                    </Link>
                </div>
            </div>
        </div>
    );
}

// تخطيط مستقل تماماً عن لوحة التحكم
Welcome.layout = (page: React.ReactNode) => <>{page}</>;
