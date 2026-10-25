<?php

declare(strict_types=1);

namespace Zana\Maker\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Maker\GeneratedFile;
use Zana\Maker\Generator;
use Zana\Maker\MakerException;

/**
 * The --force and --dry-run options and the reporting shared by the make: commands.
 *
 * @internal
 */
abstract class MakerCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace files that already exist')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the files without writing them');
    }

    /**
     * @param \Closure(): list<GeneratedFile> $plan
     */
    protected function generate(Generator $generator, \Closure $plan, InputInterface $input, SymfonyStyle $io): int
    {
        try {
            $files = $plan();
            $actions = array_map(static fn (GeneratedFile $file): array => [match (true) {
                $file->update => 'update',
                $file->exists() => 'replace',
                default => 'create',
            }, $file->relativePath], $files);

            if ((bool) $input->getOption('dry-run')) {
                $io->writeln('Dry run; nothing was written.');
                $io->listing(array_map(static fn (array $action): string => "would {$action[0]} {$action[1]}", $actions));
                self::manualSteps($generator, $io);

                return self::SUCCESS;
            }

            $generator->write($files, (bool) $input->getOption('force'));
        } catch (MakerException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->listing(array_map(static fn (array $action): string => "{$action[0]}d {$action[1]}", $actions));
        $io->success(sprintf('Generated %d file%s.', count($files), count($files) === 1 ? '' : 's'));
        self::manualSteps($generator, $io);

        return self::SUCCESS;
    }

    private static function manualSteps(Generator $generator, SymfonyStyle $io): void
    {
        if ($generator->manualSteps() !== []) {
            $io->warning('The module class has no zana:maker markers, so wire the new classes yourself:');
            $io->listing($generator->manualSteps());
        }
    }
}
