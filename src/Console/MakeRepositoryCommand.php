<?php

declare(strict_types=1);

namespace Zana\Maker\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Maker\Generator;

#[AsCommand(name: 'make:repository', description: "Create the database repository of an entity, its migration and its registration")]
final class MakeRepositoryCommand extends MakerCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'The module, e.g. Patients')
            ->addArgument('entity', InputArgument::REQUIRED, 'The entity, made with make:entity, e.g. Patient');
    }

    public function handle(Generator $generator, InputInterface $input, SymfonyStyle $io): int
    {
        return $this->generate($generator, static fn (): array => $generator->planRepository((string) $input->getArgument('module'), (string) $input->getArgument('entity')), $input, $io);
    }
}
