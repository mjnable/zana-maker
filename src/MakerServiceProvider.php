<?php

declare(strict_types=1);

namespace Zana\Maker;

use Zana\Console\ConsoleKernel;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\ApplicationInterface;
use Zana\Core\ServiceProvider;
use Zana\Maker\Console\MakeControllerCommand;
use Zana\Maker\Console\MakeCrudCommand;
use Zana\Maker\Console\MakePageCommand;
use Zana\Maker\Console\MakeEntityCommand;
use Zana\Maker\Console\MakeFeatureCommand;
use Zana\Maker\Console\MakeModuleCommand;

/**
 * Registers the Generator (for the application's base path) and the make: commands.
 */
final class MakerServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services->singleton(Generator::class, static fn (ServiceResolverInterface $r): Generator => new Generator($r->get(ApplicationInterface::class)->basePath()));

        foreach ([MakeModuleCommand::class, MakeFeatureCommand::class, MakeEntityCommand::class, MakeControllerCommand::class, MakePageCommand::class, MakeCrudCommand::class] as $command) {
            $services->singleton($command)->tag(ConsoleKernel::TAG, $command);
        }
    }

    public function origin(): string
    {
        return 'package:mj-zana/maker';
    }
}
