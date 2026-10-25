<?php

declare(strict_types=1);

namespace Zana\Maker;

/**
 * Plans the files of a module, feature, entity or controller, then writes
 * them. Modules live in <basePath>/modules/<Module>/ with the namespace
 * Modules\<Module>.
 *
 * Planning never touches the disk; write() refuses to replace existing files
 * unless forced, and then writes nothing.
 */
final class Generator
{
    private const string NAME = '/^[A-Z][A-Za-z0-9]*$/';

    /** Marker comments in the generated module class; wiring lines go above them. */
    public const string SERVICES_MARKER = '// zana:maker services';

    public const string BOOT_MARKER = '// zana:maker boot';

    /** @var list<string> */
    private array $manualSteps = [];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * The module class, its folders, and its entry in config/modules.php.
     *
     * @return list<GeneratedFile>
     */
    public function planModule(string $name): array
    {
        self::validate($name, 'module');
        $this->manualSteps = [];
        $class = "Modules\\{$name}\\{$name}Module";

        $files = [$this->file("modules/{$name}/{$name}Module.php", 'module', ['name' => $name, 'module' => $name, 'namespace' => "Modules\\{$name}"])];
        foreach (['Contracts', 'Domain', 'Application', 'Infrastructure', 'Presentation', 'Tests'] as $folder) {
            $files[] = new GeneratedFile($this->path("modules/{$name}/{$folder}/.gitkeep"), "modules/{$name}/{$folder}/.gitkeep", '');
        }

        $list = new ModuleList($this->path('config/modules.php'));
        $contents = $list->with($class);
        if (!in_array($class, $list->classes(), true)) {
            $files[] = new GeneratedFile($this->path('config/modules.php'), 'config/modules.php', $contents, update: true);
        }

        return $files;
    }

    /**
     * A command (or query), its #[Handles] handler and a handler test.
     *
     * @return list<GeneratedFile>
     */
    public function planFeature(string $module, string $name, bool $query = false): array
    {
        $this->requireModule($module);
        self::validate($name, 'feature');

        $kind = $query ? 'Query' : 'Command';
        $values = [
            'module' => $module,
            'name' => $name,
            'namespace' => "Modules\\{$module}\\Application\\Features\\{$name}",
            'message' => "{$name}{$kind}",
        ];

        $handler = '\\' . $values['namespace'] . "\\{$name}Handler::class";

        return $this->withWiring($module, [
            $this->file("modules/{$module}/Application/Features/{$name}/{$name}{$kind}.php", $query ? 'query' : 'command', $values),
            $this->file("modules/{$module}/Application/Features/{$name}/{$name}Handler.php", $query ? 'query-handler' : 'command-handler', $values),
            $this->file("modules/{$module}/Tests/Application/{$name}HandlerTest.php", 'handler-test', [...$values, 'namespace' => "Modules\\{$module}\\Tests\\Application", 'featureNamespace' => $values['namespace']]),
        ], ["\$services->scoped({$handler});"], ["\$container->get(\\Zana\\Mediator\\HandlerMap::class)->register({$handler});"]);
    }

    /**
     * An aggregate root, its identity and its repository interface.
     *
     * @return list<GeneratedFile>
     */
    public function planEntity(string $module, string $name): array
    {
        $this->requireModule($module);
        self::validate($name, 'entity');
        $this->manualSteps = [];
        $values = ['module' => $module, 'name' => $name, 'namespace' => "Modules\\{$module}\\Domain"];

        return [
            $this->file("modules/{$module}/Domain/{$name}.php", 'entity', $values),
            $this->file("modules/{$module}/Domain/{$name}Id.php", 'entity-id', $values),
            $this->file("modules/{$module}/Domain/{$name}RepositoryInterface.php", 'repository', $values),
        ];
    }

    /**
     * An API controller with a #[Route] example.
     *
     * @return list<GeneratedFile>
     */
    public function planController(string $module, string $name): array
    {
        $this->requireModule($module);
        self::validate($name, 'controller');
        $slug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));

        $controller = "\\Modules\\{$module}\\Presentation\\Api\\{$name}Controller::class";

        return $this->withWiring($module, [$this->file("modules/{$module}/Presentation/Api/{$name}Controller.php", 'controller', [
            'module' => $module,
            'name' => $name,
            'namespace' => "Modules\\{$module}\\Presentation\\Api",
            'path' => $slug,
            'route' => strtolower($module) . ".{$slug}",
        ])], ["\$services->transient({$controller});"], ["\$container->get(\\Zana\\Routing\\Router::class)->loadAttributes({$controller});"]);
    }

    /**
     * What the last plan could not wire into the module class (it has no
     * zana:maker markers), as lines for the user to add. Empty when the plan
     * wired everything itself.
     *
     * @return list<string>
     */
    public function manualSteps(): array
    {
        return $this->manualSteps;
    }

    /**
     * Add the module-class update that registers the generated classes, or
     * record manual steps when the module class has no markers.
     *
     * @param list<GeneratedFile> $files
     * @param list<string> $services Lines for register()
     * @param list<string> $boot Lines for boot()
     *
     * @return list<GeneratedFile>
     */
    private function withWiring(string $module, array $files, array $services, array $boot): array
    {
        $this->manualSteps = [];
        $relative = "modules/{$module}/{$module}Module.php";
        $code = (string) file_get_contents($this->path($relative));

        if (!str_contains($code, self::SERVICES_MARKER) || !str_contains($code, self::BOOT_MARKER)) {
            foreach ($services as $line) {
                $this->manualSteps[] = "In {$module}Module::register(): {$line}";
            }
            foreach ($boot as $line) {
                $this->manualSteps[] = "In {$module}Module::boot(): {$line}";
            }

            return $files;
        }

        $updated = self::insertBefore(self::insertBefore($code, self::SERVICES_MARKER, $services), self::BOOT_MARKER, $boot);
        if ($updated !== $code) {
            $files[] = new GeneratedFile($this->path($relative), $relative, $updated, update: true);
        }

        return $files;
    }

    /**
     * Insert lines above the marker, with its indentation; lines already in
     * the code are skipped, so generating twice wires once.
     *
     * @param list<string> $lines
     */
    private static function insertBefore(string $code, string $marker, array $lines): string
    {
        $lines = array_values(array_filter($lines, static fn (string $line): bool => !str_contains($code, $line)));
        if ($lines === []) {
            return $code;
        }

        return (string) preg_replace_callback(
            '/^([ \t]*)' . preg_quote($marker, '/') . '/m',
            static fn (array $m): string => implode('', array_map(static fn (string $line): string => $m[1] . $line . "\n", $lines)) . $m[0],
            $code,
            1,
        );
    }

    /**
     * Write a plan. Without $force, nothing is written when a new file
     * already exists.
     *
     * @param list<GeneratedFile> $files
     *
     * @throws MakerException Listing the existing files
     */
    public function write(array $files, bool $force = false): void
    {
        $conflicts = array_filter($files, static fn (GeneratedFile $file): bool => $file->conflicts());
        if ($conflicts !== [] && !$force) {
            throw new MakerException("These files already exist (use --force to replace them):\n  "
                . implode("\n  ", array_map(static fn (GeneratedFile $file): string => $file->relativePath, $conflicts)));
        }

        foreach ($files as $file) {
            $directory = dirname($file->path);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new MakerException("Cannot create {$directory}.");
            }
            if (file_put_contents($file->path, $file->contents) === false) {
                throw new MakerException("Cannot write {$file->path}.");
            }
        }
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    private static function validate(string $name, string $what): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new MakerException("The {$what} name \"{$name}\" must be StudlyCase: a capital letter, then letters and digits.");
        }
    }

    private function requireModule(string $module): void
    {
        self::validate($module, 'module');
        if (!is_file($this->path("modules/{$module}/{$module}Module.php"))) {
            throw new MakerException("There is no module \"{$module}\"; create it with make:module {$module}.");
        }
    }

    /**
     * @param array<string, string> $values
     */
    private function file(string $relative, string $stub, array $values): GeneratedFile
    {
        return new GeneratedFile($this->path($relative), $relative, Stub::load($stub, $this->basePath)->render($values));
    }

    private function path(string $relative): string
    {
        return rtrim(str_replace('\\', '/', $this->basePath), '/') . '/' . $relative;
    }
}
