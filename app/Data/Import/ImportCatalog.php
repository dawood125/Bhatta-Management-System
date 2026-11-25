<?php

namespace App\Data\Import;

use App\Data\Excel;

/**
 * The imports on the Data page, in the order the first day needs them, and their templates.
 */
class ImportCatalog
{
    /** @return array<string, Importer> */
    public function all(): array
    {
        $importers = [new PartiesImporter, new WorkersImporter, new MaterialStockImporter, new BrickStockImporter];
        $byKey = [];
        foreach ($importers as $importer) {
            $byKey[$importer->key()] = $importer;
        }

        return $byKey;
    }

    public function find(string $key): ?Importer
    {
        return $this->all()[$key] ?? null;
    }

    /** Write the Excel template: the columns with examples, and a Help sheet. */
    public function writeTemplate(Importer $importer, string $path): void
    {
        $help = [];
        foreach ($importer->columns() as $header => [$required, $hint]) {
            $help[] = [$header, $required ? __('Yes') : __('No'), $hint];
        }
        foreach ($importer->allowedValues() as $title => $values) {
            $help[] = [null, null, null];
            $help[] = [__('Allowed values for ":column"', ['column' => $title]), null, null];
            foreach ($values as $value) {
                $help[] = [$value, null, null];
            }
        }

        Excel::write($path, [
            $importer->title() => ['headers' => array_keys($importer->columns()), 'rows' => $importer->examples()],
            __('Help') => ['headers' => [__('Column'), __('Required'), __('How to fill it')], 'rows' => $help, 'widths' => [1 => 32, 2 => 12, 3 => 90]],
        ]);
    }
}
