<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Notify;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إشعارات العملاء — الإدارة تعرض إشعارات أي عميل وترسل إشعاراً حقيقياً (UserNotification).
 */
class ClientNotifController extends Controller
{
    public function index(Request $request): Response
    {
        $clients = User::where('role', Role::Client)->orderBy('name')->get(['id', 'name']);
        $selected = (int) ($request->query('client') ?: $clients->first()?->id);

        $notifs = $selected
            ? UserNotification::where('user_id', $selected)->latest('id')->get()
                ->map(fn (UserNotification $n) => [
                    'id' => $n->id, 'ic' => $n->icon, 'tone' => $n->tone,
                    'text' => $n->body, 'time' => $n->time_label, 'unread' => ! $n->is_read,
                ])
            : collect();

        return Inertia::render('admin/clientnotifs', [
            'clients' => $clients->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]),
            'selected' => $selected,
            'notifs' => $notifs->values(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'body' => ['required', 'string', 'max:500'],
        ]);
        User::where('role', Role::Client)->findOrFail($data['client_id']);

        Notify::send($data['client_id'], 'bell', 't-blue', $data['body']);

        return back(fallback: route('admin.clientnotifs', ['client' => $data['client_id']]))
            ->with('flash', 'تم إرسال الإشعار للعميل.');
    }

    public function markRead(Request $request): RedirectResponse
    {
        $data = $request->validate(['client_id' => ['required', 'integer']]);
        UserNotification::where('user_id', $data['client_id'])->update(['is_read' => true]);

        return back()->with('flash', 'تم تعليم إشعارات العميل كمقروءة.');
    }
}
