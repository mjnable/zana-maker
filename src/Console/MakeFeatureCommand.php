<?php

declare(strict_types=1);

namespace Zana\Maker\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Maker\Generator;

#[AsCommand(name: 'make:feature', description: 'Create a command (or query), its handler and a handler test')]
final class MakeFeatureCommand extends MakerCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'The module, e.g. Patients')
            ->addArgument('name', InputArgument::REQUIRED, 'The feature, e.g. RegisterPatient')
            ->addOption('query', null, InputOption::VALUE_NONE, 'Create a query instead of a command');
    }

    public function handle(Generator $generator, InputInterface $input, SymfonyStyle $io): int
    {
        return $this->generate($generator, static fn (): array => $generator->planFeature(
            (string) $input->getArgument('module'),
            (string) $input->getArgument('name'),
            (bool) $input->getOption('query'),
        ), $input, $io);
    }
}
