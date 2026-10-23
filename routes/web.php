<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\SettingsHomeController;
use App\Livewire\Accounts\AccountIndex;
use App\Livewire\Accounts\CashBook;
use App\Livewire\Accounts\TransactionIndex;
use App\Livewire\Activity\ActivityIndex;
use App\Livewire\Dashboard;
use App\Livewire\Dev\ComponentGallery;
use App\Livewire\Expenses\ExpenseIndex;
use App\Livewire\Materials\CostReport;
use App\Livewire\Materials\PurchaseForm;
use App\Livewire\Materials\PurchaseIndex;
use App\Livewire\Materials\PurchaseShow;
use App\Livewire\Materials\StockCard;
use App\Livewire\Materials\StockIndex;
use App\Livewire\Materials\UsageIndex;
use App\Livewire\Parties\PartyIndex;
use App\Livewire\Parties\PartyStatement;
use App\Livewire\Profile\ProfileEdit;
use App\Livewire\Reports\TrialBalance;
use App\Livewire\Settings\AreaIndex;
use App\Livewire\Settings\ExpenseCategoryIndex;
use App\Livewire\Settings\KilnProfile;
use App\Livewire\Settings\MaterialIndex;
use App\Livewire\Settings\ProductIndex;
use App\Livewire\Settings\StageIndex;
use App\Livewire\Settings\UnitIndex;
use App\Livewire\Users\UserIndex;
use App\Livewire\Vouchers\VoucherForm;
use App\Livewire\Vouchers\VoucherIndex;
use App\Livewire\Vouchers\VoucherShow;
use Illuminate\Support\Facades\Route;

Route::post('/locale', LocaleController::class)->name('locale.update');

Route::middleware(['auth', 'auth.session', 'kiln'])->group(function () {
    Route::redirect('/', '/dashboard');
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('/users', UserIndex::class)->name('users.index');
    Route::livewire('/activity', ActivityIndex::class)->name('activity.index');
    Route::livewire('/profile', ProfileEdit::class)->name('profile.edit');

    Route::livewire('/parties', PartyIndex::class)->name('parties.index');
    Route::livewire('/parties/{party}', PartyStatement::class)->name('parties.show');

    Route::livewire('/vouchers', VoucherIndex::class)->name('vouchers.index');
    Route::livewire('/vouchers/new/{kind}', VoucherForm::class)->whereIn('kind', ['payment', 'receipt'])->name('vouchers.create');
    Route::livewire('/vouchers/{entry}', VoucherShow::class)->whereNumber('entry')->name('vouchers.show');
    Route::livewire('/expenses', ExpenseIndex::class)->name('expenses.index');

    Route::prefix('materials')->name('materials.')->group(function () {
        Route::livewire('/', StockIndex::class)->name('index');
        Route::livewire('/purchases', PurchaseIndex::class)->name('purchases');
        Route::livewire('/purchases/new', PurchaseForm::class)->name('purchases.create');
        Route::livewire('/purchases/{purchase}', PurchaseShow::class)->whereNumber('purchase')->name('purchases.show');
        Route::livewire('/usage', UsageIndex::class)->name('usage');
        Route::livewire('/report', CostReport::class)->name('report');
        Route::livewire('/{material}', StockCard::class)->whereNumber('material')->name('show');
    });

    Route::livewire('/accounts', AccountIndex::class)->name('accounts.index');
    Route::livewire('/accounts/{account}/book', CashBook::class)->name('accounts.book');
    Route::livewire('/transactions', TransactionIndex::class)->name('transactions.index');
    Route::livewire('/reports/trial-balance', TrialBalance::class)->name('reports.trial-balance');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', SettingsHomeController::class)->name('index');
        Route::livewire('/kiln', KilnProfile::class)->name('kiln');
        Route::livewire('/products', ProductIndex::class)->name('products');
        Route::livewire('/units', UnitIndex::class)->name('units');
        Route::livewire('/stages', StageIndex::class)->name('stages');
        Route::livewire('/areas', AreaIndex::class)->name('areas');
        Route::livewire('/expense-categories', ExpenseCategoryIndex::class)->name('expense-categories');
        Route::livewire('/materials', MaterialIndex::class)->name('materials');
    });

    if (app()->environment('local', 'testing')) {
        Route::livewire('/dev/components', ComponentGallery::class)->name('dev.components');
    }
});
