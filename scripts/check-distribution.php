<?php
declare(strict_types=1);

$file = $argv[1] ?? throw new RuntimeException('Pass a Composer ZIP archive.');
$zip = new ZipArchive();
if ($zip->open($file) !== true) { throw new RuntimeException('Cannot open package archive.'); }
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = (string) $zip->getNameIndex($index);
    if (preg_match('#^(vendor|node_modules|tests|examples|scripts|\.ci)/#', $name) === 1 || str_contains($name, '/cache/')) {
        throw new RuntimeException('Development artifact included in archive: ' . $name);
    }
}
foreach (['src/Kernel.php', 'src/Messages.php', 'resources/lang/en.php', 'resources/lang/ar.php', 'resources/lang/ku.php', 'dist/manifest.json', 'LICENSE', 'THIRD_PARTY_NOTICES.md'] as $required) {
    if ($zip->locateName($required) === false) { throw new RuntimeException('Missing package file: ' . $required); }
}
$manifest = json_decode((string) $zip->getFromName('dist/manifest.json'), true, 16, JSON_THROW_ON_ERROR);
$script = $manifest['resources/js/reactive.ts']['file'] ?? '';
$style = $manifest['style.css']['file'] ?? '';
if (!is_string($script) || preg_match('/^reactive-v\d+\.\d+\.\d+\.js$/D', $script) !== 1
    || $style !== substr($script, 0, -3) . '.css'
    || $zip->locateName('dist/' . $script) === false || $zip->locateName('dist/' . $style) === false) {
    throw new RuntimeException('Missing matching release-versioned JS/CSS assets in the manifest or archive.');
}
echo 'Distribution contains PHP, compiled assets and licenses without development dependencies.', PHP_EOL;
$zip->close();
