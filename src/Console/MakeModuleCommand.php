<?php

declare(strict_types=1);

namespace Zana\Maker\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Maker\Generator;

#[AsCommand(name: 'make:module', description: 'Create a module and enable it in config/modules.php')]
final class MakeModuleCommand extends MakerCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::REQUIRED, 'The module name, e.g. Patients');
    }

    public function handle(Generator $generator, InputInterface $input, SymfonyStyle $io): int
    {
        return $this->generate($generator, static fn (): array => $generator->planModule((string) $input->getArgument('name')), $input, $io);
    }
}
