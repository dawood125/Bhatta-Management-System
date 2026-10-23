<?php

namespace App\Models\Concerns;

/**
 * For records that have an English `name` and an optional Urdu `name_ur`.
 *
 * @property string $name
 * @property string|null $name_ur
 */
trait HasLocalizedName
{
    /** Name in the current UI language (falls back to the English name when no Urdu name is set). */
    public function displayName(): string
    {
        return app()->getLocale() === 'ur' && filled($this->name_ur) ? (string) $this->name_ur : $this->name;
    }
}
