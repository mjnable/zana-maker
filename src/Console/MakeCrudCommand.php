<?php

declare(strict_types=1);

namespace Zana\Maker\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Configuration\ConfigurationInterface;
use Zana\Maker\Generator;

/**
 * make:crud Blog Post --translatable
 *
 * The screens need mj-zana/view, session, security (with a
 * PasswordUserProviderInterface) and database. Editors need the
 * "<items>.edit" permission, e.g. "posts.edit".
 */
#[AsCommand(name: 'make:crud', description: 'Create back-office screens for a content type: list, edit, publish, archive, delete and preview')]
final class MakeCrudCommand extends MakerCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'The module, e.g. Blog')
            ->addArgument('name', InputArgument::REQUIRED, 'The content type, singular, e.g. Post')
            ->addOption('translatable', null, InputOption::VALUE_NONE, 'Edit the text in every locale of translation.locales, not only the first');
    }

    public function handle(Generator $generator, InputInterface $input, SymfonyStyle $io, ConfigurationInterface $config): int
    {
        /** @var list<string> $locales */
        $locales = $config->array('translation.locales', ['en']);
        $locales = (bool) $input->getOption('translatable') ? $locales : array_slice($locales, 0, 1);

        return $this->generate($generator, static fn (): array => $generator->planCrud((string) $input->getArgument('module'), (string) $input->getArgument('name'), $locales), $input, $io);
    }
}
