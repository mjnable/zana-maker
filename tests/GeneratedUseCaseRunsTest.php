<?php

declare(strict_types=1);

namespace Zana\Maker\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Core\Application;
use Zana\Core\ApplicationInterface;
use Zana\Database\DatabaseServiceProvider;
use Zana\Ddd\Domain\AggregateRoot;
use Zana\Ddd\Domain\DomainException;
use Zana\Ddd\Domain\RepositoryInterface;
use Zana\Http\HttpServiceProvider;
use Zana\Maker\Generator;
use Zana\Mediator\MediatorServiceProvider;
use Zana\Routing\RoutingServiceProvider;
use Zana\Serialization\SerializationServiceProvider;
use Zana\Testing\ConsoleTester;
use Zana\Testing\HttpTestClient;
use Zana\Validation\ValidationServiceProvider;

/**
 * FE-47's acceptance: whole use cases generated into an empty application
 * pass `check` (wiring, architecture rules, routes, migrations), answer their
 * routes, persist through the generated repository, and the tests generated
 * with them pass. The same input always generates the same code.
 */
final class GeneratedUseCaseRunsTest extends TestCase
{
    private string $base = '';

    /** @var \Closure(string): void|null */
    private ?\Closure $autoloader = null;

    #[Before]
    public function createApplication(): void
    {
        $this->base = self::folder();
        $this->autoloader = self::autoloader($this->base);
        spl_autoload_register($this->autoloader);
    }

    #[After]
    public function removeApplication(): void
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
        }
        self::remove($this->base);
    }

    public function testGeneratedUseCasesPassCheckAndAnswerTheirRoutes(): void
    {
        self::generateLibrary($this->base);
        $app = self::application($this->base);
        $console = new ConsoleTester($app->container()->get(ConsoleKernel::class));

        $this->assertSame(0, $console->run('migrate')->exitCode());
        $check = $console->run('check --format=json');
        $report = json_decode($check->output(), true);
        $this->assertIsArray($report, $check->output());
        $this->assertSame([], $report['findings'], $check->output());
        $this->assertSame('ok', $report['status']);
        $this->assertContains('Architecture: 1 module(s), no violation', $report['passed']);
        $this->assertSame(0, $check->exitCode());

        $http = new HttpTestClient($app->container()->get(\Zana\Http\HttpKernel::class));
        $http->post('/api/library/add-book', ['title' => 'Dune', 'pages' => 412])->assertNoContent();
        $errors = $http->post('/api/library/add-book', ['pages' => 'many'])->assertUnprocessable()->jsonPath('errors');
        $this->assertIsArray($errors);
        $this->assertEqualsCanonicalizing(['pages', 'title'], array_keys($errors), 'a mistyped and a missing field');
        $http->post('/api/library/add-book', ['title' => ''])->assertUnprocessable()->assertJsonPath('errors.title', ['This value should not be blank.']);
        $http->post('/api/library/add-book', '[1, 2]')->assertStatus(400);
        $http->get('/api/library/list-books?author=Herbert')->assertOk()->assertJsonPath('data', []);

        // The generated repository, on the migrated table.
        $repository = $app->container()->createScope()->get('Modules\Library\Domain\BookRepositoryInterface');
        $this->assertInstanceOf(RepositoryInterface::class, $repository);
        $book = self::create('Modules\Library\Domain\Book');
        $repository->add($book);

        $stored = $repository->get($book->getId());
        $this->assertTrue($stored->equals($book));
        $this->assertSame(1, $stored->getVersion());

        $stale = $repository->get($book->getId());
        $repository->add($stored);
        $this->assertSame(2, $repository->get($book->getId())->getVersion(), 'a stored entity is updated');

        try {
            $repository->add($stale);
            $this->fail('A change to an outdated copy must be refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('was changed in the meantime', $e->getMessage());
        }

        $app->terminate();
    }

    public function testTheGeneratedTestsPass(): void
    {
        self::generateLibrary($this->base);
        file_put_contents("{$this->base}/phpunit.xml", '<phpunit bootstrap="bootstrap.php" cacheResult="false"><testsuites><testsuite name="modules"><directory suffix="Test.php">modules</directory></testsuite></testsuites></phpunit>');
        file_put_contents("{$this->base}/bootstrap.php", "<?php\nrequire " . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ";\nrequire __DIR__ . '/autoload.php';\n");
        file_put_contents("{$this->base}/autoload.php", "<?php\nspl_autoload_register(static function (string \$class): void {\n    foreach (['App\\\\' => '/src/', 'Modules\\\\' => '/modules/'] as \$prefix => \$folder) {\n        if (str_starts_with(\$class, \$prefix) && is_file(\$file = __DIR__ . \$folder . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php')) {\n            require \$file;\n        }\n    }\n});\n");

        exec(escapeshellarg(PHP_BINARY) . ' -d xdebug.mode=off ' . escapeshellarg(dirname(__DIR__) . '/vendor/phpunit/phpunit/phpunit') . ' -c ' . escapeshellarg("{$this->base}/phpunit.xml") . ' --no-progress 2>&1', $output, $code);

        $output = implode("\n", $output);
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/Tests: 7, Assertions: \d+, Incomplete: 2\./', $output, 'the API, value object and handler tests run; the handler tests wait for their logic');
    }

    public function testTheSameInputGeneratesTheSameCode(): void
    {
        $other = self::folder();
        try {
            self::generateLibrary($this->base);
            self::generateLibrary($other);

            $this->assertSame(self::files($this->base), self::files($other));
        } finally {
            self::remove($other);
        }
    }

    /**
     * A library module made of every generator of FE-47, and the application's Bootstrap.
     */
    private static function generateLibrary(string $base): void
    {
        $generator = new Generator($base);
        $generator->write($generator->planModule('Library'));
        $generator->write($generator->planValueObject('Library', 'BookTitle'));
        $generator->write($generator->planEntity('Library', 'Book'));
        $generator->write($generator->planRepository('Library', 'Book'));
        $generator->write($generator->planFeature('Library', 'AddBook', fields: ['title:string', 'pages:?int'], http: true));
        $generator->write($generator->planFeature('Library', 'ListBooks', query: true, fields: ['author:?string'], http: true));
        if (!is_dir("{$base}/src")) {
            mkdir("{$base}/src");
        }
        file_put_contents("{$base}/src/Bootstrap.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            use Zana\Console\ConsoleServiceProvider;
            use Zana\Core\Application;
            use Zana\Core\ApplicationBuilder;
            use Zana\Database\DatabaseServiceProvider;
            use Zana\Http\HttpServiceProvider;
            use Zana\Mediator\MediatorServiceProvider;
            use Zana\Routing\RoutingServiceProvider;
            use Zana\Serialization\SerializationServiceProvider;
            use Zana\Validation\ValidationServiceProvider;

            final class Bootstrap
            {
                public static function builder(string $basePath): ApplicationBuilder
                {
                    return Application::configure($basePath)
                        ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class, MediatorServiceProvider::class, SerializationServiceProvider::class, ValidationServiceProvider::class, DatabaseServiceProvider::class, ConsoleServiceProvider::class)
                        ->withConfiguration(['database.connections.main' => ['driver' => 'sqlite', 'database' => ':memory:']]);
                }
            }
            PHP);
    }

    private static function application(string $base): ApplicationInterface
    {
        return Application::configure($base)
            ->withEnvironment('testing')
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class, MediatorServiceProvider::class, SerializationServiceProvider::class, ValidationServiceProvider::class, DatabaseServiceProvider::class, ConsoleServiceProvider::class)
            ->withConfiguration(['database.connections.main' => ['driver' => 'sqlite', 'database' => ':memory:']])
            ->build();
    }

    /**
     * @return array<string, string> relative path (migration timestamps removed) => contents
     */
    private static function files(string $base): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$base}/modules", \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($base) + 1);
            $files[(string) preg_replace('/\d{4}_\d{2}_\d{2}_\d{6}_/', '', $relative)] = (string) file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }

    /**
     * A new aggregate of a class generated during the test (unknown to static analysis).
     */
    private static function create(string $class): AggregateRoot
    {
        $callable = [$class, 'create'];
        self::assertTrue(is_callable($callable), "{$class}::create() was not generated.");
        $result = $callable();
        self::assertInstanceOf(AggregateRoot::class, $result);

        return $result;
    }

    private static function folder(): string
    {
        $base = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-maker-usecase-' . bin2hex(random_bytes(4));
        mkdir("{$base}/var", 0777, true);

        return $base;
    }

    /**
     * @return \Closure(string): void
     */
    private static function autoloader(string $base): \Closure
    {
        return static function (string $class) use ($base): void {
            if (str_starts_with($class, 'Modules\\')) {
                $file = $base . '/modules/' . str_replace('\\', '/', substr($class, strlen('Modules\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
    }

    private static function remove(string $base): void
    {
        exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' . escapeshellarg(str_replace('/', '\\', $base)) : 'rm -rf ' . escapeshellarg($base));
    }
}
