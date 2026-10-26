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
                    ['label' => __('Reports'), 'route' => 'reports.index', 'icon' => 'chart-column', 'active' => 'reports.*', 'permissions' => [
                        Permission::FinancialReportsView, Permission::DaysClose, Permission::AccountsView, Permission::PartiesView,
                        Permission::SalesView, Permission::ProductionView, Permission::MaterialsView, Permission::LabourView,
                    ]],
                ],
            ],
            [
                'label' => __('Money'),
                'items' => [
                    ['label' => __('Parties'), 'route' => 'parties.index', 'icon' => 'contact', 'active' => 'parties.*', 'permissions' => [Permission::PartiesView]],
                    ['label' => __('Payments and receipts'), 'route' => 'vouchers.index', 'icon' => 'arrow-left-right', 'active' => 'vouchers.*', 'permissions' => [Permission::AccountsView]],
                    ['label' => __('Expenses'), 'route' => 'expenses.index', 'icon' => 'receipt-text', 'active' => 'expenses.*', 'permissions' => [Permission::AccountsView]],
                    ['label' => __('Cash and bank'), 'route' => 'accounts.index', 'icon' => 'wallet', 'active' => 'accounts.*', 'permissions' => [Permission::AccountsView]],
                    ['label' => __('Transactions'), 'route' => 'transactions.index', 'icon' => 'receipt', 'active' => 'transactions.*', 'permissions' => [Permission::AccountsView]],
                    ['label' => __('Day close'), 'route' => 'reports.day-close', 'icon' => 'lock', 'active' => 'reports.day-close', 'permissions' => [Permission::DaysClose]],
                ],
            ],
            [
                'label' => __('Kiln'),
                'items' => [
                    ['label' => __('Sales'), 'route' => 'sales.index', 'icon' => 'shopping-cart', 'active' => 'sales.*', 'permissions' => [Permission::SalesView]],
                    ['label' => __('Production'), 'route' => 'production.index', 'icon' => 'factory', 'active' => 'production.*', 'permissions' => [Permission::ProductionView]],
                    ['label' => __('Materials'), 'route' => 'materials.index', 'icon' => 'package', 'active' => 'materials.*', 'permissions' => [Permission::MaterialsView]],
                    ['label' => __('Labour'), 'route' => 'labour.index', 'icon' => 'hard-hat', 'active' => 'labour.*', 'permissions' => [Permission::LabourView]],
                ],
            ],
            [
                'label' => __('Administration'),
                'items' => [
                    ['label' => __('Kiln settings'), 'route' => 'settings.index', 'icon' => 'settings', 'active' => 'settings.*', 'permissions' => [Permission::KilnProfileManage, Permission::SettingsManage]],
                    ['label' => __('Users'), 'route' => 'users.index', 'icon' => 'users', 'active' => 'users.*', 'permissions' => [Permission::UsersView]],
                    ['label' => __('Activity log'), 'route' => 'activity.index', 'icon' => 'history', 'active' => 'activity.*', 'permissions' => [Permission::ActivityView]],
                    ['label' => __('Backups'), 'route' => 'backups.index', 'icon' => 'database-backup', 'active' => 'backups.*', 'permissions' => [Permission::BackupsManage]],
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
            ['label' => __('Email'), 'route' => 'settings.email', 'icon' => 'mail', 'permissions' => [Permission::KilnProfileManage]],
            ['label' => __('Products and grades'), 'route' => 'settings.products', 'icon' => 'brick-wall', 'permissions' => [Permission::SettingsManage]],
            ['label' => __('Production stages'), 'route' => 'settings.stages', 'icon' => 'flame', 'permissions' => [Permission::SettingsManage]],
            ['label' => __('Units'), 'route' => 'settings.units', 'icon' => 'ruler', 'permissions' => [Permission::SettingsManage]],
            ['label' => __('Areas'), 'route' => 'settings.areas', 'icon' => 'map-pin', 'permissions' => [Permission::SettingsManage]],
            ['label' => __('Expense categories'), 'route' => 'settings.expense-categories', 'icon' => 'tags', 'permissions' => [Permission::SettingsManage]],
            ['label' => __('Materials'), 'route' => 'settings.materials', 'icon' => 'package', 'permissions' => [Permission::SettingsManage]],
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
