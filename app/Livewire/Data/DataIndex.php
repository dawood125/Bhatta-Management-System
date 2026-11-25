<?php

namespace App\Livewire\Data;

use App\Data\DataReset;
use App\Data\Excel;
use App\Data\Export\DataExporter;
use App\Data\Import\ImportCatalog;
use App\Data\Import\ImportRunner;
use App\Enums\Permission;
use App\Enums\Role;
use App\Livewire\Concerns\RunsActions;
use App\Models\User;
use App\Support\Access\KilnAccess;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

/**
 * Administration → Data: reset the kiln's data (owner only, after a backup), import lists and
 * opening stock from Excel, and export everything to Excel.
 */
class DataIndex extends Component
{
    use RunsActions, WithFileUploads;

    /** The import being done: parties, workers, material-stock or brick-stock. */
    public string $importType = '';

    /** @var TemporaryUploadedFile|UploadedFile|null */
    public $file = null;

    /** @var array{rows: list<array{line: int, summary: string, errors: list<string>}>, ok: int, failed: int, saved: bool}|null */
    public ?array $report = null;

    public bool $showReset = false;

    public string $resetMode = DataReset::CLEAR_ENTRIES;

    public string $password = '';

    public string $phrase = '';

    /** @var array{mode: string, backup: string|null, disk: string|null, deleted: int}|null */
    public ?array $resetDone = null;

    public function mount(): void
    {
        $this->authorize(Permission::DataManage->value);
    }

    public function chooseImport(string $type): void
    {
        abort_if(app(ImportCatalog::class)->find($type) === null, 404);
        $this->resetErrorBag();
        $this->importType = $type;
        $this->file = null;
        $this->report = null;
    }

    public function cancelImport(): void
    {
        $this->importType = '';
        $this->file = null;
        $this->report = null;
    }

    /** A file was chosen: check every row straight away (nothing is saved yet). */
    public function updatedFile(): void
    {
        $this->report = null;
        $this->validate(['file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv']], [], ['file' => __('file')]);
        $this->runImport(save: false);
    }

    public function import(): void
    {
        $this->runImport(save: true);

        if ($this->report !== null && $this->report['saved']) {
            $this->toast(__(':count rows imported.', ['count' => $this->report['ok']]));
            $this->file = null;
        }
    }

    private function runImport(bool $save): void
    {
        $importer = app(ImportCatalog::class)->find($this->importType);
        abort_if($importer === null, 404);

        if ($this->file === null) {
            $this->addError('file', __('Choose the filled-in Excel file.'));

            return;
        }

        try {
            $rows = Excel::read($this->file->getRealPath(), $this->file->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());

            return;
        } catch (Throwable) {
            $this->addError('file', __('This file could not be read. Save it from Excel as .xlsx and try again.'));

            return;
        }

        if ($rows === []) {
            $this->addError('file', __('The file has no rows under the headings.'));

            return;
        }

        $this->report = app(ImportRunner::class)->run($this->actor(), $importer, $rows, $save);
    }

    public function openReset(string $mode): void
    {
        abort_unless($this->isOwner(), 403);
        $this->resetErrorBag();
        $this->resetMode = $mode === DataReset::FACTORY ? DataReset::FACTORY : DataReset::CLEAR_ENTRIES;
        $this->password = '';
        $this->phrase = '';
        $this->showReset = true;
    }

    public function runReset(DataReset $dataReset): void
    {
        $result = $this->runAction(fn () => $dataReset->handle($this->actor(), [
            'mode' => $this->resetMode,
            'password' => $this->password,
            'phrase' => $this->phrase,
        ]));

        $this->password = '';
        $this->phrase = '';
        $this->showReset = false;
        $this->resetDone = [
            'mode' => $result['mode'],
            'backup' => $result['backup']->path !== null ? basename($result['backup']->path) : null,
            'disk' => $result['backup']->disk,
            'deleted' => $result['deleted'],
        ];
        $this->toast($result['mode'] === DataReset::FACTORY ? __('Factory reset done.') : __('All entries cleared.'));
    }

    public function render(): View
    {
        return view('livewire.data.data-index', [
            'isOwner' => $this->isOwner(),
            'phraseToType' => DataReset::PHRASE,
            'importers' => app(ImportCatalog::class)->all(),
            'exports' => app(DataExporter::class)->lists(),
        ])->title(__('Data'));
    }

    private function isOwner(): bool
    {
        return app(KilnAccess::class)->roleIn($this->actor(), app(CurrentKiln::class)->get()) === Role::Owner;
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
