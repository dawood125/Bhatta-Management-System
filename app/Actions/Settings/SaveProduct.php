<?php

namespace App\Actions\Settings;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\ProductKind;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveProduct
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, ?Product $product, array $input): Product
    {
        $this->authorizeSettings($actor);
        $input = $this->clean($input);
        $input['is_saleable'] = filter_var($input['is_saleable'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $kilnId = $this->kilnId();
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100', $this->uniqueInKiln('products', 'name', $product?->id)],
            'name_ur' => ['nullable', 'string', 'max:100', $this->uniqueInKiln('products', 'name_ur', $product?->id)],
            'kind' => ['required', Rule::enum(ProductKind::class)],
            // Only active units of this kiln (an id from another kiln is simply "invalid").
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'is_saleable' => ['boolean'],
            'sort_order' => ['required', 'integer', 'between:0,9999'],
        ])->validate();

        $kind = ProductKind::from($data['kind']);
        $unit = Unit::query()->findOrFail((int) $data['unit_id']);

        // Brick grades and raw bricks are counted as bricks; production and stock depend on it.
        if ($kind !== ProductKind::ByProduct && $unit->code !== Unit::BRICK) {
            throw ValidationException::withMessages(['unit_id' => __('Brick grades and raw bricks must be counted in bricks.')]);
        }

        return DB::transaction(function () use ($product, $data): Product {
            $product ??= new Product(['is_active' => true]);
            $product->fill($data)->save();

            return $product;
        });
    }
}
