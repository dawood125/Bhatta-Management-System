<?php

namespace App\Livewire\Concerns;

use App\Accounting\StatementPager;
use Livewire\Attributes\Url;

/**
 * A statement shown a page at a time (StatementPager), newest page first. Changing the dates goes
 * back to the newest page.
 */
trait PagesStatement
{
    /** Page of the statement; empty means the newest page. */
    #[Url(as: 'page', except: '')]
    public string $statementPage = '';

    public function updatedFrom(): void
    {
        $this->statementPage = '';
    }

    public function updatedTo(): void
    {
        $this->statementPage = '';
    }

    public function showStatementPage(int $page): void
    {
        $this->statementPage = (string) max(1, $page);
    }

    protected function statementPageNumber(): ?int
    {
        return ctype_digit($this->statementPage) && (int) $this->statementPage > 0 ? (int) $this->statementPage : null;
    }

    protected function statementPerPage(): int
    {
        return StatementPager::perPage();
    }
}
