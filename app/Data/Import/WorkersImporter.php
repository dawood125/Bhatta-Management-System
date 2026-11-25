<?php

namespace App\Data\Import;

use App\Actions\Labour\SaveWorker;
use App\Enums\Permission;
use App\Models\LabourCategory;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Validation\ValidationException;

/** Workers, families and teams, with wages due or advances on the first day. */
class WorkersImporter extends Importer
{
    public function key(): string
    {
        return 'workers';
    }

    public function title(): string
    {
        return __('Workers');
    }

    public function description(): string
    {
        return __('Workers, families and teams, with wages due or advances taken.');
    }

    public function columns(): array
    {
        return [
            'Name' => [true, __('A family or team can be one worker, named after its head.')],
            'Name (Urdu)' => [false, __('Optional.')],
            'Category' => [true, __('See the Help sheet.')],
            'Phone' => [false, ''],
            'Joined on' => [false, __('For salaries. Blank: not set.')],
            'Opening balance' => [false, __('0 or blank if nothing is owed.')],
            'Who owes' => [false, __('"Kiln owes worker" (wages due) or "Worker owes kiln" (advance).')],
            'As of date' => [false, __('Blank: today.')],
        ];
    }

    public function examples(): array
    {
        return [
            ['Rashid family', 'رشید فیملی', 'Pathai', '03001234567', '', '12000', 'Kiln owes worker', now()->format('d-m-Y')],
            ['Hamza', '', 'Machine operator', '', '', '5000', 'Worker owes kiln', now()->format('d-m-Y')],
            ['Aslam', '', 'Kamyaar', '', '', '0', '', ''],
        ];
    }

    public function allowedValues(): array
    {
        return [
            'Category' => LabourCategory::query()->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (LabourCategory $c) => $c->name.($c->name_ur ? ' ('.$c->name_ur.')' : ''))->all(),
            'Who owes' => ['Kiln owes worker', 'Worker owes kiln'],
        ];
    }

    public function allowed(User $actor): bool
    {
        return $actor->can(Permission::LabourWorkers->value);
    }

    /** @param  array<string, mixed>  $values */
    public function describe(array $values): string
    {
        return $this->value($values, 'Name');
    }

    public function importRow(User $actor, array $values, string $key): void
    {
        $name = $this->required($values, 'Name');
        $category = $this->category($this->required($values, 'Category'));

        if (Worker::query()->where('name', $name)->where('labour_category_id', $category->id)->exists()) {
            throw ValidationException::withMessages(['Name' => __('A :category worker named ":name" already exists.', ['category' => $category->displayName(), 'name' => $name])]);
        }

        $direction = match (self::plain($this->value($values, 'Who owes'))) {
            '', 'kilnowesworker', 'kilnowes', 'wagesdue', 'wages' => SaveWorker::KILN_OWES,
            'workeroweskiln', 'workerowes', 'advance' => SaveWorker::WORKER_OWES,
            default => throw ValidationException::withMessages(['Who owes' => __('Who owes must be "Kiln owes worker" or "Worker owes kiln".')]),
        };
        $joined = $this->value($values, 'Joined on');

        app(SaveWorker::class)->handle($actor, null, [
            'name' => $name,
            'name_ur' => $this->value($values, 'Name (Urdu)'),
            'phone' => $this->phone($values, 'Phone'),
            'labour_category_id' => (string) $category->id,
            'joined_on' => $joined === '' ? '' : $this->date($values, 'Joined on'),
            'notes' => '',
            'opening_amount' => $this->number($values, 'Opening balance') ?: '0',
            'opening_direction' => $direction,
            'opening_date' => $this->date($values, 'As of date'),
        ], $key);
    }

    private function category(string $text): LabourCategory
    {
        $plain = self::plain($text);
        $category = LabourCategory::query()->where('is_active', true)->get()
            ->first(fn (LabourCategory $c) => in_array($plain, [self::plain($c->name), self::plain((string) $c->name_ur), self::plain((string) $c->code)], true));

        if ($category === null) {
            throw ValidationException::withMessages(['Category' => __('Category ":category" is not known. See the Help sheet of the template.', ['category' => $text])]);
        }

        return $category;
    }
}
