<?php
declare(strict_types=1);

// Development/CI bridge until the generic response support is released as 2.3.2.
// The distributable composer.json always retains its real minimum requirement.
$path = $argv[1] ?? throw new RuntimeException('Pass a Core checkout path.');
$json = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
$json['repositories'] = [['type' => 'path', 'url' => $path, 'options' => ['symlink' => true, 'versions' => ['hostkurd/flocms-core' => '2.3.2']]]];
file_put_contents(__DIR__ . '/../composer.local.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
