<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/lib', __DIR__ . '/test', __DIR__ . '/examples'])
    ->append([__DIR__ . '/ActiveRecord.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS3x0' => true,
        '@PHP8x3Migration' => true,
    ])
    ->setFinder($finder);
