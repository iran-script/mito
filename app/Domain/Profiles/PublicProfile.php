<?php

namespace App\Domain\Profiles;

use App\Domain\Discovery\ActivityPresenter;
use App\Domain\Memberships\GoldMembershipService;
use App\Domain\Users\MitoId;
use App\Support\Presentation;

final readonly class PublicProfile
{
    public function __construct(public string $name, public int $age, public string $gender, public string $city, public array $interests, public ?string $photoFileId = null, public ?string $voiceFileId = null, public ?float $distanceKm = null, public string $activity = 'activity unavailable', public bool $isGold = false, public string $mitoId = '') {}

    public static function fromProfile(Profile $profile, ?float $distanceKm = null): self
    {
        return new self($profile->display_name, $profile->birth_date->age, $profile->gender->value, $profile->city->name, $profile->interests()->orderBy('name')->pluck('name')->all(), $profile->photo_file_id, $profile->voice_file_id, $distanceKm, ActivityPresenter::label($profile->user?->last_activity_at), app(GoldMembershipService::class)->isGold($profile->user), MitoId::display($profile->user->public_mito_id));
    }

    public function text(): string
    {
        $distance = $this->distanceKm === null ? '' : __("\nDistance: ").number_format($this->distanceKm, 1).__(' km away');

        return __('Mito ID: :id', ['id' => $this->mitoId])."\n".($this->isGold ? '⭐ '.__("Gold\n") : '').__(":v1\nAge: :v2\nGender: :v3\nCity: :v4\nInterests: ", ['v1' => $this->name, 'v2' => $this->age, 'v3' => Presentation::label($this->gender), 'v4' => Presentation::label($this->city)]).(implode('، ', array_map(Presentation::label(...), $this->interests)) ?: __('None'))."\n{$this->activity}{$distance}";
    }
}
