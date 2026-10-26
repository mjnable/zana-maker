<?php

declare(strict_types=1);

namespace Zana\Maker\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Zana\Cache\CacheServiceProvider;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Database\DatabaseServiceProvider;
use Zana\Database\Migration\Migrator;
use Zana\Http\HttpKernel;
use Zana\Http\HttpServiceProvider;
use Zana\Maker\Generator;
use Zana\Routing\Router;
use Zana\Routing\RoutingServiceProvider;
use Zana\Security\PasswordHasher;
use Zana\Security\PasswordUserProviderInterface;
use Zana\Security\SecurityServiceProvider;
use Zana\Security\Session\SessionLogin;
use Zana\Security\UserInterface;
use Zana\Security\UserProviderInterface;
use Zana\Session\SessionMiddleware;
use Zana\Session\SessionServiceProvider;
use Zana\View\ViewServiceProvider;

/**
 * The end-to-end proof of make:crud: the generated back office runs with no
 * hand edits: sign in, create a post in two languages, preview, publish,
 * and delete after confirming.
 */
final class GeneratedBackOfficeRunsTest extends TestCase
{
    private string $base = '';

    /** @var \Closure(string): void|null */
    private ?\Closure $autoloader = null;

    private HttpKernel $kernel;

    private string $cookie = '';

    protected function setUp(): void
    {
        $this->base = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-maker-crud-' . bin2hex(random_bytes(4));
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

    public function testTheGeneratedBackOfficeWorksWithoutHandEdits(): void
    {
        $generator = new Generator($this->base);
        $generator->write($generator->planModule('Blog', web: true));
        $generator->write($generator->planCrud('Blog', 'Post', ['en', 'fr']));
        $this->boot();

        $this->assertSame(303, $this->get('/admin/posts')->getStatusCode(), 'anonymous editors are sent to the login page');
        $this->signIn();

        $this->assertSame(200, $this->get('/admin/posts')->getStatusCode());
        $form = (string) $this->get('/admin/posts/new')->getBody();
        $this->assertStringContainsString('<legend>fr</legend>', $form, 'one fieldset per locale');

        $invalid = $this->post('/admin/posts', ['title_en' => '', 'body_en' => 'x'], $form);
        $this->assertSame(422, $invalid->getStatusCode());
        $this->assertStringContainsString('A title is required.', (string) $invalid->getBody());

        $created = $this->post('/admin/posts', ['title_en' => 'Launch day', 'body_en' => 'We launched.', 'title_fr' => 'Jour de lancement', 'body_fr' => 'Nous avons lancé.'], $form);
        $this->assertSame(303, $created->getStatusCode(), (string) $created->getBody());
        $edit = $created->getHeaderLine('Location');
        $this->assertMatchesRegularExpression('#^/admin/posts/[0-9a-f-]{36}/edit$#', $edit);
        $id = explode('/', $edit)[3];

        $page = (string) $this->get($edit)->getBody();
        $this->assertStringContainsString('Saved as a draft.', $page);
        $this->assertStringContainsString('Status: <strong>draft</strong>', $page);
        $this->assertStringContainsString('value="launch-day"', $page, 'slugs come from the titles');
        $this->assertSame(1, preg_match('#href="(/preview/posts/[^"]+)"#', $page, $preview));

        $draft = $this->kernel->handle(new ServerRequest('GET', $preview[1] ?? ''));
        $this->assertSame(200, $draft->getStatusCode(), strip_tags((string) $draft->getBody()));
        $this->assertStringContainsString('Jour de lancement', (string) $draft->getBody());
        $this->assertSame('noindex', $draft->getHeaderLine('X-Robots-Tag'));

        $this->post("/admin/posts/{$id}/publish", [], $page);
        $this->assertStringContainsString('Status: <strong>published</strong>', (string) $this->get($edit)->getBody());

        $confirm = (string) $this->get("/admin/posts/{$id}/delete")->getBody();
        $this->assertStringContainsString('Delete “Launch day”?', $confirm);
        $this->assertSame('/admin/posts', $this->post("/admin/posts/{$id}/delete", [], $confirm)->getHeaderLine('Location'));
        $this->assertSame(404, $this->get($edit)->getStatusCode());

        $this->assertSame(403, $this->post('/admin/posts', ['title_en' => 'Forged'], '')->getStatusCode(), 'posts need the CSRF token');
    }

    private function boot(): void
    {
        $app = Application::configure($this->base)
            ->withEnvironment('testing')
            ->withConfiguration([
                'security.secret' => str_repeat('s', 32),
                'security.roles' => ['editor' => ['posts.edit']],
                'security.login.path' => '/login',
                'database.connections.main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'cache.default' => 'memory',
                'cache.stores.memory' => ['driver' => 'array'],
            ])
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class, CacheServiceProvider::class, DatabaseServiceProvider::class, SessionServiceProvider::class, SecurityServiceProvider::class, ViewServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $s
                ->singleton(Editors::class)
                ->alias(PasswordUserProviderInterface::class, Editors::class)
                ->alias(UserProviderInterface::class, Editors::class))
            ->build();
        $container = $app->container();

        $migrator = $container->get(Migrator::class);
        assert($migrator instanceof Migrator);
        $migrator->migrate();

        $this->addLoginRoute($container);
        $kernel = $container->get(HttpKernel::class);
        assert($kernel instanceof HttpKernel);
        $this->kernel = $kernel;
    }

    private function addLoginRoute(ContainerInterface $container): void
    {
        $router = $container->get(Router::class);
        assert($router instanceof Router);
        $router->post('/login', static function (SessionLogin $login): string {
            return $login->attempt('editor@example.com', 'secret-pass') === null ? 'no' : 'yes';
        })->middleware(SessionMiddleware::class);
    }

    private function signIn(): void
    {
        $response = $this->kernel->handle(new ServerRequest('POST', '/login'));
        $this->assertSame('yes', (string) $response->getBody());
        $this->cookie = explode(';', $response->getHeaderLine('Set-Cookie'))[0];
    }

    private function get(string $path): ResponseInterface
    {
        return $this->kernel->handle(new ServerRequest('GET', $path, ['Accept' => 'text/html', 'Cookie' => $this->cookie]));
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $path, array $fields, string $formPage): ResponseInterface
    {
        preg_match('/name="_token" value="([^"]+)"/', $formPage, $token);

        return $this->kernel->handle((new ServerRequest('POST', $path, ['Accept' => 'text/html', 'Cookie' => $this->cookie]))
            ->withParsedBody([...$fields, '_token' => $token[1] ?? '']));
    }
}

final class Editor implements UserInterface
{
    public function identifier(): string
    {
        return 'editor-1';
    }

    public function roles(): array
    {
        return ['editor'];
    }
}

final class Editors implements PasswordUserProviderInterface
{
    private ?string $hash = null;

    public function findByIdentifier(string $identifier): ?UserInterface
    {
        return $identifier === 'editor-1' ? new Editor() : null;
    }

    public function findByLogin(string $login): ?UserInterface
    {
        return $login === 'editor@example.com' ? new Editor() : null;
    }

    public function passwordHashFor(UserInterface $user): string
    {
        return $this->hash ??= (new PasswordHasher(['cost' => 4], PASSWORD_BCRYPT))->hash('secret-pass');
    }
}
