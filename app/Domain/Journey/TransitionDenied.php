<?php

namespace App\Domain\Journey;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * رفضُ انتقال — `HttpException` كي يعامله `bootstrap/app.php` كما يعامل `abort(422)`:
 * زيارات Inertia تتلقّى الرسالة في `onError`، ونداءات axios جسمَ خطأٍ حقيقيّاً.
 */
final class TransitionDenied extends HttpException
{
    public static function state(string $current): self
    {
        return new self(422, "لا يمكن تنفيذ هذا الإجراء والملفّ في حالة «{$current}».");
    }

    public static function forbidden(string $why): self
    {
        return new self(403, $why);
    }

    public static function invalid(string $why): self
    {
        return new self(422, $why);
    }
}
