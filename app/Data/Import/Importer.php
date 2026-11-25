<?php

namespace App\Data\Import;

use App\Data\Excel;
use App\Models\User;
use DateTime;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * One kind of import (parties, workers, material or brick opening stock). Each row goes through
 * the same action as the normal form, so balances, stock and the books come out exactly as if
 * it had been typed in.
 */
abstract class Importer
{
    /** Short name used in links, e.g. "parties". */
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /**
     * The columns of the template: header => [required, hint].
     *
     * @return array<string, array{0: bool, 1: string}>
     */
    abstract public function columns(): array;

    /**
     * Example rows for the template.
     *
     * @return list<list<scalar|null>>
     */
    abstract public function examples(): array;

    /**
     * Allowed values shown on the template's "Help" sheet: title => values.
     *
     * @return array<string, list<string>>
     */
    public function allowedValues(): array
    {
        return [];
    }

    /** Whether the user may run this import. */
    abstract public function allowed(User $actor): bool;

    /**
     * Import one row (values keyed by normalised header). Throws a ValidationException whose
     * messages say what is wrong.
     *
     * @param  array<string, mixed>  $values
     */
    abstract public function importRow(User $actor, array $values, string $key): void;

    /**
     * A short summary of a row for the preview, e.g. its name.
     *
     * @param  array<string, mixed>  $values
     */
    abstract public function describe(array $values): string;

    /**
     * The value of a column by its header ("Name (Urdu)" or its normalised form).
     *
     * @param  array<string, mixed>  $values
     */
    protected function value(array $values, string $header): string
    {
        $raw = $values[Excel::normalise($header)] ?? null;

        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d');
        }
        if (is_float($raw)) {
            // Excel keeps numbers as floats: 1500.0 → "1500", 12.5 → "12.5".
            return rtrim(rtrim(number_format($raw, 3, '.', ''), '0'), '.');
        }

        return trim((string) $raw);
    }

    /** @param  array<string, mixed>  $values */
    protected function required(array $values, string $header): string
    {
        $value = $this->value($values, $header);
        if ($value === '') {
            throw ValidationException::withMessages([$header => __(':column is empty.', ['column' => __($header)])]);
        }

        return $value;
    }

    /**
     * An amount or quantity typed with commas, e.g. "1,500" → "1500". Blank stays blank.
     *
     * @param  array<string, mixed>  $values
     */
    protected function number(array $values, string $header): string
    {
        return str_replace([',', ' '], '', $this->value($values, $header));
    }

    /**
     * A date as dd-mm-yyyy, dd/mm/yyyy, yyyy-mm-dd or an Excel date; today when blank.
     *
     * @param  array<string, mixed>  $values
     */
    protected function date(array $values, string $header): string
    {
        $text = $this->value($values, $header);
        if ($text === '') {
            return now()->format('Y-m-d');
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'j-n-Y', 'j/n/Y'] as $format) {
            // DateTime returns false on a mismatch (Carbon throws).
            $date = DateTime::createFromFormat('!'.$format, $text);
            if ($date !== false && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }

        throw ValidationException::withMessages([$header => __(':column ":value" is not a date. Write it like 25-11-2026.', ['column' => __($header), 'value' => $text])]);
    }

    /**
     * Excel turns 03001234567 into 3001234567: put the leading 0 back.
     *
     * @param  array<string, mixed>  $values
     */
    protected function phone(array $values, string $header): string
    {
        $phone = $this->value($values, $header);

        return preg_match('/^3\d{9}$/', $phone) ? '0'.$phone : $phone;
    }

    /** Lower-case words without spaces or brackets, for matching typed choices. */
    protected static function plain(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(trim($text)));
    }
}
