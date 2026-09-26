<?php

namespace App\Http\Middleware;

use App\Support\ConversationHandler;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **هذا الطلب ردٌّ يكتبه موظّفٌ للعميل** — فما يُنشأ فيه من رسائل قد ينقل مسؤوليّة المحادثة.
 *
 * قرار المالك (2026-09-25): «من **يردّ** يصير المسؤول». والإعلانات الآليّة التي تُكتب في المحادثة
 * أثناء فعلٍ آخر (اعتماد ملخّص · تحديد أتعاب · إغلاق قضيّة) ليست ردّاً — كانت تجعل المدير «مسؤول
 * المحادثة» لأنّه اعتمد ملخّصاً. فالوسم على **مسارات الردّ** وحدها في `routes/web.php` (الردّ ·
 * الإرفاق · طلب المستندات): قائمةٌ مقروءة في موضعٍ واحد، بلا سطرٍ في المتحكّمات.
 */
final class MarksConversationReply
{
    public function handle(Request $request, Closure $next): Response
    {
        return ConversationHandler::whileReplying(fn () => $next($request));
    }
}
