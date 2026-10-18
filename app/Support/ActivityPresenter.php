<?php

namespace App\Support;

use App\Enums\ProductKind;
use App\Enums\Role;
use App\Models\Activity;
use App\Models\Area;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubCategory;
use App\Models\Kiln;
use App\Models\Product;
use App\Models\ProductionStage;
use App\Models\Unit;
use App\Models\User;

/**
 * Turns raw audit-log rows into readable, translated text for the activity screen.
 */
class ActivityPresenter
{
    public static function eventLabel(Activity $activity): string
    {
        return match ($activity->event ?? $activity->description) {
            'created' => __('Created'),
            'updated' => __('Updated'),
            'deleted' => __('Deleted'),
            'logged_in' => __('Logged in'),
            'role_changed' => __('Role changed'),
            'password_reset' => __('Password reset'),
            'password_changed' => __('Changed own password'),
            default => (string) $activity->description,
        };
    }

    public static function eventTone(Activity $activity): string
    {
        return match ($activity->event ?? $activity->description) {
            'created' => 'success',
            'deleted' => 'danger',
            'role_changed', 'password_reset' => 'warning',
            'logged_in', 'password_changed' => 'info',
            default => 'neutral',
        };
    }

    public static function subjectLabel(Activity $activity): string
    {
        $subject = $activity->subject;

        $type = match (true) {
            $subject instanceof User => __('User'),
            $subject instanceof Kiln => __('Kiln'),
            $subject instanceof Product => __('Product'),
            $subject instanceof Unit => __('Unit'),
            $subject instanceof ProductionStage => __('Production stage'),
            $subject instanceof Area => __('Area'),
            $subject instanceof ExpenseCategory => __('Expense category'),
            $subject instanceof ExpenseSubCategory => __('Expense sub-category'),
            default => null,
        };

        // Unknown type, or the record no longer exists: show its type and id.
        if ($type === null) {
            return class_basename((string) $activity->subject_type).' #'.$activity->subject_id;
        }

        $name = method_exists($subject, 'displayName') ? $subject->displayName() : (string) $subject->getAttribute('name');

        // U+2068/U+2069 isolate the name so Latin names and numbers keep their order inside Urdu text.
        return $type.': '."\u{2068}{$name}\u{2069}";
    }

    /**
     * @return list<array{field: string, old: string, new: string}>
     */
    public static function changes(Activity $activity): array
    {
        $properties = $activity->properties;
        $new = (array) ($properties['attributes'] ?? []);
        $old = (array) ($properties['old'] ?? []);

        $isUser = $activity->subject_type === (new User)->getMorphClass();

        $rows = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
            $rows[] = [
                'field' => self::fieldLabel((string) $field),
                'old' => self::value((string) $field, $old[$field] ?? null, $isUser),
                'new' => self::value((string) $field, $new[$field] ?? null, $isUser),
            ];
        }

        return $rows;
    }

    private static function fieldLabel(string $field): string
    {
        return match ($field) {
            'name' => __('Name'),
            'name_ur' => __('Name (Urdu)'),
            'username' => __('Username'),
            'email' => __('Email'),
            'phone' => __('Phone'),
            'locale' => __('Language'),
            'is_active' => __('Status'),
            'role' => __('Role'),
            'address' => __('Address'),
            'address_ur' => __('Address (Urdu)'),
            'logo_path' => __('Logo'),
            'kind' => __('Type'),
            'unit_id' => __('Unit'),
            'is_saleable' => __('For sale'),
            'sort_order' => __('Display order'),
            'symbol' => __('Symbol'),
            'decimals' => __('Decimal places'),
            'expense_category_id' => __('Expense category'),
            default => $field,
        };
    }

    private static function value(string $field, mixed $value, bool $isUser): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($field) {
            // Users are deactivated; lists (products, units, ...) are archived.
            'is_active' => $value ? __('Active') : ($isUser ? __('Deactivated') : __('Archived')),
            'is_saleable' => $value ? __('Yes') : __('No'),
            'role' => Role::tryFrom((string) $value)?->label() ?? (string) $value,
            'kind' => ProductKind::tryFrom((string) $value)?->label() ?? (string) $value,
            'locale' => $value === 'ur' ? 'اردو' : 'English',
            'logo_path' => __('Logo'),
            default => is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
