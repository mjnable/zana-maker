<?php

declare(strict_types=1);

namespace Zana\Maker\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Maker\Generator;
use Zana\Maker\MakerException;
use Zana\Maker\Plan;
use Zana\Maker\PlanRunner;

/**
 * make:plan <file.json> [--dry-run] [--force] [--format=json]
 *
 * Generates a whole feature from a plan file (FE-51, ADR-026): a module, its
 * entities, value objects, repositories and features, by running the make:
 * commands the plan lists, in a temporary copy first. Nothing is written
 * unless every step succeeds. The JSON report is zana.make/1 with the
 * equivalent "commands" and the "steps" one by one.
 */
#[AsCommand(name: 'make:plan', description: 'Generate a module, its entities, value objects, repositories and features from a plan file')]
final class MakePlanCommand extends MakerCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('file', InputArgument::REQUIRED, 'The plan, a JSON file ("schema": "zana.plan/1")');
    }

    public function handle(Generator $generator, InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $format = $input->getOption('format');
        $json = $format === 'json';
        $dryRun = (bool) $input->getOption('dry-run');
        $file = (string) $input->getArgument('file');
        $path = is_file($file) ? $file : $generator->basePath() . '/' . $file;

        try {
            if ($format !== 'text' && !$json) {
                throw new MakerException('The format must be text or json.');
            }
            if (!is_file($path)) {
                throw new MakerException("There is no plan file {$file}.");
            }
            $steps = (new PlanRunner($generator->basePath()))->run(Plan::fromJson((string) file_get_contents($path)), $dryRun, (bool) $input->getOption('force'));
        } catch (MakerException $e) {
            if (!$json) {
                $io->error($e->getMessage());

                return self::FAILURE;
            }
            $output->writeln((string) json_encode(['schema' => self::SCHEMA, 'status' => 'error', 'dry_run' => $dryRun, 'commands' => [], 'steps' => [], 'files' => [], 'wiring' => [], 'manual_steps' => [], 'error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }

        $manualSteps = array_values(array_unique(array_merge(...array_map(static fn (array $step): array => $step['manual_steps'], $steps))));
        if ($json) {
            $output->writeln((string) json_encode([
                'schema' => self::SCHEMA,
                'status' => 'ok',
                'dry_run' => $dryRun,
                'commands' => array_column($steps, 'command'),
                'steps' => $steps,
                'files' => array_merge(...array_column($steps, 'files')),
                'wiring' => array_merge(...array_column($steps, 'wiring')),
                'manual_steps' => $manualSteps,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        foreach ($steps as $step) {
            $io->section($step['command']);
            $io->listing(array_map(static fn (array $file): string => ($dryRun ? "would {$file['action']} " : "{$file['action']}d ") . $file['path'], $step['files']));
        }
        $dryRun ? $io->writeln('Dry run; nothing was written.') : $io->success(sprintf('Ran %d step%s of the plan.', count($steps), count($steps) === 1 ? '' : 's'));
        if ($manualSteps !== []) {
            $io->warning('Some steps are left to do by hand:');
            $io->listing($manualSteps);
        }

        return self::SUCCESS;
    }
}
