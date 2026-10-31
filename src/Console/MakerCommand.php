<?php

declare(strict_types=1);

namespace Zana\Maker\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Maker\GeneratedFile;
use Zana\Maker\Generator;
use Zana\Maker\MakerException;

/**
 * The --force, --dry-run and --format options and the reporting shared by the
 * make: commands. With --format=json (FE-47), the report is
 * {"schema": "zana.make/1", "status", "dry_run", "files": [{action, path}],
 * "wiring": [{method, line}], "manual_steps": [...], "error"?}.
 *
 * @internal
 */
abstract class MakerCommand extends Command
{
    public const string SCHEMA = 'zana.make/1';

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace files that already exist')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the files without writing them')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    /**
     * @param \Closure(): list<GeneratedFile> $plan
     */
    protected function generate(Generator $generator, \Closure $plan, InputInterface $input, SymfonyStyle $io): int
    {
        $format = $input->getOption('format');
        if ($format !== 'text' && $format !== 'json') {
            $io->error('The format must be text or json.');

            return self::FAILURE;
        }
        $json = $format === 'json';
        $dryRun = (bool) $input->getOption('dry-run');
        $actions = [];

        try {
            $files = $plan();
            $actions = array_map(static fn (GeneratedFile $file): array => [match (true) {
                $file->update => 'update',
                $file->exists() => 'replace',
                default => 'create',
            }, $file->relativePath], $files);

            if (!$dryRun) {
                $generator->write($files, (bool) $input->getOption('force'));
            }
        } catch (MakerException $e) {
            if ($json) {
                return self::report($io, $generator, $dryRun, $actions, $e->getMessage());
            }
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        if ($json) {
            return self::report($io, $generator, $dryRun, $actions);
        }
        if ($dryRun) {
            $io->writeln('Dry run; nothing was written.');
            $io->listing(array_map(static fn (array $action): string => "would {$action[0]} {$action[1]}", $actions));
        } else {
            $io->listing(array_map(static fn (array $action): string => "{$action[0]}d {$action[1]}", $actions));
            $io->success(sprintf('Generated %d file%s.', count($files), count($files) === 1 ? '' : 's'));
        }
        if ($generator->manualSteps() !== []) {
            $io->warning('Some steps are left to do by hand:');
            $io->listing($generator->manualSteps());
        }

        return self::SUCCESS;
    }

    /**
     * @param list<array{string, string}> $actions
     */
    private static function report(SymfonyStyle $io, Generator $generator, bool $dryRun, array $actions, ?string $error = null): int
    {
        $report = [
            'schema' => self::SCHEMA,
            'status' => $error === null ? 'ok' : 'error',
            'dry_run' => $dryRun,
            'files' => array_map(static fn (array $action): array => ['action' => $action[0], 'path' => $action[1]], $error === null ? $actions : []),
            'wiring' => $error === null ? $generator->wiring() : [],
            'manual_steps' => $error === null ? $generator->manualSteps() : [],
        ];
        if ($error !== null) {
            $report['error'] = $error;
        }
        $io->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

        return $error === null ? self::SUCCESS : self::FAILURE;
    }
}
