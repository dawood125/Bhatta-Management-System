<?php

use App\Support\Kiln\CurrentKiln;
use App\Support\Kiln\MissingKilnContext;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Fixtures\KilnRecord;

beforeEach(function () {
    $this->kilnA = makeKiln(['name' => 'Kiln A']);
    $this->kilnB = makeKiln(['name' => 'Kiln B']);
    $this->context = app(CurrentKiln::class);
});

it('refuses to read kiln data when no kiln is selected', function () {
    $this->context->forget();

    KilnRecord::query()->get();
})->throws(MissingKilnContext::class);

it('refuses to create kiln data when no kiln is selected', function () {
    $this->context->forget();

    KilnRecord::query()->create(['name' => 'orphan']);
})->throws(MissingKilnContext::class);

it('only ever returns records of the current kiln', function () {
    $this->context->run($this->kilnA, fn () => KilnRecord::query()->create(['name' => 'A1']));
    $this->context->run($this->kilnB, fn () => KilnRecord::query()->create(['name' => 'B1']));

    $this->context->set($this->kilnA);
    expect(KilnRecord::query()->pluck('name')->all())->toBe(['A1'])
        ->and(KilnRecord::query()->count())->toBe(1);

    $this->context->set($this->kilnB);
    expect(KilnRecord::query()->pluck('name')->all())->toBe(['B1']);
});

it('cannot find another kiln\'s record by id', function () {
    $record = $this->context->run($this->kilnB, fn () => KilnRecord::query()->create(['name' => 'B1']));

    $this->context->set($this->kilnA);

    expect(KilnRecord::query()->find($record->id))->toBeNull();
    KilnRecord::query()->findOrFail($record->id);
})->throws(ModelNotFoundException::class);

it('takes kiln_id from the current kiln, never from input', function () {
    $this->context->set($this->kilnA);

    $record = KilnRecord::query()->create(['name' => 'A1']);

    expect($record->kiln_id)->toBe($this->kilnA->id);
});

it('refuses to create a record for a different kiln', function () {
    $this->context->set($this->kilnA);

    KilnRecord::query()->create(['name' => 'sneaky', 'kiln_id' => $this->kilnB->id]);
})->throws(MissingKilnContext::class);

it('refuses to update or move a record of another kiln', function () {
    $record = $this->context->run($this->kilnB, fn () => KilnRecord::query()->create(['name' => 'B1']));
    $loaded = KilnRecord::withoutGlobalScopes()->findOrFail($record->id);

    $this->context->set($this->kilnA);
    expect(fn () => $loaded->update(['name' => 'changed']))->toThrow(MissingKilnContext::class);

    $this->context->set($this->kilnB);
    expect(fn () => $loaded->update(['kiln_id' => $this->kilnA->id]))->toThrow(MissingKilnContext::class);

    expect(KilnRecord::withoutGlobalScopes()->findOrFail($record->id)->name)->toBe('B1');
});

it('refuses to delete a record of another kiln', function () {
    $record = $this->context->run($this->kilnB, fn () => KilnRecord::query()->create(['name' => 'B1']));
    $loaded = KilnRecord::withoutGlobalScopes()->findOrFail($record->id);

    $this->context->set($this->kilnA);

    expect(fn () => $loaded->delete())->toThrow(MissingKilnContext::class)
        ->and(KilnRecord::withoutGlobalScopes()->whereKey($record->id)->exists())->toBeTrue();
});

it('restores the previous kiln after running a callback in another kiln', function () {
    $this->context->set($this->kilnA);

    $this->context->run($this->kilnB, fn () => expect($this->context->id())->toBe($this->kilnB->id));
    expect($this->context->id())->toBe($this->kilnA->id);

    $this->context->forget();
    $this->context->run($this->kilnB, fn () => null);
    expect($this->context->has())->toBeFalse();
});

it('restores the previous kiln even when the callback fails', function () {
    $this->context->set($this->kilnA);

    try {
        $this->context->run($this->kilnB, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect($this->context->id())->toBe($this->kilnA->id);
});

describe('money columns', function () {
    it('stores Money as paisa and reads it back exactly', function () {
        $this->context->set($this->kilnA);

        $record = KilnRecord::query()->create(['name' => 'x', 'amount' => Money::fromRupees('13,800.55')]);

        expect($record->fresh()->amount)->toBeInstanceOf(Money::class)
            ->and($record->fresh()->amount->paisa)->toBe(1380055)
            ->and(DB::table('test_kiln_records')->where('id', $record->id)->value('amount'))->toBe(1380055);
    });

    it('allows null amounts', function () {
        $this->context->set($this->kilnA);

        expect(KilnRecord::query()->create(['name' => 'x', 'amount' => null])->fresh()->amount)->toBeNull();
    });

    it('rejects plain numbers so rupees and paisa can never be mixed up', function () {
        $this->context->set($this->kilnA);

        KilnRecord::query()->create(['name' => 'x', 'amount' => 1380]);
    })->throws(InvalidArgumentException::class);
});
