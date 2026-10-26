<?php

namespace App\Livewire;

use App\Enums\Permission;
use App\Models\User;
use App\Reports\DashboardData;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The kiln today: money, who owes whom, sales, production and stock, the last six months of
 * sales and costs, and whether the day is closed. Each section shows only for those who may
 * see it.
 */
class Dashboard extends Component
{
    public function render(CurrentKiln $currentKiln, DashboardData $dashboard): View
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
            'data' => $dashboard->for($user),
            'can' => [
                'sale' => $user->can(Permission::SalesRecord->value),
                'production' => $user->can(Permission::ProductionRecord->value),
                'payment' => $user->can(Permission::PaymentsRecord->value),
            ],
        ])->title(__('Dashboard'));
    }
}
