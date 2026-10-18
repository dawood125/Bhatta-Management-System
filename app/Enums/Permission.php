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

    public function label(): string
    {
        return match ($this) {
            self::UsersView => __('View users'),
            self::UsersManage => __('Manage users'),
            self::ActivityView => __('View activity log'),
        };
    }
}
