<?php

namespace App\Support;

use App\Events\StaffPresenceChanged;
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
 * - **الطاقم في الجلسات الآن** (`staffInSession`): مَن دخل غرفة المنصّة يُعرَّف لـZoom برقم حسابه
 *   (`customerKey` ⇐ `customer_key` في الحدث — `ZoomController::sdkSignature`)، فيُعرف المحامي داخل
 *   أيّ غرفةٍ هو الآن. خروجُه من غرفةٍ وهو في أخرى يُبقيه «في جلسة» — لذلك الغرفُ لكلّ شخص مجموعةٌ لا علَم.
 *   للعرض وحده (قرار المالك 2026-09-29): لا يمسّ حكم الحجز (`LawyerAvailability`).
 * - **التسجيل**: كلّ اجتماعٍ يُنشأ بتسجيلٍ سحابيّ آليّ (`ZoomService::settings` → `auto_recording`)،
 *   فالجارية بغرفة Zoom تُعدّ مُسجَّلة حتى يقول Zoom غير ذلك (`recording.stopped/paused`).
 */
final class RoomPresence
{
    /** يومٌ يكفي لأطول جلسة — وشبكة النسيان تُنهي ما بعدها. */
    private const TTL_SECONDS = 86400;

    private const STAFF_KEY = 'room:staff-in-session';

    /** دخولٌ إلى الغرفة — و`$staffId` حين عرّف Zoom الداخلَ بحسابه من الطاقم. */
    public static function participantJoined(Consult|Meeting $session, string $participant, ?int $staffId = null): void
    {
        self::mutateParticipants($session, function (array $ids) use ($participant, $staffId) {
            $ids[$participant] = $staffId ?? true;

            return $ids;
        });

        if ($staffId !== null) {
            self::mutateStaff(function (array $map) use ($session, $staffId) {
                $map[$staffId][self::roomKey($session)] = (string) $session->ref;

                return $map;
            });
        }
    }

    public static function participantLeft(Consult|Meeting $session, string $participant): void
    {
        $staffId = null;
        $stillInside = false;
        self::mutateParticipants($session, function (array $ids) use ($participant, &$staffId, &$stillInside) {
            $staffId = is_int($ids[$participant] ?? null) ? $ids[$participant] : null;
            unset($ids[$participant]);
            // الشخص نفسه من جهازٍ ثانٍ ما زال في الغرفة ⇒ لم يخرج منها
            $stillInside = $staffId !== null && in_array($staffId, $ids, true);

            return $ids;
        });

        if ($staffId !== null && ! $stillInside) {
            self::leaveRoom([$staffId], $session);
        }
    }

    /**
     * **مَن من الطاقم داخل جلسةٍ الآن** — [معرّف الحساب ⇒ رقم آخر جلسةٍ دخلها]. قراءةٌ واحدة من
     * المخزن تكفي كلّ القوائم في الصفحة (`HandleInertiaRequests::share`).
     *
     * @return array<int,string>
     */
    public static function staffInSession(): array
    {
        $map = Cache::get(self::STAFF_KEY);

        return is_array($map)
            ? array_map(fn (array $rooms) => (string) end($rooms), array_filter($map))
            : [];
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
        // مَن بقي فيها من الطاقم (خروجٌ لم يصل حدثه) لا يبقى «في جلسة» أُغلقت
        $ids = Cache::get(self::key($session, 'participants'));
        self::leaveRoom(is_array($ids) ? array_filter($ids, 'is_int') : [], $session);

        Cache::forget(self::key($session, 'participants'));
        Cache::forget(self::key($session, 'recording'));
    }

    /** @param  array<int,int>  $staffIds */
    private static function leaveRoom(array $staffIds, Consult|Meeting $session): void
    {
        if ($staffIds === []) {
            return;
        }

        self::mutateStaff(function (array $map) use ($staffIds, $session) {
            foreach ($staffIds as $id) {
                unset($map[$id][self::roomKey($session)]);
                if (empty($map[$id])) {
                    unset($map[$id]);
                }
            }

            return $map;
        });
    }

    /** @param  callable(array<string, true|int>): array<string, true|int>  $mutate */
    private static function mutateParticipants(Consult|Meeting $session, callable $mutate): void
    {
        self::mutateLocked(self::key($session, 'participants'), $mutate);
    }

    /**
     * تعديل خريطة الطاقم ثمّ بثّها لحظيّاً إن تغيّر ما يُعرض — أفضل-جهد (`Live`): الصفحة تقرؤها عند
     * أيّ تحميلٍ على كلّ حال.
     *
     * @param  callable(array<int, array<string,string>>): array<int, array<string,string>>  $mutate
     */
    private static function mutateStaff(callable $mutate): void
    {
        $before = self::staffInSession();
        self::mutateLocked(self::STAFF_KEY, $mutate);
        $after = self::staffInSession();

        if ($after !== $before) {
            Live::push(new StaffPresenceChanged($after));
        }
    }

    private static function mutateLocked(string $key, callable $mutate): void
    {
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
        return 'room:'.self::roomKey($session).':'.$what;
    }

    private static function roomKey(Consult|Meeting $session): string
    {
        return RoomDetails::kind($session).':'.$session->getKey();
    }
}
