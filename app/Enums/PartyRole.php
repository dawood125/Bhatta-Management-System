<?php

namespace App\Enums;

/**
 * What a party is to the kiln. One party can have several roles (e.g. a customer who also
 * supplies soil).
 */
enum PartyRole: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Driver = 'driver';
    case Agent = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::Customer => __('Customer'),
            self::Supplier => __('Supplier'),
            self::Driver => __('Driver'),
            self::Agent => __('Sales agent'),
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Customer => __('Customers'),
            self::Supplier => __('Suppliers'),
            self::Driver => __('Drivers'),
            self::Agent => __('Sales agents'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Customer => 'brand',
            self::Supplier => 'info',
            self::Driver => 'warning',
            self::Agent => 'success',
        };
    }
}
