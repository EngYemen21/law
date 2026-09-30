<?php

use App\Http\Controllers\Admin\AiBlindReviewController as AdminAiBlindReviewController;
use App\Http\Controllers\Admin\AiOpsController as AdminAiOpsController;
use App\Http\Controllers\Admin\AiReviewController as AdminAiReviewController;
use App\Http\Controllers\Admin\ApprovalsController as AdminApprovalsController;
use App\Http\Controllers\Admin\ArchiveController as AdminArchiveController;
use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\CaseController as AdminCaseController;
use App\Http\Controllers\Admin\CatalogueController as AdminCatalogueController;
use App\Http\Controllers\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Admin\CourtHearingController as AdminCourtHearingController;
use App\Http\Controllers\Admin\DistributeController as AdminDistributeController;
use App\Http\Controllers\Admin\FinanceController as AdminFinanceController;
use App\Http\Controllers\Admin\FinancialReportController as AdminFinancialReportController;
use App\Http\Controllers\Admin\JourneyTransitionController as AdminJourneyTransitionController;
use App\Http\Controllers\Admin\LawyerController as AdminLawyerController;
use App\Http\Controllers\Admin\LegalSourceController as AdminLegalSourceController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StaffPayoutController;
use App\Http\Controllers\Admin\TaskController as AdminTaskController;
use App\Http\Controllers\Admin\TicketController as AdminTicketController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\ClientStatementController;
use App\Http\Controllers\ConsultBookingController;
use App\Http\Controllers\ConsultController;
use App\Http\Controllers\ConversationFileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentVerificationController;
use App\Http\Controllers\Employee\CalendarController as EmployeeCalendarController;
use App\Http\Controllers\Employee\CaseController as EmployeeCaseController;
use App\Http\Controllers\Employee\ExpenseController as EmployeeExpenseController;
use App\Http\Controllers\Employee\ScheduleController as EmployeeScheduleController;
use App\Http\Controllers\Employee\TicketController as EmployeeTicketController;
use App\Http\Controllers\Employee\TransferController as EmployeeTransferController;
use App\Http\Controllers\ExecFlowController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\Lawyer\AssistantController as LawyerAssistantController;
use App\Http\Controllers\Lawyer\CalendarController as LawyerCalendarController;
use App\Http\Controllers\Lawyer\CaseController as LawyerCaseController;
use App\Http\Controllers\Lawyer\DocumentController as LawyerDocumentController;
use App\Http\Controllers\Lawyer\DocumentEditorController as LawyerDocumentEditorController;
use App\Http\Controllers\Lawyer\TaskController as LawyerTaskController;
use App\Http\Controllers\Lawyer\TicketController as LawyerTicketController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MeetRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Staff\ConsultController as StaffConsultController;
use App\Http\Controllers\Staff\ConsultRecordingController as StaffConsultRecordingController;
use App\Http\Controllers\Staff\EarningsController as StaffEarningsController;
use App\Http\Controllers\Staff\MeetingController as StaffMeetingController;
use App\Http\Controllers\Staff\MeetRequestController as StaffMeetRequestController;
use App\Http\Controllers\Staff\RevisionController as StaffRevisionController;
use App\Http\Controllers\Staff\TicketRequirementController as StaffTicketRequirementController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\ZoomController;
use App\Http\Controllers\ZoomWebhookController;
use App\Support\ConversationFiles;
use App\Support\DocumentVerification;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

// ── عام (بدون مصادقة) ──
Route::inertia('/', 'welcome')->name('home');

// مستقبِل أحداث Zoom (Webhooks) — عام، محميّ بتوقيع HMAC ومستثنى من CSRF (Zoom لا يرسل رمزاً)
Route::post('/webhooks/zoom', [ZoomWebhookController::class, 'handle'])->name('webhooks.zoom');

// مستقبِل إشعارات بوّابات الدفع (Webhooks) — عامّ، تتحقّق كلّ بوّابةٍ من مُرسِلها، ومستثنى من CSRF.
// مسار ميسّر باقٍ كما ضُبط في لوحتها؛ وأيّ بوّابةٍ تُسجَّل لاحقاً تستقبل على `/webhooks/payments/{اسمها}`.
Route::post('/webhooks/moyasar', [PaymentWebhookController::class, 'handle'])->defaults('gateway', 'moyasar')->name('webhooks.moyasar');
Route::post('/webhooks/payments/{gateway}', [PaymentWebhookController::class, 'handle'])
    ->where('gateway', '[a-z0-9_]+')->name('webhooks.payment');

// موجز التقويم الحي (RFC 5545 iCal Live Subscription Feed) — عام ومحمي برمز أمان فريد لكل مستخدم
Route::get('/calendar/feed/{user}/{token}.ics', [CalendarController::class, 'feed'])->name('calendar.feed');

// التحقّق من وثيقة مطبوعة — يفتحه رمز الاستجابة بلا دخول؛ محميّ بتوقيعٍ يصدره الخادم (DocumentVerification)
Route::get('/verify/{kind}/{ref}', DocumentVerificationController::class)
    ->whereIn('kind', DocumentVerification::KINDS)->where('ref', '[^/]+')
    ->middleware('throttle:60,1')->name(DocumentVerification::ROUTE);

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
    // سجلّ نسخ التحليلات والملخّصات — للطاقم وحده، والحارس في المتحكّم بصلاحيّة رؤية الملفّ (طلب المالك 2026-09-29)
    Route::get('/revisions/{kind}/{ref}', [StaffRevisionController::class, 'index'])->name('revisions.index');
    Route::post('/exec-flow/{execution}/action', [ExecFlowController::class, 'act'])->name('exec-flow.act');
    Route::post('/exec-flow/{execution}/pay', [ExecFlowController::class, 'pay'])->name('exec-flow.pay');
    Route::get('/exec-flow/{execution}/pay/callback', [ExecFlowController::class, 'payCallback'])->name('exec-flow.pay.callback');
    // محادثة ملفّ التنفيذ + رفع المستندات المطلوبة (يحرسان دور العميل/الملكيّة داخليّاً)
    Route::post('/exec-flow/{execution}/messages', [ExecFlowController::class, 'message'])->middleware('conversation.reply')->name('exec-flow.messages.store');
    Route::post('/exec-flow/{execution}/attach', [ExecFlowController::class, 'attach'])->middleware('conversation.reply')->name('exec-flow.attach');
    Route::post('/exec-flow/{execution}/documents/{document}', [ExecFlowController::class, 'uploadDocument'])->name('exec-flow.documents.upload');
    Route::post('/exec-flow/{execution}/documents/{document}/review', [ExecFlowController::class, 'reviewDocument'])->name('exec-flow.documents.review');
    Route::get('/exec-flow/{execution}/documents/{document}/download', [ExecFlowController::class, 'downloadDocument'])->name('exec-flow.documents.download');
    // تنزيل مرفقات المحادثات (تذكرة/قضيّة/تنفيذ) — مسارٌ واحدٌ للأدوار الأربعة، والإذن في ConversationFiles
    Route::get('/files/{type}/{id}', ConversationFileController::class)
        ->whereIn('type', array_keys(ConversationFiles::TYPES))->whereNumber('id')->name('files.download');
    Route::get('/exec-flow/{execution}/offer.pdf', [ExecFlowController::class, 'offerPdf'])->name('exec-flow.offer.pdf');
    // تقرير/ملخص الاستشارة — متاح للعميل صاحبها ولأدوار المكتب (الحارس داخل ConsultController::report)
    Route::get('/consults/{consult}/report.pdf', [ConsultController::class, 'report'])->name('consults.report');
    Route::get('/consults/{consult}/report', [ConsultController::class, 'report'])->name('consults.report.plain');
});

// ── منصة العميل (دور العميل) — تطابق 1:1 مع index (82).html ──
Route::middleware(['auth', 'active', 'role:client'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'client'])->name('dashboard');

    // طلباتي — التذاكر مربوطة بقاعدة البيانات (متحكم)
    // الأقسام والخدمات من كتالوج الخادم — لا قائمة ثابتة في الواجهة
    Route::get('/tickets/new', [TicketController::class, 'create'])->name('tickets.new');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/messages', [TicketController::class, 'storeMessage'])->name('tickets.messages.store');
    Route::post('/tickets/{ticket}/attach', [TicketController::class, 'attach'])->name('tickets.attach');
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

    // الاستشارات — «استشاراتي» مربوطة بقاعدة البيانات؛ الجلسات المرئية عبر Zoom
    Route::get('/book', [ConsultBookingController::class, 'index'])->name('book');
    Route::post('/book', [ConsultBookingController::class, 'store'])->name('book.store');
    Route::get('/myconsults', [ConsultController::class, 'index'])->name('myconsults');
    // دورة الحجز المطابقة للتصميم: الدفع عبر ميسّر (503 بلا مفاتيح — لا محاكاة) ثم جدولة الموعد بعد السداد
    Route::post('/consults/{consult}/pay', [ConsultController::class, 'pay'])->name('consults.pay');
    Route::get('/consults/{consult}/pay/callback', [ConsultController::class, 'payCallback'])->name('consults.pay.callback');
    Route::post('/consults/{consult}/schedule', [ConsultController::class, 'schedule'])->name('consults.schedule');
    Route::get('/consults/{consult}/documents', [ConsultController::class, 'documents'])->name('consults.documents');
    Route::get('/consults/room', [ConsultController::class, 'room'])->name('consults.room');
    // التبويب الزمني موحّد في /calendar. المسار يُحوَّل ولا يُحذف: بريد تأكيد الموعد
    // وإشعارات سابقة تشير إليه، وحذفه يعطي 404 لكل من يفتح رسالة قديمة.
    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments');
    Route::get('/appointments/{appointment}/card.pdf', [AppointmentController::class, 'card'])->name('appointments.card');
    Route::get('/appointments/{appointment}/card', [AppointmentController::class, 'card'])->name('appointments.card.plain');
    // رمز التحقّق على بطاقة الموعد في الشاشة — SVG من المولّد الخادميّ الوحيد (`Support\Qr`) لا مكتبةَ واجهةٍ ثانية
    Route::get('/appointments/{appointment}/qr.svg', [AppointmentController::class, 'qr'])->name('appointments.qr');
    Route::get('/meetings', [MeetingController::class, 'index'])->name('meetings');
    Route::get('/meetingroom', [MeetingController::class, 'room'])->name('meetingroom');
    // دعوات الاجتماعات (مربوطة بقاعدة البيانات — تُنشر مؤكَّدة بعد موافقة الإدارة؛ تأكيد العميل مُلغى)
    // تبويب الدعوات أُلغي — المسار يُحوّل إلى «الاجتماعات» (بريد الدعوة القديم يشير إليه)
    Route::get('/meetreqs', [MeetRequestController::class, 'index'])->name('meetreqs');
    // قناتا العميل لطلب إعادة الجدولة (فحص الأزرار 2026-08-26): الفائتة كانت نصاً ميتاً بلا زرّ
    Route::post('/consults/{consult}/reschedule-request', [ConsultController::class, 'rescheduleRequest'])->name('consults.reschedule-request');
    Route::post('/meetings/{meeting}/change-request', [MeetingController::class, 'changeRequest'])->name('meetings.change-request');
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
    // الملفات والمالية (مربوطة بقاعدة البيانات)
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/download-file', [DocumentController::class, 'downloadFile'])->name('documents.download-file');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices');
    Route::post('/invoices/{invoice}/proof', [InvoiceController::class, 'uploadProof'])->name('invoices.proof');
    Route::post('/invoices/{invoice}/checkout', [InvoiceController::class, 'checkout'])->name('invoices.checkout');
    Route::get('/invoices/{invoice}/checkout/callback', [InvoiceController::class, 'checkoutCallback'])->name('invoices.checkout.callback');
    Route::get('/invoices/{invoice}/receipt', [InvoiceController::class, 'receipt'])->name('invoices.receipt');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    // كشف الحساب — حساب العميل نفسه وحده (المرحلة ج)
    Route::get('/statement', [ClientStatementController::class, 'index'])->name('statement');
    Route::get('/statement/pdf', [ClientStatementController::class, 'pdf'])->name('statement.pdf');
});

// الحساب — متاح لأي مستخدم مسجّل
Route::middleware(['auth', 'active'])->group(function () {
    Route::redirect('/notifications', '/dashboard')->name('notifications');
    // الصفحات الأقدم من قائمة الجرس — السجلّ الكامل داخل القائمة لا صفحةٌ لا وجود لها
    Route::get('/notifications/more', [NotificationController::class, 'more'])->name('notifications.more');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::inertia('/profile', 'profile')->name('profile');
    Route::post('/profile', [ProfileController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    // تأكيد الجوال الجديد — الرقم لا يُكتب إلا هنا (عامل المصادقة الوحيد)
    Route::post('/profile/phone/verify', [ProfileController::class, 'verifyPhoneChange'])
        ->middleware('throttle:otp-verify')->name('profile.phone.verify');
    // توقيع تضمين Zoom (Meeting SDK) — متاح للعميل والموظف؛ التفويض في المتحكّم عبر ChannelAccess
    Route::post('/zoom/sdk-signature', [ZoomController::class, 'sdkSignature'])->name('zoom.signature');
});

// ── لوحة الموظف ── (deny-by-default: صلاحية صريحة لكل إجراء حسّاس فوق حارس الدور)
Route::middleware(['auth', 'active', 'role:employee'])->prefix('employee')->name('employee.')->group(function () {
    // «مستحقاتي» — الراتب ونصيب الأتعاب وأجر الجلسات وسجلّ الصرف (بيانات المستخدم الحاليّ وحده)
    Route::get('/earnings', [StaffEarningsController::class, 'index'])->name('earnings');
    Route::get('/earnings/statement.pdf', [StaffEarningsController::class, 'statement'])->name('earnings.statement');
    Route::get('/earnings/payouts/{payout}/voucher.pdf', [StaffEarningsController::class, 'voucher'])->name('earnings.voucher');
    // المصروفات — يسجّلها الموظّف بصلاحيّتها فتنتظر اعتماد الإدارة (قرار المالك 2026-09-29)
    Route::get('/expenses', [EmployeeExpenseController::class, 'index'])->name('expenses')->middleware(Permissions::middleware(Permissions::RECORD_EXPENSES));
    Route::post('/expenses', [EmployeeExpenseController::class, 'store'])->name('expenses.store')->middleware(Permissions::middleware(Permissions::RECORD_EXPENSES));
    Route::get('/expenses/{expense}/document', [EmployeeExpenseController::class, 'document'])->name('expenses.document')->middleware(Permissions::middleware(Permissions::RECORD_EXPENSES));
    Route::get('/dashboard', [DashboardController::class, 'employee'])->name('dashboard'); // عام للدور

    // صندوق مراجعة مخرجات الذكاء — الشاشة نفسها لكل دور، والعزل داخل AiReviewInbox:
    // الموظّف يرى الفرز والاستشارات وفحص المستندات، لا المسودّات ولا الملخّصات.
    //
    // **الحارس تشغيليّ لا اعتماديّ.** كان `اعتماد الملخصات` — وهي صلاحيةٌ لا يملكها
    // دور الموظّف في هذا النظام، فكان الفرع معطَّلاً عملياً: لا موظّف يفتح صندوقه.
    // ومنحُها له كان سيوسّع وصوله إلى **اعتماد الملخّصات القانونيّة** وهو ما لا يفعله.
    // فالحكم على «أهذا المستند ذو صلة؟» عملٌ تشغيليّ من صميم إدارة التذاكر، ويختلف
    // عن اعتماد رأيٍ قانونيّ — والعزل داخل الصندوق يمنعه من رؤية الثاني أصلاً.
    Route::middleware(Permissions::middleware(Permissions::MANAGE_TICKETS))->group(function () {
        Route::get('/ai-review', [AdminAiReviewController::class, 'index'])->name('ai-review');
        Route::post('/ai-review/{run}/decide', [AdminAiReviewController::class, 'decide'])->name('ai-review.decide');
    });

    // التذاكر — إدارة التذاكر (والرد على العملاء لمسار الردّ)
    Route::middleware(Permissions::middleware(Permissions::MANAGE_TICKETS))->group(function () {
        Route::get('/tickets', [EmployeeTicketController::class, 'index'])->name('tickets');
        Route::get('/tickets/{ticket}', [EmployeeTicketController::class, 'show'])->name('tickets.show');
        Route::post('/tickets/{ticket}/note', [EmployeeTicketController::class, 'note'])->name('tickets.note');
        Route::post('/tickets/{ticket}/status', [EmployeeTicketController::class, 'status'])->name('tickets.status');
        Route::post('/tickets/{ticket}/advance', [EmployeeTicketController::class, 'advance'])->name('tickets.advance');
        Route::post('/tickets/{ticket}/rerun', [EmployeeTicketController::class, 'rerunSummary'])->name('tickets.rerun');
        Route::post('/tickets/{ticket}/track/propose', [EmployeeTicketController::class, 'proposeTrack'])->name('tickets.track.propose');
        // قائمة مستندات القسم للتذكرة: ما استُوفي وما لم يُتحقّق، والتأكيد/الإلغاء اليدويّ (قرار المالك 2026-09-26)
        Route::get('/tickets/{ticket}/requirements', [StaffTicketRequirementController::class, 'show'])->name('tickets.requirements');
        Route::post('/tickets/{ticket}/requirements', [StaffTicketRequirementController::class, 'update'])->name('tickets.requirements.update');
    });
    Route::post('/tickets/{ticket}/reply', [EmployeeTicketController::class, 'reply'])->middleware('conversation.reply')
        ->middleware(Permissions::middleware(Permissions::REPLY_TO_CLIENTS))->name('tickets.reply');
    Route::post('/tickets/{ticket}/attach', [EmployeeTicketController::class, 'attach'])->middleware('conversation.reply')
        ->middleware(Permissions::middleware(Permissions::REPLY_TO_CLIENTS))->name('tickets.attach');
    Route::post('/tickets/{ticket}/request-docs', [EmployeeTicketController::class, 'requestDocs'])->middleware('conversation.reply')
        ->middleware(Permissions::middleware(Permissions::REPLY_TO_CLIENTS))->name('tickets.reqdocs');

    // القضايا وطلبات التنفيذ — إدارة القضايا والأتعاب
    Route::middleware(Permissions::middleware(Permissions::MANAGE_CASES_AND_FEES))->group(function () {
        Route::get('/cases', [EmployeeCaseController::class, 'index'])->name('cases');
        Route::get('/cases/{case}', [EmployeeCaseController::class, 'show'])->name('cases.show');
        Route::post('/cases/{case}/reply', [EmployeeCaseController::class, 'reply'])->middleware('conversation.reply')->name('cases.reply');
        Route::post('/cases/{case}/attach', [EmployeeCaseController::class, 'attach'])->middleware('conversation.reply')->name('cases.attach');
        // رفع طلب فتح تنفيذ الحكم للإدارة العليا (قرار المالك 2026-09-29)
        Route::post('/cases/{case}/execution-request', [EmployeeCaseController::class, 'requestExecution'])->name('cases.execution-request');
        // إجراءات المحكمة (ناجز والجلسات والحكم) — لمن تمنحه الإدارة «إجراءات المحكمة والجلسات» من تبويب
        // الموظّفين (قرار المالك 2026-09-11)، بحرّاس المحامي نفسها (`ManagesCourtProceedings`)
        Route::middleware(Permissions::middleware(Permissions::COURT_PROCEEDINGS))->group(function () {
            Route::post('/cases/{case}/najiz/file', [EmployeeCaseController::class, 'fileNajiz'])->name('cases.najiz.file');
            Route::post('/cases/{case}/najiz/register', [EmployeeCaseController::class, 'registerNajiz'])->name('cases.najiz.register');
            Route::post('/cases/{case}/hearings', [EmployeeCaseController::class, 'addHearing'])->name('cases.hearings.add');
            Route::post('/cases/{case}/hearings/{hearing}', [EmployeeCaseController::class, 'recordHearing'])->name('cases.hearings.record');
            Route::post('/cases/{case}/hearings/{hearing}/update', [EmployeeCaseController::class, 'updateHearing'])->name('cases.hearings.update');
            Route::post('/cases/{case}/hearings/{hearing}/cancel', [EmployeeCaseController::class, 'cancelHearing'])->name('cases.hearings.cancel');
            Route::post('/cases/{case}/ruling', [EmployeeCaseController::class, 'recordRuling'])->name('cases.ruling');
            Route::post('/cases/{case}/ruling/correct', [EmployeeCaseController::class, 'correctRuling'])->name('cases.ruling.correct');
            Route::post('/cases/{case}/appeal', [EmployeeCaseController::class, 'recordAppeal'])->name('cases.appeal');
            Route::post('/cases/{case}/appeal/ruling', [EmployeeCaseController::class, 'recordAppealRuling'])->name('cases.appeal.ruling');
        });
        // التنفيذ — تبويب موحّد (استقبال/إحالة) لدور الموظف
        Route::get('/execs', [ExecFlowController::class, 'employee'])->name('execs');
    });

    // رحلة الاستشارة + الاستقبال + الغرفة — استقبال الاستشارات
    Route::middleware(Permissions::middleware(Permissions::RECEIVE_CONSULTS))->group(function () {
        Route::get('/consults', [StaffConsultController::class, 'index'])->name('consults');
        Route::get('/consult', [StaffConsultController::class, 'show'])->name('consult');
        Route::post('/consults/{consult}/reqdocs', [StaffConsultController::class, 'requestDocs'])->name('consults.reqdocs');
        /*
         * **الصلاحيّة التي كانت تُعرض ولا تحرس.**
         *
         * كان هذا المسار داخل مجموعة «استقبال الاستشارات» وحدها، بينما نسخة الإدارة
         * (أدناه) محروسةٌ بـ«تشغيل تلخيص الفريق القانوني». فالصلاحيّة تعمل في اللوحة
         * التي **لا تحتاجها** (الأدمن يتجاوز عبر `Gate::before`) وتُهمَل في اللوحتين
         * اللتين تحتاجانها: نزعُها عن محامٍ لا يمنعه، والموظّف يشغّل التلخيص وهو لا
         * يملكها أصلاً. مربّعٌ في شاشة الصلاحيّات لا يفعل شيئاً.
         *
         * والوسيط تجميعيّ، فالشرط الآن الصلاحيّتان معاً — لا يحلّل إلّا من يصل
         * الاستشارة أصلاً.
         */
        Route::post('/consults/{consult}/analyze', [StaffConsultController::class, 'analyze'])->name('consults.analyze')->middleware(Permissions::middleware(Permissions::RUN_LEGAL_ANALYSIS));
        Route::post('/consults/{consult}/analysis', [StaffConsultController::class, 'saveAnalysis'])->name('consults.analysis');
        Route::post('/consults/{consult}/approve', [StaffConsultController::class, 'approveAnalysis'])->name('consults.approve');
        Route::post('/consults/{consult}/zoom-sync', [StaffConsultController::class, 'zoomSync'])->name('consults.zoomsync');
        Route::post('/consults/{consult}/refer', [StaffConsultController::class, 'refer'])->name('consults.refer');
        // **قرار المالك: قراءةٌ فقط للموظّف إلّا بصلاحيّة تمنحها الإدارة العليا.**
        // كان المسار غير مسجَّل بتاتاً بينما تعرض الشاشة المشتركة محرّره، فيقع ٤٠٤
        // صامت. والصلاحيّة هي البوّابة الآن لا الدور — فمن لا يملكها يُصدّ ٤٠٣،
        // ومن منحته الإدارة إيّاها يحرّر. والاعتماد يبقى فعلاً قانونياً بالصلاحيّة نفسها.
        Route::post('/consults/{consult}/summary', [StaffConsultController::class, 'saveSummary'])->name('consults.summary')->middleware(Permissions::middleware(Permissions::APPROVE_CONSULT_SUMMARY));
        // **لا اعتمادَ لملخّص الاستشارة من مجموعة الموظّف** (قرار المالك 2026-09-14): الملخّص
        // يعتمده المحامي ثمّ الإدارة. الموظّف يحرّره إن مُنح الصلاحيّة ولا يُطلقه للعميل.
        Route::get('/consultrecv', [StaffConsultController::class, 'recv'])->name('consultrecv');
        Route::post('/consults/{consult}/start', [StaffConsultController::class, 'start'])->name('consults.start');
        Route::post('/consults/{consult}/end', [StaffConsultController::class, 'end'])->name('consults.end');
        Route::post('/consults/{consult}/tasks', [StaffConsultController::class, 'createTasks'])->name('consults.tasks');
        // مخرجات الجلسة عبر الخادم — تنزيلٌ وتشغيلٌ داخل النظام بلا رابط Zoom خارجيّ
        Route::get('/consults/{consult}/recording.zip', [StaffConsultRecordingController::class, 'video'])->name('consults.recording');
        Route::get('/consults/{consult}/audio.zip', [StaffConsultRecordingController::class, 'audio'])->name('consults.audio');
        Route::get('/consults/{consult}/transcript.txt', [StaffConsultRecordingController::class, 'transcript'])->name('consults.transcript');
        Route::get('/consults/{consult}/stream/{type}', [StaffConsultRecordingController::class, 'stream'])->whereIn('type', ['video', 'audio'])->name('consults.stream');
    });

    // إجراءات المواعيد المرتبطة بدورة الاستشارة — استقبال الاستشارات أو إدارة المواعيد والحجوزات
    Route::middleware(Permissions::middleware(Permissions::RECEIVE_CONSULTS, Permissions::MANAGE_BOOKINGS))->group(function () {
        Route::post('/consults/{consult}/no-show', [StaffConsultController::class, 'noShow'])->name('consults.noshow');
        Route::post('/consults/{consult}/reschedule', [StaffConsultController::class, 'reschedule'])->name('consults.reschedule');
        Route::post('/consults/{consult}/reschedule-request/dismiss', [StaffConsultController::class, 'dismissRescheduleRequest'])->name('consults.reschedule-request.dismiss');
    });

    // أيّ من الصلاحيتين تكفي: الغرفة تُفتح من شاشة الاستقبال أيضاً — حصرها بواحدة كان يصدّ حاملي الأخرى
    Route::get('/videoroom', [StaffConsultController::class, 'room'])
        ->middleware(Permissions::middleware(Permissions::RUN_VIDEO_SESSIONS, Permissions::RECEIVE_CONSULTS))->name('videoroom');

    // التبويب الزمني الموحّد: الأحداث + لوحة المواعيد
    // يُتاح لمن يملك جدولة المواعيد أو إدارة المواعيد والحجوزات أو إجراءات المحكمة والجلسات
    Route::middleware(Permissions::middleware(Permissions::SCHEDULE_APPOINTMENTS, Permissions::MANAGE_BOOKINGS, Permissions::COURT_PROCEEDINGS))->group(function () {
        Route::get('/calendar', [EmployeeCalendarController::class, 'index'])->name('calendar');
        // شرائح اليوم لشبكة التفرّغ — اطّلاعٌ لمن يرى التقويم (الحجز نفسه محروسٌ بجدولة المواعيد)
        Route::get('/schedule/day-slots', [EmployeeScheduleController::class, 'daySlots'])->name('schedule.day-slots');
        // شاشة الجدولة المستقلّة طُويت في التقويم — تُحوَّل ولا تُحذف (روابط محفوظة/إشعارات)
        Route::get('/schedule', [EmployeeScheduleController::class, 'index'])->name('schedule');
    });

    // عمليات الحجز والجدولة الجديدة — تتطلب حصراً صلاحية «جدولة المواعيد»
    Route::middleware(Permissions::middleware(Permissions::SCHEDULE_APPOINTMENTS))->group(function () {
        Route::get('/schedule/slots', [EmployeeScheduleController::class, 'slots'])->name('schedule.slots');
        Route::post('/schedule', [EmployeeScheduleController::class, 'store'])->name('schedule.store');
        // طلب استشارة نيابةً عن العميل — يسلك التسعير والسداد قبل الحجز
        Route::post('/consults/request', [EmployeeScheduleController::class, 'requestFor'])->name('consults.request');
    });

    Route::middleware(Permissions::middleware(Permissions::TRANSFER_TICKETS))->group(function () {
        Route::get('/transfer', [EmployeeTransferController::class, 'index'])->name('transfer');
        Route::post('/transfer/bulk', [EmployeeTransferController::class, 'bulkTransfer'])->name('transfer.bulk');
        Route::post('/transfer/{ticket}', [EmployeeTransferController::class, 'transfer'])->name('transfer.do');
    });

    // طلبات الاجتماعات — إرسال دعوات الاجتماعات
    Route::middleware(Permissions::middleware(Permissions::SEND_MEETING_INVITES))->group(function () {
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
        Route::get('/meetings/{meeting}/stream/{type}', [StaffMeetingController::class, 'stream'])->whereIn('type', ['video', 'audio'])->name('meetings.stream');
    });

    // محرر الصياغة القانونية للموظف — إنشاء وتحرير المستندات (الاعتماد مشروط بصلاحية خاصة)
    // أيٌّ من الصلاحيتين يكفي لفتح المحرر: «المساعد القانوني» (يحرر بلا اعتماد)
    // أو «اعتماد الصياغة القانونية» (يحرر ويعتمد). الفاصلة = OR في Spatie.
    Route::middleware(Permissions::middleware(Permissions::LEGAL_ASSISTANT, Permissions::APPROVE_DOCUMENTS))->group(function () {
        Route::get('/editor', [LawyerDocumentEditorController::class, 'index'])->name('editor');
        Route::get('/editor/create', [LawyerDocumentEditorController::class, 'create'])->name('editor.create');
        Route::get('/editor/importables', [LawyerDocumentEditorController::class, 'importables'])->name('editor.importables');
        Route::post('/editor', [LawyerDocumentEditorController::class, 'store'])->name('editor.store');
        Route::get('/editor/{doc}', [LawyerDocumentEditorController::class, 'edit'])->name('editor.edit');
        Route::put('/editor/{doc}', [LawyerDocumentEditorController::class, 'update'])->name('editor.update');
        Route::post('/editor/{doc}/approve', [LawyerDocumentEditorController::class, 'approve'])
            ->middleware(Permissions::middleware(Permissions::APPROVE_DOCUMENTS))
            ->name('editor.approve');
        Route::get('/editor/{doc}/print', [LawyerDocumentEditorController::class, 'printDoc'])->name('editor.print');
        Route::get('/editor/{doc}/pdf', [LawyerDocumentEditorController::class, 'downloadPdf'])->name('editor.pdf');
        Route::post('/editor/ai-assist', [LawyerDocumentEditorController::class, 'aiAssist'])->name('editor.ai-assist');
    });
});

// ── لوحة المحامي ── (deny-by-default: صلاحية صريحة لكل إجراء حسّاس فوق حارس الدور)
Route::middleware(['auth', 'active', 'role:lawyer'])->prefix('lawyer')->name('lawyer.')->group(function () {
    // «مستحقاتي» — الراتب ونصيب الأتعاب وأجر الجلسات وسجلّ الصرف (بيانات المستخدم الحاليّ وحده)
    Route::get('/earnings', [StaffEarningsController::class, 'index'])->name('earnings');
    Route::get('/earnings/statement.pdf', [StaffEarningsController::class, 'statement'])->name('earnings.statement');
    Route::get('/earnings/payouts/{payout}/voucher.pdf', [StaffEarningsController::class, 'voucher'])->name('earnings.voucher');
    Route::get('/dashboard', [LawyerTicketController::class, 'dashboard'])->name('dashboard'); // عام للدور
    // التذاكر المحالة — عرض عام للمحامي؛ الإجراءات الحسّاسة مُصرَّحة أدناه
    Route::get('/tickets', [LawyerTicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{ticket}', [LawyerTicketController::class, 'show'])->name('tickets.show');
    // ردّ المستشار المباشر على العميل (يطابق lwReply المرجعي) + ملاحظته الداخلية — guardAssigned يحصرهما بالمُسنَد
    Route::post('/tickets/{ticket}/reply', [LawyerTicketController::class, 'reply'])->middleware('conversation.reply')->name('tickets.reply');
    Route::post('/tickets/{ticket}/note', [LawyerTicketController::class, 'note'])->name('tickets.note');
    // قائمة مستندات القسم للتذكرة — guardAssigned في المتحكّم يحصرها بالمُسنَد
    Route::get('/tickets/{ticket}/requirements', [StaffTicketRequirementController::class, 'show'])->name('tickets.requirements');
    Route::post('/tickets/{ticket}/requirements', [StaffTicketRequirementController::class, 'update'])->name('tickets.requirements.update');
    Route::get('/calendar', [LawyerCalendarController::class, 'index'])->name('calendar');

    // الملخصات والاعتماد — اعتماد الملخصات
    Route::middleware(Permissions::middleware(Permissions::APPROVE_SUMMARIES))->group(function () {
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
    });

    // دورة القضية + التنفيذ + المهام — إدارة القضايا والأتعاب
    Route::middleware(Permissions::middleware(Permissions::MANAGE_CASES_AND_FEES))->group(function () {
        Route::post('/tickets/{ticket}/request-docs', [LawyerTicketController::class, 'requestDocs'])->middleware('conversation.reply')->name('tickets.reqdocs');
        Route::post('/tickets/{ticket}/track/propose', [LawyerTicketController::class, 'proposeTrack'])->name('tickets.track.propose');
        Route::get('/cases', [LawyerCaseController::class, 'index'])->name('cases');
        Route::get('/cases/{case}', [LawyerCaseController::class, 'show'])->name('cases.show');
        // تنزيل مستند قضية/تذكرة — المحامي المسنَد وحده (والإدارة إشرافاً). لم يكن للطاقم
        // مسار تنزيل إطلاقاً: يرى أنّ مستنداً رُفع ويقرأ ملخّصه ولا يفتحه.
        Route::get('/documents/{type}/{id}/download', [LawyerDocumentController::class, 'download'])
            ->whereIn('type', ['case', 'ticket'])->whereNumber('id')
            ->name('documents.download');
        Route::post('/cases/{case}/pleading', [LawyerCaseController::class, 'approvePleading'])->name('cases.pleading');
        // محرّر اللائحة: حفظ (مسودّة محجوبة) · إعادة توليد (محدودة المعدّل — نداءٌ مدفوع للنموذج)
        Route::post('/cases/{case}/pleading/save', [LawyerCaseController::class, 'savePleading'])->name('cases.pleading.save');
        Route::post('/cases/{case}/pleading/regenerate', [LawyerCaseController::class, 'regeneratePleading'])
            ->middleware('throttle:3,10')->name('cases.pleading.regenerate');
        // رفع الدعوى في ناجز ثمّ قيدها (الخطّة ب — 2026-09-11)
        Route::post('/cases/{case}/najiz/file', [LawyerCaseController::class, 'fileNajiz'])->name('cases.najiz.file');
        Route::post('/cases/{case}/najiz/register', [LawyerCaseController::class, 'registerNajiz'])->name('cases.najiz.register');
        Route::post('/cases/{case}/reply', [LawyerCaseController::class, 'reply'])->name('cases.reply');
        Route::post('/cases/{case}/attach', [LawyerCaseController::class, 'attach'])->name('cases.attach');
        Route::post('/cases/{case}/hearings', [LawyerCaseController::class, 'addHearing'])->name('cases.hearings.add');
        Route::post('/cases/{case}/hearings/{hearing}', [LawyerCaseController::class, 'recordHearing'])->name('cases.hearings.record');
        Route::post('/cases/{case}/hearings/{hearing}/update', [LawyerCaseController::class, 'updateHearing'])->name('cases.hearings.update');
        Route::post('/cases/{case}/hearings/{hearing}/cancel', [LawyerCaseController::class, 'cancelHearing'])->name('cases.hearings.cancel');
        Route::post('/cases/{case}/ruling', [LawyerCaseController::class, 'recordRuling'])->name('cases.ruling');
        Route::post('/cases/{case}/ruling/correct', [LawyerCaseController::class, 'correctRuling'])->name('cases.ruling.correct');
        Route::post('/cases/{case}/appeal', [LawyerCaseController::class, 'recordAppeal'])->name('cases.appeal');
        Route::post('/cases/{case}/appeal/ruling', [LawyerCaseController::class, 'recordAppealRuling'])->name('cases.appeal.ruling');
        // فتح تنفيذ الحكم بطلبٍ تعتمده الإدارة العليا (قرار المالك 2026-09-29) — لا فتحَ مباشراً من المحامي
        Route::post('/cases/{case}/execution-request', [LawyerCaseController::class, 'requestExecution'])->name('cases.execution-request');
        // التنفيذ — تبويب موحّد (تدفّق + تنفيذات قديمة) لدور المحامي، محصور بالمسند إليه/القابل للالتقاط
        Route::get('/execs', [ExecFlowController::class, 'lawyer'])->name('execs');
        Route::get('/tasks', [LawyerTaskController::class, 'index'])->name('tasks');
        Route::post('/tasks', [LawyerTaskController::class, 'store'])->name('tasks.store');
        Route::post('/tasks/{task}/complete', [LawyerTaskController::class, 'complete'])->name('tasks.complete');
    });

    // الاجتماعات — إدارة الاجتماعات
    // الغرفة تُفتح من دعوات الاجتماعات أيضاً — أيّ من الصلاحيتين تكفي (كانت تصدّ محامي الدعوات وحدها)
    Route::get('/meetingroom', [StaffMeetingController::class, 'room'])
        ->middleware(Permissions::middleware(Permissions::MANAGE_MEETINGS, Permissions::SEND_MEETING_INVITES))->name('meetingroom');
    Route::middleware(Permissions::middleware(Permissions::MANAGE_MEETINGS))->group(function () {
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
        Route::get('/meetings/{meeting}/stream/{type}', [StaffMeetingController::class, 'stream'])->whereIn('type', ['video', 'audio'])->name('meetings.stream');
    });

    // طلبات الاجتماعات — إرسال دعوات الاجتماعات
    Route::middleware(Permissions::middleware(Permissions::SEND_MEETING_INVITES))->group(function () {
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
    Route::middleware(Permissions::middleware(Permissions::LEGAL_ASSISTANT))->group(function () {
        Route::get('/assistant', [LawyerAssistantController::class, 'index'])->name('assistant');
        Route::post('/assistant/generate', [LawyerAssistantController::class, 'generate'])->name('assistant.generate');
        // تسليم المسودّة لمحرّر الصياغة عبر الجلسة — لا في عنوانٍ يُحقن منه نصّ
        Route::post('/assistant/to-editor', [LawyerAssistantController::class, 'toEditor'])->name('assistant.to-editor');

        // اعتماد المصادر القانونيّة: **المحامي المسؤول** هو من يعتمد كما تنصّ الخطة.
        // حصرُه في لوحة الإدارة يجعل الفعل القانونيّ بيد غير أهله — والاعتماد يُسجَّل
        // باسم من ضغط الزرّ، فلا يصحّ أن يكون غير المحامي.
        Route::get('/legal-sources', [AdminLegalSourceController::class, 'index'])->name('legal-sources');
        Route::post('/legal-sources/{source}/approve', [AdminLegalSourceController::class, 'approve'])->name('legal-sources.approve');
        Route::post('/legal-sources/approve-system', [AdminLegalSourceController::class, 'approveSystem'])->name('legal-sources.approve-system');
        Route::post('/legal-sources/{source}/suspend', [AdminLegalSourceController::class, 'suspend'])->name('legal-sources.suspend');
    });

    // محرر الصياغة القانونية — إنشاء وتحرير المستندات المنسّقة (لوائح، مذكرات، عقود...)
    Route::middleware(Permissions::middleware(Permissions::LEGAL_ASSISTANT))->group(function () {
        Route::get('/editor', [LawyerDocumentEditorController::class, 'index'])->name('editor');
        Route::get('/editor/create', [LawyerDocumentEditorController::class, 'create'])->name('editor.create');
        Route::get('/editor/importables', [LawyerDocumentEditorController::class, 'importables'])->name('editor.importables');
        Route::post('/editor', [LawyerDocumentEditorController::class, 'store'])->name('editor.store');
        Route::get('/editor/{doc}', [LawyerDocumentEditorController::class, 'edit'])->name('editor.edit');
        Route::put('/editor/{doc}', [LawyerDocumentEditorController::class, 'update'])->name('editor.update');
        Route::post('/editor/{doc}/approve', [LawyerDocumentEditorController::class, 'approve'])
            ->middleware(Permissions::middleware(Permissions::APPROVE_DOCUMENTS))
            ->name('editor.approve');
        Route::get('/editor/{doc}/print', [LawyerDocumentEditorController::class, 'printDoc'])->name('editor.print');
        Route::get('/editor/{doc}/pdf', [LawyerDocumentEditorController::class, 'downloadPdf'])->name('editor.pdf');
        Route::post('/editor/ai-assist', [LawyerDocumentEditorController::class, 'aiAssist'])->name('editor.ai-assist');
    });

    // استقبال الاستشارات + رحلة الاستشارة + الغرفة — استقبال الاستشارات (والغرفة تحتاج إجراء الجلسات)
    Route::middleware(Permissions::middleware(Permissions::RECEIVE_CONSULTS))->group(function () {
        Route::get('/consults', [StaffConsultController::class, 'index'])->name('consults');
        Route::get('/consultrecv', [StaffConsultController::class, 'recv'])->name('consultrecv');
        Route::get('/consult', [StaffConsultController::class, 'show'])->name('consult');
        Route::post('/consults/{consult}/start', [StaffConsultController::class, 'start'])->name('consults.start');
        Route::post('/consults/{consult}/end', [StaffConsultController::class, 'end'])->name('consults.end');
        Route::post('/consults/{consult}/no-show', [StaffConsultController::class, 'noShow'])->name('consults.noshow');
        Route::post('/consults/{consult}/reschedule', [StaffConsultController::class, 'reschedule'])->name('consults.reschedule');
        Route::post('/consults/{consult}/reschedule-request/dismiss', [StaffConsultController::class, 'dismissRescheduleRequest'])->name('consults.reschedule-request.dismiss');
        Route::post('/consults/{consult}/reqdocs', [StaffConsultController::class, 'requestDocs'])->name('consults.reqdocs');
        Route::post('/consults/{consult}/analyze', [StaffConsultController::class, 'analyze'])->name('consults.analyze')->middleware(Permissions::middleware(Permissions::RUN_LEGAL_ANALYSIS)); // انظر شرح النسخة أعلاه
        Route::post('/consults/{consult}/analysis', [StaffConsultController::class, 'saveAnalysis'])->name('consults.analysis');
        // تحرير ملخّص الجلسة قبل اعتماده — «تعديل واعتماد» كان خياراً بلا حقلٍ يستقبله
        Route::post('/consults/{consult}/summary', [StaffConsultController::class, 'saveSummary'])->name('consults.summary')->middleware(Permissions::middleware(Permissions::APPROVE_CONSULT_SUMMARY));
        // الاعتماد من شاشة الملفّ — لملخّصٍ لا قيد له في `ai_runs` فلا يبلغ الصندوق أبداً.
        Route::post('/consults/{consult}/summary/approve', [StaffConsultController::class, 'approveSummary'])->name('consults.summary.approve')->middleware(Permissions::middleware(Permissions::APPROVE_CONSULT_SUMMARY));
        Route::post('/consults/{consult}/approve', [StaffConsultController::class, 'approveAnalysis'])->name('consults.approve');
        Route::post('/consults/{consult}/zoom-sync', [StaffConsultController::class, 'zoomSync'])->name('consults.zoomsync');
        Route::post('/consults/{consult}/refer', [StaffConsultController::class, 'refer'])->name('consults.refer');
        Route::post('/consults/{consult}/tasks', [StaffConsultController::class, 'createTasks'])->name('consults.tasks');
        // مخرجات الجلسة عبر الخادم — للاستشارات المسندة إلى المحامي وحده (ScopedToLawyer)
        Route::get('/consults/{consult}/recording.zip', [StaffConsultRecordingController::class, 'video'])->name('consults.recording');
        Route::get('/consults/{consult}/audio.zip', [StaffConsultRecordingController::class, 'audio'])->name('consults.audio');
        Route::get('/consults/{consult}/transcript.txt', [StaffConsultRecordingController::class, 'transcript'])->name('consults.transcript');
        Route::get('/consults/{consult}/stream/{type}', [StaffConsultRecordingController::class, 'stream'])->whereIn('type', ['video', 'audio'])->name('consults.stream');
    });
    // أيّ من الصلاحيتين تكفي: الغرفة تُفتح من شاشة الاستقبال أيضاً — حصرها بواحدة كان يصدّ حاملي الأخرى
    Route::get('/videoroom', [StaffConsultController::class, 'room'])
        ->middleware(Permissions::middleware(Permissions::RUN_VIDEO_SESSIONS, Permissions::RECEIVE_CONSULTS))->name('videoroom');
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
    Route::get('/clients/{client}/statement', [ClientStatementController::class, 'forClient'])->name('clients.statement');
    Route::get('/clients/{client}/statement/pdf', [ClientStatementController::class, 'forClientPdf'])->name('clients.statement.pdf');
    // التقويم والمواعيد — لوحة الإدارة كانت بلا أي تبويب زمني. نفس متحكّم الموظف
    // (نطاق المكتب نفسه)، نظير توجيه تذاكر الإدارة إلى متحكّم المستشار أدناه.
    Route::get('/calendar', [EmployeeCalendarController::class, 'index'])->name('calendar');
    // نظيرا الجدولة للوحة الإدارة — التقويم الإداري يحجز ويجلب الفترات من مساراته هو
    // (قرار 2026-08-28: لا يمرّ الأدمن عبر بوابات الأدوار الأخرى إطلاقًا)
    Route::get('/schedule/slots', [EmployeeScheduleController::class, 'slots'])->name('schedule.slots');
    Route::get('/schedule/day-slots', [EmployeeScheduleController::class, 'daySlots'])->name('schedule.day-slots');
    Route::post('/schedule', [EmployeeScheduleController::class, 'store'])->name('schedule.store');
    Route::post('/consults/request', [EmployeeScheduleController::class, 'requestFor'])->name('consults.request');
    // اعتماد موعدٍ اقترحه موظّف — كما هو أو بعد تعديله (قرار المالك 2026-09-14)
    Route::post('/consults/{consult}/appointment/approve', [StaffConsultController::class, 'approveAppointment'])->name('consults.appointment.approve');
    Route::get('/tickets', [AdminTicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{ticket}', [AdminTicketController::class, 'show'])->name('tickets.show');
    // تصحيح الحالة استثناءٌ إداريّ مسبَّب — لا قائمة حالات بيد الموظّف (قرار المالك 2026-09-14)
    Route::post('/tickets/{ticket}/correct-status', [AdminTicketController::class, 'correctStatus'])->name('tickets.correct-status');
    // صفحة تذكرة الإدارة تعيد استخدام شاشة المستشار — فتحتاج نظائر admin.* لإجراءاتها
    Route::post('/tickets/{ticket}/request-docs', [LawyerTicketController::class, 'requestDocs'])->middleware('conversation.reply')->name('tickets.reqdocs');
    Route::post('/tickets/{ticket}/reply', [LawyerTicketController::class, 'reply'])->middleware('conversation.reply')->name('tickets.reply');
    // ملاحظة إدارية داخلية (يطابق adtSaveNote المرجعي) — نفس ميثود المستشار (واعٍ بالدور) ولا تصل قناة العميل
    Route::post('/tickets/{ticket}/note', [LawyerTicketController::class, 'note'])->name('tickets.note');
    Route::get('/tickets/{ticket}/requirements', [StaffTicketRequirementController::class, 'show'])->name('tickets.requirements');
    Route::post('/tickets/{ticket}/requirements', [StaffTicketRequirementController::class, 'update'])->name('tickets.requirements.update');
    Route::post('/tickets/{ticket}/track/propose', [AdminTicketController::class, 'proposeTrack'])->name('tickets.track.propose');
    Route::post('/tickets/{ticket}/track/approve', [AdminTicketController::class, 'approveTrack'])->name('tickets.track.approve');
    Route::get('/lawyers', [AdminLawyerController::class, 'index'])->name('lawyers');
    Route::get('/lawyers/{user}', [AdminLawyerController::class, 'show'])->whereNumber('user')->name('lawyers.show');
    Route::post('/lawyers/{user}/mode', [AdminLawyerController::class, 'toggleMode'])->name('lawyers.mode');
    // رحلة الاستشارة — مربوطة بقاعدة البيانات (+ صلاحيات الإدارة: الأولوية)
    Route::get('/consults', [StaffConsultController::class, 'index'])->name('consults');
    Route::get('/consult-requests', [StaffConsultController::class, 'requests'])->name('consult-requests')->middleware(Permissions::middleware(Permissions::MANAGE_BOOKINGS));
    Route::post('/consults/{consult}/remind-schedule', [StaffConsultController::class, 'remindSchedule'])->name('consults.remind-schedule')->middleware(Permissions::middleware(Permissions::MANAGE_BOOKINGS));
    Route::post('/consults/{consult}/cancel-request', [StaffConsultController::class, 'cancelRequest'])->name('consults.cancel-request')->middleware(Permissions::middleware(Permissions::MANAGE_BOOKINGS));
    Route::post('/consults/{consult}/price', [StaffConsultController::class, 'setPrice'])->name('consults.price')->middleware(Permissions::middleware(Permissions::MANAGE_BOOKINGS));
    // تصحيح تسعيرٍ خاطئ قبل السداد — لم يكن للمشروع مخرجٌ منه إلّا إلغاء الطلب كلّه
    Route::post('/consults/{consult}/reprice', [StaffConsultController::class, 'reprice'])->name('consults.reprice')->middleware(Permissions::middleware(Permissions::MANAGE_BOOKINGS));
    Route::post('/consults/{consult}/reqdocs', [StaffConsultController::class, 'requestDocs'])->name('consults.reqdocs');
    Route::post('/consults/{consult}/analyze', [StaffConsultController::class, 'analyze'])->name('consults.analyze')->middleware(Permissions::middleware(Permissions::RUN_LEGAL_ANALYSIS));
    Route::post('/consults/{consult}/analysis', [StaffConsultController::class, 'saveAnalysis'])->name('consults.analysis')->middleware(Permissions::middleware(Permissions::APPROVE_CONSULT_SUMMARY));
    Route::post('/consults/{consult}/summary', [StaffConsultController::class, 'saveSummary'])->name('consults.summary')->middleware(Permissions::middleware(Permissions::APPROVE_CONSULT_SUMMARY));
    // الاعتماد من شاشة الملفّ — لملخّصٍ لا قيد له في `ai_runs` فلا يبلغ الصندوق أبداً.
    Route::post('/consults/{consult}/summary/approve', [StaffConsultController::class, 'approveSummary'])->name('consults.summary.approve')->middleware(Permissions::middleware(Permissions::APPROVE_CONSULT_SUMMARY));
    Route::post('/consults/{consult}/approve', [StaffConsultController::class, 'approveAnalysis'])->name('consults.approve')->middleware(Permissions::middleware(Permissions::APPROVE_CONSULT_SUMMARY));
    Route::post('/consults/{consult}/zoom-sync', [StaffConsultController::class, 'zoomSync'])->name('consults.zoomsync');
    Route::post('/consults/{consult}/refer', [StaffConsultController::class, 'refer'])->name('consults.refer');
    Route::post('/consults/{consult}/priority', [StaffConsultController::class, 'priority'])->name('consults.priority');
    // تسجيل الموظفين وإدارتهم (مربوط بقاعدة البيانات + spatie)
    Route::get('/staff', [StaffController::class, 'index'])->name('staff')->middleware(Permissions::middleware(Permissions::MANAGE_STAFF));
    Route::get('/staff/lookup', [StaffController::class, 'lookup'])->name('staff.lookup')->middleware(Permissions::middleware(Permissions::MANAGE_STAFF));
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store')->middleware(Permissions::middleware(Permissions::MANAGE_STAFF));
    Route::put('/staff/{user}', [StaffController::class, 'update'])->name('staff.update')->middleware(Permissions::middleware(Permissions::MANAGE_STAFF));
    Route::post('/staff/{user}/toggle', [StaffController::class, 'toggle'])->name('staff.toggle')->middleware(Permissions::middleware(Permissions::MANAGE_STAFF));
    // مستحقّات الموظّف وسجلّ صرفه — القيد يُسجَّل ويُلغى، ولا تعديل ولا حذف
    Route::get('/staff/{user}/earnings', [StaffPayoutController::class, 'show'])->name('staff.earnings')->middleware(Permissions::middleware(Permissions::MANAGE_STAFF));
    Route::post('/staff/{user}/payouts', [StaffPayoutController::class, 'store'])->name('staff.payouts.store')->middleware(Permissions::middleware(Permissions::MANAGE_STAFF));
    Route::post('/staff/{user}/payouts/{payout}/void', [StaffPayoutController::class, 'void'])->name('staff.payouts.void')->middleware(Permissions::middleware(Permissions::MANAGE_STAFF));
    Route::get('/staff/{user}/payouts/{payout}/voucher.pdf', [StaffPayoutController::class, 'voucher'])->name('staff.payouts.voucher')->middleware(Permissions::middleware(Permissions::MANAGE_STAFF));
    Route::get('/archive', [AdminArchiveController::class, 'index'])->name('archive')->middleware(Permissions::middleware(Permissions::CONSULT_ARCHIVE));
    // مخرجات جلسة الاستشارة عبر الخادم (جلب من سحابة Zoom): فيديو/صوت + نصّ تفريغي + تشغيلٌ داخل النظام
    Route::get('/consults/{consult}/recording.zip', [StaffConsultRecordingController::class, 'video'])->name('consults.recording')->middleware(Permissions::middleware(Permissions::CONSULT_ARCHIVE));
    Route::get('/consults/{consult}/audio.zip', [StaffConsultRecordingController::class, 'audio'])->name('consults.audio')->middleware(Permissions::middleware(Permissions::CONSULT_ARCHIVE));
    Route::get('/consults/{consult}/transcript.txt', [StaffConsultRecordingController::class, 'transcript'])->name('consults.transcript')->middleware(Permissions::middleware(Permissions::CONSULT_ARCHIVE));
    Route::get('/consults/{consult}/stream/{type}', [StaffConsultRecordingController::class, 'stream'])->whereIn('type', ['video', 'audio'])->name('consults.stream')->middleware(Permissions::middleware(Permissions::CONSULT_ARCHIVE));
    Route::get('/distribute', [AdminDistributeController::class, 'index'])->name('distribute')->middleware(Permissions::middleware(Permissions::DISTRIBUTE_TICKETS));
    Route::post('/distribute/auto', [AdminDistributeController::class, 'auto'])->name('distribute.auto')->middleware(Permissions::middleware(Permissions::DISTRIBUTE_TICKETS));
    // الإسناد الجماعيّ طلبٌ واحد (قبل `/distribute/{ticket}` كي لا تُقرأ «bulk» رقمَ تذكرة)
    Route::post('/distribute/bulk', [AdminDistributeController::class, 'bulk'])->name('distribute.bulk')->middleware(Permissions::middleware(Permissions::DISTRIBUTE_TICKETS));
    Route::post('/distribute/case/{case}', [AdminDistributeController::class, 'assignCase'])->name('distribute.assign-case')->middleware(Permissions::middleware(Permissions::DISTRIBUTE_TICKETS));
    Route::post('/distribute/execution/{execution}', [AdminDistributeController::class, 'assignExecution'])->name('distribute.assign-execution')->middleware(Permissions::middleware(Permissions::DISTRIBUTE_TICKETS));
    Route::post('/distribute/consult/{consult}', [AdminDistributeController::class, 'assignConsult'])->name('distribute.assign-consult')->middleware(Permissions::middleware(Permissions::DISTRIBUTE_TICKETS));
    Route::post('/distribute/{ticket}', [AdminDistributeController::class, 'assign'])->name('distribute.assign')->middleware(Permissions::middleware(Permissions::DISTRIBUTE_TICKETS));
    Route::get('/casefees', [AdminCaseController::class, 'fees'])->name('casefees');
    Route::get('/cases', [AdminCaseController::class, 'index'])->name('cases');
    // تفاصيل القضيّة للإدارة — كانت بلا صفحة (المحادثة والجلسات والمستندات)
    Route::get('/cases/{case}', [AdminCaseController::class, 'show'])->name('cases.show');
    Route::post('/cases/{case}/fee', [AdminCaseController::class, 'setFee'])->name('cases.fee');
    Route::post('/cases/{case}/close', [AdminCaseController::class, 'closeCase'])->name('cases.close');
    Route::post('/cases/{case}/reopen', [AdminCaseController::class, 'reopenCase'])->name('cases.reopen');
    Route::post('/cases/{case}/archive', [AdminCaseController::class, 'archiveCase'])->name('cases.archive');
    // إعادة إسناد محامي القضيّة — لم يكن لها مسار
    Route::post('/cases/{case}/lawyer', [AdminCaseController::class, 'reassignLawyer'])->name('cases.lawyer');
    // الدالّة اسمها execute — الإشارة إلى convertToExecution (اسم نظيرتها لدى المحامي) كانت ترمي 500 دوماً
    Route::post('/cases/{case}/execute', [AdminCaseController::class, 'execute'])->name('cases.execute');
    Route::post('/cases/{case}/execution-request/approve', [AdminCaseController::class, 'approveExecutionRequest'])->name('cases.execution-request.approve');
    Route::post('/cases/{case}/execution-request/reject', [AdminCaseController::class, 'rejectExecutionRequest'])->name('cases.execution-request.reject');
    // التنفيذ — تبويب موحّد (تدفّق + تنفيذات قديمة) لدور الإدارة العليا
    Route::get('/execs', [ExecFlowController::class, 'admin'])->name('execs');
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
    Route::post('/meetreqs/{meetRequest}/approve', [StaffMeetRequestController::class, 'approve'])->name('meetreqs.approve')->middleware(Permissions::middleware(Permissions::APPROVE_MEETINGS));
    Route::get('/meetreqs/availability', [StaffMeetRequestController::class, 'availability'])->name('meetreqs.availability');
    Route::get('/meetlog', [StaffMeetingController::class, 'log'])->name('meetlog')->middleware(Permissions::middleware(Permissions::MEETING_REPORTS));
    Route::get('/meetings', [StaffMeetingController::class, 'index'])->name('meetings');
    Route::get('/meetingroom', [StaffMeetingController::class, 'room'])->name('meetingroom');
    Route::post('/meetings/{meeting}/summary', [StaffMeetingController::class, 'saveSummary'])->name('meetings.summary');
    Route::post('/meetings/{meeting}/minutes', [StaffMeetingController::class, 'saveMinutes'])->name('meetings.minutes');
    Route::post('/meetings/{meeting}/approve', [StaffMeetingController::class, 'approve'])->name('meetings.approve')->middleware(Permissions::middleware(Permissions::APPROVE_MEETINGS));
    Route::post('/meetings/{meeting}/start', [StaffMeetingController::class, 'start'])->name('meetings.start');
    Route::post('/meetings/{meeting}/end', [StaffMeetingController::class, 'end'])->name('meetings.end');
    Route::post('/meetings/{meeting}/reschedule', [StaffMeetingController::class, 'reschedule'])->name('meetings.reschedule');
    Route::post('/meetings/{meeting}/cancel', [StaffMeetingController::class, 'cancel'])->name('meetings.cancel');
    Route::post('/meetings/{meeting}/tasks', [StaffMeetingController::class, 'createTasks'])->name('meetings.tasks');
    Route::get('/meetings/{meeting}/transcript', [StaffMeetingController::class, 'transcript'])->name('meetings.transcript');
    Route::post('/meetings/{meeting}/zoom-sync', [StaffMeetingController::class, 'zoomSync'])->name('meetings.zoomsync');
    Route::get('/meetings/{meeting}/recording.zip', [StaffMeetingController::class, 'recordingZip'])->name('meetings.recording');
    Route::get('/meetings/{meeting}/audio.zip', [StaffMeetingController::class, 'audioZip'])->name('meetings.audio');
    // التشغيل داخل النظام — `recording-ui.tsx` يبني `${base}/meetings/{id}/stream/*` لكلّ دور؛
    // غيابُه هنا وحدَه كان يُسقط مشغّل الإدارة بـ404 بينما يعمل لدى المحامي والموظّف
    Route::get('/meetings/{meeting}/stream/{type}', [StaffMeetingController::class, 'stream'])->whereIn('type', ['video', 'audio'])->name('meetings.stream');
    // «مركز الاعتمادات والقرارات»: ملخّصات التذاكر والجلسات ومواعيد الموظّفين ومسارات المآل
    Route::get('/approvals', [AdminApprovalsController::class, 'index'])->name('approvals');
    Route::post('/approvals/reject', [AdminApprovalsController::class, 'reject'])->name('approvals.reject');
    // «استبعاد» بلا سبب أُزيل (2026-09-26): لا تناديه الواجهة، وكان يُعيد المقترح/الملخّص/المحضر بلا سببٍ
    // ولا إبلاغ — مسار التفافٍ على الرفض المسبَّب (`approvals.reject`)
    // مراجعة/اعتماد/تعديل ملخص الملف (إشراف الإدارة العليا — صلاحيات مطلقة)
    Route::get('/summary/{ticket}', [LawyerTicketController::class, 'showSummary'])->name('summary');
    Route::post('/summary/{ticket}', [LawyerTicketController::class, 'updateSummary'])->name('summary.update');
    Route::post('/summary/{ticket}/approve', [LawyerTicketController::class, 'approveSummary'])->name('summary.approve');
    Route::post('/summary/{ticket}/rerun', [LawyerTicketController::class, 'rerunSummary'])->name('summary.rerun');
    Route::post('/summary/{ticket}/najiz', [LawyerTicketController::class, 'generateNajizDraft'])->name('summary.najiz');
    Route::get('/summary/{ticket}/print', [LawyerTicketController::class, 'printSummary'])->name('summary.print');
    Route::get('/assistant', [LawyerAssistantController::class, 'index'])->name('assistant');
    Route::post('/assistant/generate', [LawyerAssistantController::class, 'generate'])->name('assistant.generate');
    Route::post('/assistant/to-editor', [LawyerAssistantController::class, 'toEditor'])->name('assistant.to-editor');
    // محرر الصياغة القانونية — نسخة الإدارة العليا (ترى كل المستندات)
    Route::get('/editor', [LawyerDocumentEditorController::class, 'index'])->name('editor');
    Route::get('/editor/create', [LawyerDocumentEditorController::class, 'create'])->name('editor.create');
    Route::get('/editor/importables', [LawyerDocumentEditorController::class, 'importables'])->name('editor.importables');
    Route::post('/editor', [LawyerDocumentEditorController::class, 'store'])->name('editor.store');
    Route::get('/editor/{doc}', [LawyerDocumentEditorController::class, 'edit'])->name('editor.edit');
    Route::put('/editor/{doc}', [LawyerDocumentEditorController::class, 'update'])->name('editor.update');
    Route::post('/editor/{doc}/approve', [LawyerDocumentEditorController::class, 'approve'])->name('editor.approve');
    Route::get('/editor/{doc}/print', [LawyerDocumentEditorController::class, 'printDoc'])->name('editor.print');
    Route::get('/editor/{doc}/pdf', [LawyerDocumentEditorController::class, 'downloadPdf'])->name('editor.pdf');
    Route::post('/editor/ai-assist', [LawyerDocumentEditorController::class, 'aiAssist'])->name('editor.ai-assist');
    Route::get('/revenue', [AdminReportController::class, 'revenue'])->name('revenue')->middleware(Permissions::middleware(Permissions::REPORTS_AND_REVENUE));
    // تصدير PDF — كانت الشاشتان بلا أي تصدير أو طباعة
    Route::get('/reports.pdf', [AdminReportController::class, 'reportsPdf'])->name('reports.pdf')->middleware(Permissions::middleware(Permissions::REPORTS_AND_REVENUE));
    Route::get('/revenue.pdf', [AdminReportController::class, 'revenuePdf'])->name('revenue.pdf')->middleware(Permissions::middleware(Permissions::REPORTS_AND_REVENUE));
    // إعدادات النظام — متغيّرات كانت ثوابتَ في الشيفرة أو صفوفاً بلا شاشة (أظهرها
    // `exec_working_days_from`: إعدادٌ مقصود ولا باب لكتابته إلّا SQL على الإنتاج).
    // **بلا صلاحيّة مستحدثة**: `Gate::before` يجعل الأدمن يتجاوز كلّ `permission:`، فصلاحيّةٌ
    // جديدة لا تحرس شيئاً عنه — ومنحُها لغيره يُسقطها `PermissionReachabilityTest` لأنّ
    // المسار داخل `role:admin` فلا يبلغه سواه.
    Route::get('/settings', [AdminSettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [AdminSettingsController::class, 'update'])->name('settings.update');
    // «الأقسام والخدمات» — كتالوج الأقسام القانونيّة وخدماتها والأقسام الإداريّة (قرار المالك 2026-09-14).
    // بلا صلاحيّة مستحدثة للتعليل نفسه أعلاه. لا حذف: إيقافٌ يُبقي السجلّات.
    Route::get('/catalogue', [AdminCatalogueController::class, 'index'])->name('catalogue');
    Route::post('/catalogue/departments', [AdminCatalogueController::class, 'storeDepartment'])->name('catalogue.departments.store');
    Route::post('/catalogue/departments/reorder', [AdminCatalogueController::class, 'reorderDepartments'])->name('catalogue.departments.reorder');
    Route::put('/catalogue/departments/{department}', [AdminCatalogueController::class, 'updateDepartment'])->name('catalogue.departments.update');
    Route::post('/catalogue/departments/{department}/toggle', [AdminCatalogueController::class, 'toggleDepartment'])->name('catalogue.departments.toggle');
    Route::post('/catalogue/departments/{department}/services', [AdminCatalogueController::class, 'storeService'])->name('catalogue.services.store');
    Route::post('/catalogue/departments/{department}/services/reorder', [AdminCatalogueController::class, 'reorderServices'])->name('catalogue.services.reorder');
    Route::put('/catalogue/services/{service}', [AdminCatalogueController::class, 'updateService'])->name('catalogue.services.update');
    Route::post('/catalogue/services/{service}/toggle', [AdminCatalogueController::class, 'toggleService'])->name('catalogue.services.toggle');
    // قائمة المستندات المطلوبة لكلّ قسم (قرار المالك 2026-09-26) — بالحارس نفسه: لا صلاحيّة مستحدثة للكتالوج
    Route::post('/catalogue/departments/{department}/documents', [AdminCatalogueController::class, 'storeDocument'])->name('catalogue.documents.store');
    Route::post('/catalogue/departments/{department}/documents/reorder', [AdminCatalogueController::class, 'reorderDocuments'])->name('catalogue.documents.reorder');
    Route::put('/catalogue/documents/{departmentDocument}', [AdminCatalogueController::class, 'updateDocument'])->name('catalogue.documents.update');
    Route::delete('/catalogue/documents/{departmentDocument}', [AdminCatalogueController::class, 'destroyDocument'])->name('catalogue.documents.destroy');
    Route::post('/catalogue/staff-departments', [AdminCatalogueController::class, 'storeStaffDepartment'])->name('catalogue.staff-departments.store');
    Route::put('/catalogue/staff-departments/{staffDepartment}', [AdminCatalogueController::class, 'updateStaffDepartment'])->name('catalogue.staff-departments.update');
    Route::post('/catalogue/staff-departments/{staffDepartment}/toggle', [AdminCatalogueController::class, 'toggleStaffDepartment'])->name('catalogue.staff-departments.toggle');
    // «المالية والمحاسبة» — الشاشة الواحدة بتبويباتها الستّة خادميّةً عبر `?tab=` (م٣).
    //
    // **بلا صلاحيّة مستحدثة، بقرارٍ موثَّق** (خ٦ في `docs/finance-plan.md`، والقسم ٢٤ في
    // `HANDOVER.md`): كلّ مسارات `/admin/*` داخل `role:admin`، والأدمن يتجاوز كلّ `permission:`
    // بـ`Gate::before`، وغيرُ الأدمن لا يبلغ المجموعة أصلاً بلا استثناء (`EnsureRole`).
    // فصلاحيّة «المالية والمحاسبة» كانت ستظهر مؤشَّرةً في شاشة الصلاحيّات ولا تفتح باباً —
    // سابقةُ «أرشيف الاستشارات» الموثّقة في `Permissions.php` ويحرسها `PermissionReachabilityTest`.
    // ومتى حُسم ق٩ (هل يرى المحامي أرقام موكّلي غيره؟) تُستحدث الصلاحيّة **مع** مسارٍ خارج
    // `role:admin` يقابلها، لا قبله.
    Route::get('/finance', [AdminFinanceController::class, 'index'])->name('finance');
    // التقارير الماليّة — الإيرادات والمصروفات والأرباح والخسائر بمقارنة الفترة السابقة (المرحلة د)
    Route::get('/financial-reports', [AdminFinancialReportController::class, 'index'])->name('financial-reports');
    Route::get('/financial-reports/pdf', [AdminFinancialReportController::class, 'pdf'])->name('financial-reports.pdf');
    Route::get('/financial-reports/csv', [AdminFinancialReportController::class, 'csv'])->name('financial-reports.csv');
    Route::post('/invoices/{invoice}/pay', [AdminFinanceController::class, 'pay'])->name('invoices.pay');
    // دورة حياة الفاتورة من الشاشة — كلٌّ ينادي انتقاله فيُسجَّل في `journey_transitions` (م٢)
    Route::post('/invoices/{invoice}/issue', [AdminFinanceController::class, 'issue'])->name('invoices.issue');
    Route::post('/invoices/{invoice}/cancel', [AdminFinanceController::class, 'cancel'])->name('invoices.cancel');
    Route::post('/invoices/{invoice}/write-off', [AdminFinanceController::class, 'writeOff'])->name('invoices.write-off');
    Route::get('/invoices/{invoice}/proof', [AdminFinanceController::class, 'proof'])->name('invoices.proof');
    Route::get('/receipts/{payment}/pdf', [AdminFinanceController::class, 'receipt'])->name('receipts.pdf');
    // المصروفات (المرحلة ب): الإدارة تسجّل فيُعتمد فوراً، وتعتمد ما سجّله الموظّف أو ترفضه، وتلغي المعتمد بسبب
    Route::post('/expenses', [AdminFinanceController::class, 'storeExpense'])->name('expenses.store');
    Route::post('/expenses/{expense}/approve', [AdminFinanceController::class, 'approveExpense'])->name('expenses.approve');
    Route::post('/expenses/{expense}/reject', [AdminFinanceController::class, 'rejectExpense'])->name('expenses.reject');
    Route::post('/expenses/{expense}/void', [AdminFinanceController::class, 'voidExpense'])->name('expenses.void');
    Route::get('/expenses/{expense}/document', [AdminFinanceController::class, 'expenseDocument'])->name('expenses.document');
    Route::get('/expenses/{expense}/voucher.pdf', [AdminFinanceController::class, 'expenseVoucher'])->name('expenses.voucher');
    // رفض الإثبات يعيد الفاتورة للاستحقاق — رافع الملف الخاطئ كان يفقد زرّ الدفع نهائياً
    Route::post('/invoices/{invoice}/proof/reject', [AdminFinanceController::class, 'rejectProof'])->name('invoices.proof.reject');
    Route::get('/meetreports', [StaffMeetingController::class, 'reports'])->name('meetreports');
    Route::get('/reports', [AdminReportController::class, 'reports'])->name('reports');
    // استقبال الاستشارات وغرفة الجلسة (مربوطة بقاعدة البيانات)
    Route::get('/consultrecv', [StaffConsultController::class, 'recv'])->name('consultrecv');
    Route::get('/videoroom', [StaffConsultController::class, 'room'])->name('videoroom');
    Route::post('/consults/{consult}/start', [StaffConsultController::class, 'start'])->name('consults.start');
    Route::post('/consults/{consult}/end', [StaffConsultController::class, 'end'])->name('consults.end');
    Route::post('/consults/{consult}/no-show', [StaffConsultController::class, 'noShow'])->name('consults.noshow');
    Route::post('/consults/{consult}/reschedule', [StaffConsultController::class, 'reschedule'])->name('consults.reschedule');
    Route::post('/consults/{consult}/reschedule-request/dismiss', [StaffConsultController::class, 'dismissRescheduleRequest'])->name('consults.reschedule-request.dismiss');
    Route::post('/consults/{consult}/tasks', [StaffConsultController::class, 'createTasks'])->name('consults.tasks');
    Route::get('/consult', [StaffConsultController::class, 'show'])->name('consult');
    Route::get('/meeting', [StaffMeetingController::class, 'show'])->name('meeting');
    // سجل الرقابة والتدقيق الأمني (Audit Logs & Activity Trail) — صلاحية مستقلة كنمط
    // بقية الصفحات الإدارية الحساسة (يحوي IPs الجميع وتصدير CSV)
    Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])
        ->middleware(Permissions::middleware(Permissions::SECURITY_AUDIT_LOG))->name('audit-logs');
    Route::get('/audit-logs/export', [AdminAuditLogController::class, 'export'])
        ->middleware(Permissions::middleware(Permissions::SECURITY_AUDIT_LOG))->name('audit-logs.export');

    // سجل انتقالات الرحلة والحالات الموحد (Workflow Journey Transitions Log)
    Route::get('/journey-transitions', [AdminJourneyTransitionController::class, 'index'])
        ->middleware(Permissions::middleware(Permissions::SECURITY_AUDIT_LOG))->name('journey-transitions');
    Route::get('/journey-transitions/export', [AdminJourneyTransitionController::class, 'export'])
        ->middleware(Permissions::middleware(Permissions::SECURITY_AUDIT_LOG))->name('journey-transitions.export');

    // الجلسات القضائية وتواريخ المحاكم — الإدارة العليا
    Route::get('/hearings', [AdminCourtHearingController::class, 'index'])->name('hearings');
    Route::get('/hearings/export', [AdminCourtHearingController::class, 'export'])->name('hearings.export');

    // تشغيل الذكاء وحوكمته — المؤشّرات ومعايرة العتبة والأسعار ومدد الاحتفاظ.
    // قرارات مكتب لا هندسة: العتبة قانونيّة والأسعار محاسبيّة والاحتفاظ نظاميّ،
    // فلا يصحّ أن يلزمها تعديل كود ونشر.
    Route::middleware(Permissions::middleware(Permissions::REPORTS_AND_REVENUE))->group(function () {
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
        ->middleware(Permissions::middleware(Permissions::LEGAL_ASSISTANT))->name('legal-sources');
    Route::post('/legal-sources/{source}/approve', [AdminLegalSourceController::class, 'approve'])
        ->middleware(Permissions::middleware(Permissions::LEGAL_ASSISTANT))->name('legal-sources.approve');
    // اعتماد نظامٍ كامل: مئات المواد لا تُعتمد بمئات النقرات
    Route::post('/legal-sources/approve-system', [AdminLegalSourceController::class, 'approveSystem'])
        ->middleware(Permissions::middleware(Permissions::LEGAL_ASSISTANT))->name('legal-sources.approve-system');
    Route::post('/legal-sources/{source}/suspend', [AdminLegalSourceController::class, 'suspend'])
        ->middleware(Permissions::middleware(Permissions::LEGAL_ASSISTANT))->name('legal-sources.suspend');

    // صندوق مراجعة مخرجات الذكاء (P3) — محروس بصلاحية «اعتماد الملخصات»:
    // مراجعة مخرج قانونيّ فعلُ اعتماد، فيُحرَس بما يُحرَس به الاعتماد لا بأقلّ منه.
    Route::get('/ai-review', [AdminAiReviewController::class, 'index'])
        ->middleware(Permissions::middleware(Permissions::APPROVE_SUMMARIES))->name('ai-review');
    Route::post('/ai-review/{run}/decide', [AdminAiReviewController::class, 'decide'])
        ->middleware(Permissions::middleware(Permissions::APPROVE_SUMMARIES))->name('ai-review.decide');

    // الطبقة الثالثة: عيّنة عمياء يحكم عليها محامٍ قبل كشف مصدرها
    Route::get('/ai-blind-review', [AdminAiBlindReviewController::class, 'index'])
        ->middleware(Permissions::middleware(Permissions::APPROVE_SUMMARIES))->name('ai-blind-review');
    Route::post('/ai-blind-review/draw', [AdminAiBlindReviewController::class, 'draw'])
        ->middleware(Permissions::middleware(Permissions::APPROVE_SUMMARIES))->name('ai-blind-review.draw');
    Route::post('/ai-blind-review/{review}/judge', [AdminAiBlindReviewController::class, 'judge'])
        ->middleware(Permissions::middleware(Permissions::APPROVE_SUMMARIES))->name('ai-blind-review.judge');
});

/*
 * **الرابط المجهول صفحةُ ٤٠٤ عربيّة داخل التطبيق** — لا صفحة لارافل الإنجليزيّة.
 *
 * رابطٌ لا يطابق مساراً يُرفض في الموجِّه قبل مجموعة `web`: فلا جلسة ولا مستخدم ولا خصائص
 * مشتركة، فتسقط صفحته على القالب الساكن. مسار الاحتياط يمرّ بالمجموعة كاملةً، فيعرض
 * `ErrorResponse` الصفحة بالشريط الجانبيّ لمن سجّل دخوله — والرسالة من الخريطة الواحدة.
 *
 * بكلّ الأفعال لا GET وحده (`Route::fallback` يسجّل GET): احتياطُ GET وحده يجعل كلّ نشرٍ إلى رابطٍ
 * مجهول «٤٠٥ الفعل غير مدعوم» بدل ٤٠٤ — ومسارٌ أُزيل (`/impersonate/leave`) يبقى ٤٠٤ كما تحرسه اختباراته.
 */
Route::any('{fallbackPlaceholder}', fn () => abort(404))->where('fallbackPlaceholder', '.*')->fallback();
