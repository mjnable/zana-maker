<?php

declare(strict_types=1);

namespace Zana\Maker\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Maker\Generator;

#[AsCommand(name: 'make:controller', description: 'Create an API controller, or with --web an HTML controller and its template')]
final class MakeControllerCommand extends MakerCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'The module, e.g. Patients')
            ->addArgument('name', InputArgument::REQUIRED, 'The controller, without the Controller suffix, e.g. Measurements')
            ->addOption('web', null, InputOption::VALUE_NONE, 'A Presentation/Web controller returning a View, with its template');
    }

    public function handle(Generator $generator, InputInterface $input, SymfonyStyle $io): int
    {
        return $this->generate($generator, static fn (): array => $generator->planController((string) $input->getArgument('module'), (string) $input->getArgument('name'), (bool) $input->getOption('web')), $input, $io);
    }
}
