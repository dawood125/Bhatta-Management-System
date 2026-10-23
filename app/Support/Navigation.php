<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;

/**
 * Sidebar navigation and the settings tabs. Each module adds its entries here; items the user
 * has no permission for are not shown (the routes themselves are protected separately).
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
                    ['label' => __('Dashboard'), 'route' => 'dashboard', 'icon' => 'layout-dashboard', 'active' => 'dashboard', 'permissions' => []],
                ],
            ],
            [
                'label' => __('Administration'),
                'items' => [
                    ['label' => __('Kiln settings'), 'route' => 'settings.index', 'icon' => 'settings', 'active' => 'settings.*', 'permissions' => [Permission::KilnProfileManage, Permission::SettingsManage]],
                    ['label' => __('Users'), 'route' => 'users.index', 'icon' => 'users', 'active' => 'users.*', 'permissions' => [Permission::UsersView]],
                    ['label' => __('Activity log'), 'route' => 'activity.index', 'icon' => 'history', 'active' => 'activity.*', 'permissions' => [Permission::ActivityView]],
                ],
            ],
        ];

        $visible = [];
        foreach ($sections as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                if (self::allowed($user, $item['permissions'])) {
                    unset($item['permissions']);
                    $items[] = $item;
                }
            }

            if ($items !== []) {
                $visible[] = ['label' => $section['label'], 'items' => $items];
            }
        }

        return $visible;
    }

    /**
     * Tabs inside "Kiln settings".
     *
     * @return list<array{label: string, route: string, icon: string}>
     */
    public static function settingsTabs(User $user): array
    {
        $tabs = [
            ['label' => __('Kiln profile'), 'route' => 'settings.kiln', 'icon' => 'factory', 'permissions' => [Permission::KilnProfileManage]],
            ['label' => __('Products and grades'), 'route' => 'settings.products', 'icon' => 'brick-wall', 'permissions' => [Permission::SettingsManage]],
            ['label' => __('Production stages'), 'route' => 'settings.stages', 'icon' => 'flame', 'permissions' => [Permission::SettingsManage]],
            ['label' => __('Units'), 'route' => 'settings.units', 'icon' => 'ruler', 'permissions' => [Permission::SettingsManage]],
            ['label' => __('Areas'), 'route' => 'settings.areas', 'icon' => 'map-pin', 'permissions' => [Permission::SettingsManage]],
            ['label' => __('Expense categories'), 'route' => 'settings.expense-categories', 'icon' => 'tags', 'permissions' => [Permission::SettingsManage]],
        ];

        $visible = [];
        foreach ($tabs as $tab) {
            if (self::allowed($user, $tab['permissions'])) {
                unset($tab['permissions']);
                $visible[] = $tab;
            }
        }

        return $visible;
    }

    /**
     * Visible when there is no permission requirement, or the user has any of the permissions.
     *
     * @param  list<Permission>  $permissions
     */
    private static function allowed(User $user, array $permissions): bool
    {
        if ($permissions === []) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission->value)) {
                return true;
            }
        }

        return false;
    }
}
