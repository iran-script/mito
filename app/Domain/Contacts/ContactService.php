<?php

namespace App\Domain\Contacts;

use App\Domain\Users\BlockService;
use App\Domain\Users\User;

class ContactService
{
    public function __construct(private readonly BlockService $blocks) {}

    public function add(User $user, User $contact): UserContact
    {
        if ($user->is($contact) || $this->blocks->isBlocked($user, $contact)) {
            throw new \DomainException('This contact is unavailable.');
        }

        return UserContact::firstOrCreate(['user_id' => $user->id, 'contact_user_id' => $contact->id]);
    }

    public function remove(User $user, User $contact): bool
    {
        return UserContact::where(['user_id' => $user->id, 'contact_user_id' => $contact->id])->delete() > 0;
    }

    public function removeById(User $user, int $contactId): bool
    {
        return UserContact::where(['user_id' => $user->id, 'contact_user_id' => $contactId])->delete() > 0;
    }

    public function list(User $user)
    {
        return UserContact::where('user_id', $user->id)->with('contact.profile.city')->latest('created_at')->get();
    }
}
