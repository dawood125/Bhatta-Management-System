<?php

use App\Http\Controllers\LocaleController;
use App\Livewire\Activity\ActivityIndex;
use App\Livewire\Dashboard;
use App\Livewire\Dev\ComponentGallery;
use App\Livewire\Profile\ProfileEdit;
use App\Livewire\Users\UserIndex;
use Illuminate\Support\Facades\Route;

Route::post('/locale', LocaleController::class)->name('locale.update');

Route::middleware(['auth', 'auth.session', 'kiln'])->group(function () {
    Route::redirect('/', '/dashboard');
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('/users', UserIndex::class)->name('users.index');
    Route::livewire('/activity', ActivityIndex::class)->name('activity.index');
    Route::livewire('/profile', ProfileEdit::class)->name('profile.edit');

    if (app()->environment('local', 'testing')) {
        Route::livewire('/dev/components', ComponentGallery::class)->name('dev.components');
    }
});
