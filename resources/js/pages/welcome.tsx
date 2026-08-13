import React, { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Scale,
    ShieldCheck,
    Video,
    Gavel,
    FileText,
    CreditCard,
    Sparkles,
    ArrowLeft,
    CheckCircle2,
    ChevronDown,
    Building2,
    Users,
    Lock,
    Menu,
    X,
    FileCheck,
    Bot,
} from 'lucide-react';

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

    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [activeFaq, setActiveFaq] = useState<number | null>(0);
    const [activeTab, setActiveTab] = useState<'clients' | 'lawyers' | 'admin'>('clients');

    const services = [
        {
            icon: Scale,
            title: 'إدارة القضايا والترافع القضائي',
            desc: 'متابعة شاملة لكافة مراحل الدعاوى، مواعيد الجلسات لدى المحاكم، كتابة اللوائح، وتتبع منطوق الأحكام بدقة.',
            tag: 'ترافع شامل',
        },
        {
            icon: Video,
            title: 'الاستشارات القانونية المرئية',
            desc: 'جلسات استشارية مرئية تفاعلية سحابية عبر Zoom مع ربط المواعيد، وتوليد روابط الجلسات الآمنة تلقائياً.',
            tag: 'فوري وسحابي',
        },
        {
            icon: Gavel,
            title: 'شؤون وملفات التنفيذ',
            desc: 'تدفق آلي متكامل لمتابعة طلبات التنفيذ، إرفاق السندات لأمر، اعتماد الأتعاب، وتحصيل المبالغ المحكوم بها.',
            tag: 'تنفيذ ذكي',
        },
        {
            icon: Sparkles,
            title: 'المساعد الذكي والفرز الآلي',
            desc: 'تحليل أولي متقدم للوقائع بواسطة الذكاء الاصطناعي، صياغة مسودات الآراء القانونية، وفرز التذاكر آلياً.',
            tag: 'مدعوم بالـ AI',
        },
        {
            icon: FileText,
            title: 'المخاطبات والمعاملات العدلية',
            desc: 'إدارة متكاملة للخطابات والمخاطبات الرسمية الواردة والصادرة مع الأرشفة الإلكترونية المشفرة.',
            tag: 'أرشفة فورية',
        },
        {
            icon: CreditCard,
            title: 'الفوترة والدفع الإلكتروني',
            desc: 'إصدار فواتير معتمدة لضريبة القيمة المضافة مع سداد إلكتروني فوري عبر بوابة ميسّر (مدى، فيزا، Apple Pay).',
            tag: 'دفع معتمد',
        },
    ];

    const stats = [
        { value: '+1,200', label: 'قضية ومعاملة مدارة', icon: Scale },
        { value: '99.8%', label: 'نسبة الدقة والامتثال', icon: ShieldCheck },
        { value: '100%', label: 'استشارات مرئية فورية', icon: Video },
        { value: '24/7', label: 'حماية ومصادقة آمنة OTP', icon: Lock },
    ];

    const workflowSteps = [
        {
            step: '01',
            title: 'دخول آمن برمز OTP',
            desc: 'تسجيل دخول فوري وسلس برقم الهوية الوطنية ورمز التحقق لمرة واحدة دون الحاجة لكلمات مرور.',
            icon: Lock,
        },
        {
            step: '02',
            title: 'فرز ذكي وتعيين المستشار',
            desc: 'تحليل موضوع طلبك وتعيين المحامي والمستشار المتخصص في موضوع القضية أو الاستشارة.',
            icon: Bot,
        },
        {
            step: '03',
            title: 'جلسات وترافع لحظي',
            desc: 'متابعة مجريات الترافع، حضور الاجتماعات المرئية، وتبادل المستندات والرسائل القانونية.',
            icon: Users,
        },
        {
            step: '04',
            title: 'نتائج وفواتير معتمدة',
            desc: 'استلام مذكرات الرأي، محاضر الجلسات، وسداد الفواتير الإلكترونية المعتمدة بنقرة واحدة.',
            icon: FileCheck,
        },
    ];

    const faqs = [
        {
            q: 'كيف يمكنني تسجيل الدخول إلى النظام؟',
            a: 'يتم تسجيل الدخول بمنتهى السهولة والأمان من خلال إدخال رقم الهوية الوطنية، وسيصلك رمز تحقق (OTP) لمرة واحدة على رقم جوالك المسجل دون الحاجة لكتابة أو تذكر كلمات مرور.',
        },
        {
            q: 'كيف تتم الاستشارات القانونية المرئية؟',
            a: 'عند حجز استشارة مرئية، يتم تزويدك برابط مباشر للجلسة المرئية داخل حسابك وعبر البريد الإلكتروني قبل الموعد بـ 5 دقائق للانضمام والحديث المباشر مع المستشار.',
        },
        {
            q: 'هل يمكنني متابعة سير القضية أو ملف التنفيذ لحظياً؟',
            a: 'نعم، يوفر النظام لوحة تحكم تفاعلية مخصصة تتيح لك متابعة جدول الجلسات القضائية، آخر التحديثات، تبادل المستندات مع المحامي، واستلام الإشعارات اللحظية عبر البريد والرسائل.',
        },
        {
            q: 'ما هي طرق الدفع المعتمدة في المنظومة؟',
            a: 'ندعم الدفع الإلكتروني الآمن والمعتمد عبر بوابة ميسّر (Moyasar) بواسطة بطاقات مدى (Mada)، البطاقات الائتمانية (Visa / MasterCard)، وخدمة Apple Pay مع إصدار فواتير ضريبية فورية.',
        },
        {
            q: 'هل بياناتي ومستنداتي القانونية محمية وسرية؟',
            a: 'نعم تماماً، تطبق المنظومة أعلى معايير التشفير والسرية المهنية المتوافقة مع الأنظمة واللوائح المرعية في المملكة العربية السعودية مع عزل تام للبيانات وسجلات تدقيق دقيقة.',
        },
    ];

    return (
        <div className="min-h-screen bg-white text-slate-800 font-['Tajawal',sans-serif] overflow-x-hidden antialiased" >
            <Head>
                <title>النظام الإداري لمكاتب المحاماه | المنظومة الرقمية الشاملة</title>
                <meta name="description" content="النظام الإداري لمكاتب المحاماه والاستشارات القانونية — منصة سحابية رائدة لإدارة القضايا، الجلسات، ملفات التنفيذ، والاستشارات المرئية." />
            </Head>

            {/* 🌟 1. الترويسة العلوية النظيفة (Header) */}
            <header className="sticky top-0 z-50 bg-white border-b border-slate-200 shadow-sm">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">

                    {/* الشعار واسم المنظومة */}
                    <div className="flex items-center gap-3">
                        <div className="w-11 h-11 rounded-xl bg-white border border-slate-200 p-1 flex items-center justify-center shadow-sm shrink-0">
                            <img
                                src="/images/logomark.jpg"
                                alt="شعار النظام"
                                className="w-full h-full object-contain rounded-lg"
                                onError={(e) => {
                                    e.currentTarget.style.display = 'none';
                                }}
                            />
                        </div>
                        <div>
                            <span className="text-lg font-black text-[#0A2A55] block leading-tight">
                                النظام الإداري لمكاتب المحاماه
                            </span>
                            <span className="text-xs text-[#0E5C9C] font-bold">
                                منظومة المحاماة والاستشارات القانونية
                            </span>
                        </div>
                    </div>

                    {/* روابط التصفح */}
                    <nav className="hidden lg:flex items-center gap-8 text-sm font-bold text-slate-600">
                        <a href="#services" className="hover:text-[#0E5C9C] transition-colors">الخدمات والحلول</a>
                        <a href="#features" className="hover:text-[#0E5C9C] transition-colors">مميزات المنظومة</a>
                        <a href="#workflow" className="hover:text-[#0E5C9C] transition-colors">كيف نعمل؟</a>
                        <a href="#faq" className="hover:text-[#0E5C9C] transition-colors">الأسئلة الشائعة</a>
                    </nav>

                    {/* أزرار الإجراءات */}
                    <div className="hidden sm:flex items-center gap-3">
                        {user ? (
                            <Link
                                href={user.home || '/dashboard'}
                                className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#0E5C9C] text-white font-black text-sm shadow-sm hover:bg-[#0A2A55] transition-colors"
                            >
                                <span>لوحة التحكم ({user.name.split(' ')[0]})</span>
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href="/login"
                                    className="px-5 py-2.5 rounded-xl bg-white text-[#0E5C9C] hover:bg-[#F0F7FF] font-black text-sm transition-colors border border-[#0E5C9C]/30"
                                >
                                    تسجيل الدخول
                                </Link>
                                <Link
                                    href="/login"
                                    className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#0E5C9C] hover:bg-[#0A2A55] text-white font-black text-sm shadow-sm transition-colors"
                                >
                                    <Sparkles className="w-4 h-4" />
                                    <span>طلب استشارة فورية</span>
                                </Link>
                            </>
                        )}
                    </div>

                    {/* زر القائمة للشاشات الصغيرة */}
                    <div className="lg:hidden">
                        <button
                            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                            className="p-2 rounded-xl bg-slate-100 text-slate-700 hover:text-[#0E5C9C] border border-slate-200"
                        >
                            {mobileMenuOpen ? <X className="w-6 h-6" /> : <Menu className="w-6 h-6" />}
                        </button>
                    </div>
                </div>

                {/* القائمة المنسدلة للجوال */}
                {mobileMenuOpen && (
                    <div className="lg:hidden bg-white border-b border-slate-200 px-6 py-6 space-y-4 shadow-xl">
                        <a href="#services" onClick={() => setMobileMenuOpen(false)} className="block text-slate-700 font-bold py-2 hover:text-[#0E5C9C]">الخدمات والحلول</a>
                        <a href="#features" onClick={() => setMobileMenuOpen(false)} className="block text-slate-700 font-bold py-2 hover:text-[#0E5C9C]">مميزات المنظومة</a>
                        <a href="#workflow" onClick={() => setMobileMenuOpen(false)} className="block text-slate-700 font-bold py-2 hover:text-[#0E5C9C]">كيف نعمل؟</a>
                        <a href="#faq" onClick={() => setMobileMenuOpen(false)} className="block text-slate-700 font-bold py-2 hover:text-[#0E5C9C]">الأسئلة الشائعة</a>
                        <div className="pt-4 border-t border-slate-100 flex flex-col gap-2">
                            {user ? (
                                <Link href={user.home || '/dashboard'} className="w-full text-center py-3 rounded-xl bg-[#0E5C9C] text-white font-bold text-sm">
                                    لوحة التحكم ({user.name})
                                </Link>
                            ) : (
                                <>
                                    <Link href="/login" className="w-full text-center py-3 rounded-xl bg-slate-100 text-[#0E5C9C] font-bold text-sm border border-slate-200">
                                        تسجيل الدخول (OTP)
                                    </Link>
                                    <Link href="/login" className="w-full text-center py-3 rounded-xl bg-[#0E5C9C] text-white font-bold text-sm shadow-sm">
                                        طلب استشارة قانونية
                                    </Link>
                                </>
                            )}
                        </div>
                    </div>
                )}
            </header>

            {/* 🏛️ 2. قسم البطل (Hero Section) - تصميم ناصع وأنيق */}
            <section className="pt-16 pb-20 lg:pt-24 lg:pb-28 bg-gradient-to-b from-[#F0F7FF]/70 via-white to-[#F8FAFC] border-b border-slate-200">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">

                    {/* الشارة الترحيبية */}
                    <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-[#EBF5FF] border border-[#BFDBFE] text-[#0E5C9C] text-xs sm:text-sm font-black mb-6 shadow-sm">
                        <span className="w-2 h-2 rounded-full bg-[#0E5C9C]" />
                        الجيل القادم من أنظمة إدارة مكاتب المحاماة والعمليات العدلية
                    </div>

                    {/* العنوان الرئيسي */}
                    <h1 className="text-3xl sm:text-4xl md:text-5xl lg:text-5xl font-black text-[#0A2A55] leading-tight mb-6 tracking-tight max-w-4xl mx-auto">
                        العدالة الرقمية المتكاملة.. <span className="text-[#0E5C9C]">وإدارة القضايا والامتثال</span> بأعلى معايير الاحترافية
                    </h1>

                    {/* النص التعريفي */}
                    <p className="text-base sm:text-lg text-slate-600 leading-relaxed font-medium mb-10 max-w-2xl mx-auto">
                        منظومة سحابية رائدة تُمكّن مكاتب المحاماة والشركات والأفراد من إدارة القضايا، الجلسات القضائية، ملفات التنفيذ، والاستشارات المرئية بدقة وسرية تامة.
                    </p>

                    {/* أزرار الدعوة للعمل */}
                    <div className="flex flex-wrap items-center justify-center gap-4 mb-16">
                        <Link
                            href={user ? (user.home || '/dashboard') : '/login'}
                            className="inline-flex items-center justify-center gap-2 px-8 py-3.5 rounded-xl bg-[#0E5C9C] hover:bg-[#0A2A55] text-white font-black text-base shadow-md transition-all"
                        >
                            <span>{user ? 'الدخول إلى لوحة التحكم' : 'ابدأ الآن وتسجيل الدخول'}</span>
                            <ArrowLeft className="w-5 h-5" />
                        </Link>

                        <a
                            href="#services"
                            className="inline-flex items-center justify-center gap-2 px-8 py-3.5 rounded-xl bg-white hover:bg-slate-50 text-[#0E5C9C] font-black text-base border-2 border-[#0E5C9C]/30 transition-all shadow-sm"
                        >
                            <span>استعراض الخدمات والحلول</span>
                        </a>
                    </div>

                    {/* بطاقات المميزات الرئيسية للواجهة */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 text-right max-w-6xl mx-auto">

                        <div className="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm hover:border-[#0E5C9C] transition-all">
                            <div className="w-12 h-12 rounded-xl bg-[#F0F7FF] text-[#0E5C9C] flex items-center justify-center mb-4 border border-[#BFDBFE]">
                                <Scale className="w-6 h-6" />
                            </div>
                            <div className="text-xs font-bold text-slate-500 mb-1">جلسات المحاكم والترافع</div>
                            <div className="text-xl font-black text-[#0A2A55] mb-2">إدارة الجلسات اللحظية</div>
                            <p className="text-xs text-slate-600 leading-relaxed">
                                متابعة مواعيد الجلسات القضائية واللوائح والتنبيهات الذكية عبر التقويم المتكامل.
                            </p>
                        </div>

                        <div className="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm hover:border-[#0E5C9C] transition-all">
                            <div className="w-12 h-12 rounded-xl bg-[#F0F7FF] text-[#0E5C9C] flex items-center justify-center mb-4 border border-[#BFDBFE]">
                                <Video className="w-6 h-6" />
                            </div>
                            <div className="text-xs font-bold text-slate-500 mb-1">استشارات مرئية وسحابية</div>
                            <div className="text-xl font-black text-[#0A2A55] mb-2">تكامل Zoom المباشر</div>
                            <p className="text-xs text-slate-600 leading-relaxed">
                                عقد الاستشارات القانونية بالصوت والصورة مع روابط آمنة تُفعّل تلقائياً قبل الموعد.
                            </p>
                        </div>

                        <div className="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm hover:border-[#0E5C9C] transition-all">
                            <div className="w-12 h-12 rounded-xl bg-[#F0F7FF] text-[#0E5C9C] flex items-center justify-center mb-4 border border-[#BFDBFE]">
                                <Bot className="w-6 h-6" />
                            </div>
                            <div className="text-xs font-bold text-slate-500 mb-1">الذكاء الاصطناعي العدلي</div>
                            <div className="text-xl font-black text-[#0A2A55] mb-2">فرز وتحليل آلي</div>
                            <p className="text-xs text-slate-600 leading-relaxed">
                                فرز التذاكر وصياغة الرأي القانوني المبدئي وتحليل الوقائع بدقة فائقة.
                            </p>
                        </div>

                        <div className="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm hover:border-[#0E5C9C] transition-all">
                            <div className="w-12 h-12 rounded-xl bg-[#F0F7FF] text-[#0E5C9C] flex items-center justify-center mb-4 border border-[#BFDBFE]">
                                <CreditCard className="w-6 h-6" />
                            </div>
                            <div className="text-xs font-bold text-slate-500 mb-1">الفوترة المعتمدة</div>
                            <div className="text-xl font-black text-[#0A2A55] mb-2">سداد فوري وميسّر</div>
                            <p className="text-xs text-slate-600 leading-relaxed">
                                دفع إلكتروني آمن عبر بوابة ميسّر مع فواتير ضريبية معتمدة ومتوافقة نظامياً.
                            </p>
                        </div>

                    </div>

                </div>
            </section>

            {/* 📊 3. شريط الأرقام والإحصائيات */}
            <section className="py-12 bg-white border-b border-slate-200">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-8">
                        {stats.map((item, idx) => (
                            <div key={idx} className="flex flex-col items-center text-center p-4">
                                <div className="w-12 h-12 rounded-2xl bg-[#F0F7FF] border border-[#BFDBFE] text-[#0E5C9C] flex items-center justify-center mb-3 shadow-sm">
                                    <item.icon className="w-6 h-6" />
                                </div>
                                <div className="text-3xl sm:text-4xl font-black text-[#0A2A55] tracking-tight mb-1">{item.value}</div>
                                <div className="text-xs sm:text-sm font-bold text-slate-500">{item.label}</div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* ⚖️ 4. قسم الخدمات والحلول الأساسية */}
            <section id="services" className="py-20 lg:py-28 bg-[#F8FAFC]">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

                    <div className="text-center max-w-3xl mx-auto mb-16">
                        <div className="text-xs sm:text-sm font-black text-[#0E5C9C] tracking-wider uppercase mb-3">
                            المنظومة المتكاملة
                        </div>
                        <h2 className="text-3xl sm:text-4xl font-black text-[#0A2A55] tracking-tight mb-4">
                            حلول قانونية وإدارية ذكية تُغطي كافة احتياجاتك
                        </h2>
                        <p className="text-base text-slate-600 font-medium">
                            صُممت المنظومة لتلبي متطلبات مكاتب المحاماة الحديثة وفق أفضل الممارسات القضائية والتقنية في المملكة العربية السعودية.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                        {services.map((svc, idx) => (
                            <div
                                key={idx}
                                className="group p-8 rounded-2xl bg-white border border-slate-200 hover:border-[#0E5C9C] transition-all duration-200 hover:shadow-lg flex flex-col justify-between"
                            >
                                <div>
                                    <div className="flex items-center justify-between mb-6">
                                        <div className="w-12 h-12 rounded-xl bg-[#0E5C9C] text-white flex items-center justify-center shadow-sm">
                                            <svc.icon className="w-6 h-6" />
                                        </div>
                                        <span className="px-3 py-1 rounded-full text-xs font-bold bg-[#F0F7FF] text-[#0E5C9C] border border-[#BFDBFE]">
                                            {svc.tag}
                                        </span>
                                    </div>

                                    <h3 className="text-xl font-black text-[#0A2A55] mb-3 group-hover:text-[#0E5C9C] transition-colors">
                                        {svc.title}
                                    </h3>

                                    <p className="text-sm text-slate-600 leading-relaxed font-medium mb-6">
                                        {svc.desc}
                                    </p>
                                </div>

                                <div className="pt-4 border-t border-slate-100 flex items-center justify-between text-xs font-bold text-[#0E5C9C]">
                                    <span>متاح في المنظومة</span>
                                    <CheckCircle2 className="w-4 h-4 text-[#0E5C9C]" />
                                </div>
                            </div>
                        ))}
                    </div>

                </div>
            </section>

            {/* 🔄 5. كيف تعمل المنظومة؟ */}
            <section id="workflow" className="py-20 bg-white border-y border-slate-200">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

                    <div className="text-center max-w-3xl mx-auto mb-16">
                        <div className="text-xs sm:text-sm font-black text-[#0E5C9C] tracking-wider uppercase mb-3">
                            سلاسة الإجراءات
                        </div>
                        <h2 className="text-3xl sm:text-4xl font-black text-[#0A2A55] tracking-tight mb-4">
                            تجربة عمل رقمية مبسطة وسريعة
                        </h2>
                        <p className="text-base text-slate-600 font-medium">
                            أربع خطوات بسيطة تنقلك من طلب الخدمة إلى استلام النتائج والأحكام المعتمدة بكل شفافية.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        {workflowSteps.map((step, idx) => (
                            <div key={idx} className="p-6 rounded-2xl bg-[#F8FAFC] border border-slate-200 hover:border-[#0E5C9C] transition-all flex flex-col justify-between">
                                <div>
                                    <div className="flex items-center justify-between mb-4">
                                        <div className="text-2xl font-black text-[#0E5C9C]/40 font-mono">
                                            {step.step}
                                        </div>
                                        <div className="w-10 h-10 rounded-xl bg-white border border-[#BFDBFE] text-[#0E5C9C] flex items-center justify-center shadow-sm">
                                            <step.icon className="w-5 h-5" />
                                        </div>
                                    </div>
                                    <h3 className="text-base font-black text-[#0A2A55] mb-2">
                                        {step.title}
                                    </h3>
                                    <p className="text-xs text-slate-600 leading-relaxed font-medium">
                                        {step.desc}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>

                </div>
            </section>

            {/* 🛡️ 6. قسم مصفوفة الواجهات والأدوار */}
            <section id="features" className="py-20 lg:py-28 bg-[#F8FAFC]">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

                    <div className="text-center max-w-3xl mx-auto mb-14">
                        <div className="text-xs sm:text-sm font-black text-[#0E5C9C] tracking-wider uppercase mb-3">
                            تكامل المنظومة
                        </div>
                        <h2 className="text-3xl sm:text-4xl font-black text-[#0A2A55] tracking-tight mb-4">
                            بيئة عمل مخصصة لكل مستخدم
                        </h2>
                        <p className="text-base text-slate-600 font-medium">
                            واجهات مصممة بعناية لتلائم احتياجات العميل، المحامي والمستشار، والإدارة العليا.
                        </p>
                    </div>

                    {/* أزرار التبديل */}
                    <div className="flex justify-center gap-3 mb-10 flex-wrap">
                        <button
                            onClick={() => setActiveTab('clients')}
                            className={`px-6 py-2.5 rounded-xl font-black text-sm transition-all flex items-center gap-2 ${
                                activeTab === 'clients'
                                    ? 'bg-[#0E5C9C] text-white shadow-sm'
                                    : 'bg-white text-slate-600 hover:text-[#0E5C9C] border border-slate-200'
                            }`}
                        >
                            <Users className="w-4 h-4" />
                            <span>بوابة العملاء</span>
                        </button>
                        <button
                            onClick={() => setActiveTab('lawyers')}
                            className={`px-6 py-2.5 rounded-xl font-black text-sm transition-all flex items-center gap-2 ${
                                activeTab === 'lawyers'
                                    ? 'bg-[#0E5C9C] text-white shadow-sm'
                                    : 'bg-white text-slate-600 hover:text-[#0E5C9C] border border-slate-200'
                            }`}
                        >
                            <Scale className="w-4 h-4" />
                            <span>لوحة المحامين والمستشارين</span>
                        </button>
                        <button
                            onClick={() => setActiveTab('admin')}
                            className={`px-6 py-2.5 rounded-xl font-black text-sm transition-all flex items-center gap-2 ${
                                activeTab === 'admin'
                                    ? 'bg-[#0E5C9C] text-white shadow-sm'
                                    : 'bg-white text-slate-600 hover:text-[#0E5C9C] border border-slate-200'
                            }`}
                        >
                            <Building2 className="w-4 h-4" />
                            <span>الإدارة العليا والمكتب</span>
                        </button>
                    </div>

                    {/* محتوى التبويب */}
                    <div className="p-8 sm:p-10 rounded-2xl bg-white border border-slate-200 max-w-4xl mx-auto shadow-sm">
                        {activeTab === 'clients' && (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-8 items-center">
                                <div>
                                    <h3 className="text-2xl font-black text-[#0A2A55] mb-4">خدمات رقمية سلسة للعميل</h3>
                                    <p className="text-sm text-slate-600 leading-relaxed font-medium mb-6">
                                        تتيح بوابة العملاء متابعة كافة قضاياك، حجز الاستشارات المرئية، الدفع الإلكتروني، والتواصل المباشر مع فريقك القانوني في مكان واحد.
                                    </p>
                                    <ul className="space-y-3 text-sm font-bold text-slate-700">
                                        <li className="flex items-center gap-2.5">
                                            <CheckCircle2 className="w-5 h-5 text-[#0E5C9C]" />
                                            <span>دخول فوري برمز OTP دون كلمات مرور</span>
                                        </li>
                                        <li className="flex items-center gap-2.5">
                                            <CheckCircle2 className="w-5 h-5 text-[#0E5C9C]" />
                                            <span>إشعارات فورية عبر SMS والبريد بكل مستجد</span>
                                        </li>
                                        <li className="flex items-center gap-2.5">
                                            <CheckCircle2 className="w-5 h-5 text-[#0E5C9C]" />
                                            <span>سداد الفواتير الإلكترونية المعتمدة بنقرة واحدة</span>
                                        </li>
                                    </ul>
                                </div>
                                <div className="p-6 rounded-xl bg-[#F0F7FF] border border-[#BFDBFE] text-center">
                                    <div className="text-lg font-black text-[#0A2A55] mb-2">ابدأ كعميل الآن</div>
                                    <p className="text-xs text-slate-600 mb-6">استفد من استشارات قانونية متخصصة ومتابعة مستمرة لقضاياك.</p>
                                    <Link
                                        href="/login"
                                        className="inline-block w-full py-3 rounded-xl bg-[#0E5C9C] hover:bg-[#0A2A55] text-white font-black text-sm shadow-sm transition-colors"
                                    >
                                        دخول / إنشاء حساب جديد
                                    </Link>
                                </div>
                            </div>
                        )}

                        {activeTab === 'lawyers' && (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-8 items-center">
                                <div>
                                    <h3 className="text-2xl font-black text-[#0A2A55] mb-4">أدوات ترافع احترافية للمحامي</h3>
                                    <p className="text-sm text-slate-600 leading-relaxed font-medium mb-6">
                                        تنظيم كامل لجدول الجلسات القضائية، مذكرات الدفاع، وإسناد المهام مع مساعد ذكي لصياغة اللوائح القانونية.
                                    </p>
                                    <ul className="space-y-3 text-sm font-bold text-slate-700">
                                        <li className="flex items-center gap-2.5">
                                            <CheckCircle2 className="w-5 h-5 text-[#0E5C9C]" />
                                            <span>تقويم متقدم للجلسات والمواعيد العدلية</span>
                                        </li>
                                        <li className="flex items-center gap-2.5">
                                            <CheckCircle2 className="w-5 h-5 text-[#0E5C9C]" />
                                            <span>تحليل المستندات وتلخيص ملفات القضايا بالذكاء الاصطناعي</span>
                                        </li>
                                        <li className="flex items-center gap-2.5">
                                            <CheckCircle2 className="w-5 h-5 text-[#0E5C9C]" />
                                            <span>إدارة الاستشارات المرئية ومحاضر الاجتماعات</span>
                                        </li>
                                    </ul>
                                </div>
                                <div className="p-6 rounded-xl bg-[#F0F7FF] border border-[#BFDBFE] text-center">
                                    <div className="text-lg font-black text-[#0A2A55] mb-2">منصة المحامي والمستشار</div>
                                    <p className="text-xs text-slate-600 mb-6">تسجيل الدخول المباشر لحسابات الطاقم القانوني المعتمد.</p>
                                    <Link
                                        href="/login"
                                        className="inline-block w-full py-3 rounded-xl bg-[#0E5C9C] hover:bg-[#0A2A55] text-white font-black text-sm shadow-sm transition-colors"
                                    >
                                        دخول المحامي المسؤول
                                    </Link>
                                </div>
                            </div>
                        )}

                        {activeTab === 'admin' && (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-8 items-center">
                                <div>
                                    <h3 className="text-2xl font-black text-[#0A2A55] mb-4">رقابة وحوكمة شاملة للإدارة</h3>
                                    <p className="text-sm text-slate-600 leading-relaxed font-medium mb-6">
                                        لوحة قيادة مركزية لمتابعة الإيرادات، تحديد الأتعاب، إسناد القضايا، ومراقبة أداء الطاقم القانوني وتقارير الامتثال.
                                    </p>
                                    <ul className="space-y-3 text-sm font-bold text-slate-700">
                                        <li className="flex items-center gap-2.5">
                                            <CheckCircle2 className="w-5 h-5 text-[#0E5C9C]" />
                                            <span>مصفوفة صلاحيات تفصيلية وحوكمة دقيقة للموظفين</span>
                                        </li>
                                        <li className="flex items-center gap-2.5">
                                            <CheckCircle2 className="w-5 h-5 text-[#0E5C9C]" />
                                            <span>تقارير مالية، فواتير ضريبية، وتوزيع الإيرادات</span>
                                        </li>
                                        <li className="flex items-center gap-2.5">
                                            <CheckCircle2 className="w-5 h-5 text-[#0E5C9C]" />
                                            <span>أرشفة إلكترونية شاملة لكافة المعاملات والملفات</span>
                                        </li>
                                    </ul>
                                </div>
                                <div className="p-6 rounded-xl bg-[#F0F7FF] border border-[#BFDBFE] text-center">
                                    <div className="text-lg font-black text-[#0A2A55] mb-2">لوحة الإدارة العليا</div>
                                    <p className="text-xs text-slate-600 mb-6">الوصول الكامل لإدارة النظام المالي والتشغيلي للمكتب.</p>
                                    <Link
                                        href="/login"
                                        className="inline-block w-full py-3 rounded-xl bg-[#0A2A55] hover:bg-[#0E5C9C] text-white font-black text-sm shadow-sm transition-colors"
                                    >
                                        دخول الإدارة العليا
                                    </Link>
                                </div>
                            </div>
                        )}
                    </div>

                </div>
            </section>

            {/* ❓ 7. الأسئلة الشائعة */}
            <section id="faq" className="py-20 bg-white border-t border-slate-200">
                <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

                    <div className="text-center max-w-2xl mx-auto mb-14">
                        <div className="text-xs sm:text-sm font-black text-[#0E5C9C] tracking-wider uppercase mb-3">
                            الأسئلة المتكررة
                        </div>
                        <h2 className="text-3xl sm:text-4xl font-black text-[#0A2A55] tracking-tight mb-4">
                            إجابات سريعة على أهم استفساراتك
                        </h2>
                        <p className="text-sm sm:text-base text-slate-600 font-medium">
                            كل ما تحتاج لمعرفته حول استخدام المنظومة وحجز الاستشارات ومتابعة القضايا.
                        </p>
                    </div>

                    <div className="space-y-4">
                        {faqs.map((faq, idx) => (
                            <div
                                key={idx}
                                className="rounded-xl bg-[#F8FAFC] border border-slate-200 overflow-hidden transition-all"
                            >
                                <button
                                    onClick={() => setActiveFaq(activeFaq === idx ? null : idx)}
                                    className="w-full p-5 text-right font-black text-base text-[#0A2A55] flex items-center justify-between gap-4 hover:text-[#0E5C9C] transition-colors"
                                >
                                    <span>{faq.q}</span>
                                    <ChevronDown
                                        className={`w-5 h-5 text-[#0E5C9C] shrink-0 transition-transform duration-200 ${
                                            activeFaq === idx ? 'rotate-180' : ''
                                        }`}
                                    />
                                </button>
                                {activeFaq === idx && (
                                    <div className="px-5 pb-5 text-sm text-slate-600 leading-relaxed border-t border-slate-200 pt-3 font-medium">
                                        {faq.a}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>

                </div>
            </section>

            {/* 🚀 8. الدعوة الختامية */}
            <section className="py-16 bg-[#F8FAFC]">
                <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="rounded-2xl p-8 sm:p-12 bg-[#0A2A55] text-center text-white shadow-lg">
                        <div className="max-w-2xl mx-auto">
                            <h2 className="text-2xl sm:text-3xl lg:text-4xl font-black mb-4 tracking-tight">
                                جاهز لتجربة قانونية ورقمية متطورة؟
                            </h2>
                            <p className="text-sm sm:text-base text-[#E1F2FB] font-medium mb-8 leading-relaxed">
                                سجّل دخولك الآن واستفد من خدمات الاستشارات القانونية، الترافع القضائي، ومتابعة ملفات التنفيذ بكل يسر وسرية.
                            </p>
                            <Link
                                href={user ? (user.home || '/dashboard') : '/login'}
                                className="inline-flex items-center gap-3 px-8 py-3.5 rounded-xl bg-white text-[#0A2A55] font-black text-base shadow-md hover:bg-[#F0F7FF] transition-all"
                            >
                                <span>{user ? 'الانتقال إلى لوحة التحكم' : 'دخول المنظومة الآن (OTP)'}</span>
                                <ArrowLeft className="w-5 h-5 text-[#0A2A55]" />
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            {/* 🏢 9. التذييل الرسمي للموقع (Footer) */}
            <footer className="bg-white border-t border-slate-200 pt-14 pb-10 text-slate-600 text-sm">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 mb-10">

                        {/* معلومات المنظومة */}
                        <div className="space-y-3">
                            <div className="flex items-center gap-3">
                                <div className="w-9 h-9 rounded-lg bg-white border border-slate-200 p-1 flex items-center justify-center shadow-sm">
                                    <img src="/images/logomark.jpg" alt="Logo" className="w-full h-full object-contain rounded" />
                                </div>
                                <span className="text-base font-black text-[#0A2A55]">النظام الإداري لمكاتب المحاماه</span>
                            </div>
                            <p className="text-xs text-slate-500 leading-relaxed font-medium">
                                المنظومة الرقمية الرائدة لإدارة المكاتب العدلية والقانونية، الجلسات القضائية، ملفات التنفيذ، والاستشارات المرئية السحابية.
                            </p>
                        </div>

                        {/* روابط سريعة */}
                        <div>
                            <h4 className="text-[#0A2A55] font-black text-sm mb-3">روابط المنظومة</h4>
                            <ul className="space-y-2 text-xs font-bold text-slate-600">
                                <li><a href="#services" className="hover:text-[#0E5C9C] transition-colors">الخدمات والحلول</a></li>
                                <li><a href="#features" className="hover:text-[#0E5C9C] transition-colors">مميزات المنظومة</a></li>
                                <li><a href="#workflow" className="hover:text-[#0E5C9C] transition-colors">كيف نعمل؟</a></li>
                                <li><a href="#faq" className="hover:text-[#0E5C9C] transition-colors">الأسئلة الشائعة</a></li>
                            </ul>
                        </div>

                        {/* الخدمات القانونية */}
                        <div>
                            <h4 className="text-[#0A2A55] font-black text-sm mb-3">الخدمات القضائية</h4>
                            <ul className="space-y-2 text-xs font-bold text-slate-600">
                                <li><Link href="/login" className="hover:text-[#0E5C9C] transition-colors">الترافع وإدارة القضايا</Link></li>
                                <li><Link href="/login" className="hover:text-[#0E5C9C] transition-colors">الاستشارات القانونية المرئية</Link></li>
                                <li><Link href="/login" className="hover:text-[#0E5C9C] transition-colors">متابعة ملفات التنفيذ</Link></li>
                                <li><Link href="/login" className="hover:text-[#0E5C9C] transition-colors">صياغة اللوائح والعقود</Link></li>
                            </ul>
                        </div>

                        {/* معلومات الأمان والدعم */}
                        <div>
                            <h4 className="text-[#0A2A55] font-black text-sm mb-3">الأمان والموثوقية</h4>
                            <p className="text-xs text-slate-500 leading-relaxed mb-2 font-medium">
                                🔒 نظام مشفر ومعتمد ومحمي بالمصادقة الآمنة OTP لضمان أعلى مستويات السرية المهنية.
                            </p>
                            <div className="text-xs text-slate-400 font-mono">
                                المملكة العربية السعودية · الرياض
                            </div>
                        </div>

                    </div>

                    <div className="pt-6 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500 font-medium">
                        <div>
                            جميع الحقوق محفوظة © {new Date().getFullYear()} للنظام الإداري لمكاتب المحاماه.
                        </div>
                        <div className="flex items-center gap-4 font-bold text-slate-600">
                            <span>امتثال قانوني كامل</span>
                            <span>·</span>
                            <span>حماية وسرية تامة للبيانات</span>
                        </div>
                    </div>
                </div>
            </footer>

        </div>
    );
}
