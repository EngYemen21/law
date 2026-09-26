import { Link } from '@inertiajs/react';
import { useSettings } from '@/lib/settings';
import {
    AI_CHAIN,
    AI_POINTS,
    CLIENT_FEATURES,
    DASHBOARDS,
    NAV_LINKS,
    STEPS,
    TEAM_GROUPS,
    TRUST_POINTS,
} from './content';
import LandingIcon from './LandingIcon';

/*
 * أقسام الصفحة الترويجية.
 *
 * تنبيه تنسيق: babylon.css مستورد خارج طبقات Tailwind، فقواعده العامّة
 * (`a{color:inherit}` و`svg{display:block}` و`img{display:block}`) تغلب أصناف Tailwind
 * مهما كانت خصوصيّتها. لذلك يُكتب لون الروابط بلاحقة `!`.
 */

export type Cta = {
    href: string;
    label: string;
    isGuest: boolean;
};

const CONTAINER = 'mx-auto! w-full max-w-6xl px-4! sm:px-6! lg:px-8!';
const FOCUS_LIGHT = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0E5C9C]';
const FOCUS_DARK = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white';
const SECTION_SCROLL = 'scroll-mt-20';

function SectionHeading({ id, eyebrow, title, intro }: { id: string; eyebrow: string; title: string; intro?: string }) {
    return (
        <div className="mx-auto! max-w-2xl text-center">
            <p className="text-sm font-bold text-[#0E5C9C]">{eyebrow}</p>
            <h2 id={id} className="mt-2! text-2xl leading-snug font-extrabold text-[#0A2A55] sm:text-3xl">
                {title}
            </h2>
            {intro && <p className="mt-4! text-base leading-8 text-slate-600">{intro}</p>}
        </div>
    );
}

export function LandingHeader({ cta }: { cta: Cta }) {
    // اسم المكتب من إعداده (`office_name`) — تغيّره الإدارة من الإعدادات بلا نشر كود
    const { office_name } = useSettings();

    return (
        <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
            <div className={`${CONTAINER} flex h-16 items-center justify-between gap-3`}>
                <a href="#top" className={`flex min-w-0 items-center gap-3 rounded-lg ${FOCUS_LIGHT}`}>
                    <img
                        src="/images/021.png"
                        width={1672}
                        height={512}
                        alt="شعار سلاسل بابل لتقنية المعلومات"
                        className="h-9 w-auto shrink-0"
                    />
                    <span className="hidden truncate text-sm font-bold text-[#0A2A55] sm:inline md:text-base">
                        {office_name}
                    </span>
                </a>

                <nav aria-label="أقسام الصفحة" className="hidden lg:block">
                    <ul className="flex items-center gap-1">
                        {NAV_LINKS.map((l) => (
                            <li key={l.href}>
                                <a
                                    href={l.href}
                                    className={`rounded-lg px-3! py-2! text-sm font-bold text-slate-600! transition-colors hover:bg-slate-100 hover:text-[#0A2A55]! ${FOCUS_LIGHT}`}
                                >
                                    {l.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>

                <Link
                    href={cta.href}
                    className={`inline-flex h-10 shrink-0 items-center gap-2 rounded-xl bg-[#0E5C9C] px-4! text-sm font-bold text-white! shadow-sm transition-colors hover:bg-[#0A2A55] ${FOCUS_LIGHT}`}
                >
                    <LandingIcon name={cta.isGuest ? 'lock' : 'home'} className="size-4" />
                    {cta.label}
                </Link>
            </div>
        </header>
    );
}

export function Hero({ cta }: { cta: Cta }) {
    const { office_name } = useSettings();

    return (
        <section
            aria-labelledby="hero-title"
            className="relative overflow-hidden bg-[linear-gradient(140deg,#081E3D_0%,#0A2A55_45%,#0E5C9C_100%)] text-white"
        >
            <div aria-hidden="true" className="pointer-events-none absolute -start-32 -top-32 size-96 rounded-full bg-white/5" />
            <div aria-hidden="true" className="pointer-events-none absolute -end-24 -bottom-40 size-80 rounded-full bg-[#11A0C8]/15" />

            <div className={`${CONTAINER} relative grid items-center gap-10 py-14! sm:py-20! lg:grid-cols-[1.15fr_0.85fr] lg:gap-14`}>
                <div>
                    <p className="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3! py-1! text-xs font-bold text-blue-50 sm:text-sm">
                        <LandingIcon name="scale" className="size-4" />
                        {office_name}
                    </p>
                    <h1 id="hero-title" className="mt-5! text-3xl leading-[1.4] font-extrabold sm:text-4xl lg:text-[2.75rem]">
                        من أول طلب يفتحه العميل حتى تنفيذ الحكم، في منصّة واحدة
                    </h1>
                    <p className="mt-5! max-w-xl text-base leading-8 text-blue-100 sm:text-lg sm:leading-9">
                        طلبات ومحادثات، واستشارات مرئية، وقضايا وجلسات، وتنفيذ وفواتير. لكلٍّ من العميل والموظّف والمحامي
                        والإدارة العليا لوحته الخاصّة، ومساعد قانوني ذكي لا يصل ما يكتبه إلى العميل إلا بعد اعتماد المحامي ثمّ
                        الإدارة.
                    </p>

                    <div className="mt-8! flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        <Link
                            href={cta.href}
                            className={`inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-white px-6! text-base font-extrabold text-[#0A2A55]! shadow-lg transition-colors hover:bg-blue-50 ${FOCUS_DARK}`}
                        >
                            {cta.label}
                            <LandingIcon name="forward" className="size-5" />
                        </Link>
                        <a
                            href="#how"
                            className={`inline-flex h-12 items-center justify-center rounded-xl border border-white/35 px-6! text-base font-bold text-white! transition-colors hover:bg-white/10 ${FOCUS_DARK}`}
                        >
                            تعرّف على مراحل الخدمة
                        </a>
                    </div>

                    {cta.isGuest && (
                        <p className="mt-5! max-w-xl text-sm leading-7 text-blue-100">
                            عميل جديد؟ أنشئ حسابك من صفحة الدخول برقم الهوية والجوال والبريد، ويُؤكَّد برمز تحقّق.
                        </p>
                    )}
                </div>

                <div className="rounded-3xl bg-white/10 p-4! ring-1 ring-white/15 sm:p-5!">
                    <p className="px-1! pb-3! text-sm font-bold text-blue-50">أربع لوحات، منصّة واحدة</p>
                    <ul className="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
                        {DASHBOARDS.map((d) => (
                            <li key={d.title} className="rounded-2xl bg-white p-4! text-[#0A2A55] shadow-md">
                                <span className="flex size-10 items-center justify-center rounded-xl bg-[#EAF2FA] text-[#0E5C9C]">
                                    <LandingIcon name={d.icon} className="size-5" />
                                </span>
                                <p className="mt-3! text-base font-extrabold">{d.title}</p>
                                <p className="mt-1! text-sm leading-6 text-slate-600">{d.text}</p>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </section>
    );
}

export function HowItWorks() {
    return (
        <section id="how" aria-labelledby="how-title" className={`${SECTION_SCROLL} py-16! sm:py-24!`}>
            <div className={CONTAINER}>
                <SectionHeading
                    id="how-title"
                    eyebrow="رحلة العميل"
                    title="كيف تسير خدمتك مع المكتب"
                    intro="كل مرحلة تبدأ من حيث انتهت سابقتها، وتبقى محادثتك ومستنداتك معك في كل خطوة."
                />

                <ol className="mt-12! grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    {STEPS.map((s, i) => (
                        <li key={s.title} className="relative flex flex-col rounded-2xl border border-slate-200 bg-white p-6! shadow-sm">
                            <div className="flex items-center justify-between">
                                <span className="flex size-12 items-center justify-center rounded-2xl bg-[#0A2A55] text-white">
                                    <LandingIcon name={s.icon} className="size-6" />
                                </span>
                                <span className="text-sm font-extrabold text-[#0E5C9C]">
                                    <span className="sr-only">الخطوة </span>
                                    {i + 1}
                                </span>
                            </div>
                            <h3 className="mt-5! text-lg font-extrabold text-[#0A2A55]">{s.title}</h3>
                            <p className="mt-2! text-[0.95rem] leading-7 text-slate-600">{s.text}</p>
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}

export function Features() {
    return (
        <section id="features" aria-labelledby="features-title" className={`${SECTION_SCROLL} border-y border-slate-200 bg-white py-16! sm:py-24!`}>
            <div className={CONTAINER}>
                <SectionHeading
                    id="features-title"
                    eyebrow="المزايا"
                    title="كل ما يحتاجه العميل والمكتب"
                    intro="شاشات حقيقية مبنية لكل دور، لا نسخة واحدة تُخفى أجزاؤها."
                />

                <h3 className="mt-14! flex items-center gap-2 text-xl font-extrabold text-[#0A2A55]">
                    <LandingIcon name="user" className="size-6 text-[#0E5C9C]" />
                    للعميل
                </h3>
                <ul className="mt-6! grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {CLIENT_FEATURES.map((f) => (
                        <li key={f.title} className="flex gap-4 rounded-2xl border border-slate-200 bg-[#FAFBFD] p-5!">
                            <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#EAF2FA] text-[#0E5C9C]">
                                <LandingIcon name={f.icon} className="size-5" />
                            </span>
                            <div className="min-w-0">
                                <h4 className="text-base font-extrabold text-[#0A2A55]">{f.title}</h4>
                                <p className="mt-1! text-sm leading-7 text-slate-600">{f.text}</p>
                            </div>
                        </li>
                    ))}
                </ul>

                <h3 className="mt-16! flex items-center gap-2 text-xl font-extrabold text-[#0A2A55]">
                    <LandingIcon name="office" className="size-6 text-[#0E5C9C]" />
                    لفريق المكتب
                </h3>
                <div className="mt-6! grid gap-5 lg:grid-cols-3">
                    {TEAM_GROUPS.map((g) => (
                        <article key={g.title} className="rounded-2xl border border-slate-200 bg-[#FAFBFD] p-6!">
                            <div className="flex items-center gap-3">
                                <span className="flex size-11 items-center justify-center rounded-xl bg-[#0A2A55] text-white">
                                    <LandingIcon name={g.icon} className="size-5" />
                                </span>
                                <h4 className="text-lg font-extrabold text-[#0A2A55]">{g.title}</h4>
                            </div>
                            <ul className="mt-5! space-y-3!">
                                {g.items.map((item) => (
                                    <li key={item} className="flex gap-2 text-sm leading-7 text-slate-700">
                                        <LandingIcon name="check" className="mt-1.5! size-4 shrink-0 text-[#1E9D6B]" />
                                        <span>{item}</span>
                                    </li>
                                ))}
                            </ul>
                        </article>
                    ))}
                </div>
            </div>
        </section>
    );
}

export function AiSection() {
    return (
        <section id="ai" aria-labelledby="ai-title" className={`${SECTION_SCROLL} py-16! sm:py-24!`}>
            <div className={CONTAINER}>
                <div className="overflow-hidden rounded-3xl bg-[#0A2A55] px-5! py-12! text-white sm:px-10! lg:px-14!">
                    <div className="mx-auto! max-w-2xl text-center">
                        <p className="text-sm font-bold text-blue-100">المساعد القانوني الذكي</p>
                        <h2 id="ai-title" className="mt-2! text-2xl leading-snug font-extrabold sm:text-3xl">
                            الذكاء يساعد الفريق، والقرار للمحامي
                        </h2>
                        <p className="mt-4! text-base leading-8 text-blue-100">
                            يلخّص المساعد الطلبات والجلسات ويصوغ المسودّات ليوفّر وقت الفريق، لكنّ ما يكتبه يمرّ باعتمادين بشريّين
                            قبل أن يراه العميل.
                        </p>
                    </div>

                    <ol className="mt-10! grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {AI_CHAIN.map((c, i) => (
                            <li key={c.title} className="relative rounded-2xl bg-white/10 p-5! ring-1 ring-white/15">
                                <div className="flex items-center gap-3">
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white text-[#0A2A55]">
                                        <LandingIcon name={c.icon} className="size-5" />
                                    </span>
                                    <h3 className="text-base font-extrabold">
                                        <span className="sr-only">المرحلة {i + 1}: </span>
                                        {c.title}
                                    </h3>
                                </div>
                                <p className="mt-3! text-sm leading-7 text-blue-100">{c.text}</p>
                            </li>
                        ))}
                    </ol>

                    <ul className="mx-auto! mt-10! grid max-w-3xl gap-3">
                        {AI_POINTS.map((p) => (
                            <li key={p} className="flex gap-3 text-sm leading-7 text-blue-50 sm:text-base">
                                <LandingIcon name="check" className="mt-1.5! size-4 shrink-0 text-[#46C0E4]" />
                                <span>{p}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </section>
    );
}

export function Trust() {
    return (
        <section id="trust" aria-labelledby="trust-title" className={`${SECTION_SCROLL} pb-16! sm:pb-24!`}>
            <div className={CONTAINER}>
                <SectionHeading
                    id="trust-title"
                    eyebrow="الضمانات"
                    title="ضمانات مبنية في النظام نفسه"
                    intro="ليست وعودًا في نصّ، بل قواعد تفرضها المنصّة على كل حساب."
                />

                <ul className="mt-12! grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {TRUST_POINTS.map((t) => (
                        <li key={t.title} className="rounded-2xl border border-slate-200 bg-white p-6! shadow-sm">
                            <span className="flex size-11 items-center justify-center rounded-xl bg-[#E7F6EF] text-[#16734F]">
                                <LandingIcon name={t.icon} className="size-5" />
                            </span>
                            <h3 className="mt-4! text-base font-extrabold text-[#0A2A55]">{t.title}</h3>
                            <p className="mt-2! text-sm leading-7 text-slate-600">{t.text}</p>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}

export function FinalCta({ cta }: { cta: Cta }) {
    return (
        <section aria-labelledby="cta-title" className="pb-16! sm:pb-24!">
            <div className={CONTAINER}>
                <div className="relative overflow-hidden rounded-3xl bg-[linear-gradient(140deg,#081E3D_0%,#0A2A55_50%,#0E5C9C_100%)] px-5! py-12! text-center text-white sm:px-10!">
                    <div aria-hidden="true" className="pointer-events-none absolute -start-20 -top-24 size-72 rounded-full bg-white/5" />
                    <h2 id="cta-title" className="relative text-2xl leading-snug font-extrabold sm:text-3xl">
                        {cta.isGuest ? 'تابع طلباتك وقضاياك من مكان واحد' : 'لوحتك بانتظارك'}
                    </h2>
                    <p className="relative mx-auto! mt-4! max-w-xl text-base leading-8 text-blue-100">
                        {cta.isGuest
                            ? 'ادخل برقم هويتك ورمز التحقّق، أو أنشئ حساب عميل جديد من صفحة الدخول نفسها.'
                            : 'انتقل إلى لوحتك لمتابعة ما يخصّك.'}
                    </p>
                    <Link
                        href={cta.href}
                        className={`relative mt-8! inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-white px-7! text-base font-extrabold text-[#0A2A55]! shadow-lg transition-colors hover:bg-blue-50 ${FOCUS_DARK}`}
                    >
                        {cta.label}
                        <LandingIcon name="forward" className="size-5" />
                    </Link>
                </div>
            </div>
        </section>
    );
}

export function LandingFooter({ cta }: { cta: Cta }) {
    const { office_name } = useSettings();

    return (
        <footer className="border-t border-slate-200 bg-white">
            <div className={`${CONTAINER} flex flex-col gap-6 py-8! md:flex-row md:items-center md:justify-between`}>
                <div>
                    <p className="text-base font-extrabold text-[#0A2A55]">{office_name}</p>
                    <p className="mt-1! text-sm text-slate-600">© {new Date().getFullYear()} جميع الحقوق محفوظة</p>
                </div>
                <nav aria-label="روابط التذييل">
                    <ul className="flex flex-wrap items-center gap-x-2 gap-y-2">
                        {NAV_LINKS.map((l) => (
                            <li key={l.href}>
                                <a
                                    href={l.href}
                                    className={`rounded-md px-2! py-1! text-sm font-bold text-slate-600! hover:text-[#0A2A55]! ${FOCUS_LIGHT}`}
                                >
                                    {l.label}
                                </a>
                            </li>
                        ))}
                        <li>
                            <Link
                                href={cta.href}
                                className={`rounded-md px-2! py-1! text-sm font-bold text-[#0E5C9C]! hover:text-[#0A2A55]! ${FOCUS_LIGHT}`}
                            >
                                {cta.label}
                            </Link>
                        </li>
                    </ul>
                </nav>
            </div>
        </footer>
    );
}
