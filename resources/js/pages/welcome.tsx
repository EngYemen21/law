import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { Cta } from '@/components/landing/sections';
import {
    AiSection,
    FinalCta,
    Features,
    Hero,
    HowItWorks,
    LandingFooter,
    LandingHeader,
    Trust,
} from '@/components/landing/sections';

interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    home?: string;
}

// الصفحة الترويجية العامّة — تخطيط مستقلّ بلا لوحة تحكّم.
// الزرّ الرئيس يتبع حالة الجلسة: الزائر إلى /login، والمسجَّل إلى لوحته (user.home).
export default function Welcome() {
    const { auth } = usePage<{ auth: { user: AuthUser | null } }>().props;
    const user = auth?.user;

    const cta: Cta = user
        ? { href: user.home || '/dashboard', label: 'الانتقال إلى لوحة التحكم', isGuest: false }
        : { href: '/login', label: 'تسجيل الدخول', isGuest: true };

    return (
        <div id="top" lang="ar" dir="rtl" className="min-h-screen bg-[#FAFBFD] font-['Tajawal',sans-serif] text-slate-800 antialiased">
            {/* بلا `<title>`: العنوان الفارغ يجعل `title` في `app.tsx` يكتب اسم المكتب وحده من إعداده */}
            <Head>
                <meta
                    name="description"
                    head-key="description"
                    content="منصّة لإدارة مكتب المحاماة: طلبات ومحادثات، استشارات مرئية، قضايا وجلسات، تنفيذ وفواتير، بلوحة مستقلّة لكل دور ومساعد قانوني باعتماد بشري."
                />
            </Head>

            <a
                href="#main"
                className="sr-only rounded-lg bg-[#0A2A55] px-4! py-2! text-sm font-bold text-white! focus:not-sr-only focus:fixed focus:start-4 focus:top-3 focus:z-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#11A0C8]"
            >
                تخطَّ إلى المحتوى
            </a>

            <LandingHeader cta={cta} />

            <main id="main" tabIndex={-1} className="outline-none">
                <Hero cta={cta} />
                <HowItWorks />
                <Features />
                <AiSection />
                <Trust />
                <FinalCta cta={cta} />
            </main>

            <LandingFooter cta={cta} />
        </div>
    );
}

// تخطيط مستقل تماماً عن لوحة التحكم
Welcome.layout = (page: ReactNode) => <>{page}</>;
