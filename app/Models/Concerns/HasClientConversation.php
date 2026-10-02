<?php

namespace App\Models\Concerns;

/**
 * تنفيذ `App\Contracts\ClientConversation::hasHumanStaffMessage` لنماذج الملفّات ذات المحادثة —
 * والنموذج يعرّف `messages()` بنوعه الدقيق.
 */
trait HasClientConversation
{
    /** مرسِلو المكتب البشريّون في المحادثات — وما سواهم `client` · `ai` · `system` · `note`. */
    public const HUMAN_STAFF_SENDERS = ['staff', 'lawyer', 'admin'];

    public function hasHumanStaffMessage(): bool
    {
        return $this->messages()->whereIn('who', self::HUMAN_STAFF_SENDERS)->exists();
    }
}
