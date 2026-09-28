<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * دليل العملاء المسجّلين وملفاتهم الحقيقية (يطابق CLIENT_DIR في التصميم الأصلي)
 * — يُستخدم في دعوات الاجتماعات وإنشائها، وفي «طلب استشارة نيابةً عن العميل».
 */
class ClientDirectory
{
    public static function list(): array
    {
        // تحميل مسبق: 4 استعلامات إجمالاً بدل 3N+1 (استعلام لكل عميل × 3)
        return User::where('role', Role::Client)
            ->with([
                'tickets:id,user_id,number,subject',
                'cases:id,user_id,ticket_id,number,type',
                'cases.ticket:id,subject',
                'consults:id,user_id,ref,subject',
            ])
            ->get()->map(function (User $u) {
                $items = collect()
                    ->merge($u->tickets->map(fn ($t) => self::item($t->number, 'ticket', 'تذكرة', $t->subject)))
                    // القضية بلا عمود موضوع — موضوعها موضوع تذكرتها، وإلا نوعها
                    ->merge($u->cases->map(fn ($c) => self::item($c->number, 'case', 'قضية', $c->ticket?->subject ?: $c->type)))
                    ->merge($u->consults->map(fn ($c) => self::item($c->ref, 'consult', 'استشارة', $c->subject)))
                    ->values()->all();

                return ['id' => $u->id, 'name' => $u->name, 'items' => $items];
            })->values()->all();
    }

    /**
     * خيارُ ملفٍّ واحد: المرجع، ونصُّ الخيار كما يُعرض، وموضوعُه من مصدره في القاعدة.
     *
     * `subject` يملأ حقل «الموضوع/الخدمة» تلقائياً عند اختيار الملفّ — و**null إن لم
     * يُسجَّل موضوع**، فيبقى الحقل فارغاً للكتابة بدل أن يُملأ بنصٍّ مختلق.
     */
    private static function item(string $ref, string $kind, string $kindLabel, ?string $subject): array
    {
        return [
            'ref' => $ref,
            // نوع الملفّ مفتاحاً (`ticket`·`case`·`consult`) — نموذج «طلب استشارة نيابةً عن العميل» يعرض التذاكر وحدها
            'kind' => $kind,
            'label' => $ref.' — '.$kindLabel,
            'subject' => trim((string) $subject) ?: null,
        ];
    }
}
