<?php

declare(strict_types=1);

namespace Zana\Maker\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Zana\Core\Application;
use Zana\Http\HttpKernel;
use Zana\Http\HttpServiceProvider;
use Zana\Maker\Generator;
use Zana\Mediator\MediatorInterface;
use Zana\Mediator\MediatorServiceProvider;
use Zana\Routing\RoutingServiceProvider;

/**
 * The end-to-end proof: what make:module, make:feature and make:controller
 * generate is wired and works in a real application, with no hand edits.
 */
final class GeneratedModuleRunsTest extends TestCase
{
    private string $base = '';

    /** @var \Closure(string): void|null */
    private ?\Closure $autoloader = null;

    protected function setUp(): void
    {
        $this->base = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-maker-run-' . bin2hex(random_bytes(4));
        mkdir($this->base);

        $base = $this->base;
        $this->autoloader = static function (string $class) use ($base): void {
            if (str_starts_with($class, 'Modules\\')) {
                $file = $base . '/modules/' . str_replace('\\', '/', substr($class, strlen('Modules\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoloader);
    }

    #[After]
    public function cleanUp(): void
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
        }
        exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' . escapeshellarg(str_replace('/', '\\', $this->base)) : 'rm -rf ' . escapeshellarg($this->base));
    }

    public function testAGeneratedModuleWorksWithoutHandEdits(): void
    {
        $generator = new Generator($this->base);
        $generator->write($generator->planModule('Clinics'));
        $generator->write($generator->planEntity('Clinics', 'Clinic'));
        $generator->write($generator->planFeature('Clinics', 'ListClinics', query: true));
        $generator->write($generator->planFeature('Clinics', 'OpenClinic'));
        $generator->write($generator->planController('Clinics', 'Clinics'));

        $app = Application::configure($this->base)
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class, MediatorServiceProvider::class)
            ->build();
        $container = $app->container();

        $this->assertSame(['Clinics'], array_map(static fn ($module): string => $module->name(), $app->modules()), 'enabled through config/modules.php');

        $mediator = $container->createScope()->get(MediatorInterface::class);
        $this->assertSame([], $mediator->send(self::generated('Modules\Clinics\Application\Features\ListClinics\ListClinicsQuery')), 'the query handler is registered and mapped');
        $this->assertNull($mediator->send(self::generated('Modules\Clinics\Application\Features\OpenClinic\OpenClinicCommand')), 'so is the command handler');

        $kernel = $container->get(HttpKernel::class);
        $index = $kernel->handle(new ServerRequest('GET', '/api/clinics', ['Accept' => 'application/json']));
        $this->assertSame(200, $index->getStatusCode(), (string) $index->getBody());
        $this->assertSame('{"data":[]}', (string) $index->getBody());

        $show = $kernel->handle(new ServerRequest('GET', '/api/clinics/42', ['Accept' => 'application/json']));
        $this->assertSame('{"data":{"id":"42"}}', (string) $show->getBody(), 'the controller routes are loaded');

        $app->terminate();
    }

    /**
     * An instance of a class generated during the test (unknown to static analysis).
     */
    private static function generated(string $class): object
    {
        if (!class_exists($class)) {
            self::fail("{$class} was not generated.");
        }

        return new $class();
    }
}
