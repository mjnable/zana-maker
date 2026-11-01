<?php

declare(strict_types=1);

namespace Zana\Maker\Inspection;

use Composer\InstalledVersions;
use Zana\Core\ApplicationInterface;
use Zana\Core\ConsoleScript;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;

/**
 * AGENTS.md exists and names the installed Zana minor version (FE-52,
 * ADR-026): an agent then follows the rules and reads the documentation of
 * this version. A warning, never an error: an application without agents is
 * not broken. The maker is a development dependency, so this runs where
 * agents work and in CI.
 */
final readonly class AgentsCheck implements ApplicationCheck
{
    public function findings(ApplicationInterface $application): array
    {
        $file = rtrim(str_replace('\\', '/', $application->basePath()), '/') . '/AGENTS.md';
        if (!is_file($file)) {
            return [new Finding('agents.instructions', Severity::Warning, 'There is no AGENTS.md, the instructions for AI coding agents.', hint: 'Write it with ' . ConsoleScript::command('make:agents') . '.')];
        }

        $version = self::installedMinor();
        if ($version !== null && !str_contains((string) file_get_contents($file), "This is a Zana {$version} application")) {
            return [new Finding(
                'agents.instructions',
                Severity::Warning,
                "AGENTS.md does not name the installed Zana {$version}, so agents read the rules and documentation of another version.",
                $file,
                hint: 'Write it again with ' . ConsoleScript::command('make:agents --force') . ', then add back any section of your own.',
            )];
        }

        return [];
    }

    private static function installedMinor(): ?string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('mj-zana/core')) {
            return null;
        }

        return preg_match('/^v?(\d+\.\d+)/', (string) InstalledVersions::getPrettyVersion('mj-zana/core'), $match) === 1 ? $match[1] : null;
    }
}
