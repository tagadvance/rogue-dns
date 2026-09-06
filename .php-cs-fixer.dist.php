<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__FILE__, __DIR__ . '/cloudflare.php']);

return new PhpCsFixer\Config()
    ->setRules([
        '@PER-CS' => true,
        '@PHP85Migration' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => false],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
    ])
    ->setFinder($finder);
