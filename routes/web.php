<?php

use App\Http\Controllers\Admin\AccountingController as AdminAccountingController;
use App\Http\Controllers\Admin\ArchiveController as AdminArchiveController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CaseController as AdminCaseController;
use App\Http\Controllers\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Admin\ClientNotifController as AdminClientNotifController;
use App\Http\Controllers\Admin\DistributeController as AdminDistributeController;
use App\Http\Controllers\Admin\ExecutionController as AdminExecutionController;
use App\Http\Controllers\Admin\LawyerController as AdminLawyerController;
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
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Employee\CaseController as EmployeeCaseController;
use App\Http\Controllers\Employee\ExecutionController as EmployeeExecutionController;
use App\Http\Controllers\Employee\ScheduleController as EmployeeScheduleController;
use App\Http\Controllers\Employee\TicketController as EmployeeTicketController;
use App\Http\Controllers\Employee\TransferController as EmployeeTransferController;
use App\Http\Controllers\ExecutionController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\Lawyer\AssistantController as LawyerAssistantController;
use App\Http\Controllers\Lawyer\CalendarController as LawyerCalendarController;
use App\Http\Controllers\Lawyer\CaseController as LawyerCaseController;
use App\Http\Controllers\Lawyer\ExecutionController as LawyerExecutionController;
use App\Http\Controllers\Lawyer\TaskController as LawyerTaskController;
use App\Http\Controllers\Lawyer\TicketController as LawyerTicketController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MeetRequestController;
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

// المصادقة
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

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
    Route::post('/cases/{case}/pay', [CaseController::class, 'pay'])->name('cases.pay');
    Route::post('/cases/{case}/pay-installment', [CaseController::class, 'payInstallment'])->name('cases.pay-installment');

    // طلبات التنفيذ (مربوطة بقاعدة البيانات)
    Route::get('/execs', [ExecutionController::class, 'index'])->name('execs');
    Route::post('/execs', [ExecutionController::class, 'store'])->name('execs.store');
    Route::get('/execs/{execution}', [ExecutionController::class, 'show'])->name('execs.show');
    Route::post('/execs/{execution}/messages', [ExecutionController::class, 'storeMessage'])->name('execs.messages.store');

    // الاستشارات — «استشاراتي» مربوطة بقاعدة البيانات؛ الجلسات المرئية عبر Zoom
    Route::get('/book', [ConsultBookingController::class, 'index'])->name('book');
    Route::get('/book/availability', [ConsultBookingController::class, 'availability'])->name('book.availability');
    Route::post('/book', [ConsultBookingController::class, 'store'])->name('book.store');
    Route::get('/myconsults', [ConsultController::class, 'index'])->name('myconsults');
    Route::get('/consults/room', [ConsultController::class, 'room'])->name('consults.room');
    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments');
    Route::get('/meetings', [MeetingController::class, 'index'])->name('meetings');
    Route::get('/meetingroom', [MeetingController::class, 'room'])->name('meetingroom');
    // دعوات الاجتماعات (مربوطة بقاعدة البيانات — تأكيد الحضور يُنشئ جلسة Zoom)
    Route::get('/meetreqs', [MeetRequestController::class, 'index'])->name('meetreqs');
    Route::post('/meetreqs/{meetRequest}/confirm', [MeetRequestController::class, 'confirm'])->name('meetreqs.confirm');
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');

    // الملفات والمالية (مربوطة بقاعدة البيانات)
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents');
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices');
});

// الحساب — متاح لأي مستخدم مسجّل
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::inertia('/profile', 'profile')->name('profile');
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    // إنهاء معاينة لوحة الموظف (إمبرسنيشن)
    Route::post('/impersonate/leave', [ImpersonationController::class, 'leave'])->name('impersonate.leave');
    // توقيع تضمين Zoom (Meeting SDK) — متاح للعميل والموظف؛ التفويض في المتحكّم عبر ChannelAccess
    Route::post('/zoom/sdk-signature', [ZoomController::class, 'sdkSignature'])->name('zoom.signature');
});

// ── لوحة الموظف ── (deny-by-default: صلاحية صريحة لكل إجراء حسّاس فوق حارس الدور)
Route::middleware(['auth', 'active', 'role:employee'])->prefix('employee')->name('employee.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'employee'])->name('dashboard'); // عام للدور

    // التذاكر — إدارة التذاكر (والرد على العملاء لمسار الردّ)
    Route::middleware('permission:إدارة التذاكر')->group(function () {
        Route::get('/tickets', [EmployeeTicketController::class, 'index'])->name('tickets');
        Route::get('/tickets/{ticket}', [EmployeeTicketController::class, 'show'])->name('tickets.show');
        Route::post('/tickets/{ticket}/note', [EmployeeTicketController::class, 'note'])->name('tickets.note');
        Route::post('/tickets/{ticket}/status', [EmployeeTicketController::class, 'status'])->name('tickets.status');
        Route::post('/tickets/{ticket}/advance', [EmployeeTicketController::class, 'advance'])->name('tickets.advance');
        Route::post('/tickets/{ticket}/convert', [EmployeeTicketController::class, 'convertToCase'])->name('tickets.convert');
        Route::post('/tickets/{ticket}/documents/{document}/approve-summary', [EmployeeTicketController::class, 'approveDocSummary'])->name('tickets.documents.approve-summary');
    });
    Route::post('/tickets/{ticket}/reply', [EmployeeTicketController::class, 'reply'])
        ->middleware('permission:الرد على العملاء')->name('tickets.reply');

    // القضايا وطلبات التنفيذ — إدارة القضايا والأتعاب
    Route::middleware('permission:إدارة القضايا والأتعاب')->group(function () {
        Route::get('/cases', [EmployeeCaseController::class, 'index'])->name('cases');
        Route::get('/cases/{case}', [EmployeeCaseController::class, 'show'])->name('cases.show');
        Route::post('/cases/{case}/reply', [EmployeeCaseController::class, 'reply'])->name('cases.reply');
        Route::get('/execs', [EmployeeExecutionController::class, 'index'])->name('execs');
        Route::get('/execs/{execution}', [EmployeeExecutionController::class, 'show'])->name('execs.show');
        Route::post('/execs/{execution}/reply', [EmployeeExecutionController::class, 'reply'])->name('execs.reply');
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
        Route::post('/consults/{consult}/refer', [StaffConsultController::class, 'refer'])->name('consults.refer');
        Route::get('/consultrecv', [StaffConsultController::class, 'recv'])->name('consultrecv');
        Route::post('/consults/{consult}/start', [StaffConsultController::class, 'start'])->name('consults.start');
        Route::post('/consults/{consult}/end', [StaffConsultController::class, 'end'])->name('consults.end');
        Route::post('/consults/{consult}/tasks', [StaffConsultController::class, 'createTasks'])->name('consults.tasks');
    });
    Route::get('/videoroom', [StaffConsultController::class, 'room'])
        ->middleware('permission:إجراء الجلسات المرئية')->name('videoroom');

    Route::middleware('permission:جدولة المواعيد')->group(function () {
        Route::get('/schedule', [EmployeeScheduleController::class, 'index'])->name('schedule');
        Route::post('/schedule', [EmployeeScheduleController::class, 'store'])->name('schedule.store');
    });
    Route::middleware('permission:تحويل التذاكر')->group(function () {
        Route::get('/transfer', [EmployeeTransferController::class, 'index'])->name('transfer');
        Route::post('/transfer/{ticket}', [EmployeeTransferController::class, 'transfer'])->name('transfer.do');
    });

    // طلبات الاجتماعات — إرسال دعوات الاجتماعات
    Route::middleware('permission:إرسال دعوات الاجتماعات')->group(function () {
        Route::get('/meetreqs', [StaffMeetRequestController::class, 'index'])->name('meetreqs');
        Route::post('/meetreqs', [StaffMeetRequestController::class, 'store'])->name('meetreqs.store');
        Route::post('/meetreqs/{meetRequest}/cancel', [StaffMeetRequestController::class, 'cancel'])->name('meetreqs.cancel');
        Route::post('/meetreqs/{meetRequest}/start', [StaffMeetRequestController::class, 'start'])->name('meetreqs.start');
    });
});

// ── لوحة المحامي ── (deny-by-default: صلاحية صريحة لكل إجراء حسّاس فوق حارس الدور)
Route::middleware(['auth', 'active', 'role:lawyer'])->prefix('lawyer')->name('lawyer.')->group(function () {
    Route::get('/dashboard', [LawyerTicketController::class, 'dashboard'])->name('dashboard'); // عام للدور
    // التذاكر المحالة — عرض عام للمحامي؛ الإجراءات الحسّاسة مُصرَّحة أدناه
    Route::get('/tickets', [LawyerTicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{ticket}', [LawyerTicketController::class, 'show'])->name('tickets.show');
    Route::get('/calendar', [LawyerCalendarController::class, 'index'])->name('calendar');

    // الملخصات والاعتماد — اعتماد الملخصات
    Route::middleware('permission:اعتماد الملخصات')->group(function () {
        Route::get('/summaries', [LawyerTicketController::class, 'summaries'])->name('summaries');
        Route::get('/summary/{ticket}', [LawyerTicketController::class, 'showSummary'])->name('summary');
        Route::post('/summary/{ticket}', [LawyerTicketController::class, 'updateSummary'])->name('summary.update');
        Route::post('/summary/{ticket}/approve', [LawyerTicketController::class, 'approveSummary'])->name('summary.approve');
        Route::post('/tickets/{ticket}/result', [LawyerTicketController::class, 'approveResult'])->name('result.approve');
    });

    // التحويل لقضية + دورة القضية + التنفيذ + المهام — إدارة القضايا والأتعاب
    Route::middleware('permission:إدارة القضايا والأتعاب')->group(function () {
        Route::post('/tickets/{ticket}/convert', [LawyerTicketController::class, 'convertToCase'])->name('tickets.convert');
        Route::post('/tickets/{ticket}/close', [LawyerTicketController::class, 'closeWithoutCase'])->name('tickets.close');
        Route::post('/tickets/{ticket}/request-docs', [LawyerTicketController::class, 'requestDocs'])->name('tickets.reqdocs');
        Route::get('/cases', [LawyerCaseController::class, 'index'])->name('cases');
        Route::get('/cases/{case}', [LawyerCaseController::class, 'show'])->name('cases.show');
        Route::post('/cases/{case}/pleading', [LawyerCaseController::class, 'approvePleading'])->name('cases.pleading');
        Route::post('/cases/{case}/hearings', [LawyerCaseController::class, 'addHearing'])->name('cases.hearings.add');
        Route::post('/cases/{case}/hearings/{hearing}', [LawyerCaseController::class, 'recordHearing'])->name('cases.hearings.record');
        Route::post('/cases/{case}/ruling', [LawyerCaseController::class, 'recordRuling'])->name('cases.ruling');
        Route::post('/cases/{case}/execute', [LawyerCaseController::class, 'convertToExecution'])->name('cases.execute');
        Route::get('/execs', [LawyerExecutionController::class, 'index'])->name('execs');
        Route::get('/execs/{execution}', [LawyerExecutionController::class, 'show'])->name('execs.show');
        Route::post('/execs/{execution}/instrument', [LawyerExecutionController::class, 'prepareInstrument'])->name('execs.instrument');
        Route::post('/execs/{execution}/court', [LawyerExecutionController::class, 'registerCourt'])->name('execs.court');
        Route::post('/execs/{execution}/procedures', [LawyerExecutionController::class, 'addProcedure'])->name('execs.procedures.add');
        Route::post('/execs/{execution}/procedures/{procedure}', [LawyerExecutionController::class, 'recordProcedure'])->name('execs.procedures.record');
        Route::post('/execs/{execution}/complete', [LawyerExecutionController::class, 'complete'])->name('execs.complete');
        Route::get('/tasks', [LawyerTaskController::class, 'index'])->name('tasks');
        Route::post('/tasks', [LawyerTaskController::class, 'store'])->name('tasks.store');
        Route::post('/tasks/{task}/complete', [LawyerTaskController::class, 'complete'])->name('tasks.complete');
    });

    // الاجتماعات — إدارة الاجتماعات
    Route::middleware('permission:إدارة الاجتماعات')->group(function () {
        Route::get('/meetings', [StaffMeetingController::class, 'index'])->name('meetings');
        Route::get('/meeting', [StaffMeetingController::class, 'show'])->name('meeting');
        Route::get('/meetingroom', [StaffMeetingController::class, 'room'])->name('meetingroom');
        Route::post('/meetings/{meeting}/summary', [StaffMeetingController::class, 'saveSummary'])->name('meetings.summary');
        Route::post('/meetings/{meeting}/minutes', [StaffMeetingController::class, 'saveMinutes'])->name('meetings.minutes');
        Route::post('/meetings/{meeting}/end', [StaffMeetingController::class, 'end'])->name('meetings.end');
        Route::post('/meetings/{meeting}/tasks', [StaffMeetingController::class, 'createTasks'])->name('meetings.tasks');
    });

    // طلبات الاجتماعات — إرسال دعوات الاجتماعات
    Route::middleware('permission:إرسال دعوات الاجتماعات')->group(function () {
        Route::get('/meetreqs', [StaffMeetRequestController::class, 'index'])->name('meetreqs');
        Route::post('/meetreqs', [StaffMeetRequestController::class, 'store'])->name('meetreqs.store');
        Route::post('/meetreqs/{meetRequest}/cancel', [StaffMeetRequestController::class, 'cancel'])->name('meetreqs.cancel');
        Route::post('/meetreqs/{meetRequest}/start', [StaffMeetRequestController::class, 'start'])->name('meetreqs.start');
    });

    Route::middleware('permission:المساعد القانوني')->group(function () {
        Route::get('/assistant', [LawyerAssistantController::class, 'index'])->name('assistant');
        Route::post('/assistant/generate', [LawyerAssistantController::class, 'generate'])->name('assistant.generate');
    });

    // استقبال الاستشارات + رحلة الاستشارة + الغرفة — استقبال الاستشارات (والغرفة تحتاج إجراء الجلسات)
    Route::middleware('permission:استقبال الاستشارات')->group(function () {
        Route::get('/consultrecv', [StaffConsultController::class, 'recv'])->name('consultrecv');
        Route::get('/consult', [StaffConsultController::class, 'show'])->name('consult');
        Route::post('/consults/{consult}/start', [StaffConsultController::class, 'start'])->name('consults.start');
        Route::post('/consults/{consult}/end', [StaffConsultController::class, 'end'])->name('consults.end');
        Route::post('/consults/{consult}/take', [StaffConsultController::class, 'take'])->name('consults.take');
        Route::post('/consults/{consult}/reqdocs', [StaffConsultController::class, 'requestDocs'])->name('consults.reqdocs');
        Route::post('/consults/{consult}/analyze', [StaffConsultController::class, 'analyze'])->name('consults.analyze');
        Route::post('/consults/{consult}/analysis', [StaffConsultController::class, 'saveAnalysis'])->name('consults.analysis');
        Route::post('/consults/{consult}/approve', [StaffConsultController::class, 'approveAnalysis'])->name('consults.approve');
        Route::post('/consults/{consult}/refer', [StaffConsultController::class, 'refer'])->name('consults.refer');
        Route::post('/consults/{consult}/tasks', [StaffConsultController::class, 'createTasks'])->name('consults.tasks');
    });
    Route::get('/videoroom', [StaffConsultController::class, 'room'])
        ->middleware('permission:إجراء الجلسات المرئية')->name('videoroom');
});

// ── لوحة الإدارة ── (الإدارة تتجاوز الصلاحيات عبر Gate::before؛ الحماية بالدور)
Route::middleware(['auth', 'active', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'admin'])->name('dashboard');
    Route::get('/clients', [AdminClientController::class, 'index'])->name('clients');
    Route::get('/tickets', [AdminTicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{ticket}', [AdminTicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/result', [AdminTicketController::class, 'approveResult'])->name('tickets.result');
    Route::get('/lawyers', [AdminLawyerController::class, 'index'])->name('lawyers');
    Route::post('/lawyers/{user}/mode', [AdminLawyerController::class, 'toggleMode'])->name('lawyers.mode');
    // رحلة الاستشارة — مربوطة بقاعدة البيانات (+ صلاحيات الإدارة: الأولوية)
    Route::get('/consults', [StaffConsultController::class, 'index'])->name('consults');
    Route::post('/consults/{consult}/take', [StaffConsultController::class, 'take'])->name('consults.take');
    Route::post('/consults/{consult}/reqdocs', [StaffConsultController::class, 'requestDocs'])->name('consults.reqdocs');
    Route::post('/consults/{consult}/analyze', [StaffConsultController::class, 'analyze'])->name('consults.analyze');
    Route::post('/consults/{consult}/analysis', [StaffConsultController::class, 'saveAnalysis'])->name('consults.analysis');
    Route::post('/consults/{consult}/approve', [StaffConsultController::class, 'approveAnalysis'])->name('consults.approve');
    Route::post('/consults/{consult}/refer', [StaffConsultController::class, 'refer'])->name('consults.refer');
    Route::post('/consults/{consult}/priority', [StaffConsultController::class, 'priority'])->name('consults.priority');
    // تسجيل الموظفين وإدارتهم (مربوط بقاعدة البيانات + spatie)
    Route::get('/staff', [StaffController::class, 'index'])->name('staff');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::put('/staff/{user}', [StaffController::class, 'update'])->name('staff.update');
    Route::post('/staff/{user}/toggle', [StaffController::class, 'toggle'])->name('staff.toggle');
    Route::post('/staff/{user}/preview', [StaffController::class, 'preview'])->name('staff.preview');
    // الفروع (موديل Branch حقيقي)
    Route::get('/branches', [BranchController::class, 'index'])->name('branches');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::get('/archive', [AdminArchiveController::class, 'index'])->name('archive');
    Route::get('/distribute', [AdminDistributeController::class, 'index'])->name('distribute');
    Route::post('/distribute/auto', [AdminDistributeController::class, 'auto'])->name('distribute.auto');
    Route::post('/distribute/{ticket}', [AdminDistributeController::class, 'assign'])->name('distribute.assign');
    Route::get('/casefees', [AdminCaseController::class, 'fees'])->name('casefees');
    Route::get('/cases', [AdminCaseController::class, 'index'])->name('cases');
    Route::post('/cases/{case}/fee', [AdminCaseController::class, 'setFee'])->name('cases.fee');
    Route::post('/cases/{case}/close', [AdminCaseController::class, 'closeCase'])->name('cases.close');
    Route::get('/execs', [AdminExecutionController::class, 'index'])->name('execs');
    Route::post('/execs/{execution}/close', [AdminExecutionController::class, 'close'])->name('execs.close');
    Route::get('/tasks', [AdminTaskController::class, 'index'])->name('tasks');
    Route::post('/tasks', [AdminTaskController::class, 'store'])->name('tasks.store');
    // منظومة الاجتماعات (مربوطة بقاعدة البيانات): إدارة/اعتماد/سجل/تقارير/دعوات
    Route::get('/meetmgmt', [StaffMeetingController::class, 'mgmt'])->name('meetmgmt');
    Route::post('/meetings', [StaffMeetingController::class, 'store'])->name('meetings.store');
    Route::get('/meetreqs', [StaffMeetRequestController::class, 'index'])->name('meetreqs');
    Route::post('/meetreqs', [StaffMeetRequestController::class, 'store'])->name('meetreqs.store');
    Route::post('/meetreqs/{meetRequest}/cancel', [StaffMeetRequestController::class, 'cancel'])->name('meetreqs.cancel');
    Route::post('/meetreqs/{meetRequest}/start', [StaffMeetRequestController::class, 'start'])->name('meetreqs.start');
    Route::get('/meetlog', [StaffMeetingController::class, 'log'])->name('meetlog');
    Route::get('/clientnotifs', [AdminClientNotifController::class, 'index'])->name('clientnotifs');
    Route::post('/clientnotifs/send', [AdminClientNotifController::class, 'send'])->name('clientnotifs.send');
    Route::post('/clientnotifs/read', [AdminClientNotifController::class, 'markRead'])->name('clientnotifs.read');
    Route::get('/meetings', [StaffMeetingController::class, 'index'])->name('meetings');
    Route::post('/meetings/{meeting}/approve', [StaffMeetingController::class, 'approve'])->name('meetings.approve');
    Route::post('/meetings/{meeting}/end', [StaffMeetingController::class, 'end'])->name('meetings.end');
    Route::post('/meetings/{meeting}/tasks', [StaffMeetingController::class, 'createTasks'])->name('meetings.tasks');
    Route::get('/summaries', [AdminTicketController::class, 'summaries'])->name('summaries');
    // مراجعة/اعتماد/تعديل ملخص الملف (إشراف الإدارة العليا — صلاحيات مطلقة)
    Route::get('/summary/{ticket}', [LawyerTicketController::class, 'showSummary'])->name('summary');
    Route::post('/summary/{ticket}', [LawyerTicketController::class, 'updateSummary'])->name('summary.update');
    Route::post('/summary/{ticket}/approve', [LawyerTicketController::class, 'approveSummary'])->name('summary.approve');
    Route::get('/revenue', [AdminReportController::class, 'revenue'])->name('revenue');
    Route::get('/prices', [AdminPriceController::class, 'index'])->name('prices');
    Route::post('/prices', [AdminPriceController::class, 'update'])->name('prices.update');
    Route::get('/accounting', [AdminAccountingController::class, 'index'])->name('accounting');
    Route::post('/invoices/{invoice}/pay', [AdminAccountingController::class, 'pay'])->name('invoices.pay');
    Route::get('/meetreports', [StaffMeetingController::class, 'reports'])->name('meetreports');
    Route::get('/reports', [AdminReportController::class, 'reports'])->name('reports');
    // استقبال الاستشارات وغرفة الجلسة (مربوطة بقاعدة البيانات)
    Route::get('/consultrecv', [StaffConsultController::class, 'recv'])->name('consultrecv');
    Route::get('/videoroom', [StaffConsultController::class, 'room'])->name('videoroom');
    Route::post('/consults/{consult}/start', [StaffConsultController::class, 'start'])->name('consults.start');
    Route::post('/consults/{consult}/end', [StaffConsultController::class, 'end'])->name('consults.end');
    Route::post('/consults/{consult}/tasks', [StaffConsultController::class, 'createTasks'])->name('consults.tasks');
    Route::get('/consult', [StaffConsultController::class, 'show'])->name('consult');
    Route::get('/meeting', [StaffMeetingController::class, 'show'])->name('meeting');
});
