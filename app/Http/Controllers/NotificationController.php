<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    // قائمة إشعارات العميل الحالي
    public function index(Request $request): Response
    {
        $notifications = UserNotification::where('user_id', $request->user()->id)->latest('id')->get()
            ->map(fn (UserNotification $n) => $n->toData());

        return Inertia::render('notifications', [
            'notifications' => $notifications,
        ]);
    }

    // تعليم كل الإشعارات كمقروءة
    public function markAllRead(Request $request): RedirectResponse
    {
        UserNotification::where('user_id', $request->user()->id)->update(['is_read' => true]);

        return back();
    }
}
