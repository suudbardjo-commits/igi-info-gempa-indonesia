<?php

namespace App\Policies;

use App\Models\EvacuationCamp;
use App\Models\User;

class EvacuationCampPolicy
{
    /** Melapor untuk sebuah posko: koordinatornya, atau relawan yang ditugaskan ke sana. */
    public function report(User $user, EvacuationCamp $camp): bool
    {
        return $user->can('field-report.create') && $camp->canBeReportedBy($user);
    }
}
