<?php

namespace App\Enums;

/**
 * Every permission in the system. Modules add their cases here; `php artisan permissions:sync`
 * writes them to the database and refreshes the default roles.
 */
enum Permission: string
{
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case ActivityView = 'activity.view';
    case SettingsManage = 'settings.manage';
    case KilnProfileManage = 'kiln.profile';
    case AccountsView = 'accounts.view';
    case AccountsManage = 'accounts.manage';
    case TransactionsRecord = 'transactions.record';
    case TransactionsReverse = 'transactions.reverse';
    case CapitalRecord = 'capital.record';
    case FinancialReportsView = 'reports.financial';

    public function label(): string
    {
        return match ($this) {
            self::UsersView => __('View users'),
            self::UsersManage => __('Manage users'),
            self::ActivityView => __('View activity log'),
            self::SettingsManage => __('Manage kiln settings'),
            self::KilnProfileManage => __('Edit kiln profile'),
            self::AccountsView => __('View cash and bank accounts'),
            self::AccountsManage => __('Manage cash and bank accounts'),
            self::TransactionsRecord => __('Record transfers'),
            self::TransactionsReverse => __('Reverse transactions'),
            self::CapitalRecord => __('Record owner capital and withdrawals'),
            self::FinancialReportsView => __('View financial reports'),
        };
    }
}
