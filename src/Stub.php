<?php

declare(strict_types=1);

namespace Zana\Maker;

/**
 * A file template with {{ placeholder }} markers. The built-in stubs live in
 * this package's stubs/ folder; <basePath>/stubs/<name>.stub overrides one.
 */
final readonly class Stub
{
    private const string PLACEHOLDER = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    public function __construct(
        public string $name,
        public string $contents,
    ) {
    }

    public static function load(string $name, string $basePath): self
    {
        foreach ([$basePath . "/stubs/{$name}.stub", dirname(__DIR__) . "/stubs/{$name}.stub"] as $file) {
            if (is_file($file)) {
                return new self($name, (string) file_get_contents($file));
            }
        }

        throw new MakerException("There is no stub named \"{$name}\".");
    }

    /**
     * @param array<string, string> $values
     *
     * @throws MakerException When a placeholder has no value
     */
    public function render(array $values): string
    {
        $missing = [];
        $output = preg_replace_callback(self::PLACEHOLDER, static function (array $match) use ($values, &$missing): string {
            if (!array_key_exists($match[1], $values)) {
                $missing[] = $match[1];

                return $match[0];
            }

            return $values[$match[1]];
        }, $this->contents);

        if ($missing !== []) {
            throw new MakerException(sprintf('The stub "%s" uses unknown placeholders: %s.', $this->name, implode(', ', array_unique($missing))));
        }

        return (string) $output;
    }
}
