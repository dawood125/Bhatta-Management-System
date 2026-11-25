<?php

use App\Accounting\LedgerVerifier;
use App\Data\Excel;
use App\Data\Import\ImportCatalog;
use App\Data\Import\ImportRunner;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Livewire\Data\DataIndex;
use App\Materials\MaterialStock;
use App\Models\Party;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Data → Import from Excel and Export to Excel (client, 2026-11-25).
 */
beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
});

/** An .xlsx file with these headers and rows, as a path. */
function excelFile(array $headers, array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    Excel::write($path, ['Sheet1' => ['headers' => $headers, 'rows' => $rows]]);

    return $path;
}

function importFile(User $owner, string $type, string $path, bool $save = true): array
{
    return app(ImportRunner::class)->run($owner, app(ImportCatalog::class)->find($type), Excel::read($path, 'xlsx'), $save);
}

/** All rows of the first sheet of an .xlsx file, as arrays. */
function readXlsx(string $path, ?string $sheetName = null): array
{
    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        if ($sheetName !== null && $sheet->getName() !== $sheetName) {
            continue;
        }
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        break;
    }
    $reader->close();

    return $rows;
}

describe('importing parties', function () {
    it('creates parties with roles, areas and opening balances', function () {
        $path = excelFile(
            ['Name', 'Name (Urdu)', 'Type', 'Phone', 'Phone 2', 'Address', 'Area', 'Opening balance', 'Who owes', 'As of date'],
            [
                ['Ali Traders', 'علی ٹریڈرز', 'Customer', 3001234567, '', 'Main bazar', 'City', '25,000', 'They owe us', '20-11-2026'],
                ['Rafiq Coal', '', 'Supplier, Driver', '', '', '', '', 40000, 'We owe them', ''],
                ['Walk-in', '', 'customer', '', '', '', 'City', '', '', ''],
            ],
        );

        $result = importFile($this->owner, 'parties', $path);

        $ali = Party::query()->where('name', 'Ali Traders')->sole();
        $rafiq = Party::query()->where('name', 'Rafiq Coal')->sole();
        expect($result['saved'])->toBeTrue()
            ->and($result['ok'])->toBe(3)
            ->and($ali->phone)->toBe('03001234567')
            ->and($ali->name_ur)->toBe('علی ٹریڈرز')
            ->and($ali->area->name)->toBe('City')
            ->and($ali->opening_date->format('Y-m-d'))->toBe('2026-11-20')
            ->and(partyBalance($ali))->toBe('25000.00')
            ->and($rafiq->roles())->toEqual([PartyRole::Driver, PartyRole::Supplier])
            ->and(partyBalance($rafiq))->toBe('-40000.00')
            ->and(Party::query()->where('name', 'Walk-in')->sole()->area_id)->toBe($ali->area_id)
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('saves nothing when one row is wrong, and says which', function () {
        makeParty($this->owner, 'Old Customer');
        $path = excelFile(['Name', 'Type', 'Opening balance'], [
            ['New One', 'Customer', '100'],
            ['Old Customer', 'Customer', ''],
            ['Bad Type', 'Friend', ''],
            ['', 'Customer', ''],
        ]);

        $result = importFile($this->owner, 'parties', $path);

        expect($result['saved'])->toBeFalse()
            ->and($result['failed'])->toBe(3)
            ->and($result['rows'][0]['errors'])->toBe([])
            ->and($result['rows'][1]['errors'][0])->toContain('already exists')
            ->and($result['rows'][2]['errors'][0])->toContain('Friend')
            ->and($result['rows'][3]['errors'][0])->toContain('Name is empty')
            ->and($result['rows'][1]['line'])->toBe(3)
            ->and(Party::query()->where('name', 'New One')->exists())->toBeFalse();
    });

    it('checks without saving', function () {
        $path = excelFile(['Name', 'Type'], [['Check Only', 'Customer']]);

        $result = importFile($this->owner, 'parties', $path, save: false);

        expect($result['ok'])->toBe(1)
            ->and($result['saved'])->toBeFalse()
            ->and(Party::query()->count())->toBe(0);
    });

    it('catches two rows with the same name in one file', function () {
        $path = excelFile(['Name', 'Type'], [['Twice', 'Customer'], ['Twice', 'Customer']]);

        $result = importFile($this->owner, 'parties', $path);

        expect($result['failed'])->toBe(1)->and(Party::query()->count())->toBe(0);
    });
});

describe('importing workers and stock', function () {
    it('creates workers with categories and opening balances', function () {
        $path = excelFile(['Name', 'Category', 'Opening balance', 'Who owes', 'As of date'], [
            ['Rashid family', 'Pathai', '12000', 'Kiln owes worker', ''],
            ['Hamza', 'machine operator', '5000', 'Worker owes kiln', ''],
            ['Aslam', 'کمیار', '', '', ''],
            ['Nobody', 'Pilot', '', '', ''],
        ]);

        $result = importFile($this->owner, 'workers', $path);
        expect($result['failed'])->toBe(1)->and($result['rows'][3]['errors'][0])->toContain('Pilot');

        // Fixed file: without the bad row.
        $path = excelFile(['Name', 'Category', 'Opening balance', 'Who owes', 'As of date'], [
            ['Rashid family', 'Pathai', '12000', 'Kiln owes worker', ''],
            ['Hamza', 'machine operator', '5000', 'Worker owes kiln', ''],
        ]);
        $result = importFile($this->owner, 'workers', $path);

        expect($result['saved'])->toBeTrue()
            ->and(workerBalance(Worker::query()->where('name', 'Rashid family')->sole()))->toBe('12000.00')
            ->and(workerBalance(Worker::query()->where('name', 'Hamza')->sole()))->toBe('-5000.00')
            ->and(Worker::query()->where('name', 'Hamza')->sole()->category->code)->toBe('machine_operator');
    });

    it('records material and brick opening stock', function () {
        $materials = excelFile(['Material', 'Quantity', 'Value', 'As of date'], [
            ['Soil', '40', '120000', ''],
            ['Coal', '25.5', '1,275,000', ''],
        ]);
        $bricks = excelFile(['Stage', 'Grade', 'Quantity', 'As of date'], [
            ['Raw', '', '150000', ''],
            ['In the kiln (Julai)', '', '300000', ''],
            ['Ready for sale', 'Awal', '80000', ''],
            ['Ready for sale', '', '100', ''],
        ]);

        expect(importFile($this->owner, 'material-stock', $materials)['saved'])->toBeTrue()
            ->and(materialQtyOf('soil'))->toBe('40.000')
            ->and(materialQtyOf('coal'))->toBe('25.500');

        $result = importFile($this->owner, 'brick-stock', $bricks);
        expect($result['failed'])->toBe(1)->and($result['rows'][3]['errors'][0])->toContain('grade');

        $bricks = excelFile(['Stage', 'Grade', 'Quantity', 'As of date'], [
            ['Raw', '', '150000', ''],
            ['In the kiln (Julai)', '', '300000', ''],
            ['Ready for sale', 'Awal', '80000', ''],
        ]);
        expect(importFile($this->owner, 'brick-stock', $bricks)['saved'])->toBeTrue();

        $stock = brickStock();
        expect($stock['raw'])->toBe('150,000')
            ->and($stock['in_firing'])->toBe('300,000')
            ->and($stock['saleable']['awal'])->toBe('80,000')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('reads a CSV file too', function () {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBFName,Type,Opening balance\nCSV Customer,Customer,500\n");

        $result = app(ImportRunner::class)->run($this->owner, app(ImportCatalog::class)->find('parties'), Excel::read($path, 'csv'), true);

        expect($result['saved'])->toBeTrue()
            ->and(partyBalance(Party::query()->where('name', 'CSV Customer')->sole()))->toBe('500.00');
    });
});

function materialQtyOf(string $code): string
{
    $id = material($code)->id;

    return app(MaterialStock::class)->levels([$id])[$id]['quantity']->toDecimal();
}

describe('templates and the page', function () {
    it('downloads a template with the columns, examples and a Help sheet', function () {
        $response = $this->get(route('data.template', 'workers'))->assertOk();
        $path = $response->getFile()->getPathname();

        $rows = readXlsx($path);
        expect($rows[0])->toBe(['Name', 'Name (Urdu)', 'Category', 'Phone', 'Joined on', 'Opening balance', 'Who owes', 'As of date'])
            ->and(count($rows))->toBe(4);

        $help = array_merge(...readXlsx($path, 'Help'));
        expect($help)->toContain('Pathai (پتھائی)')->toContain('Machine operator (مشین آپریٹر)');
    });

    it('imports through the page: upload, check, then import', function () {
        $path = excelFile(['Name', 'Type', 'Opening balance'], [['Page Customer', 'Customer', '700']]);
        $file = UploadedFile::fake()->createWithContent('parties.xlsx', file_get_contents($path));

        Livewire::test(DataIndex::class)
            ->call('chooseImport', 'parties')
            ->set('file', $file)
            ->assertSee('All 1 rows are correct.')
            ->assertSee('Import 1 rows')
            ->call('import')
            ->assertSee('1 rows imported.');

        expect(partyBalance(Party::query()->where('name', 'Page Customer')->sole()))->toBe('700.00');
    });

    it('refuses a file that is not Excel', function () {
        Livewire::test(DataIndex::class)
            ->call('chooseImport', 'parties')
            ->set('file', UploadedFile::fake()->create('notes.pdf', 10))
            ->assertHasErrors(['file']);
    });
});

describe('exports', function () {
    beforeEach(function () {
        $this->customer = makeParty($this->owner, 'Export Customer', [PartyRole::Customer], '9000');
        setLabourRate($this->owner, 'pathai', '1000');
        $this->worker = makeWorker($this->owner, 'Export Worker', 'pathai');
        recordWork($this->owner, $this->worker, '2000');
    });

    it('downloads all data as one workbook with a sheet per list', function () {
        $response = $this->get(route('data.export', 'all'))->assertOk();
        $path = $response->getFile()->getPathname();

        $reader = new Reader;
        $reader->open($path);
        $names = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $names[] = $sheet->getName();
        }
        $reader->close();

        expect($names)->toBe(['Parties', 'Workers', 'Sales', 'Material purchases', 'Transactions', 'Material stock', 'Brick stock']);

        $parties = readXlsx($path, 'Parties');
        expect($parties[1][0])->toBe('Export Customer')
            ->and($parties[1][7])->toEqual(9000)
            ->and($parties[1][8])->toBe('They owe us');

        $workers = readXlsx($path, 'Workers');
        expect($workers[1][5])->toEqual(2000)->and($workers[1][6])->toBe('Kiln owes worker');
    });

    it('lets each list be downloaded by whoever can see it', function () {
        $viewer = makeUser($this->kiln, Role::Viewer);

        $this->actingAs($viewer)->get(route('data.export', 'parties'))->assertOk();
        $this->actingAs($viewer)->get(route('data.export', 'transactions'))->assertForbidden();
        $this->actingAs($viewer)->get(route('data.export', 'all'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('data.export', 'nothing'))->assertNotFound();
    });

    it('limits sales and transactions to the dates asked for', function () {
        $response = $this->get(route('data.export', ['type' => 'transactions', 'from' => now()->addDay()->format('Y-m-d')]))->assertOk();

        expect(readXlsx($response->getFile()->getPathname()))->toHaveCount(1);
    });
});
