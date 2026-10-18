<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;

/**
 * Click-to-sort table columns. Only columns listed in sortableColumns() can be used,
 * so a crafted request cannot sort by arbitrary SQL. Use together with WithPagination.
 */
trait WithSorting
{
    /**
     * Provided by Livewire's WithPagination (signature must match it exactly).
     *
     * @param  string  $pageName
     * @return void
     */
    abstract public function resetPage($pageName = 'page');

    #[Url(as: 'sort', except: '')]
    public string $sortField = '';

    #[Url(as: 'dir', except: 'asc')]
    public string $sortDirection = 'asc';

    /**
     * @return list<string>
     */
    abstract protected function sortableColumns(): array;

    abstract protected function defaultSort(): string;

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortableColumns(), true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    protected function activeSortField(): string
    {
        return in_array($this->sortField, $this->sortableColumns(), true) ? $this->sortField : $this->defaultSort();
    }

    protected function activeSortDirection(): string
    {
        return $this->sortDirection === 'desc' ? 'desc' : 'asc';
    }
}
