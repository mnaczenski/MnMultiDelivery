<?php declare(strict_types=1);

/*
 * Checks that the administration internals this plugin overrides still exist
 * in a Shopware version: for every component in admin-overrides.json, the
 * component's directory, the methods and computed properties the override
 * calls with $super(), and the Twig blocks it extends. A renamed method or
 * block makes an override silently do nothing; this check makes it fail.
 *
 *   php tests/ci/check-admin-overrides.php <administration src directory>
 *
 * The directory is vendor/shopware/administration/Resources/app/administration/src
 * in a shop, src/Administration/Resources/app/administration/src in the
 * Shopware repository.
 */

$adminSrc = rtrim($argv[1] ?? '', '/');
if (!is_dir($adminSrc)) {
    fwrite(STDERR, "Administration sources not found: {$adminSrc}\n");
    exit(2);
}

/** @var array<string, array{members?: list<string>, blocks?: list<string>}> $overrides */
$overrides = json_decode((string) file_get_contents(__DIR__ . '/admin-overrides.json'), true, 512, JSON_THROW_ON_ERROR);

$directories = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($adminSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($iterator as $file) {
    if ($file->isDir() && isset($overrides[$file->getFilename()])) {
        $directories[$file->getFilename()][] = $file->getPathname();
    }
}

$failures = [];
foreach ($overrides as $component => $needs) {
    $code = '';
    $twig = '';
    foreach ($directories[$component] ?? [] as $directory) {
        foreach (glob($directory . '/*.{js,ts}', GLOB_BRACE) ?: [] as $file) {
            $code .= file_get_contents($file);
        }
        foreach (glob($directory . '/*.twig') ?: [] as $file) {
            $twig .= file_get_contents($file);
        }
    }
    if ($code === '') {
        $failures[] = "{$component}: component not found";
        continue;
    }
    foreach ($needs['members'] ?? [] as $member) {
        if (!preg_match('/(^|[\s,{])(async\s+)?' . preg_quote($member, '/') . '\s*(\(|:)/m', $code)) {
            $failures[] = "{$component}: method or computed property {$member} not found";
        }
    }
    foreach ($needs['blocks'] ?? [] as $block) {
        if (!preg_match('/{%\s*block\s+' . preg_quote($block, '/') . '\s*%}/', $twig)) {
            $failures[] = "{$component}: Twig block {$block} not found";
        }
    }
}

foreach ($failures as $failure) {
    echo "FAIL {$failure}\n";
}
printf("%d components checked, %d problems\n", count($overrides), count($failures));
exit($failures === [] ? 0 : 1);
