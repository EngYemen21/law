<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ContentRevisions;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * **سجلّ نسخ التحليلات والملخّصات للطاقم** (طلب المالك 2026-09-29) — قراءةٌ فقط.
 *
 * يراه من يرى الملفّ نفسه: الإدارة كلّ شيء، والمحامي ما أُسند إليه (والاجتماع الذي يشارك فيه)، والموظّف
 * بصلاحيّة ذلك النوع من الملفّات. والعميل لا يراه أبداً — النسخ عملٌ داخليّ قبل اعتماده.
 */
class RevisionController extends Controller
{
    /** مالك كلّ نوعٍ ومفتاح إيجاده من الواجهة (رقم الملفّ أو معرّفه). */
    private const OWNERS = [
        'ticket' => [Ticket::class, 'number', [Permissions::MANAGE_TICKETS]],
        'consult' => [Consult::class, 'id', [Permissions::RECEIVE_CONSULTS, Permissions::MANAGE_BOOKINGS, Permissions::APPROVE_CONSULT_SUMMARY]],
        'case' => [LegalCase::class, 'number', [Permissions::MANAGE_CASES_AND_FEES]],
        'exec' => [Execution::class, 'number', [Permissions::MANAGE_CASES_AND_FEES]],
        'meeting' => [Meeting::class, 'id', [Permissions::SEND_MEETING_INVITES, Permissions::MANAGE_MEETINGS]],
    ];

    public function index(Request $request, string $kind, string $ref): JsonResponse
    {
        abort_unless(isset(ContentRevisions::KINDS[$kind]), 404);
        [$class, $key, $perms] = self::OWNERS[strtok($kind, '_')] ?? abort(404);

        /** @var Model $owner */
        $owner = $class::where($key, $ref)->firstOrFail();
        abort_unless(self::canView($request->user(), $owner, $perms), 403);

        return response()->json([
            'kind' => $kind,
            'label' => ContentRevisions::KINDS[$kind],
            'versions' => ContentRevisions::history($owner, $kind),
        ]);
    }

    /** @param  list<string>  $perms */
    private static function canView(User $user, Model $owner, array $perms): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->isLawyer()) {
            return $owner instanceof Meeting
                ? in_array($user->id, $owner->staffIds(), true)
                : (int) $owner->getAttribute('assigned_lawyer_id') === $user->id;
        }

        return $user->isEmployee() && collect($perms)->contains(fn (string $p) => $user->can($p));
    }
}
