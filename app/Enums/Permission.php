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
    case PartiesView = 'parties.view';
    case PartiesManage = 'parties.manage';
    case PaymentsRecord = 'payments.record';
    case ExpensesRecord = 'expenses.record';
    case MaterialsView = 'materials.view';
    case MaterialsRecord = 'materials.record';
    case MaterialsAdjust = 'materials.adjust';
    case ProductionView = 'production.view';
    case ProductionRecord = 'production.record';
    case ProductionAdjust = 'production.adjust';

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
            self::PartiesView => __('View parties and statements'),
            self::PartiesManage => __('Manage parties'),
            self::PaymentsRecord => __('Record payments and receipts'),
            self::ExpensesRecord => __('Record expenses'),
            self::MaterialsView => __('View material stock and purchases'),
            self::MaterialsRecord => __('Record material purchases and usage'),
            self::MaterialsAdjust => __('Enter opening stock and stock shortages'),
            self::ProductionView => __('View production and brick stock'),
            self::ProductionRecord => __('Record daily production'),
            self::ProductionAdjust => __('Correct production: adjust brick stock and reverse entries'),
        };
    }
}
