<?php

declare(strict_types=1);

/**
 * KaririCode Devkit project-level overrides.
 *
 * The devkit excludes `src/Contract` from static analysis by default. This
 * package has no such directory, and PHPStan rejects an exclude path that does
 * not exist, so nothing is excluded here.
 */
return [
    'exclude_dirs' => [],
];
