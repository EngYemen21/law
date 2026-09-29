<?php

namespace App\Models\Concerns;

use App\Support\ContentRevisions;
use Illuminate\Database\Eloquent\Model;

/**
 * **نموذجٌ نصوصُه المولَّدة والمعدَّلة تُحفظ نسخاً** (`ContentRevisions`) — بعد كلّ حفظ.
 *
 * يعرّف النموذج أنواع نصوصه (`revisionKinds`: النوع ← الأعمدة) والملفّ المالك الذي تُنسب إليه
 * (`revisionOwner`: نفسه، أو تذكرة الملخّص، أو قضيّة مسودّة اللائحة).
 */
trait TracksRevisions
{
    public static function bootTracksRevisions(): void
    {
        static::saved(fn (Model $model) => ContentRevisions::capture($model));
    }

    /** @return array<string, list<string>> */
    abstract public function revisionKinds(): array;

    abstract public function revisionOwner(): ?Model;
}
