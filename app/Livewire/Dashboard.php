<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Dashboard extends Component
{
    public function render(CurrentKiln $currentKiln): View
    {
        /** @var User $user */
        $user = auth()->user();
        $kiln = $currentKiln->get();
        $hour = (int) now()->format('G');

        return view('livewire.dashboard', [
            'user' => $user,
            'kiln' => $kiln,
            'greeting' => match (true) {
                $hour < 12 => __('Good morning'),
                $hour < 17 => __('Good afternoon'),
                default => __('Good evening'),
            },
            'activeStaff' => $kiln->users()->where('users.is_active', true)->count(),
            'canManageUsers' => $user->can('viewAny', User::class),
        ])->title(__('Dashboard'));
    }
}
