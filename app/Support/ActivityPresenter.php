<?php

namespace App\Support;

use App\Enums\LabourActivity;
use App\Enums\PartyRole;
use App\Enums\PayBasis;
use App\Enums\ProductKind;
use App\Enums\Role;
use App\Enums\TreasuryKind;
use App\Models\Activity;
use App\Models\Area;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubCategory;
use App\Models\Kiln;
use App\Models\LabourCategory;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductionStage;
use App\Models\TreasuryAccount;
use App\Models\Unit;
use App\Models\User;
use App\Models\Worker;

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
            'roles_changed' => __('Roles changed'),
            'rate_set' => __('Rate set'),
            'rate_removed' => __('Rate removed'),
            default => (string) $activity->description,
        };
    }

    public static function eventTone(Activity $activity): string
    {
        return match ($activity->event ?? $activity->description) {
            'created' => 'success',
            'deleted' => 'danger',
            'role_changed', 'roles_changed', 'password_reset', 'rate_removed' => 'warning',
            'rate_set' => 'info',
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
            $subject instanceof Party => __('Party'),
            $subject instanceof TreasuryAccount => __('Account'),
            $subject instanceof LabourCategory => __('Labour category'),
            $subject instanceof Worker => __('Worker'),
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
            'roles' => __('Roles'),
            'phone_2' => __('Second phone'),
            'area_id' => __('Area'),
            'sales_agent_id' => __('Sales agent'),
            'opening_date' => __('Opening date'),
            'notes' => __('Note'),
            'account_number' => __('Account number'),
            'allow_negative' => __('Allow negative balance'),
            'basis' => __('How paid'),
            'activity' => __('Paid for'),
            'labour_category_id' => __('Labour category'),
            'joined_on' => __('Joined on'),
            'rate' => __('Rate'),
            'effective_from' => __('From date'),
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
            'kind' => ProductKind::tryFrom((string) $value)?->label() ?? TreasuryKind::tryFrom((string) $value)?->label() ?? (string) $value,
            'roles' => collect(explode(', ', (string) $value))->map(fn (string $role) => PartyRole::tryFrom($role)?->label() ?? $role)->implode(', '),
            'allow_negative' => $value ? __('Yes') : __('No'),
            'basis' => PayBasis::tryFrom((string) $value)?->label() ?? (string) $value,
            'activity' => LabourActivity::tryFrom((string) $value)?->label() ?? (string) $value,
            'labour_category_id' => LabourCategory::query()->find((int) $value)?->displayName() ?? '#'.$value,
            'locale' => $value === 'ur' ? 'اردو' : 'English',
            'logo_path' => __('Logo'),
            default => is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
