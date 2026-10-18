<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;

/**
 * Sidebar navigation. Each module adds its entries here; items the user has no permission
 * for are not shown (the routes themselves are protected separately).
 */
class Navigation
{
    /**
     * @return list<array{label: string, items: list<array{label: string, route: string, icon: string, active: string}>}>
     */
    public static function for(User $user): array
    {
        $sections = [
            [
                'label' => __('Overview'),
                'items' => [
                    ['label' => __('Dashboard'), 'route' => 'dashboard', 'icon' => 'layout-dashboard', 'active' => 'dashboard', 'permission' => null],
                ],
            ],
            [
                'label' => __('Administration'),
                'items' => [
                    ['label' => __('Users'), 'route' => 'users.index', 'icon' => 'users', 'active' => 'users.*', 'permission' => Permission::UsersView],
                    ['label' => __('Activity log'), 'route' => 'activity.index', 'icon' => 'history', 'active' => 'activity.*', 'permission' => Permission::ActivityView],
                ],
            ],
        ];

        $visible = [];
        foreach ($sections as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                if ($item['permission'] === null || $user->can($item['permission']->value)) {
                    unset($item['permission']);
                    $items[] = $item;
                }
            }

            if ($items !== []) {
                $visible[] = ['label' => $section['label'], 'items' => $items];
            }
        }

        return $visible;
    }
}
