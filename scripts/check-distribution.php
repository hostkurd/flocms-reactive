<?php
declare(strict_types=1);

$file = $argv[1] ?? throw new RuntimeException('Pass a Composer ZIP archive.');
$zip = new ZipArchive();
if ($zip->open($file) !== true) { throw new RuntimeException('Cannot open package archive.'); }
$assets = false;
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = (string) $zip->getNameIndex($index);
    if (preg_match('#^(vendor|node_modules|tests|examples|scripts|\.ci)/#', $name) === 1 || str_contains($name, '/cache/')) {
        throw new RuntimeException('Development artifact included in archive: ' . $name);
    }
    $assets = $assets || preg_match('#^dist/reactive-[A-Za-z0-9_-]+\.js$#', $name) === 1;
}
foreach (['src/Kernel.php', 'dist/manifest.json', 'LICENSE', 'THIRD_PARTY_NOTICES.md'] as $required) {
    if ($zip->locateName($required) === false) { throw new RuntimeException('Missing package file: ' . $required); }
}
if (!$assets) { throw new RuntimeException('Missing compiled browser bundle.'); }
echo 'Distribution contains PHP, compiled assets and licenses without development dependencies.', PHP_EOL;
$zip->close();
