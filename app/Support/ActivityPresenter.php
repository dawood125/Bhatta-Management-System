<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Activity;
use App\Models\Kiln;
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

        // U+2068/U+2069 isolate the name so Latin names and numbers keep their order inside Urdu text.
        return match (true) {
            $subject instanceof User => __('User').': '."\u{2068}{$subject->name}\u{2069}",
            $subject instanceof Kiln => __('Kiln').': '."\u{2068}{$subject->name}\u{2069}",
            default => class_basename((string) $activity->subject_type).' #'.$activity->subject_id,
        };
    }

    /**
     * @return list<array{field: string, old: string, new: string}>
     */
    public static function changes(Activity $activity): array
    {
        $properties = $activity->properties;
        $new = (array) ($properties['attributes'] ?? []);
        $old = (array) ($properties['old'] ?? []);

        $rows = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
            $rows[] = [
                'field' => self::fieldLabel((string) $field),
                'old' => self::value((string) $field, $old[$field] ?? null),
                'new' => self::value((string) $field, $new[$field] ?? null),
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
            default => $field,
        };
    }

    private static function value(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($field) {
            'is_active' => $value ? __('Active') : __('Deactivated'),
            'role' => Role::tryFrom((string) $value)?->label() ?? (string) $value,
            'locale' => $value === 'ur' ? 'اردو' : 'English',
            default => is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
