<?php

declare(strict_types=1);

namespace Zana\Maker;

/**
 * A file the generator plans to write. An update replaces a file that is
 * meant to change (config/modules.php); a new file never silently replaces
 * an existing one.
 */
final readonly class GeneratedFile
{
    public function __construct(
        public string $path,
        public string $relativePath,
        public string $contents,
        public bool $update = false,
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * A new file whose path is already taken.
     */
    public function conflicts(): bool
    {
        return !$this->update && $this->exists();
    }
}
