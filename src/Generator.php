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

    /** Field types of make:feature --field, with an example value for tests. */
    private const array FIELD_EXAMPLES = ['string' => "'Example'", 'int' => '1', 'float' => '1.5', 'bool' => 'true'];

    /** @var list<string> */
    private array $manualSteps = [];

    /** @var list<array{method: string, line: string}> */
    private array $wiring = [];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * The module class, its folders, and its entry in config/modules.php.
     *
     * @return list<GeneratedFile>
     */
    public function planModule(string $name, bool $web = false): array
    {
        self::validate($name, 'module');
        $this->reset();
        $class = "Modules\\{$name}\\{$name}Module";

        $files = [$this->file("modules/{$name}/{$name}Module.php", 'module', ['name' => $name, 'module' => $name, 'namespace' => "Modules\\{$name}"])];
        $folders = ['Contracts', 'Domain', 'Application', 'Infrastructure', 'Presentation', 'Tests'];
        if ($web) {
            // Templates and translations, loaded automatically by the view and translation packages.
            $folders = [...array_diff($folders, ['Presentation']), 'Presentation/Web', 'Resources/views', 'Resources/translations'];
        }
        foreach ($folders as $folder) {
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
     * A command (or query) with its typed fields, its #[Handles] handler and a
     * handler test. With $http, the vertical slice (FE-47): an API action
     * routed to POST (GET for a query) /api/<module>/<feature> that reads and
     * validates the input through the module's ApiInput, and an HTTP test.
     *
     * @param list<string> $fields "name:type", type string, int, float or bool; "?type" is optional
     * @return list<GeneratedFile>
     */
    public function planFeature(string $module, string $name, bool $query = false, array $fields = [], bool $http = false): array
    {
        $this->requireModule($module);
        self::validate($name, 'feature');
        $fields = self::fields($fields);
        $required = array_values(array_filter($fields, static fn (array $f): bool => !$f['optional']));
        $validated = array_filter($required, static fn (array $f): bool => $f['type'] === 'string') !== [];
        self::requirePackages(array_filter([
            'mj-zana/mediator' => 'Zana\Mediator\HandlerMap',
            'mj-zana/ddd' => 'Zana\Ddd\Application\Command',
            'mj-zana/validation' => $validated || $http ? 'Zana\Validation\Validator' : null,
            'mj-zana/serialization' => $http ? 'Zana\Serialization\Serializer' : null,
            'mj-zana/routing' => $http ? 'Zana\Routing\Router' : null,
            'mj-zana/testing' => $http ? 'Zana\Testing\ApplicationTestCase' : null,
        ]), $http ? 'make:feature --http' : 'make:feature');

        $kind = $query ? 'Query' : 'Command';
        $values = [
            'module' => $module,
            'name' => $name,
            'namespace' => "Modules\\{$module}\\Application\\Features\\{$name}",
            'message' => "{$name}{$kind}",
            'imports' => $validated ? "\nuse Zana\\Validation\\Constraint\\NotBlank;" : '',
            'constructor' => self::constructor($fields),
            'arguments' => implode(', ', array_map(static fn (array $f): string => "{$f['name']}: " . self::FIELD_EXAMPLES[$f['type']], $required)),
        ];

        $handler = '\\' . $values['namespace'] . "\\{$name}Handler::class";
        $files = [
            $this->file("modules/{$module}/Application/Features/{$name}/{$name}{$kind}.php", $query ? 'query' : 'command', $values),
            $this->file("modules/{$module}/Application/Features/{$name}/{$name}Handler.php", $query ? 'query-handler' : 'command-handler', $values),
            $this->file("modules/{$module}/Tests/Application/{$name}HandlerTest.php", 'handler-test', [...$values, 'namespace' => "Modules\\{$module}\\Tests\\Application", 'featureNamespace' => $values['namespace']]),
        ];
        $services = ["\$services->scoped({$handler});"];
        $boot = ["\$container->get(\\Zana\\Mediator\\HandlerMap::class)->register({$handler});"];

        if ($http) {
            $api = "Modules\\{$module}\\Presentation\\Api";
            $path = self::kebab($module) . '/' . self::kebab($name);
            $slice = [...$values, 'namespace' => $api, 'featureNamespace' => $values['namespace'], 'path' => $path, 'route' => strtolower($module) . '.' . self::kebab($name)];
            if (!is_file($this->path("modules/{$module}/Presentation/Api/ApiInput.php"))) {
                $files[] = $this->file("modules/{$module}/Presentation/Api/ApiInput.php", 'api-input', $slice);
            }
            $files[] = $this->file("modules/{$module}/Presentation/Api/{$name}Action.php", $query ? 'api-query-action' : 'api-command-action', $slice);
            $files[] = $this->file("modules/{$module}/Tests/Presentation/{$name}ApiTest.php", 'api-test', [
                ...$slice,
                'namespace' => "Modules\\{$module}\\Tests\\Presentation",
                ...self::apiTest("/api/{$path}", $query, $required),
            ]);
            $services[] = "\$services->singleton(\\{$api}\\ApiInput::class);";
            $services[] = "\$services->transient(\\{$api}\\{$name}Action::class);";
            $boot[] = "\$container->get(\\Zana\\Routing\\Router::class)->loadAttributes(\\{$api}\\{$name}Action::class);";
        }

        $files = $this->withWiring($module, $files, $services, $boot);
        if ($http && !is_file($this->path('src/Bootstrap.php'))) {
            $this->manualSteps[] = "The API test boots App\\Bootstrap::builder(); without src/Bootstrap.php, change createApplicationBuilder() in modules/{$module}/Tests/Presentation/{$name}ApiTest.php.";
        }
        $this->requireProviders($http
            ? ['Zana\Mediator\MediatorServiceProvider', 'Zana\Serialization\SerializationServiceProvider', 'Zana\Validation\ValidationServiceProvider']
            : ['Zana\Mediator\MediatorServiceProvider']);

        return $files;
    }

    /**
     * A value object of the domain (FE-47), holding one value with an example
     * rule, and its unit test.
     *
     * @param string $type string, int or float
     * @return list<GeneratedFile>
     */
    public function planValueObject(string $module, string $name, string $type = 'string'): array
    {
        $this->requireModule($module);
        self::validate($name, 'value object');
        $this->reset();
        $label = self::words($name);
        [$rule, $check, $valid, $other, $invalid] = match ($type) {
            'string' => [
                'not empty, without surrounding spaces.',
                "\$value = trim(\$value);\n        if (\$value === '') {\n            throw new DomainException('{$label} must not be empty.');\n        }",
                "'Example'", "'Other'", "'  '",
            ],
            'int', 'float' => [
                'zero or more.',
                "if (\$value < 0) {\n            throw new DomainException('{$label} must not be negative.');\n        }",
                ...($type === 'int' ? ['1', '2', '-1'] : ['1.5', '2.5', '-0.5']),
            ],
            default => throw new MakerException("The value type \"{$type}\" must be string, int or float."),
        };
        $values = ['module' => $module, 'name' => $name, 'label' => $label, 'type' => $type, 'rule' => $rule, 'check' => $check];

        return [
            $this->file("modules/{$module}/Domain/{$name}.php", 'value-object', [...$values, 'namespace' => "Modules\\{$module}\\Domain"]),
            $this->file("modules/{$module}/Tests/Domain/{$name}Test.php", 'value-object-test', [
                ...$values,
                'namespace' => "Modules\\{$module}\\Tests\\Domain",
                'domainNamespace' => "Modules\\{$module}\\Domain",
                'valid' => $valid,
                'other' => $other,
                'invalid' => $invalid,
            ]),
        ];
    }

    /**
     * The database implementation of an entity's repository interface (FE-47),
     * its migration, and its registration in the module.
     *
     * @return list<GeneratedFile>
     */
    public function planRepository(string $module, string $entity): array
    {
        $this->requireModule($module);
        self::validate($entity, 'entity');
        self::requirePackages(['mj-zana/database' => 'Zana\Database\ConnectionInterface', 'mj-zana/ddd' => 'Zana\Ddd\Domain\AggregateRoot'], 'make:repository');
        foreach (["{$entity}.php", "{$entity}RepositoryInterface.php"] as $file) {
            if (!is_file($this->path("modules/{$module}/Domain/{$file}"))) {
                throw new MakerException("There is no modules/{$module}/Domain/{$file}; create the entity with make:entity {$module} {$entity}.");
            }
        }

        $table = str_replace('-', '_', self::kebab($entity)) . 's';
        $migrations = "modules/{$module}/Infrastructure/Persistence/Migrations";
        $existing = glob($this->path("{$migrations}/*_create_{$table}_table.php")) ?: [];
        $migration = $existing === [] ? gmdate('Y_m_d_His') . "_create_{$table}_table.php" : basename($existing[0]);
        $values = ['module' => $module, 'name' => $entity, 'table' => $table, 'label' => strtolower(self::words($entity))];

        $files = $this->withWiring($module, [
            $this->file("modules/{$module}/Infrastructure/Persistence/Database{$entity}Repository.php", 'database-repository', [...$values, 'namespace' => "Modules\\{$module}\\Infrastructure\\Persistence"]),
            $this->file("{$migrations}/{$migration}", 'repository-migration', $values),
        ], ["\$services->scoped(\\Modules\\{$module}\\Domain\\{$entity}RepositoryInterface::class, \\Modules\\{$module}\\Infrastructure\\Persistence\\Database{$entity}Repository::class);"], []);

        if (!str_contains((string) file_get_contents($this->path("modules/{$module}/Domain/{$entity}.php")), 'function restore(')) {
            $this->manualSteps[] = "Add {$entity}::restore({$entity}Id \$id, int \$version): self, which the repository uses to rebuild a stored {$entity} (make:entity writes it since 2.8).";
        }
        $this->requireProviders(['Zana\Database\DatabaseServiceProvider']);

        return $files;
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
        $this->reset();
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
    public function planController(string $module, string $name, bool $web = false): array
    {
        $this->requireModule($module);
        self::validate($name, 'controller');
        $slug = self::kebab($name);
        $layer = $web ? 'Web' : 'Api';

        $controller = "\\Modules\\{$module}\\Presentation\\{$layer}\\{$name}Controller::class";
        $values = [
            'module' => $module,
            'name' => $name,
            'namespace' => "Modules\\{$module}\\Presentation\\{$layer}",
            'path' => $slug,
            'route' => strtolower($module) . ".{$slug}",
        ];

        $files = [$this->file("modules/{$module}/Presentation/{$layer}/{$name}Controller.php", $web ? 'web-controller' : 'controller', $values)];
        if ($web) {
            $files[] = $this->file("modules/{$module}/Resources/views/{$slug}/index.php", 'web-view', $values);
        }

        return $this->withWiring($module, $files, ["\$services->transient({$controller});"], ["\$container->get(\\Zana\\Routing\\Router::class)->loadAttributes({$controller});"]);
    }

    /**
     * A localised page: an invokable class with its routes, SEO metadata, and
     * a template using translation keys.
     *
     * @param array<string, string> $paths locale => path, e.g. ['en' => '/about', 'fr' => '/a-propos']
     * @return list<GeneratedFile>
     */
    public function planPage(string $module, string $name, array $paths): array
    {
        $this->requireModule($module);
        self::validate($name, 'page');
        if ($paths === []) {
            throw new MakerException('A page needs at least one locale and path.');
        }
        $slug = self::kebab($name);
        $page = "\\Modules\\{$module}\\Presentation\\Web\\{$name}Page::class";
        $values = [
            'module' => $module,
            'name' => $name,
            'namespace' => "Modules\\{$module}\\Presentation\\Web",
            'path' => $slug,
            'route' => $slug,
            'key' => 'page.' . str_replace('-', '_', $slug),
            'paths' => self::shortArray($paths),
        ];

        return $this->withWiring($module, [
            $this->file("modules/{$module}/Presentation/Web/{$name}Page.php", 'page', $values),
            $this->file("modules/{$module}/Resources/views/pages/{$slug}.php", 'page-view', $values),
        ], ["\$services->transient({$page});"], ["{$page}::routes(\$container->get(\\Zana\\Routing\\Router::class));"]);
    }

    /**
     * Back-office screens for a content type (FE-32): a status enum (draft,
     * published, archived), a migration with a translations table, a
     * repository, an admin controller with publish, archive, delete with
     * confirmation and signed previews, and its templates.
     *
     * @param list<string> $locales The locales editors write in; the first is required
     * @return list<GeneratedFile>
     */
    public function planCrud(string $module, string $name, array $locales): array
    {
        $this->requireModule($module);
        self::validate($name, 'entity');
        if ($locales === []) {
            throw new MakerException('make:crud needs at least one locale.');
        }

        $slug = self::kebab($name) . 's';
        $foreign = str_replace('-', '_', self::kebab($name));
        $values = [
            'module' => $module,
            'name' => $name,
            'title' => $name . 's',
            'path' => $slug,
            'route' => 'admin.' . $slug,
            'table' => "{$foreign}s",
            'foreign' => $foreign,
            'permission' => "{$slug}.edit",
            'locales' => self::shortArray($locales),
        ];
        $controller = "\\Modules\\{$module}\\Presentation\\Web\\Admin\\{$name}AdminController";
        $views = "modules/{$module}/Resources/views/admin";

        return $this->withWiring($module, [
            $this->file("modules/{$module}/Domain/{$name}Status.php", 'crud-status', [...$values, 'namespace' => "Modules\\{$module}\\Domain"]),
            $this->file("modules/{$module}/Infrastructure/Persistence/Migrations/" . gmdate('Y_m_d_His') . "_create_{$values['table']}_table.php", 'crud-migration', $values),
            $this->file("modules/{$module}/Infrastructure/{$name}Repository.php", 'crud-repository', [...$values, 'namespace' => "Modules\\{$module}\\Infrastructure"]),
            $this->file("modules/{$module}/Presentation/Web/Admin/{$name}AdminController.php", 'crud-controller', [...$values, 'namespace' => "Modules\\{$module}\\Presentation\\Web\\Admin"]),
            $this->file("modules/{$module}/Presentation/Web/Admin/{$name}Preview.php", 'crud-preview-controller', [...$values, 'namespace' => "Modules\\{$module}\\Presentation\\Web\\Admin"]),
            $this->file("{$views}/{$slug}/index.php", 'crud-index', $values),
            $this->file("{$views}/{$slug}/form.php", 'crud-form', $values),
            $this->file("{$views}/{$slug}/confirm-delete.php", 'crud-confirm-delete', $values),
            $this->file("{$views}/{$slug}/preview.php", 'crud-preview', $values),
            ...(is_file($this->path("{$views}/layout.php")) ? [] : [$this->file("{$views}/layout.php", 'crud-layout', $values)]),
        ], [
            "\$services->scoped(\\Modules\\{$module}\\Infrastructure\\{$name}Repository::class);",
            "\$services->transient({$controller}::class);",
            "\$services->transient(\\Modules\\{$module}\\Presentation\\Web\\Admin\\{$name}Preview::class);",
        ], ["{$controller}::routes(\$container->get(\\Zana\\Routing\\Router::class));"]);
    }

    /**
     * The instructions for AI coding agents (FE-44): AGENTS.md, read by most
     * agents, and one-line pointers for the tools that read their own file
     * (CLAUDE.md, .github/copilot-instructions.md). The rules are only in
     * AGENTS.md, which links to the documentation of this Zana version.
     *
     * @param string $version The installed Zana minor version, e.g. "2.7"
     * @return list<GeneratedFile>
     * @throws MakerException For a version that is not "<major>.<minor>"
     */
    public function planAgents(string $version): array
    {
        if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
            throw new MakerException("\"{$version}\" is not a Zana minor version such as 2.7.");
        }
        $this->reset();

        return [
            $this->file('AGENTS.md', 'agents', ['version' => $version]),
            $this->file('CLAUDE.md', 'agents-claude', []),
            $this->file('.github/copilot-instructions.md', 'agents-copilot', ['version' => $version]),
        ];
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
     * The lines the last plan registers in the module class, written by the
     * plan itself or, without markers, listed in manualSteps().
     *
     * @return list<array{method: string, line: string}>
     */
    public function wiring(): array
    {
        return $this->wiring;
    }

    private function reset(): void
    {
        $this->manualSteps = [];
        $this->wiring = [];
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
        $this->reset();
        foreach ([...array_map(static fn (string $line): array => ['method' => 'register', 'line' => $line], $services), ...array_map(static fn (string $line): array => ['method' => 'boot', 'line' => $line], $boot)] as $wiring) {
            $this->wiring[] = $wiring;
        }
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

    private static function kebab(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }

    /**
     * "PostalCode" → "Postal code".
     */
    private static function words(string $name): string
    {
        return ucfirst(str_replace('-', ' ', self::kebab($name)));
    }

    /**
     * @param list<string> $specs "name:type", "name:?type" or "name" (a string)
     * @return list<array{name: string, type: string, optional: bool}> required fields first
     */
    private static function fields(array $specs): array
    {
        $fields = [];
        foreach ($specs as $spec) {
            if (preg_match('/^([a-z][A-Za-z0-9]*)(?::(\??)(string|int|float|bool))?$/', $spec, $m) !== 1) {
                throw new MakerException("The field \"{$spec}\" must be name:type, with a camelCase name and the type string, int, float or bool (?type for an optional field).");
            }
            if (isset($fields[$m[1]])) {
                throw new MakerException("The field \"{$m[1]}\" is given twice.");
            }
            $fields[$m[1]] = ['name' => $m[1], 'type' => ($m[3] ?? '') === '' ? 'string' : $m[3], 'optional' => ($m[2] ?? '') === '?'];
        }
        usort($fields, static fn (array $a, array $b): int => $a['optional'] <=> $b['optional']);

        return $fields;
    }

    /**
     * The constructor's signature and opening brace, with a promoted property
     * per field: required strings must not be blank; optional fields default to null.
     *
     * @param list<array{name: string, type: string, optional: bool}> $fields
     */
    private static function constructor(array $fields): string
    {
        if ($fields === []) {
            return "public function __construct()\n    {";
        }
        $lines = [];
        foreach ($fields as $field) {
            if (!$field['optional'] && $field['type'] === 'string') {
                $lines[] = '#[NotBlank]';
            }
            $lines[] = $field['optional']
                ? "public readonly ?{$field['type']} \${$field['name']} = null,"
                : "public readonly {$field['type']} \${$field['name']},";
        }

        return "public function __construct(\n        " . implode("\n        ", $lines) . "\n    ) {";
    }

    /**
     * The request of the slice's HTTP test, and a test of invalid input when
     * one can be made.
     *
     * @param list<array{name: string, type: string, optional: bool}> $required
     * @return array{request: string, invalid: string}
     */
    private static function apiTest(string $uri, bool $query, array $required): array
    {
        $examples = array_map(static fn (array $f): string => var_export($f['name'], true) . ' => ' . self::FIELD_EXAMPLES[$f['type']], $required);
        if ($query) {
            $parameters = array_map(static fn (array $f): string => $f['name'] . '=' . match ($f['type']) {
                'string' => 'Example',
                'float' => '1.5',
                default => '1',
            }, $required);
            $request = "\$this->http()->get('{$uri}" . ($parameters === [] ? '' : '?' . implode('&', $parameters)) . "')->assertOk()->assertJsonPath('data', []);";
        } else {
            $request = "\$this->http()->post('{$uri}', [" . implode(', ', $examples) . '])->assertNoContent();';
        }

        $invalid = match (true) {
            $required !== [] => "\n    public function testMissingFieldsAreRefused(): void\n    {\n"
                . "        \$errors = \$this->http()->" . ($query ? "get('{$uri}')" : "post('{$uri}', [])") . "->assertUnprocessable()->jsonPath('errors');\n\n"
                . "        \$this->assertIsArray(\$errors);\n"
                . "        \$this->assertArrayHasKey('{$required[0]['name']}', \$errors);\n    }\n",
            !$query => "\n    public function testABodyThatIsNotAnObjectIsRefused(): void\n    {\n"
                . "        \$this->http()->post('{$uri}', '[1, 2]')->assertStatus(400);\n    }\n",
            default => '',
        };

        return ['request' => $request, 'invalid' => $invalid];
    }

    /**
     * A manual step for each provider the generated code needs that the
     * application's src/Bootstrap.php (the skeletons' convention) does not name.
     *
     * @param list<string> $providers
     */
    private function requireProviders(array $providers): void
    {
        $bootstrap = $this->path('src/Bootstrap.php');
        if (!is_file($bootstrap)) {
            return;
        }
        $code = (string) file_get_contents($bootstrap);
        foreach ($providers as $provider) {
            $short = substr($provider, (int) strrpos($provider, '\\') + 1);
            if (!str_contains($code, $short)) {
                $this->manualSteps[] = "Register {$short} in src/Bootstrap.php: ->withProviders(..., \\{$provider}::class).";
            }
        }
    }

    /**
     * @param array<string, string> $packages package => a class it provides
     */
    private static function requirePackages(array $packages, string $command): void
    {
        $missing = array_keys(array_filter($packages, static fn (string $class): bool => !class_exists($class) && !interface_exists($class)));
        if ($missing !== []) {
            throw new MakerException("{$command} generates code that needs " . implode(', ', $missing) . ': composer require ' . implode(' ', $missing));
        }
    }

    /**
     * @param array<array-key, string> $values
     */
    private static function shortArray(array $values): string
    {
        $items = [];
        foreach ($values as $key => $value) {
            $items[] = (array_is_list($values) ? '' : var_export($key, true) . ' => ') . var_export($value, true);
        }

        return '[' . implode(', ', $items) . ']';
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
