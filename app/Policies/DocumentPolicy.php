<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Determines whether the user can view or download the document.
     */
    public function view(User $user, Document $document): bool
    {
        // Admin, Employee, or Lawyer can view relevant documents
        if ($user->isAdmin() || $user->isEmployee() || $user->isLawyer()) {
            return true;
        }

        // Client can only view their own documents
        return (int) $user->id === (int) $document->user_id;
    }

    /**
     * Determines whether the user can delete the document.
     */
    public function delete(User $user, Document $document): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return (int) $user->id === (int) $document->user_id;
    }
}
