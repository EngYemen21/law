<?php

use App\Http\Controllers\Admin\AccountingController as AdminAccountingController;
use App\Http\Controllers\Admin\AiBlindReviewController as AdminAiBlindReviewController;
use App\Http\Controllers\Admin\AiOpsController as AdminAiOpsController;
use App\Http\Controllers\Admin\AiReviewController as AdminAiReviewController;
use App\Http\Controllers\Admin\ArchiveController as AdminArchiveController;
use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\CaseController as AdminCaseController;
use App\Http\Controllers\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Admin\ClientNotifController as AdminClientNotifController;
use App\Http\Controllers\Admin\DistributeController as AdminDistributeController;
use App\Http\Controllers\Admin\LawyerController as AdminLawyerController;
use App\Http\Controllers\Admin\LegalSourceController as AdminLegalSourceController;
use App\Http\Controllers\Admin\PriceController as AdminPriceController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TaskController as AdminTaskController;
use App\Http\Controllers\Admin\TicketController as AdminTicketController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\ConsultBookingController;
use App\Http\Controllers\ConsultController;
use App\Http\Controllers\CorrespondenceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Employee\CalendarController as EmployeeCalendarController;
use App\Http\Controllers\Employee\CaseController as EmployeeCaseController;
use App\Http\Controllers\Employee\ScheduleController as EmployeeScheduleController;
use App\Http\Controllers\Employee\TicketController as EmployeeTicketController;
use App\Http\Controllers\Employee\TransferController as EmployeeTransferController;
use App\Http\Controllers\ExecFlowController;
// use App\Http\Controllers\ImpersonationController; // أُلغيت معاينة اللوحات (الإمبرسنيشن) بقرار 2026-08-28
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\Lawyer\AssistantController as LawyerAssistantController;
use App\Http\Controllers\Lawyer\CalendarController as LawyerCalendarController;
use App\Http\Controllers\Lawyer\CaseController as LawyerCaseController;
use App\Http\Controllers\Lawyer\DocumentController as LawyerDocumentController;
use App\Http\Controllers\Lawyer\TaskController as LawyerTaskController;
use App\Http\Controllers\Lawyer\TicketController as LawyerTicketController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MeetRequestController;
use App\Http\Controllers\MoyasarWebhookController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Staff\ConsultController as StaffConsultController;
use App\Http\Controllers\Staff\MeetingController as StaffMeetingController;
use App\Http\Controllers\Staff\MeetRequestController as StaffMeetRequestController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\ZoomController;
use App\Http\Controllers\ZoomWebhookController;
use Illuminate\Support\Facades\Route;

// ── عام (بدون مصادقة) ──
Route::inertia('/', 'welcome')->name('home');

// مستقبِل أحداث Zoom (Webhooks) — عام، محميّ بتوقيع HMAC ومستثنى من CSRF (Zoom لا يرسل رمزاً)
Route::post('/webhooks/zoom', [ZoomWebhookController::class, 'handle'])->name('webhooks.zoom');

// مستقبِل إشعارات Moyasar (Webhooks) — عام، محميّ بـsecret_token ومستثنى من CSRF
Route::post('/webhooks/moyasar', [MoyasarWebhookController::class, 'handle'])->name('webhooks.moyasar');

// موجز التقويم الحي (RFC 5545 iCal Live Subscription Feed) — عام ومحمي برمز أمان فريد لكل مستخدم
Route::get('/calendar/feed/{user}/{token}.ics', [CalendarController::class, 'feed'])->name('calendar.feed');

// المصادقة — دخول برقم الهويّة + رمز SMS (OTP)، بلا كلمة مرور
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    // تسجيل الدخول (خطوتان): طلب الرمز ثمّ تأكيده
    Route::post('/auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request')->name('auth.otp.request');
    Route::post('/auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify')->name('auth.otp.verify');
    // إعادة إرسال الرمز (دخول أو تسجيل) — من بيانات الجلسة
    Route::post('/auth/otp/resend', [AuthController::class, 'resend'])->middleware('throttle:otp-request')->name('auth.otp.resend');
    // تسجيل عميل جديد: بيانات → تأكيد الجوال (تقنيات) → تأكيد البريد (Resend) → إنشاء
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:otp-request')->name('auth.register');
    Route::post('/auth/register/verify-phone', [AuthController::class, 'verifyRegisterPhone'])->middleware('throttle:otp-verify')->name('auth.register.verify-phone');
    Route::post('/auth/register/verify-email', [AuthController::class, 'verifyRegisterEmail'])->middleware('throttle:otp-verify')->name('auth.register.verify-email');
    // اختيار الحساب بعد الرمز حين تطابق الهُويّة عدّة حسابات لنفس الشخص
    Route::post('/auth/choose-account', [AuthController::class, 'chooseAccount'])->middleware('throttle:otp-verify')->name('auth.choose-account');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
// تبديل الحساب داخل المنصّة — بين حسابات نفس الشخص (نفس الهُويّة + الجوال)
Route::post('/auth/switch-account', [AuthController::class, 'switchAccount'])->middleware(['auth', 'active', 'throttle:otp-verify'])->name('auth.switch-account');

// تدفّق طلب التنفيذ (المرحلة 2) — تقديم العميل + موزّع الإجراءات (يحرس الدور/الملكيّة داخليّاً)
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/exec-flow', [ExecFlowController::class, 'store'])->name('exec-flow.store');
    Route::post('/exec-flow/{execution}/action', [ExecFlowController::class, 'act'])->name('exec-flow.act');
    Route::post('/exec-flow/{execution}/pay', [ExecFlowController::class, 'pay'])->name('exec-flow.pay');
    Route::get('/exec-flow/{execution}/pay/callback', [ExecFlowController::class, 'payCallback'])->name('exec-flow.pay.callback');
    // محادثة ملفّ التنفيذ + رفع المستندات المطلوبة (يحرسان دور العميل/الملكيّة داخليّاً)
    Route::post('/exec-flow/{execution}/messages', [ExecFlowController::class, 'message'])->name('exec-flow.messages.store');
    Route::post('/exec-flow/{execution}/attach', [ExecFlowController::class, 'attach'])->name('exec-flow.attach');
    Route::post('/exec-flow/{execution}/documents/{document}', [ExecFlowController::class, 'uploadDocument'])->name('exec-flow.documents.upload');
    Route::post('/exec-flow/{execution}/documents/{document}/review', [ExecFlowController::class, 'reviewDocument'])->name('exec-flow.documents.review');
    Route::get('/exec-flow/{execution}/documents/{document}/download', [ExecFlowController::class, 'downloadDocument'])->name('exec-flow.documents.download');
    Route::get('/exec-flow/{execution}/offer.pdf', [ExecFlowController::class, 'offerPdf'])->name('exec-flow.offer.pdf');
    // تقرير/ملخص الاستشارة — متاح للعميل صاحبها ولأدوار المكتب (الحارس داخل ConsultController::report)
    // طباعة نصّ المخاطبة PDF (الحارس داخل letterPdf: المالك/الطاقم/المحامي المسند)
    Route::get('/correspondences/{correspondence}/letter.pdf', [CorrespondenceController::class, 'letterPdf'])->name('correspondences.letter.pdf');
    Route::get('/consults/{consult}/report.pdf', [ConsultController::class, 'report'])->name('consults.report');
    Route::get('/consults/{consult}/report', [ConsultController::class, 'report'])->name('consults.report.plain');
});

// ── منصة العميل (دور العميل) — تطابق 1:1 مع index (82).html ──
Route::middleware(['auth', 'active', 'role:client'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'client'])->name('dashboard');

    // طلباتي — التذاكر مربوطة بقاعدة البيانات (متحكم)
    Route::inertia('/tickets/new', 'newticket')->name('tickets.new');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/messages', [TicketController::class, 'storeMessage'])->name('tickets.messages.store');
    Route::post('/tickets/{ticket}/attach', [TicketController::class, 'attach'])->name('tickets.attach');
    Route::get('/tickets/{ticket}/availability', [TicketController::class, 'availability'])->name('tickets.availability');
    Route::post('/tickets/{ticket}/book', [TicketController::class, 'book'])->name('tickets.book');
    // القضايا (مربوطة بقاعدة البيانات)
    Route::get('/cases', [CaseController::class, 'index'])->name('cases');
    Route::get('/cases/{case}', [CaseController::class, 'show'])->name('cases.show');
    Route::post('/cases/{case}/messages', [CaseController::class, 'storeMessage'])->name('cases.messages.store');
    Route::post('/cases/{case}/attach', [CaseController::class, 'attach'])->name('cases.attach');
    Route::post('/cases/{case}/pay', [CaseController::class, 'pay'])->name('cases.pay');
    Route::get('/cases/{case}/pay/callback', [CaseController::class, 'payCallback'])->name('cases.pay.callback');
    Route::post('/cases/{case}/pay-installment', [CaseController::class, 'payInstallment'])->name('cases.pay-installment');

    // التنفيذ — تبويب موحّد (تدفّق + تنفيذات قديمة) على مسار /execs
    Route::get('/execs', [ExecFlowController::class, 'client'])->name('execs');
    // توافق خلفيّ: المسار القديم يُحوّل للتبويب الموحّد
    Route::redirect('/exec-preview', '/execs')->name('exec.preview');

    // الاستشارات — «استشاراتي» مربوطة بقاعدة البيانات؛ الجلسات المرئية عبر Zoom
    Route::get('/book', [ConsultBookingController::class, 'index'])->name('book');
    Route::get('/book/availability', [ConsultBookingController::class, 'availability'])->name('book.availability');
    Route::post('/book', [ConsultBookingController::class, 'store'])->name('book.store');
    Route::get('/myconsults', [ConsultController::class, 'index'])->name('myconsults');
    // دورة الحجز المطابقة للتصميم: دفع محاكى (يفتح اختيار الموعد) ثم جدولة الموعد بعد السداد
    Route::post('/consults/{consult}/pay', [ConsultController::class, 'pay'])->name('consults.pay');
    Route::get('/consults/{consult}/pay/callback', [ConsultController::class, 'payCallback'])->name('consults.pay.callback');
    Route::post('/consults/{consult}/schedule', [ConsultController::class, 'schedule'])->name('consults.schedule');
    Route::get('/consults/room', [ConsultController::class, 'room'])->name('consults.room');
    // التبويب الزمني موحّد في /calendar. المسار يُحوَّل ولا يُحذف: بريد تأكيد الموعد
    // وإشعارات سابقة تشير إليه، وحذفه يعطي 404 لكل من يفتح رسالة قديمة.
    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments');
    Route::get('/appointments/{appointment}/card.pdf', [AppointmentController::class, 'card'])->name('appointments.card');
    Route::get('/appointments/{appointment}/card', [AppointmentController::class, 'card'])->name('appointments.card.plain');
    Route::get('/meetings', [MeetingController::class, 'index'])->name('meetings');
    Route::get('/meetingroom', [MeetingController::class, 'room'])->name('meetingroom');
    // دعوات الاجتماعات (مربوطة بقاعدة البيانات — تُنشر مؤكَّدة بعد موافقة الإدارة؛ تأكيد العميل مُلغى)
    // تبويب الدعوات أُلغي — المسار يُحوّل إلى «الاجتماعات» (بريد الدعوة القديم يشير إليه)
    Route::get('/meetreqs', [MeetRequestController::class, 'index'])->name('meetreqs');
    // قناتا العميل لطلب إعادة الجدولة (فحص الأزرار 2026-08-26): الفائتة كانت نصاً ميتاً بلا زرّ
    Route::post('/consults/{consult}/reschedule-request', [ConsultController::class, 'rescheduleRequest'])->name('consults.reschedule-request');
    Route::post('/meetings/{meeting}/change-request', [MeetingController::class, 'changeRequest'])->name('meetings.change-request');
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
    // مخاطباتي — رحلة المخاطبة المبسّطة + طلب إفادة رسميّة
    Route::get('/mycorr', [CorrespondenceController::class, 'mine'])->name('mycorr');
    Route::post('/correspondences/{correspondence}/request-brief', [CorrespondenceController::class, 'requestBrief'])->name('correspondences.request-brief');
    // إفادة العميل PDF — رسمية ومُصيَّرة خادمياً (بدل طباعة متصفح مرتجلة تفشل صامتاً)
    Route::get('/correspondences/{correspondence}/brief.pdf', [CorrespondenceController::class, 'briefPdf'])->name('correspondences.brief.pdf');

    // الملفات والمالية (مربوطة بقاعدة البيانات)
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/download-file', [DocumentController::class, 'downloadFile'])->name('documents.download-file');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices');
    Route::post('/invoices/{invoice}/proof', [InvoiceController::class, 'uploadProof'])->name('invoices.proof');
    Route::post('/invoices/{invoice}/checkout', [InvoiceController::class, 'checkout'])->name('invoices.checkout');
    Route::get('/invoices/{invoice}/checkout/callback', [InvoiceController::class, 'checkoutCallback'])->name('invoices.checkout.callback');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
});

// الحساب — متاح لأي مستخدم مسجّل
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::inertia('/profile', 'profile')->name('profile');
    Route::post('/profile', [ProfileController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    // تأكيد الجوال الجديد — الرقم لا يُكتب إلا هنا (عامل المصادقة الوحيد)
    Route::post('/profile/phone/verify', [ProfileController::class, 'verifyPhoneChange'])
        ->middleware('throttle:otp-verify')->name('profile.phone.verify');
    // أُلغيت معاينة لوحة الموظف (الإمبرسنيشن) بقرار 2026-08-28 — الإدارة مقصورة على لوحتها
    // Route::post('/impersonate/leave', [ImpersonationController::class, 'leave'])->name('impersonate.leave');
    // توقيع تضمين Zoom (Meeting SDK) — متاح للعميل والموظف؛ التفويض في المتحكّم عبر ChannelAccess
    Route::post('/zoom/sdk-signature', [ZoomController::class, 'sdkSignature'])->name('zoom.signature');
});

// ── لوحة الموظف ── (deny-by-default: صلاحية صريحة لكل إجراء حسّاس فوق حارس الدور)
Route::middleware(['auth', 'active', 'role:employee'])->prefix('employee')->name('employee.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'employee'])->name('dashboard'); // عام للدور

    // صندوق مراجعة مخرجات الذكاء — الشاشة نفسها لكل دور، والعزل داخل AiReviewInbox:
    // الموظّف يرى الفرز والاستشارات وفحص المستندات، لا المسودّات ولا الملخّصات.
    //
    // **الحارس تشغيليّ لا اعتماديّ.** كان `اعتماد الملخصات` — وهي صلاحيةٌ لا يملكها
    // دور الموظّف في هذا النظام، فكان الفرع معطَّلاً عملياً: لا موظّف يفتح صندوقه.
    // ومنحُها له كان سيوسّع وصوله إلى **اعتماد الملخّصات القانونيّة** وهو ما لا يفعله.
    // فالحكم على «أهذا المستند ذو صلة؟» عملٌ تشغيليّ من صميم إدارة التذاكر، ويختلف
    // عن اعتماد رأيٍ قانونيّ — والعزل داخل الصندوق يمنعه من رؤية الثاني أصلاً.
    Route::middleware('permission:إدارة التذاكر')->group(function () {
        Route::get('/ai-review', [AdminAiReviewController::class, 'index'])->name('ai-review');
        Route::post('/ai-review/{run}/decide', [AdminAiReviewController::class, 'decide'])->name('ai-review.decide');
    });

    // التذاكر — إدارة التذاكر (والرد على العملاء لمسار الردّ)
    Route::middleware('permission:إدارة التذاكر')->group(function () {
        Route::get('/tickets', [EmployeeTicketController::class, 'index'])->name('tickets');
        Route::get('/tickets/{ticket}', [EmployeeTicketController::class, 'show'])->name('tickets.show');
        Route::post('/tickets/{ticket}/note', [EmployeeTicketController::class, 'note'])->name('tickets.note');
        Route::post('/tickets/{ticket}/status', [EmployeeTicketController::class, 'status'])->name('tickets.status');
        Route::post('/tickets/{ticket}/advance', [EmployeeTicketController::class, 'advance'])->name('tickets.advance');
        Route::post('/tickets/{ticket}/rerun', [EmployeeTicketController::class, 'rerunSummary'])->name('tickets.rerun');
        Route::post('/tickets/{ticket}/convert', [EmployeeTicketController::class, 'convertToCase'])->name('tickets.convert');
        // تحويل التذكرة إلى طلب استشارة (يطابق convertToConsult المرجعي) — ينشئ طلب تسعير للعميل
        Route::post('/tickets/{ticket}/convert-consult', [EmployeeTicketController::class, 'convertToConsult'])->name('tickets.convert-consult');
    });
    Route::post('/tickets/{ticket}/reply', [EmployeeTicketController::class, 'reply'])
        ->middleware('permission:الرد على العملاء')->name('tickets.reply');
    Route::post('/tickets/{ticket}/attach', [EmployeeTicketController::class, 'attach'])
        ->middleware('permission:الرد على العملاء')->name('tickets.attach');
    Route::post('/tickets/{ticket}/request-docs', [EmployeeTicketController::class, 'requestDocs'])
        ->middleware('permission:الرد على العملاء')->name('tickets.reqdocs');

    // القضايا وطلبات التنفيذ — إدارة القضايا والأتعاب
    Route::middleware('permission:إدارة القضايا والأتعاب')->group(function () {
        Route::get('/cases', [EmployeeCaseController::class, 'index'])->name('cases');
        Route::get('/cases/{case}', [EmployeeCaseController::class, 'show'])->name('cases.show');
        Route::post('/cases/{case}/reply', [EmployeeCaseController::class, 'reply'])->name('cases.reply');
        Route::post('/cases/{case}/attach', [EmployeeCaseController::class, 'attach'])->name('cases.attach');
        // التنفيذ — تبويب موحّد (استقبال/إحالة) لدور الموظف
        Route::get('/execs', [ExecFlowController::class, 'employee'])->name('execs');
        Route::redirect('/exec-preview', '/employee/execs')->name('exec.preview');
    });

    // رحلة الاستشارة + الاستقبال + الغرفة — استقبال الاستشارات
    Route::middleware('permission:استقبال الاستشارات')->group(function () {
        Route::get('/consults', [StaffConsultController::class, 'index'])->name('consults');
        Route::get('/consult', [StaffConsultController::class, 'show'])->name('consult');
        Route::post('/consults/{consult}/take', [StaffConsultController::class, 'take'])->name('consults.take');
        Route::post('/consults/{consult}/reqdocs', [StaffConsultController::class, 'requestDocs'])->name('consults.reqdocs');
        Route::post('/consults/{consult}/analyze', [StaffConsultController::class, 'analyze'])->name('consults.analyze');
        Route::post('/consults/{consult}/analysis', [StaffConsultController::class, 'saveAnalysis'])->name('consults.analysis');
        Route::post('/consults/{consult}/approve', [StaffConsultController::class, 'approveAnalysis'])->name('consults.approve');
        Route::post('/consults/{consult}/zoom-sync', [StaffConsultController::class, 'zoomSync'])->name('consults.zoomsync');
        Route::post('/consults/{consult}/refer', [StaffConsultController::class, 'refer'])->name('consults.refer');
        // **قرار المالك: قراءةٌ فقط للموظّف إلّا بصلاحيّة تمنحها الإدارة العليا.**
        // كان المسار غير مسجَّل بتاتاً بينما تعرض الشاشة المشتركة محرّره، فيقع ٤٠٤
        // صامت. والصلاحيّة هي البوّابة الآن لا الدور — فمن لا يملكها يُصدّ ٤٠٣،
        // ومن منحته الإدارة إيّاها يحرّر. والاعتماد يبقى فعلاً قانونياً بالصلاحيّة نفسها.
        Route::post('/consults/{consult}/summary', [StaffConsultController::class, 'saveSummary'])->name('consults.summary')->middleware('permission:اعتماد/تعديل ملخص الاستشارة');
        Route::post('/consults/{consult}/summary/approve', [StaffConsultController::class, 'approveSummary'])->name('consults.summary.approve')->middleware('permission:اعتماد/تعديل ملخص الاستشارة');
        Route::get('/consultrecv', [StaffConsultController::class, 'recv'])->name('consultrecv');
        Route::post('/consults/{consult}/start', [StaffConsultController::class, 'start'])->name('consults.start');
        Route::post('/consults/{consult}/end', [StaffConsultController::class, 'end'])->name('consults.end');
        Route::post('/consults/{consult}/no-show', [StaffConsultController::class, 'noShow'])->name('consults.noshow');
        Route::post('/consults/{consult}/reschedule', [StaffConsultController::class, 'reschedule'])->name('consults.reschedule');
        Route::post('/consults/{consult}/tasks', [StaffConsultController::class, 'createTasks'])->name('consults.tasks');
    });
    // أيّ من الصلاحيتين تكفي: الغرفة تُفتح من شاشة الاستقبال أيضاً — حصرها بواحدة كان يصدّ حاملي الأخرى
    Route::get('/videoroom', [StaffConsultController::class, 'room'])
        ->middleware('permission:إجراء الجلسات المرئية,استقبال الاستشارات')->name('videoroom');

    Route::middleware('permission:جدولة المواعيد')->group(function () {
        // التبويب الزمني الموحّد: الأحداث + لوحة المواعيد وحجزها في صفحة واحدة
        Route::get('/calendar', [EmployeeCalendarController::class, 'index'])->name('calendar');
        // شاشة الجدولة المستقلّة طُويت في التقويم — تُحوَّل ولا تُحذف (روابط محفوظة/إشعارات)
        Route::get('/schedule', [EmployeeScheduleController::class, 'index'])->name('schedule');
        Route::get('/schedule/slots', [EmployeeScheduleController::class, 'slots'])->name('schedule.slots');
        Route::post('/schedule', [EmployeeScheduleController::class, 'store'])->name('schedule.store');
    });

    Route::middleware('permission:تحويل التذاكر')->group(function () {
        Route::get('/transfer', [EmployeeTransferController::class, 'index'])->name('transfer');
        Route::post('/transfer/bulk', [EmployeeTransferController::class, 'bulkTransfer'])->name('transfer.bulk');
        Route::post('/transfer/{ticket}', [EmployeeTransferController::class, 'transfer'])->name('transfer.do');
    });

    // طلبات الاجتماعات — إرسال دعوات الاجتماعات
    Route::middleware('permission:إرسال دعوات الاجتماعات')->group(function () {
        Route::get('/meetreqs', [StaffMeetRequestController::class, 'index'])->name('meetreqs');
        Route::post('/meetreqs', [StaffMeetRequestController::class, 'store'])->name('meetreqs.store');
        Route::post('/meetreqs/{meetRequest}/cancel', [StaffMeetRequestController::class, 'cancel'])->name('meetreqs.cancel');
        Route::post('/meetreqs/{meetRequest}/start', [StaffMeetRequestController::class, 'start'])->name('meetreqs.start');
        Route::post('/meetreqs/{meetRequest}/resend', [StaffMeetRequestController::class, 'resend'])->name('meetreqs.resend');
        Route::get('/meetreqs/availability', [StaffMeetRequestController::class, 'availability'])->name('meetreqs.availability');
        // غرفة الاجتماع المضمّنة (بعد بدء الدعوة) — داخل الموقع
        Route::get('/meetingroom', [StaffMeetingController::class, 'room'])->name('meetingroom');
        // اجتماعات الموظف (قائمة/تفاصيل/دورة الحياة) — كان الموظف يُشعَر «متاح في لوحتك» بلا أي صفحة (طريق مسدود)؛
        // المتحكم يحرس الوصول أصلاً (guardMeeting/scopedQuery)
        Route::get('/meetings', [StaffMeetingController::class, 'index'])->name('meetings');
        Route::get('/meeting', [StaffMeetingController::class, 'show'])->name('meeting');
        Route::post('/meetings/{meeting}/summary', [StaffMeetingController::class, 'saveSummary'])->name('meetings.summary');
        Route::post('/meetings/{meeting}/minutes', [StaffMeetingController::class, 'saveMinutes'])->name('meetings.minutes');
        Route::post('/meetings/{meeting}/start', [StaffMeetingController::class, 'start'])->name('meetings.start');
        Route::post('/meetings/{meeting}/end', [StaffMeetingController::class, 'end'])->name('meetings.end');
        Route::post('/meetings/{meeting}/reschedule', [StaffMeetingController::class, 'reschedule'])->name('meetings.reschedule');
        Route::post('/meetings/{meeting}/cancel', [StaffMeetingController::class, 'cancel'])->name('meetings.cancel');
        Route::post('/meetings/{meeting}/tasks', [StaffMeetingController::class, 'createTasks'])->name('meetings.tasks');
        Route::get('/meetings/{meeting}/transcript', [StaffMeetingController::class, 'transcript'])->name('meetings.transcript');
        Route::post('/meetings/{meeting}/zoom-sync', [StaffMeetingController::class, 'zoomSync'])->name('meetings.zoomsync');
        Route::get('/meetings/{meeting}/recording.zip', [StaffMeetingController::class, 'recordingZip'])->name('meetings.recording');
        Route::get('/meetings/{meeting}/audio.zip', [StaffMeetingController::class, 'audioZip'])->name('meetings.audio');
    });
});

// ── لوحة المحامي ── (deny-by-default: صلاحية صريحة لكل إجراء حسّاس فوق حارس الدور)
Route::middleware(['auth', 'active', 'role:lawyer'])->prefix('lawyer')->name('lawyer.')->group(function () {
    Route::get('/dashboard', [LawyerTicketController::class, 'dashboard'])->name('dashboard'); // عام للدور
    // التذاكر المحالة — عرض عام للمحامي؛ الإجراءات الحسّاسة مُصرَّحة أدناه
    Route::get('/tickets', [LawyerTicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{ticket}', [LawyerTicketController::class, 'show'])->name('tickets.show');
    // ردّ المستشار المباشر على العميل (يطابق lwReply المرجعي) + ملاحظته الداخلية — guardAssigned يحصرهما بالمُسنَد
    Route::post('/tickets/{ticket}/reply', [LawyerTicketController::class, 'reply'])->name('tickets.reply');
    Route::post('/tickets/{ticket}/note', [LawyerTicketController::class, 'note'])->name('tickets.note');
    Route::get('/calendar', [LawyerCalendarController::class, 'index'])->name('calendar');

    // الملخصات والاعتماد — اعتماد الملخصات
    Route::middleware('permission:اعتماد الملخصات')->group(function () {
        Route::get('/summaries', [LawyerTicketController::class, 'summaries'])->name('summaries');
        Route::get('/summary/{ticket}', [LawyerTicketController::class, 'showSummary'])->name('summary');
        Route::post('/summary/{ticket}', [LawyerTicketController::class, 'updateSummary'])->name('summary.update');
        Route::post('/summary/{ticket}/approve', [LawyerTicketController::class, 'approveSummary'])->name('summary.approve');
        Route::post('/summary/{ticket}/rerun', [LawyerTicketController::class, 'rerunSummary'])->name('summary.rerun');
        Route::post('/summary/{ticket}/najiz', [LawyerTicketController::class, 'generateNajizDraft'])->name('summary.najiz');
        Route::get('/summary/{ticket}/print', [LawyerTicketController::class, 'printSummary'])->name('summary.print');
        // صندوق مراجعة مخرجات الذكاء — الشاشة نفسها لكل دور، والعزل داخل
        // AiReviewInbox: المحامي يرى ما صُعِّد إليه، والموظّف التذاكر والاستشارات.
        // P3 تفرض توحيد المراجعة «للمحامي والموظف»، فحصرها في لوحة الإدارة يناقضها.
        Route::get('/ai-review', [AdminAiReviewController::class, 'index'])->name('ai-review');
        Route::post('/ai-review/{run}/decide', [AdminAiReviewController::class, 'decide'])->name('ai-review.decide');

        // الطبقة الثالثة: **المحامي** هو من تفرض الخطة أن يراجع العيّنة العمياء،
        // فحصرُها في لوحة الإدارة يجعل الفعل الموصوف مستحيلاً على صاحبه.
        Route::get('/ai-blind-review', [AdminAiBlindReviewController::class, 'index'])->name('ai-blind-review');
        Route::post('/ai-blind-review/draw', [AdminAiBlindReviewController::class, 'draw'])->name('ai-blind-review.draw');
        Route::post('/ai-blind-review/{review}/judge', [AdminAiBlindReviewController::class, 'judge'])->name('ai-blind-review.judge');

        Route::post('/tickets/{ticket}/result', [LawyerTicketController::class, 'approveResult'])->name('result.approve');
    });

    // التحويل لقضية + دورة القضية + التنفيذ + المهام — إدارة القضايا والأتعاب
    Route::middleware('permission:إدارة القضايا والأتعاب')->group(function () {
        Route::post('/tickets/{ticket}/convert', [LawyerTicketController::class, 'convertToCase'])->name('tickets.convert');
        Route::post('/tickets/{ticket}/close', [LawyerTicketController::class, 'closeWithoutCase'])->name('tickets.close');
        Route::post('/tickets/{ticket}/request-docs', [LawyerTicketController::class, 'requestDocs'])->name('tickets.reqdocs');
        Route::get('/cases', [LawyerCaseController::class, 'index'])->name('cases');
        Route::get('/cases/{case}', [LawyerCaseController::class, 'show'])->name('cases.show');
        // تنزيل مستند قضية/تذكرة — المحامي المسنَد وحده (والإدارة إشرافاً). لم يكن للطاقم
        // مسار تنزيل إطلاقاً: يرى أنّ مستنداً رُفع ويقرأ ملخّصه ولا يفتحه.
        Route::get('/documents/{type}/{id}/download', [LawyerDocumentController::class, 'download'])
            ->whereIn('type', ['case', 'ticket'])->whereNumber('id')
            ->name('documents.download');
        Route::post('/cases/{case}/pleading', [LawyerCaseController::class, 'approvePleading'])->name('cases.pleading');
        Route::post('/cases/{case}/reply', [LawyerCaseController::class, 'reply'])->name('cases.reply');
        Route::post('/cases/{case}/attach', [LawyerCaseController::class, 'attach'])->name('cases.attach');
        Route::post('/cases/{case}/hearings', [LawyerCaseController::class, 'addHearing'])->name('cases.hearings.add');
        Route::post('/cases/{case}/hearings/{hearing}', [LawyerCaseController::class, 'recordHearing'])->name('cases.hearings.record');
        Route::post('/cases/{case}/hearings/{hearing}/update', [LawyerCaseController::class, 'updateHearing'])->name('cases.hearings.update');
        Route::post('/cases/{case}/hearings/{hearing}/cancel', [LawyerCaseController::class, 'cancelHearing'])->name('cases.hearings.cancel');
        Route::post('/cases/{case}/ruling', [LawyerCaseController::class, 'recordRuling'])->name('cases.ruling');
        Route::post('/cases/{case}/execute', [LawyerCaseController::class, 'convertToExecution'])->name('cases.execute');
        // التنفيذ — تبويب موحّد (تدفّق + تنفيذات قديمة) لدور المحامي، محصور بالمسند إليه/القابل للالتقاط
        Route::get('/execs', [ExecFlowController::class, 'lawyer'])->name('execs');
        Route::redirect('/exec-preview', '/lawyer/execs')->name('exec.preview');
        Route::get('/tasks', [LawyerTaskController::class, 'index'])->name('tasks');
        Route::post('/tasks', [LawyerTaskController::class, 'store'])->name('tasks.store');
        Route::post('/tasks/{task}/complete', [LawyerTaskController::class, 'complete'])->name('tasks.complete');
    });

    // الاجتماعات — إدارة الاجتماعات
    // الغرفة تُفتح من دعوات الاجتماعات أيضاً — أيّ من الصلاحيتين تكفي (كانت تصدّ محامي الدعوات وحدها)
    Route::get('/meetingroom', [StaffMeetingController::class, 'room'])
        ->middleware('permission:إدارة الاجتماعات,إرسال دعوات الاجتماعات')->name('meetingroom');
    Route::middleware('permission:إدارة الاجتماعات')->group(function () {
        Route::get('/meetings', [StaffMeetingController::class, 'index'])->name('meetings');
        Route::get('/meeting', [StaffMeetingController::class, 'show'])->name('meeting');
        Route::post('/meetings/{meeting}/summary', [StaffMeetingController::class, 'saveSummary'])->name('meetings.summary');
        Route::post('/meetings/{meeting}/minutes', [StaffMeetingController::class, 'saveMinutes'])->name('meetings.minutes');
        Route::post('/meetings/{meeting}/start', [StaffMeetingController::class, 'start'])->name('meetings.start');
        Route::post('/meetings/{meeting}/end', [StaffMeetingController::class, 'end'])->name('meetings.end');
        Route::post('/meetings/{meeting}/reschedule', [StaffMeetingController::class, 'reschedule'])->name('meetings.reschedule');
        Route::post('/meetings/{meeting}/cancel', [StaffMeetingController::class, 'cancel'])->name('meetings.cancel');
        Route::post('/meetings/{meeting}/tasks', [StaffMeetingController::class, 'createTasks'])->name('meetings.tasks');
        Route::get('/meetings/{meeting}/transcript', [StaffMeetingController::class, 'transcript'])->name('meetings.transcript');
        Route::post('/meetings/{meeting}/zoom-sync', [StaffMeetingController::class, 'zoomSync'])->name('meetings.zoomsync');
        Route::get('/meetings/{meeting}/recording.zip', [StaffMeetingController::class, 'recordingZip'])->name('meetings.recording');
        Route::get('/meetings/{meeting}/audio.zip', [StaffMeetingController::class, 'audioZip'])->name('meetings.audio');
    });

    // طلبات الاجتماعات — إرسال دعوات الاجتماعات
    Route::middleware('permission:إرسال دعوات الاجتماعات')->group(function () {
        Route::get('/meetreqs', [StaffMeetRequestController::class, 'index'])->name('meetreqs');
        Route::post('/meetreqs', [StaffMeetRequestController::class, 'store'])->name('meetreqs.store');
        Route::post('/meetreqs/{meetRequest}/cancel', [StaffMeetRequestController::class, 'cancel'])->name('meetreqs.cancel');
        Route::post('/meetreqs/{meetRequest}/start', [StaffMeetRequestController::class, 'start'])->name('meetreqs.start');
        Route::post('/meetreqs/{meetRequest}/resend', [StaffMeetRequestController::class, 'resend'])->name('meetreqs.resend');
        Route::get('/meetreqs/availability', [StaffMeetRequestController::class, 'availability'])->name('meetreqs.availability');
    });

    // المساعد القانوني ومختبر الصياغة والتحليل — تسجيل واحد محروس بالصلاحية.
    // كان مسجّلاً مرتين بنفس الاسم (نسخة بلا حراسة أعلى الملف): المطابقة تأخذ الأولى
    // فيُبطَل هذا الحارس، و«artisan route:cache» يفشل بـAnother route has already been assigned name.
    Route::middleware('permission:المساعد القانوني')->group(function () {
        Route::get('/assistant', [LawyerAssistantController::class, 'index'])->name('assistant');
        Route::post('/assistant/generate', [LawyerAssistantController::class, 'generate'])->name('assistant.generate');

        // اعتماد المصادر القانونيّة: **المحامي المسؤول** هو من يعتمد كما تنصّ الخطة.
        // حصرُه في لوحة الإدارة يجعل الفعل القانونيّ بيد غير أهله — والاعتماد يُسجَّل
        // باسم من ضغط الزرّ، فلا يصحّ أن يكون غير المحامي.
        Route::get('/legal-sources', [AdminLegalSourceController::class, 'index'])->name('legal-sources');
        Route::post('/legal-sources/{source}/approve', [AdminLegalSourceController::class, 'approve'])->name('legal-sources.approve');
        Route::post('/legal-sources/approve-system', [AdminLegalSourceController::class, 'approveSystem'])->name('legal-sources.approve-system');
        Route::post('/legal-sources/{source}/suspend', [AdminLegalSourceController::class, 'suspend'])->name('legal-sources.suspend');
    });

    // المخاطبات الرسميّة (المحامي بمخاطباته المسندة)
    Route::middleware('permission:المخاطبات')->group(function () {
        Route::get('/correspondences', [CorrespondenceController::class, 'index'])->name('correspondences');
        Route::post('/correspondences', [CorrespondenceController::class, 'store'])->name('correspondences.store');
        Route::get('/correspondences/{correspondence}', [CorrespondenceController::class, 'show'])->name('correspondences.show');
        Route::post('/correspondences/{correspondence}/advance', [CorrespondenceController::class, 'advance'])->name('correspondences.advance');
        Route::post('/correspondences/{correspondence}/sync', [CorrespondenceController::class, 'sync'])->name('correspondences.sync');
        Route::post('/correspondences/{correspondence}/receive', [CorrespondenceController::class, 'receive'])->name('correspondences.receive');
        Route::post('/correspondences/{correspondence}/brief', [CorrespondenceController::class, 'brief'])->name('correspondences.brief');
        Route::post('/correspondences/{correspondence}/close', [CorrespondenceController::class, 'close'])->name('correspondences.close');
    });

    // استقبال الاستشارات + رحلة الاستشارة + الغرفة — استقبال الاستشارات (والغرفة تحتاج إجراء الجلسات)
    Route::middleware('permission:استقبال الاستشارات')->group(function () {
        Route::get('/consults', [StaffConsultController::class, 'index'])->name('consults');
        Route::get('/consultrecv', [StaffConsultController::class, 'recv'])->name('consultrecv');
        Route::get('/consult', [StaffConsultController::class, 'show'])->name('consult');
        Route::post('/consults/{consult}/start', [StaffConsultController::class, 'start'])->name('consults.start');
        Route::post('/consults/{consult}/end', [StaffConsultController::class, 'end'])->name('consults.end');
        Route::post('/consults/{consult}/no-show', [StaffConsultController::class, 'noShow'])->name('consults.noshow');
        Route::post('/consults/{consult}/reschedule', [StaffConsultController::class, 'reschedule'])->name('consults.reschedule');
        Route::post('/consults/{consult}/take', [StaffConsultController::class, 'take'])->name('consults.take');
        Route::post('/consults/{consult}/reqdocs', [StaffConsultController::class, 'requestDocs'])->name('consults.reqdocs');
        Route::post('/consults/{consult}/analyze', [StaffConsultController::class, 'analyze'])->name('consults.analyze');
        Route::post('/consults/{consult}/analysis', [StaffConsultController::class, 'saveAnalysis'])->name('consults.analysis');
        // تحرير ملخّص الجلسة قبل اعتماده — «تعديل واعتماد» كان خياراً بلا حقلٍ يستقبله
        Route::post('/consults/{consult}/summary', [StaffConsultController::class, 'saveSummary'])->name('consults.summary')->middleware('permission:اعتماد/تعديل ملخص الاستشارة');
        // الاعتماد من شاشة الملفّ — لملخّصٍ لا قيد له في `ai_runs` فلا يبلغ الصندوق أبداً.
        Route::post('/consults/{consult}/summary/approve', [StaffConsultController::class, 'approveSummary'])->name('consults.summary.approve')->middleware('permission:اعتماد/تعديل ملخص الاستشارة');
        Route::post('/consults/{consult}/approve', [StaffConsultController::class, 'approveAnalysis'])->name('consults.approve');
        Route::post('/consults/{consult}/zoom-sync', [StaffConsultController::class, 'zoomSync'])->name('consults.zoomsync');
        Route::post('/consults/{consult}/refer', [StaffConsultController::class, 'refer'])->name('consults.refer');
        Route::post('/consults/{consult}/tasks', [StaffConsultController::class, 'createTasks'])->name('consults.tasks');
    });
    // أيّ من الصلاحيتين تكفي: الغرفة تُفتح من شاشة الاستقبال أيضاً — حصرها بواحدة كان يصدّ حاملي الأخرى
    Route::get('/videoroom', [StaffConsultController::class, 'room'])
        ->middleware('permission:إجراء الجلسات المرئية,استقبال الاستشارات')->name('videoroom');
});

// ── لوحة الإدارة ── (الإدارة تتجاوز الصلاحيات عبر Gate::before؛ الحماية بالدور)
Route::middleware(['auth', 'active', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'admin'])->name('dashboard');
    Route::post('/reset-database', [DashboardController::class, 'resetDatabase'])->name('reset-database');
    Route::get('/clients', [AdminClientController::class, 'index'])->name('clients');
    Route::get('/clients/{client}', [AdminClientController::class, 'show'])->name('clients.show');
    // نظائر admin لتنزيلات ملف العميل وPDF الفاتورة — كانت روابط الإدارة تمرّ عبر بوابة
    // دور العميل (قرار 2026-08-28: مسارات خاصة بالأدمن؛ التفويض داخل المتحكّمَين يسمح للإدارة)
    Route::get('/documents/download-file', [DocumentController::class, 'downloadFile'])->name('documents.download-file');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::put('/clients/{client}', [AdminClientController::class, 'update'])->name('clients.update');
    Route::post('/clients/{client}/toggle', [AdminClientController::class, 'toggle'])->name('clients.toggle');
    // التقويم والمواعيد — لوحة الإدارة كانت بلا أي تبويب زمني. نفس متحكّم الموظف
    // (نطاق المكتب نفسه)، نظير توجيه تذاكر الإدارة إلى متحكّم المستشار أدناه.
    Route::get('/calendar', [EmployeeCalendarController::class, 'index'])->name('calendar');
    // نظيرا الجدولة للوحة الإدارة — التقويم الإداري يحجز ويجلب الفترات من مساراته هو
    // (قرار 2026-08-28: لا يمرّ الأدمن عبر بوابات الأدوار الأخرى إطلاقًا)
    Route::get('/schedule/slots', [EmployeeScheduleController::class, 'slots'])->name('schedule.slots');
    Route::post('/schedule', [EmployeeScheduleController::class, 'store'])->name('schedule.store');
    Route::get('/tickets', [AdminTicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{ticket}', [AdminTicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/result', [AdminTicketController::class, 'approveResult'])->name('tickets.result');
    // صفحة تذكرة الإدارة تعيد استخدام شاشة المستشار — فتحتاج نظائر admin.* لإجراءاتها (كانت مثبّتة على /lawyer)
    Route::post('/tickets/{ticket}/convert', [LawyerTicketController::class, 'convertToCase'])->name('tickets.convert');
    Route::post('/tickets/{ticket}/convert-consult', [EmployeeTicketController::class, 'convertToConsult'])->name('tickets.convert-consult');
    Route::post('/tickets/{ticket}/close', [LawyerTicketController::class, 'closeWithoutCase'])->name('tickets.close');
    Route::post('/tickets/{ticket}/request-docs', [LawyerTicketController::class, 'requestDocs'])->name('tickets.reqdocs');
    Route::post('/tickets/{ticket}/reply', [LawyerTicketController::class, 'reply'])->name('tickets.reply');
    // ملاحظة إدارية داخلية (يطابق adtSaveNote المرجعي) — نفس ميثود المستشار (واعٍ بالدور) ولا تصل قناة العميل
    Route::post('/tickets/{ticket}/note', [LawyerTicketController::class, 'note'])->name('tickets.note');
    Route::get('/lawyers', [AdminLawyerController::class, 'index'])->name('lawyers');
    Route::post('/lawyers/{user}/mode', [AdminLawyerController::class, 'toggleMode'])->name('lawyers.mode');
    // المخاطبات الرسميّة (الإدارة ترى الكلّ + الاعتماد/الإرسال/الإغلاق)
    Route::get('/correspondences', [CorrespondenceController::class, 'index'])->name('correspondences');
    Route::post('/correspondences', [CorrespondenceController::class, 'store'])->name('correspondences.store');
    Route::get('/correspondences/{correspondence}', [CorrespondenceController::class, 'show'])->name('correspondences.show');
    Route::post('/correspondences/{correspondence}/advance', [CorrespondenceController::class, 'advance'])->name('correspondences.advance');
    Route::post('/correspondences/{correspondence}/sync', [CorrespondenceController::class, 'sync'])->name('correspondences.sync');
    Route::post('/correspondences/{correspondence}/receive', [CorrespondenceController::class, 'receive'])->name('correspondences.receive');
    Route::post('/correspondences/{correspondence}/brief', [CorrespondenceController::class, 'brief'])->name('correspondences.brief');
    Route::post('/correspondences/{correspondence}/close', [CorrespondenceController::class, 'close'])->name('correspondences.close');
    // رحلة الاستشارة — مربوطة بقاعدة البيانات (+ صلاحيات الإدارة: الأولوية)
    Route::get('/consults', [StaffConsultController::class, 'index'])->name('consults');
    Route::get('/consult-requests', [StaffConsultController::class, 'requests'])->name('consult-requests')->middleware('permission:إدارة المواعيد والحجوزات');
    Route::post('/consults/{consult}/remind-schedule', [StaffConsultController::class, 'remindSchedule'])->name('consults.remind-schedule')->middleware('permission:إدارة المواعيد والحجوزات');
    Route::post('/consults/{consult}/cancel-request', [StaffConsultController::class, 'cancelRequest'])->name('consults.cancel-request')->middleware('permission:إدارة المواعيد والحجوزات');
    Route::post('/consults/{consult}/price', [StaffConsultController::class, 'setPrice'])->name('consults.price')->middleware('permission:إدارة المواعيد والحجوزات');
    Route::post('/consults/{consult}/take', [StaffConsultController::class, 'take'])->name('consults.take');
    Route::post('/consults/{consult}/reqdocs', [StaffConsultController::class, 'requestDocs'])->name('consults.reqdocs');
    Route::post('/consults/{consult}/analyze', [StaffConsultController::class, 'analyze'])->name('consults.analyze')->middleware('permission:تشغيل تلخيص الفريق القانوني');
    Route::post('/consults/{consult}/analysis', [StaffConsultController::class, 'saveAnalysis'])->name('consults.analysis')->middleware('permission:اعتماد/تعديل ملخص الاستشارة');
    Route::post('/consults/{consult}/summary', [StaffConsultController::class, 'saveSummary'])->name('consults.summary')->middleware('permission:اعتماد/تعديل ملخص الاستشارة');
    // الاعتماد من شاشة الملفّ — لملخّصٍ لا قيد له في `ai_runs` فلا يبلغ الصندوق أبداً.
    Route::post('/consults/{consult}/summary/approve', [StaffConsultController::class, 'approveSummary'])->name('consults.summary.approve')->middleware('permission:اعتماد/تعديل ملخص الاستشارة');
    Route::post('/consults/{consult}/approve', [StaffConsultController::class, 'approveAnalysis'])->name('consults.approve')->middleware('permission:اعتماد/تعديل ملخص الاستشارة');
    Route::post('/consults/{consult}/zoom-sync', [StaffConsultController::class, 'zoomSync'])->name('consults.zoomsync');
    Route::post('/consults/{consult}/refer', [StaffConsultController::class, 'refer'])->name('consults.refer');
    Route::post('/consults/{consult}/priority', [StaffConsultController::class, 'priority'])->name('consults.priority');
    // تسجيل الموظفين وإدارتهم (مربوط بقاعدة البيانات + spatie)
    Route::get('/staff', [StaffController::class, 'index'])->name('staff')->middleware('permission:إدارة الموظفين');
    Route::get('/staff/lookup', [StaffController::class, 'lookup'])->name('staff.lookup')->middleware('permission:إدارة الموظفين');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store')->middleware('permission:إدارة الموظفين');
    Route::put('/staff/{user}', [StaffController::class, 'update'])->name('staff.update')->middleware('permission:إدارة الموظفين');
    Route::post('/staff/{user}/toggle', [StaffController::class, 'toggle'])->name('staff.toggle')->middleware('permission:إدارة الموظفين');
    // أُلغيت معاينة لوحة الموظف (الإمبرسنيشن) بقرار 2026-08-28
    // Route::post('/staff/{user}/preview', [StaffController::class, 'preview'])->name('staff.preview')->middleware('permission:إدارة الموظفين');
    Route::get('/archive', [AdminArchiveController::class, 'index'])->name('archive')->middleware('permission:أرشيف الاستشارات');
    // تنزيل مخرجات جلسة الاستشارة المؤرشفة (جلب خادمي من سحابة Zoom): فيديو/صوت ZIP + نصّ تفريغي
    Route::get('/consults/{consult}/recording.zip', [AdminArchiveController::class, 'recording'])->name('consults.recording')->middleware('permission:أرشيف الاستشارات');
    Route::get('/consults/{consult}/audio.zip', [AdminArchiveController::class, 'audio'])->name('consults.audio')->middleware('permission:أرشيف الاستشارات');
    Route::get('/consults/{consult}/transcript.txt', [AdminArchiveController::class, 'transcript'])->name('consults.transcript')->middleware('permission:أرشيف الاستشارات');
    Route::get('/distribute', [AdminDistributeController::class, 'index'])->name('distribute')->middleware('permission:توزيع التذاكر');
    Route::post('/distribute/auto', [AdminDistributeController::class, 'auto'])->name('distribute.auto')->middleware('permission:توزيع التذاكر');
    Route::post('/distribute/{ticket}', [AdminDistributeController::class, 'assign'])->name('distribute.assign')->middleware('permission:توزيع التذاكر');
    Route::get('/casefees', [AdminCaseController::class, 'fees'])->name('casefees');
    Route::get('/cases', [AdminCaseController::class, 'index'])->name('cases');
    Route::post('/cases/{case}/fee', [AdminCaseController::class, 'setFee'])->name('cases.fee');
    Route::post('/cases/{case}/close', [AdminCaseController::class, 'closeCase'])->name('cases.close');
    Route::post('/cases/{case}/archive', [AdminCaseController::class, 'archiveCase'])->name('cases.archive');
    // الدالّة اسمها execute — الإشارة إلى convertToExecution (اسم نظيرتها لدى المحامي) كانت ترمي 500 دوماً
    Route::post('/cases/{case}/execute', [AdminCaseController::class, 'execute'])->name('cases.execute');
    // التنفيذ — تبويب موحّد (تدفّق + تنفيذات قديمة) لدور الإدارة العليا
    Route::get('/execs', [ExecFlowController::class, 'admin'])->name('execs');
    Route::redirect('/exec-preview', '/admin/execs')->name('exec.preview');
    Route::get('/tasks', [AdminTaskController::class, 'index'])->name('tasks');
    Route::post('/tasks', [AdminTaskController::class, 'store'])->name('tasks.store');
    // إنجاز أي مهمة + إعادة إسنادها — المهمة المسندة لغير محامٍ كانت لا تُغلق من أي شاشة
    Route::post('/tasks/{task}/complete', [AdminTaskController::class, 'complete'])->name('tasks.complete');
    Route::post('/tasks/{task}/reassign', [AdminTaskController::class, 'reassign'])->name('tasks.reassign');
    // منظومة الاجتماعات (مربوطة بقاعدة البيانات): إدارة/اعتماد/سجل/تقارير/دعوات
    Route::get('/meetmgmt', [StaffMeetingController::class, 'mgmt'])->name('meetmgmt');
    Route::post('/meetings', [StaffMeetingController::class, 'store'])->name('meetings.store');
    Route::get('/meetreqs', [StaffMeetRequestController::class, 'index'])->name('meetreqs');
    Route::post('/meetreqs', [StaffMeetRequestController::class, 'store'])->name('meetreqs.store');
    Route::post('/meetreqs/{meetRequest}/cancel', [StaffMeetRequestController::class, 'cancel'])->name('meetreqs.cancel');
    Route::post('/meetreqs/{meetRequest}/start', [StaffMeetRequestController::class, 'start'])->name('meetreqs.start');
    Route::post('/meetreqs/{meetRequest}/resend', [StaffMeetRequestController::class, 'resend'])->name('meetreqs.resend');
    // موافقة الإدارة على دعوة معلّقة (بوّابة النشر) — بنفس صلاحية اعتماد الاجتماعات
    Route::post('/meetreqs/{meetRequest}/approve', [StaffMeetRequestController::class, 'approve'])->name('meetreqs.approve')->middleware('permission:اعتماد الاجتماعات');
    Route::get('/meetreqs/availability', [StaffMeetRequestController::class, 'availability'])->name('meetreqs.availability');
    Route::get('/meetlog', [StaffMeetingController::class, 'log'])->name('meetlog')->middleware('permission:تقارير الاجتماعات');
    Route::get('/clientnotifs', [AdminClientNotifController::class, 'index'])->name('clientnotifs')->middleware('permission:إشعارات العملاء');
    Route::post('/clientnotifs/send', [AdminClientNotifController::class, 'send'])->name('clientnotifs.send')->middleware('permission:إشعارات العملاء');
    Route::post('/clientnotifs/read', [AdminClientNotifController::class, 'markRead'])->name('clientnotifs.read')->middleware('permission:إشعارات العملاء');
    Route::get('/meetings', [StaffMeetingController::class, 'index'])->name('meetings');
    Route::get('/meetingroom', [StaffMeetingController::class, 'room'])->name('meetingroom');
    Route::post('/meetings/{meeting}/summary', [StaffMeetingController::class, 'saveSummary'])->name('meetings.summary');
    Route::post('/meetings/{meeting}/minutes', [StaffMeetingController::class, 'saveMinutes'])->name('meetings.minutes');
    Route::post('/meetings/{meeting}/approve', [StaffMeetingController::class, 'approve'])->name('meetings.approve')->middleware('permission:اعتماد الاجتماعات');
    Route::post('/meetings/{meeting}/start', [StaffMeetingController::class, 'start'])->name('meetings.start');
    Route::post('/meetings/{meeting}/end', [StaffMeetingController::class, 'end'])->name('meetings.end');
    Route::post('/meetings/{meeting}/reschedule', [StaffMeetingController::class, 'reschedule'])->name('meetings.reschedule');
    Route::post('/meetings/{meeting}/cancel', [StaffMeetingController::class, 'cancel'])->name('meetings.cancel');
    Route::post('/meetings/{meeting}/tasks', [StaffMeetingController::class, 'createTasks'])->name('meetings.tasks');
    Route::get('/meetings/{meeting}/transcript', [StaffMeetingController::class, 'transcript'])->name('meetings.transcript');
    Route::post('/meetings/{meeting}/zoom-sync', [StaffMeetingController::class, 'zoomSync'])->name('meetings.zoomsync');
    Route::get('/meetings/{meeting}/recording.zip', [StaffMeetingController::class, 'recordingZip'])->name('meetings.recording');
    Route::get('/meetings/{meeting}/audio.zip', [StaffMeetingController::class, 'audioZip'])->name('meetings.audio');
    Route::get('/summaries', [AdminTicketController::class, 'summaries'])->name('summaries');
    // مراجعة/اعتماد/تعديل ملخص الملف (إشراف الإدارة العليا — صلاحيات مطلقة)
    Route::get('/summary/{ticket}', [LawyerTicketController::class, 'showSummary'])->name('summary');
    Route::post('/summary/{ticket}', [LawyerTicketController::class, 'updateSummary'])->name('summary.update');
    Route::post('/summary/{ticket}/approve', [LawyerTicketController::class, 'approveSummary'])->name('summary.approve');
    Route::post('/summary/{ticket}/rerun', [LawyerTicketController::class, 'rerunSummary'])->name('summary.rerun');
    Route::post('/summary/{ticket}/najiz', [LawyerTicketController::class, 'generateNajizDraft'])->name('summary.najiz');
    Route::get('/summary/{ticket}/print', [LawyerTicketController::class, 'printSummary'])->name('summary.print');
    Route::get('/assistant', [LawyerAssistantController::class, 'index'])->name('assistant');
    Route::post('/assistant/generate', [LawyerAssistantController::class, 'generate'])->name('assistant.generate');
    Route::get('/revenue', [AdminReportController::class, 'revenue'])->name('revenue')->middleware('permission:التقارير والإيرادات');
    // تصدير PDF — كانت الشاشتان بلا أي تصدير أو طباعة
    Route::get('/reports.pdf', [AdminReportController::class, 'reportsPdf'])->name('reports.pdf')->middleware('permission:التقارير والإيرادات');
    Route::get('/revenue.pdf', [AdminReportController::class, 'revenuePdf'])->name('revenue.pdf')->middleware('permission:التقارير والإيرادات');
    Route::get('/prices', [AdminPriceController::class, 'index'])->name('prices')->middleware('permission:تحديد أسعار الاستشارات');
    Route::post('/prices', [AdminPriceController::class, 'update'])->name('prices.update')->middleware('permission:تحديد أسعار الاستشارات');
    Route::get('/accounting', [AdminAccountingController::class, 'index'])->name('accounting');
    Route::post('/invoices/{invoice}/pay', [AdminAccountingController::class, 'pay'])->name('invoices.pay');
    Route::get('/invoices/{invoice}/proof', [AdminAccountingController::class, 'proof'])->name('invoices.proof');
    // رفض الإثبات يعيد الفاتورة للاستحقاق — رافع الملف الخاطئ كان يفقد زرّ الدفع نهائياً
    Route::post('/invoices/{invoice}/proof/reject', [AdminAccountingController::class, 'rejectProof'])->name('invoices.proof.reject');
    Route::get('/meetreports', [StaffMeetingController::class, 'reports'])->name('meetreports');
    Route::get('/reports', [AdminReportController::class, 'reports'])->name('reports');
    // استقبال الاستشارات وغرفة الجلسة (مربوطة بقاعدة البيانات)
    Route::get('/consultrecv', [StaffConsultController::class, 'recv'])->name('consultrecv');
    Route::get('/videoroom', [StaffConsultController::class, 'room'])->name('videoroom');
    Route::post('/consults/{consult}/start', [StaffConsultController::class, 'start'])->name('consults.start');
    Route::post('/consults/{consult}/end', [StaffConsultController::class, 'end'])->name('consults.end');
    Route::post('/consults/{consult}/no-show', [StaffConsultController::class, 'noShow'])->name('consults.noshow');
    Route::post('/consults/{consult}/reschedule', [StaffConsultController::class, 'reschedule'])->name('consults.reschedule');
    Route::post('/consults/{consult}/tasks', [StaffConsultController::class, 'createTasks'])->name('consults.tasks');
    Route::get('/consult', [StaffConsultController::class, 'show'])->name('consult');
    Route::get('/meeting', [StaffMeetingController::class, 'show'])->name('meeting');
    // سجل الرقابة والتدقيق الأمني (Audit Logs & Activity Trail) — صلاحية مستقلة كنمط
    // بقية الصفحات الإدارية الحساسة (يحوي IPs الجميع وتصدير CSV)
    Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])
        ->middleware('permission:سجل التدقيق الأمني')->name('audit-logs');
    Route::get('/audit-logs/export', [AdminAuditLogController::class, 'export'])
        ->middleware('permission:سجل التدقيق الأمني')->name('audit-logs.export');

    // تشغيل الذكاء وحوكمته — المؤشّرات ومعايرة العتبة والأسعار ومدد الاحتفاظ.
    // قرارات مكتب لا هندسة: العتبة قانونيّة والأسعار محاسبيّة والاحتفاظ نظاميّ،
    // فلا يصحّ أن يلزمها تعديل كود ونشر.
    Route::middleware('permission:التقارير والإيرادات')->group(function () {
        Route::get('/ai-ops', [AdminAiOpsController::class, 'index'])->name('ai-ops');
        Route::post('/ai-ops/threshold', [AdminAiOpsController::class, 'saveThreshold'])->name('ai-ops.threshold');
        Route::post('/ai-ops/pricing', [AdminAiOpsController::class, 'savePricing'])->name('ai-ops.pricing');
        Route::post('/ai-ops/budget', [AdminAiOpsController::class, 'saveBudget'])->name('ai-ops.budget');
        Route::post('/ai-ops/tasks', [AdminAiOpsController::class, 'saveTasks'])->name('ai-ops.tasks');
        Route::post('/ai-ops/retention', [AdminAiOpsController::class, 'saveRetention'])->name('ai-ops.retention');
        // التقييم يُطلَق من الشاشة؛ الأمر ai:evaluate يبقى للجدولة وخطّ التكامل
        Route::post('/ai-ops/evaluate', [AdminAiOpsController::class, 'evaluate'])->name('ai-ops.evaluate');
    });

    // المصادر القانونيّة المعتمدة — الاعتماد فعلٌ قانونيّ، فيُحرَس بصلاحية المساعد
    // القانونيّ. بلا هذه الشاشة تدخل المصادر «مسودة» ولا سبيل لتفعيلها إطلاقاً.
    Route::get('/legal-sources', [AdminLegalSourceController::class, 'index'])
        ->middleware('permission:المساعد القانوني')->name('legal-sources');
    Route::post('/legal-sources/{source}/approve', [AdminLegalSourceController::class, 'approve'])
        ->middleware('permission:المساعد القانوني')->name('legal-sources.approve');
    // اعتماد نظامٍ كامل: مئات المواد لا تُعتمد بمئات النقرات
    Route::post('/legal-sources/approve-system', [AdminLegalSourceController::class, 'approveSystem'])
        ->middleware('permission:المساعد القانوني')->name('legal-sources.approve-system');
    Route::post('/legal-sources/{source}/suspend', [AdminLegalSourceController::class, 'suspend'])
        ->middleware('permission:المساعد القانوني')->name('legal-sources.suspend');

    // صندوق مراجعة مخرجات الذكاء (P3) — محروس بصلاحية «اعتماد الملخصات»:
    // مراجعة مخرج قانونيّ فعلُ اعتماد، فيُحرَس بما يُحرَس به الاعتماد لا بأقلّ منه.
    Route::get('/ai-review', [AdminAiReviewController::class, 'index'])
        ->middleware('permission:اعتماد الملخصات')->name('ai-review');
    Route::post('/ai-review/{run}/decide', [AdminAiReviewController::class, 'decide'])
        ->middleware('permission:اعتماد الملخصات')->name('ai-review.decide');

    // الطبقة الثالثة: عيّنة عمياء يحكم عليها محامٍ قبل كشف مصدرها
    Route::get('/ai-blind-review', [AdminAiBlindReviewController::class, 'index'])
        ->middleware('permission:اعتماد الملخصات')->name('ai-blind-review');
    Route::post('/ai-blind-review/draw', [AdminAiBlindReviewController::class, 'draw'])
        ->middleware('permission:اعتماد الملخصات')->name('ai-blind-review.draw');
    Route::post('/ai-blind-review/{review}/judge', [AdminAiBlindReviewController::class, 'judge'])
        ->middleware('permission:اعتماد الملخصات')->name('ai-blind-review.judge');
});
