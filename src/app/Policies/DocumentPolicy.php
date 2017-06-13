<?php

namespace LaravelEnso\DocumentsManager\app\Policies;

use App\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\HandlesAuthorization;
use LaravelEnso\DocumentsManager\app\Models\Document;

class DocumentPolicy
{
    use HandlesAuthorization;

    public function before($user, $ability)
    {
        return $user->isAdmin();
    }

    public function download(User $user, Document $document)
    {
        return $this->userOwnsDocument($user, $document);
    }

    public function destroy(User $user, Document $document)
    {
        return $this->userOwnsDocument($user, $document)
            && $this->documentIsRecent($document);
    }

    private function userOwnsDocument(User $user, Document $document)
    {
        return $user->id === $document->created_by;
    }

    private function documentIsRecent(Document $document)
    {
        return $document->created_at->diffInHours(Carbon::now()) <= config('documents.editableTimeLimitInHours');
    }
}