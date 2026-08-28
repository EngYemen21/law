<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Determines whether the user can view or download the document.
     */
    public function view(User $user, Document $document): bool
    {
        // كان هنا منحٌ شامل لأي محامٍ/موظف بلا تضييق، و`Document` لا يحمل أصلاً أي رابط
        // إسناد لمحامٍ (ملف عميل مباشر). غير قابل للوصول اليوم لأن المسار داخل مجموعة
        // role:client — لكنه كان سينفجر لحظة إضافة مسار للطاقم. أي وصول للطاقم يجب أن
        // يأتي بمسار مقصود يحرس نطاقه صراحةً، لا من سياسة مفتوحة.
        // (الإدارة تتجاوز عبر Gate::before في AppServiceProvider.)
        return (int) $user->id === (int) $document->user_id;
    }

    /**
     * Determines whether the user can delete the document.
     */
    public function delete(User $user, Document $document): bool
    {
        if ($user->role === Role::Admin) {
            return true;
        }

        return (int) $user->id === (int) $document->user_id;
    }
}
