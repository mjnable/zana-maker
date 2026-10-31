<?php

declare(strict_types=1);

namespace Zana\Maker\Console;

use Composer\InstalledVersions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Maker\Generator;
use Zana\Maker\MakerException;

/**
 * make:agents [--zana-version=2.7] [--force]
 *
 * Writes AGENTS.md, the instructions for AI coding agents, with CLAUDE.md
 * and .github/copilot-instructions.md pointing to it (FE-44). The version
 * defaults to the installed mj-zana/core's, so the links lead to the
 * matching documentation. Run it again with --force after upgrading Zana.
 */
#[AsCommand(name: 'make:agents', description: 'Write AGENTS.md, the instructions for AI coding agents, for this Zana version')]
final class MakeAgentsCommand extends MakerCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('zana-version', null, InputOption::VALUE_REQUIRED, 'The Zana minor version, e.g. 2.7 (default: the installed one)');
    }

    public function handle(Generator $generator, InputInterface $input, SymfonyStyle $io): int
    {
        $given = $input->getOption('zana-version');
        $version = is_string($given) ? $given : self::installedVersion();

        return $this->generate($generator, static fn (): array => $generator->planAgents($version), $input, $io);
    }

    /**
     * "2.7" from the installed mj-zana/core ("v2.7.0", "2.7.0", "2.7.x-dev").
     */
    private static function installedVersion(): string
    {
        $version = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('mj-zana/core')
            ? (string) InstalledVersions::getPrettyVersion('mj-zana/core')
            : '';
        if (preg_match('/^v?(\d+)\.(\d+)/', $version, $match) !== 1) {
            throw new MakerException("The installed Zana version (\"{$version}\") is not a release; pass --zana-version, e.g. --zana-version=2.7.");
        }

        return "{$match[1]}.{$match[2]}";
    }
}
