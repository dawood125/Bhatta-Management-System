<?php

namespace App\Enums;

/**
 * Default roles. Role names are stored as these keys; labels are translated for display.
 */
enum Role: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Munshi = 'munshi';
    case DataEntry = 'data_entry';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Manager => __('Manager'),
            self::Munshi => __('Munshi / Accountant'),
            self::DataEntry => __('Data entry'),
            self::Viewer => __('Viewer'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => __('Full access, including users and all settings.'),
            self::Manager => __('Runs daily operations and can manage staff accounts.'),
            self::Munshi => __('Records money, labour and sales entries.'),
            self::DataEntry => __('Records daily production entries only.'),
            self::Viewer => __('Can view reports but cannot change anything.'),
        };
    }

    /**
     * Permissions granted to this role by default.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Manager => [Permission::UsersView, Permission::UsersManage, Permission::ActivityView, Permission::SettingsManage],
            self::Munshi => [],
            self::DataEntry => [],
            self::Viewer => [],
        };
    }
}
