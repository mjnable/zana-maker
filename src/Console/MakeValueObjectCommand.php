<?php

declare(strict_types=1);

namespace Zana\Maker\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Maker\Generator;

#[AsCommand(name: 'make:value-object', description: 'Create a value object of the domain and its test')]
final class MakeValueObjectCommand extends MakerCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'The module, e.g. Patients')
            ->addArgument('name', InputArgument::REQUIRED, 'The value object, e.g. PatientName')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'The type of its value: string, int or float', 'string');
    }

    public function handle(Generator $generator, InputInterface $input, SymfonyStyle $io): int
    {
        return $this->generate($generator, static fn (): array => $generator->planValueObject(
            (string) $input->getArgument('module'),
            (string) $input->getArgument('name'),
            (string) $input->getOption('type'),
        ), $input, $io);
    }
}
