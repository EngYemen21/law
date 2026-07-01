<?php

use App\Http\Controllers\Admin\CaseController as AdminCaseController;
use App\Http\Controllers\Admin\TicketController as AdminTicketController;
use App\Http\Controllers\Employee\CaseController as EmployeeCaseController;
use App\Http\Controllers\Employee\TicketController as EmployeeTicketController;
use App\Http\Controllers\Lawyer\CaseController as LawyerCaseController;
use App\Http\Controllers\Lawyer\TicketController as LawyerTicketController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExecutionController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

// ── عام (بدون مصادقة) ──
Route::inertia('/', 'welcome')->name('home');

// المصادقة
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ── منصة العميل (دور العميل) — تطابق 1:1 مع index (82).html ──
Route::middleware(['auth', 'role:client'])->group(function () {
    Route::inertia('/dashboard', 'dashboard')->name('dashboard');

    // طلباتي — التذاكر مربوطة بقاعدة البيانات (متحكم)
    Route::inertia('/tickets/new', 'newticket')->name('tickets.new');
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
    Route::post('/cases/{case}/pay', [CaseController::class, 'pay'])->name('cases.pay');
    Route::post('/cases/{case}/pay-installment', [CaseController::class, 'payInstallment'])->name('cases.pay-installment');

    // طلبات التنفيذ (مربوطة بقاعدة البيانات)
    Route::get('/execs', [ExecutionController::class, 'index'])->name('execs');
    Route::get('/execs/{execution}', [ExecutionController::class, 'show'])->name('execs.show');
    Route::post('/execs/{execution}/messages', [ExecutionController::class, 'storeMessage'])->name('execs.messages.store');

    // الاستشارات
    Route::inertia('/book', 'book')->name('book');
    Route::inertia('/myconsults', 'myconsults')->name('myconsults');
    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments');
    Route::get('/meetings', [MeetingController::class, 'index'])->name('meetings');
    Route::inertia('/meetreqs', 'meetreqs')->name('meetreqs');
    Route::inertia('/calendar', 'calendar')->name('calendar');

    // الملفات والمالية (مربوطة بقاعدة البيانات)
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents');
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices');
});

// الحساب — متاح لأي مستخدم مسجّل
Route::middleware('auth')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::inertia('/profile', 'profile')->name('profile');
});

// ── لوحة الموظف ──
Route::middleware(['auth', 'role:employee'])->prefix('employee')->name('employee.')->group(function () {
    Route::inertia('/dashboard', 'employee/dashboard')->name('dashboard');
    // التذاكر — مشتركة مع العميل (نفس قاعدة البيانات)
    Route::get('/tickets', [EmployeeTicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{ticket}', [EmployeeTicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/reply', [EmployeeTicketController::class, 'reply'])->name('tickets.reply');
    Route::post('/tickets/{ticket}/note', [EmployeeTicketController::class, 'note'])->name('tickets.note');
    Route::post('/tickets/{ticket}/status', [EmployeeTicketController::class, 'status'])->name('tickets.status');
    Route::post('/tickets/{ticket}/advance', [EmployeeTicketController::class, 'advance'])->name('tickets.advance');
    Route::post('/tickets/{ticket}/convert', [EmployeeTicketController::class, 'convertToCase'])->name('tickets.convert');
    // القضايا — متابعة وتنسيق
    Route::get('/cases', [EmployeeCaseController::class, 'index'])->name('cases');
    Route::get('/cases/{case}', [EmployeeCaseController::class, 'show'])->name('cases.show');
    Route::post('/cases/{case}/reply', [EmployeeCaseController::class, 'reply'])->name('cases.reply');
    Route::inertia('/consults', 'employee/consults')->name('consults');
    Route::inertia('/consult', 'employee/consult')->name('consult');
    Route::inertia('/schedule', 'employee/schedule')->name('schedule');
    Route::inertia('/transfer', 'employee/transfer')->name('transfer');
    Route::inertia('/meetreqs', 'employee/meetreqs')->name('meetreqs');
    Route::inertia('/consultrecv', 'employee/consultrecv')->name('consultrecv');
    Route::inertia('/videoroom', 'employee/videoroom')->name('videoroom');
});

// ── لوحة المحامي ──
Route::middleware(['auth', 'role:lawyer'])->prefix('lawyer')->name('lawyer.')->group(function () {
    Route::get('/dashboard', [LawyerTicketController::class, 'dashboard'])->name('dashboard');
    // التذاكر المحالة + ملخص الملف + الاعتماد (يصل لمحادثة العميل)
    Route::get('/tickets', [LawyerTicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{ticket}', [LawyerTicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/convert', [LawyerTicketController::class, 'convertToCase'])->name('tickets.convert');
    Route::post('/tickets/{ticket}/close', [LawyerTicketController::class, 'closeWithoutCase'])->name('tickets.close');
    Route::post('/tickets/{ticket}/request-docs', [LawyerTicketController::class, 'requestDocs'])->name('tickets.reqdocs');
    Route::get('/summaries', [LawyerTicketController::class, 'summaries'])->name('summaries');
    Route::get('/summary/{ticket}', [LawyerTicketController::class, 'showSummary'])->name('summary');
    Route::post('/summary/{ticket}', [LawyerTicketController::class, 'updateSummary'])->name('summary.update');
    Route::post('/summary/{ticket}/approve', [LawyerTicketController::class, 'approveSummary'])->name('summary.approve');
    Route::post('/tickets/{ticket}/result', [LawyerTicketController::class, 'approveResult'])->name('result.approve');
    // دورة حياة القضية
    Route::get('/cases', [LawyerCaseController::class, 'index'])->name('cases');
    Route::get('/cases/{case}', [LawyerCaseController::class, 'show'])->name('cases.show');
    Route::post('/cases/{case}/pleading', [LawyerCaseController::class, 'approvePleading'])->name('cases.pleading');
    Route::post('/cases/{case}/hearings', [LawyerCaseController::class, 'addHearing'])->name('cases.hearings.add');
    Route::post('/cases/{case}/hearings/{hearing}', [LawyerCaseController::class, 'recordHearing'])->name('cases.hearings.record');
    Route::post('/cases/{case}/ruling', [LawyerCaseController::class, 'recordRuling'])->name('cases.ruling');
    Route::inertia('/caseflow', 'lawyer/caseflow')->name('caseflow');
    Route::inertia('/meetings', 'lawyer/meetings')->name('meetings');
    Route::inertia('/meeting', 'lawyer/meeting')->name('meeting');
    Route::inertia('/meetreqs', 'lawyer/meetreqs')->name('meetreqs');
    Route::inertia('/calendar', 'lawyer/calendar')->name('calendar');
    Route::inertia('/assistant', 'lawyer/assistant')->name('assistant');
    Route::inertia('/consultrecv', 'lawyer/consultrecv')->name('consultrecv');
    Route::inertia('/consult', 'lawyer/consult')->name('consult');
    Route::inertia('/tasks', 'lawyer/tasks')->name('tasks');
    Route::inertia('/videoroom', 'lawyer/videoroom')->name('videoroom');
});

// ── لوحة الإدارة ──
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('/dashboard', 'admin/dashboard')->name('dashboard');
    Route::inertia('/clients', 'admin/clients')->name('clients');
    Route::get('/tickets', [AdminTicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{ticket}', [AdminTicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/result', [AdminTicketController::class, 'approveResult'])->name('tickets.result');
    Route::inertia('/lawyers', 'admin/lawyers')->name('lawyers');
    Route::inertia('/consults', 'admin/consults')->name('consults');
    Route::inertia('/staff', 'admin/staff')->name('staff');
    Route::inertia('/branches', 'admin/branches')->name('branches');
    Route::inertia('/archive', 'admin/archive')->name('archive');
    Route::inertia('/distribute', 'admin/distribute')->name('distribute');
    Route::get('/casefees', [AdminCaseController::class, 'fees'])->name('casefees');
    Route::get('/cases', [AdminCaseController::class, 'index'])->name('cases');
    Route::post('/cases/{case}/fee', [AdminCaseController::class, 'setFee'])->name('cases.fee');
    Route::post('/cases/{case}/close', [AdminCaseController::class, 'closeCase'])->name('cases.close');
    Route::inertia('/tasks', 'admin/tasks')->name('tasks');
    Route::inertia('/meetmgmt', 'admin/meetmgmt')->name('meetmgmt');
    Route::inertia('/meetreqs', 'admin/meetreqs')->name('meetreqs');
    Route::inertia('/meetlog', 'admin/meetlog')->name('meetlog');
    Route::inertia('/clientnotifs', 'admin/clientnotifs')->name('clientnotifs');
    Route::inertia('/meetings', 'admin/meetings')->name('meetings');
    Route::get('/summaries', [AdminTicketController::class, 'summaries'])->name('summaries');
    Route::inertia('/revenue', 'admin/revenue')->name('revenue');
    Route::inertia('/prices', 'admin/prices')->name('prices');
    Route::inertia('/accounting', 'admin/accounting')->name('accounting');
    Route::inertia('/meetreports', 'admin/meetreports')->name('meetreports');
    Route::inertia('/reports', 'admin/reports')->name('reports');
    Route::inertia('/consultrecv', 'admin/consultrecv')->name('consultrecv');
    // تفاصيل مشتركة (تعيد استخدام صفحات المحامي مؤقتاً)
    Route::inertia('/consult', 'lawyer/consult')->name('consult');
    Route::inertia('/meeting', 'lawyer/meeting')->name('meeting');
    Route::inertia('/videoroom', 'lawyer/videoroom')->name('videoroom');
});
