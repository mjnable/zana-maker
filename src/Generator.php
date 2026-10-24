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

        return [
            $this->file("modules/{$module}/Application/Features/{$name}/{$name}{$kind}.php", $query ? 'query' : 'command', $values),
            $this->file("modules/{$module}/Application/Features/{$name}/{$name}Handler.php", $query ? 'query-handler' : 'command-handler', $values),
            $this->file("modules/{$module}/Tests/Application/{$name}HandlerTest.php", 'handler-test', [...$values, 'namespace' => "Modules\\{$module}\\Tests\\Application", 'featureNamespace' => $values['namespace']]),
        ];
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

        return [$this->file("modules/{$module}/Presentation/Api/{$name}Controller.php", 'controller', [
            'module' => $module,
            'name' => $name,
            'namespace' => "Modules\\{$module}\\Presentation\\Api",
            'path' => $slug,
            'route' => strtolower($module) . ".{$slug}",
        ])];
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
