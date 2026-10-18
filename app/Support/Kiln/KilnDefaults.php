<?php

namespace App\Support\Kiln;

use App\Enums\ProductKind;
use App\Enums\StageKind;
use App\Models\ExpenseCategory;
use App\Models\Kiln;
use App\Models\Product;
use App\Models\ProductionStage;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Starting master data for a new kiln. Everything here is editable by the kiln afterwards.
 * Safe to run more than once: existing rows are never changed or duplicated.
 *
 * Grade names and Urdu spellings are provisional until the owner confirms them (Q-16).
 */
class KilnDefaults
{
    /** @var list<array{code: string, name: string, name_ur: string, symbol: string, decimals: int, system: bool}> */
    public const UNITS = [
        ['code' => 'brick', 'name' => 'Brick', 'name_ur' => 'اینٹ', 'symbol' => 'pcs', 'decimals' => 0, 'system' => true],
        ['code' => 'ton', 'name' => 'Ton', 'name_ur' => 'ٹن', 'symbol' => 't', 'decimals' => 3, 'system' => false],
        ['code' => 'kg', 'name' => 'Kilogram', 'name_ur' => 'کلوگرام', 'symbol' => 'kg', 'decimals' => 2, 'system' => false],
        ['code' => 'maund', 'name' => 'Maund', 'name_ur' => 'من', 'symbol' => 'maund', 'decimals' => 2, 'system' => false],
        ['code' => 'cft', 'name' => 'Cubic foot', 'name_ur' => 'مکعب فٹ', 'symbol' => 'cft', 'decimals' => 2, 'system' => false],
        ['code' => 'trolley', 'name' => 'Trolley', 'name_ur' => 'ٹرالی', 'symbol' => 'trolley', 'decimals' => 2, 'system' => false],
        ['code' => 'bag', 'name' => 'Bag', 'name_ur' => 'بوری', 'symbol' => 'bag', 'decimals' => 0, 'system' => false],
        ['code' => 'litre', 'name' => 'Litre', 'name_ur' => 'لیٹر', 'symbol' => 'L', 'decimals' => 2, 'system' => false],
        ['code' => 'piece', 'name' => 'Piece', 'name_ur' => 'عدد', 'symbol' => 'pcs', 'decimals' => 0, 'system' => false],
    ];

    /** @var list<array{code: string, name: string, name_ur: string, kind: ProductKind, unit: string, saleable: bool}> */
    public const PRODUCTS = [
        ['code' => 'awal', 'name' => 'Awal', 'name_ur' => 'اوّل', 'kind' => ProductKind::FiredGrade, 'unit' => 'brick', 'saleable' => true],
        ['code' => 'doom', 'name' => 'Doom', 'name_ur' => 'دوم', 'kind' => ProductKind::FiredGrade, 'unit' => 'brick', 'saleable' => true],
        ['code' => 'khingar', 'name' => 'Khingar', 'name_ur' => 'کھنگر', 'kind' => ProductKind::FiredGrade, 'unit' => 'brick', 'saleable' => true],
        ['code' => 'tile', 'name' => 'Tile', 'name_ur' => 'ٹائل', 'kind' => ProductKind::FiredGrade, 'unit' => 'brick', 'saleable' => true],
        ['code' => 'tooty', 'name' => 'Tooty (broken)', 'name_ur' => 'ٹوٹا', 'kind' => ProductKind::ByProduct, 'unit' => 'brick', 'saleable' => true],
        ['code' => 'rora', 'name' => 'Rora', 'name_ur' => 'روڑا', 'kind' => ProductKind::ByProduct, 'unit' => 'trolley', 'saleable' => true],
        ['code' => 'kachi', 'name' => 'Kachi (raw brick)', 'name_ur' => 'کچی اینٹ', 'kind' => ProductKind::RawBrick, 'unit' => 'brick', 'saleable' => false],
    ];

    /** @var list<array{name: string, name_ur: string, subs: list<array{0: string, 1: string}>}> */
    public const EXPENSE_CATEGORIES = [
        ['name' => 'Fuel and vehicles', 'name_ur' => 'ایندھن اور گاڑیاں', 'subs' => [['Diesel', 'ڈیزل'], ['Petrol', 'پٹرول'], ['Vehicle repair', 'گاڑی کی مرمت']]],
        ['name' => 'Repairs and maintenance', 'name_ur' => 'مرمت و دیکھ بھال', 'subs' => [['Kiln repair', 'بھٹے کی مرمت'], ['Machinery repair', 'مشینری کی مرمت']]],
        ['name' => 'Utility bills', 'name_ur' => 'یوٹیلیٹی بل', 'subs' => [['Electricity', 'بجلی'], ['Phone and internet', 'فون اور انٹرنیٹ']]],
        ['name' => 'Food and mess', 'name_ur' => 'کھانا اور میس', 'subs' => [['Tea and food', 'چائے اور کھانا']]],
        ['name' => 'Office', 'name_ur' => 'دفتر', 'subs' => [['Stationery', 'اسٹیشنری']]],
        ['name' => 'Government fees and taxes', 'name_ur' => 'سرکاری فیس اور ٹیکس', 'subs' => [['Licence and fees', 'لائسنس اور فیس']]],
        ['name' => 'Miscellaneous', 'name_ur' => 'متفرق', 'subs' => []],
    ];

    public function __construct(private readonly CurrentKiln $currentKiln) {}

    public function seed(Kiln $kiln): void
    {
        $this->currentKiln->run($kiln, function (): void {
            DB::transaction(function (): void {
                $this->seedUnits();
                $this->seedProducts();
                $this->seedStages();
                $this->seedExpenseCategories();
            });
        });
    }

    private function seedUnits(): void
    {
        foreach (self::UNITS as $order => $unit) {
            Unit::query()->firstOrCreate(['code' => $unit['code']], [
                'name' => $unit['name'],
                'name_ur' => $unit['name_ur'],
                'symbol' => $unit['symbol'],
                'decimals' => $unit['decimals'],
                'is_system' => $unit['system'],
                'is_active' => true,
                'sort_order' => ($order + 1) * 10,
            ]);
        }
    }

    private function seedProducts(): void
    {
        $units = Unit::query()->whereNotNull('code')->pluck('id', 'code');

        foreach (self::PRODUCTS as $order => $product) {
            Product::query()->firstOrCreate(['code' => $product['code']], [
                'name' => $product['name'],
                'name_ur' => $product['name_ur'],
                'kind' => $product['kind'],
                'unit_id' => $units[$product['unit']],
                'is_saleable' => $product['saleable'],
                'is_active' => true,
                'sort_order' => ($order + 1) * 10,
            ]);
        }
    }

    private function seedStages(): void
    {
        foreach (StageKind::cases() as $kind) {
            ProductionStage::query()->firstOrCreate(['kind' => $kind], [
                'name' => $kind->defaultName(),
                'name_ur' => $kind->defaultNameUrdu(),
                'sort_order' => $kind->order(),
            ]);
        }
    }

    private function seedExpenseCategories(): void
    {
        foreach (self::EXPENSE_CATEGORIES as $order => $category) {
            $model = ExpenseCategory::query()->firstOrCreate(['name' => $category['name']], [
                'name_ur' => $category['name_ur'],
                'is_active' => true,
                'sort_order' => ($order + 1) * 10,
            ]);

            foreach ($category['subs'] as [$name, $nameUr]) {
                $model->subCategories()->firstOrCreate(['name' => $name], [
                    'name_ur' => $nameUr,
                    'is_active' => true,
                ]);
            }
        }
    }
}
