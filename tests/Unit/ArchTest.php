<?php

/*
 * Architecture rules that protect the whole codebase, now and in every future module.
 */

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('PHP best practices')->preset()->php()->ignoring(['App\Support\Money']);

arch('security preset')->preset()->security();

arch('enums are enums')->expect('App\Enums')->toBeEnums();

arch('policies only decide, they do not write')
    ->expect('App\Policies')
    ->not->toUse(['Illuminate\Support\Facades\DB']);

it('never stores money as float or double', function () {
    $offenders = [];

    foreach (glob(database_path('migrations/*.php')) as $migration) {
        if (preg_match('/->(float|double)\(/', file_get_contents($migration))) {
            $offenders[] = basename($migration);
        }
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Models'))) as $file) {
        if ($file->isFile() && preg_match("/=>\s*'(float|double|real)'/", file_get_contents($file->getPathname()))) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
