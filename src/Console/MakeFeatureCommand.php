<?php

declare(strict_types=1);

namespace Zana\Maker\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Maker\Generator;

#[AsCommand(name: 'make:feature', description: 'Create a command (or query), its handler and tests; with --http, its API action and route')]
final class MakeFeatureCommand extends MakerCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'The module, e.g. Patients')
            ->addArgument('name', InputArgument::REQUIRED, 'The feature, e.g. RegisterPatient')
            ->addOption('query', null, InputOption::VALUE_NONE, 'Create a query instead of a command')
            ->addOption('field', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A field, name:type (string, int, float, bool; ?type when optional), e.g. --field=name:string')
            ->addOption('http', null, InputOption::VALUE_NONE, 'Add the API action, its route and an HTTP test')
            ->addOption('public', null, InputOption::VALUE_REQUIRED, 'With --http: why anyone may call the action, e.g. --public="The public catalogue"; it needs a signed-in user otherwise');
    }

    public function handle(Generator $generator, InputInterface $input, SymfonyStyle $io): int
    {
        /** @var list<string> $fields */
        $fields = $input->getOption('field');
        $public = $input->getOption('public');

        return $this->generate($generator, static fn (): array => $generator->planFeature(
            (string) $input->getArgument('module'),
            (string) $input->getArgument('name'),
            (bool) $input->getOption('query'),
            $fields,
            (bool) $input->getOption('http'),
            is_string($public) ? $public : null,
        ), $input, $io);
    }
}
