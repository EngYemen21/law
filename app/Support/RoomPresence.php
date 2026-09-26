<?php

namespace App\Support;

use App\Models\Consult;
use App\Models\Meeting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * **ما يجري داخل غرفة Zoom الآن — من أحداث Zoom نفسها، لا من الساعة.**
 *
 * عددُ من في الغرفة وحالُ التسجيل السحابيّ حالٌ عابرة لا حالةُ رحلة: لا تُكتب في الجدول ولا
 * تُسجَّل انتقالاً، وتموت بموت الغرفة. فمكانُها المخزن المؤقّت بمفتاح الغرفة، ويكتبها ويبهوك
 * Zoom (`meeting.participant_joined/left` · `recording.started/stopped/paused/resumed`) ويقرؤها
 * عقدُ الغرفة وبثّها (`RoomDetails::state`).
 *
 * - **المشاركون** مجموعةُ معرّفات لا عدّاد: Zoom قد يكرّر الحدث أو يُعيد إرساله، والعدّاد يتضخّم
 *   بالتكرار؛ المجموعة لا. `null` = لم يصل حدثٌ بعد (لا صفرٌ يدّعي قياساً).
 * - **التسجيل**: كلّ اجتماعٍ يُنشأ بتسجيلٍ سحابيّ آليّ (`ZoomService::settings` → `auto_recording`)،
 *   فالجارية بغرفة Zoom تُعدّ مُسجَّلة حتى يقول Zoom غير ذلك (`recording.stopped/paused`).
 */
final class RoomPresence
{
    /** يومٌ يكفي لأطول جلسة — وشبكة النسيان تُنهي ما بعدها. */
    private const TTL_SECONDS = 86400;

    public static function participantJoined(Consult|Meeting $session, string $participant): void
    {
        self::mutateParticipants($session, function (array $ids) use ($participant) {
            $ids[$participant] = true;

            return $ids;
        });
    }

    public static function participantLeft(Consult|Meeting $session, string $participant): void
    {
        self::mutateParticipants($session, function (array $ids) use ($participant) {
            unset($ids[$participant]);

            return $ids;
        });
    }

    /** عدد من في الغرفة الآن، أو `null` إن لم يصل من Zoom شيء. */
    public static function participants(Consult|Meeting $session): ?int
    {
        $ids = Cache::get(self::key($session, 'participants'));

        return is_array($ids) ? count($ids) : null;
    }

    public static function setRecording(Consult|Meeting $session, bool $on): void
    {
        Cache::put(self::key($session, 'recording'), $on, self::TTL_SECONDS);
    }

    /** هل يُسجَّل الآن؟ — ما قاله Zoom، وإلّا افتراض التسجيل الآليّ لغرفةٍ لها اجتماع Zoom. */
    public static function recording(Consult|Meeting $session): bool
    {
        $known = Cache::get(self::key($session, 'recording'));

        return is_bool($known) ? $known : filled($session->meet_id);
    }

    /** الغرفة أُغلقت — لا يبقى عددٌ ولا تسجيلٌ بائتٌ لجلسةٍ منتهية. */
    public static function forget(Consult|Meeting $session): void
    {
        Cache::forget(self::key($session, 'participants'));
        Cache::forget(self::key($session, 'recording'));
    }

    /** @param  callable(array<string, true>): array<string, true>  $mutate */
    private static function mutateParticipants(Consult|Meeting $session, callable $mutate): void
    {
        $key = self::key($session, 'participants');
        $write = function () use ($key, $mutate) {
            $ids = Cache::get($key);
            Cache::put($key, $mutate(is_array($ids) ? $ids : []), self::TTL_SECONDS);
        };

        // حدثا دخولٍ متزامنان (طرفان يدخلان معاً) يقرآن المجموعة نفسها — القفل يمنع ضياع أحدهما.
        // تعذُّره لا يُسقط الويبهوك: العدد تحسينٌ للعرض، فيُكتب بلا قفل.
        try {
            Cache::lock($key.':lock', 5)->block(3, $write);
        } catch (LockTimeoutException) {
            $write();
        }
    }

    private static function key(Consult|Meeting $session, string $what): string
    {
        return 'room:'.RoomDetails::kind($session).':'.$session->getKey().':'.$what;
    }
}
