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
use Zana\Maker\MakerException;

/**
 * make:page Site About --path=en:/about --path=fr:/a-propos
 *
 * Without --path, every locale of translation.locales gets /<page-name>.
 */
#[AsCommand(name: 'make:page', description: 'Create a localised page with its routes, metadata and template')]
final class MakePageCommand extends MakerCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'The module, e.g. Site')
            ->addArgument('name', InputArgument::REQUIRED, 'The page, e.g. About')
            ->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'locale:path, once per locale, e.g. fr:/a-propos');
    }

    public function handle(Generator $generator, InputInterface $input, SymfonyStyle $io, ConfigurationInterface $config): int
    {
        return $this->generate($generator, static function () use ($generator, $input, $config): array {
            $name = (string) $input->getArgument('name');
            $paths = [];
            /** @var list<string> $options */
            $options = $input->getOption('path');
            foreach ($options as $option) {
                if (preg_match('#^([A-Za-z-]+):(/.*)$#', $option, $m) !== 1) {
                    throw new MakerException("--path must be locale:/path, got \"{$option}\".");
                }
                $paths[$m[1]] = $m[2];
            }
            if ($paths === []) {
                /** @var list<string> $locales */
                $locales = $config->array('translation.locales', ['en']);
                $slug = '/' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
                $paths = array_fill_keys($locales, $slug);
            }

            return $generator->planPage((string) $input->getArgument('module'), $name, $paths);
        }, $input, $io);
    }
}
