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
use Zana\Routing\RoutingServiceProvider;
use Zana\Translation\LocaleMiddleware;
use Zana\Translation\TranslationServiceProvider;
use Zana\View\ViewServiceProvider;

/**
 * make:module --web, make:page and make:controller --web generate a working
 * website module (FE-16).
 */
final class GeneratedWebModuleRunsTest extends TestCase
{
    private string $base = '';

    /** @var \Closure(string): void|null */
    private ?\Closure $autoloader = null;

    protected function setUp(): void
    {
        $this->base = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-maker-web-' . bin2hex(random_bytes(4));
        mkdir("{$this->base}/templates/layouts", 0777, true);
        file_put_contents("{$this->base}/templates/layouts/site.php", '<html lang="<?= $locale ?>"><title><?= $title ?></title><?= $meta ?><?= $this->section(\'content\') ?></html>');
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

    public function testAGeneratedWebModuleServesPages(): void
    {
        $generator = new Generator($this->base);
        $module = $generator->planModule('Site', web: true);
        $this->assertContains('modules/Site/Resources/views/.gitkeep', array_map(static fn ($f): string => $f->relativePath, $module));
        $generator->write($module);
        $generator->write($generator->planPage('Site', 'AboutUs', ['en' => '/about', 'fr' => '/a-propos']));
        $generator->write($generator->planController('Site', 'News', web: true));
        file_put_contents("{$this->base}/modules/Site/Resources/translations/fr.php", "<?php return ['page' => ['about_us' => ['title' => 'À propos']]];");

        $kernel = Application::configure($this->base)
            ->withEnvironment('testing')
            ->withConfiguration(['translation.locales' => ['en', 'fr'], 'app.url' => 'http://site.test', 'http.middleware' => [LocaleMiddleware::class]])
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class, TranslationServiceProvider::class, ViewServiceProvider::class)
            ->build()
            ->container()
            ->get(HttpKernel::class);
        assert($kernel instanceof HttpKernel);

        $about = $kernel->handle(new ServerRequest('GET', '/fr/a-propos', ['Accept' => 'text/html']));
        $html = (string) $about->getBody();
        $this->assertSame(200, $about->getStatusCode(), $html);
        $this->assertStringContainsString('<h1>À propos</h1>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="http://site.test/fr/a-propos">', $html);
        $this->assertStringContainsString('hreflang="en" href="http://site.test/en/about"', $html);

        $news = $kernel->handle(new ServerRequest('GET', '/news', ['Accept' => 'text/html']));
        $this->assertSame(200, $news->getStatusCode(), (string) $news->getBody());
        $this->assertStringContainsString('<h1>News</h1>', (string) $news->getBody());
    }
}
