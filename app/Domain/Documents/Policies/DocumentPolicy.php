<?php

declare(strict_types=1);

namespace App\Domain\Documents\Policies;

use App\Domain\Documents\Models\Document;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Files.
 *
 * Identity documents are treated apart from everything else. A house manual
 * and a guest's passport scan are both "documents" and are not remotely the
 * same disclosure: anybody handling a booking may need the first, and the
 * second should be reachable by as few people as the law allows.
 *
 * So `documents.view` opens the ordinary ones, and an identity document
 * additionally needs `guests.view` — the permission that already governs
 * personal data about guests.
 */
class DocumentPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['documents.view', 'documents.manage']);
    }

    public function view(User $user, Document $document): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        // An identity document is personal data of a kind that a file
        // permission alone should not unlock.
        if ($document->kind === Document::IDENTIFICATION || $document->contains_personal_data) {
            return $this->access->allows($user, 'guests.view');
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'documents.manage');
    }

    public function update(User $user, Document $document): bool
    {
        return $this->access->allows($user, 'documents.manage');
    }

    /**
     * Deletion is real here, unlike almost everywhere else in this platform,
     * and deliberately so: holding somebody's passport scan after its lawful
     * retention period is a liability rather than a record, and a system that
     * could never delete one would be unable to comply with a perfectly
     * ordinary request.
     */
    public function delete(User $user, Document $document): bool
    {
        return $this->access->allows($user, 'documents.manage');
    }
}
