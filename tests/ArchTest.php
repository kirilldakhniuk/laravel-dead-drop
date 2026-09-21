<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('DeadDrop\DeadDrop')
    ->toUseStrictTypes();

it('decides interactivity only through DetectsTerminal', function () {
    // Symfony's flag stays true with no terminal at all, and Laravel Prompts
    // answers an unaskable question with its own default — so a command that
    // consulted the flag directly would read that default back as consent.
    $offenders = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src')) as $file) {
        if ($file->getExtension() !== 'php' || $file->getFilename() === 'DetectsTerminal.php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), 'isInteractive()')) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
