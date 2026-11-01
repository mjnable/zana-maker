<?php

declare(strict_types=1);

namespace Zana\Maker;

/**
 * A feature plan (FE-51, ADR-026): one module and what to generate in it, as
 * a file an agent can propose and a person can review before anything is
 * written. Every entry is one existing make: command; commands() lists them.
 *
 *     {
 *         "schema": "zana.plan/1",
 *         "module": "Library",
 *         "entities": ["Book"],
 *         "value_objects": [{"name": "BookTitle", "type": "string"}],
 *         "repositories": ["Book"],
 *         "features": [
 *             {"name": "AddBook", "http": true, "fields": ["title:string", "pages:?int"]},
 *             {"name": "ListBooks", "query": true, "http": true}
 *         ]
 *     }
 *
 * The module is created when it does not exist ("web": true as make:module --web).
 */
final readonly class Plan
{
    public const string SCHEMA = 'zana.plan/1';

    private const array KEYS = ['schema', 'module', 'web', 'entities', 'value_objects', 'repositories', 'features'];

    private const array FEATURE_KEYS = ['name', 'query', 'http', 'fields'];

    /**
     * @param list<string> $entities
     * @param list<array{name: string, type: string}> $valueObjects
     * @param list<string> $repositories
     * @param list<array{name: string, query: bool, http: bool, fields: list<string>}> $features
     */
    private function __construct(
        public string $module,
        public bool $web,
        public array $entities,
        public array $valueObjects,
        public array $repositories,
        public array $features,
    ) {
    }

    /**
     * @throws MakerException Naming every problem of the file
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MakerException('The plan is not valid JSON: ' . $e->getMessage() . '.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new MakerException('The plan must be a JSON object.');
        }

        $problems = [];
        foreach (array_diff(array_keys($data), self::KEYS) as $key) {
            $problems[] = "\"{$key}\" is not a plan key (" . implode(', ', self::KEYS) . ').';
        }
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            $problems[] = '"schema" must be "' . self::SCHEMA . '".';
        }
        $module = $data['module'] ?? null;
        if (!is_string($module) || $module === '') {
            $problems[] = '"module" must name the module, e.g. "Library".';
        }
        if (isset($data['web']) && !is_bool($data['web'])) {
            $problems[] = '"web" must be true or false.';
        }

        $names = static function (string $key) use ($data, &$problems): array {
            $value = $data[$key] ?? [];
            if (!is_array($value) || !array_is_list($value) || array_filter($value, static fn (mixed $v): bool => !is_string($v)) !== []) {
                $problems[] = "\"{$key}\" must be a list of names.";

                return [];
            }

            /** @var list<string> $value */
            return $value;
        };
        $entities = $names('entities');
        $repositories = $names('repositories');

        $valueObjects = [];
        foreach (self::list($data, 'value_objects', $problems) as $i => $entry) {
            $entry = is_string($entry) ? ['name' => $entry] : $entry;
            if (!is_array($entry) || !is_string($entry['name'] ?? null) || array_diff(array_keys($entry), ['name', 'type']) !== [] || !is_string($entry['type'] ?? 'string')) {
                $problems[] = "\"value_objects\"[{$i}] must be a name or {\"name\", \"type\"}.";
                continue;
            }
            $valueObjects[] = ['name' => $entry['name'], 'type' => is_string($entry['type'] ?? null) ? $entry['type'] : 'string'];
        }

        $features = [];
        foreach (self::list($data, 'features', $problems) as $i => $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
                $problems[] = "\"features\"[{$i}] must be an object with a \"name\".";
                continue;
            }
            foreach (array_diff(array_keys($entry), self::FEATURE_KEYS) as $key) {
                $problems[] = "\"features\"[{$i}]: \"{$key}\" is not a feature key (" . implode(', ', self::FEATURE_KEYS) . ').';
            }
            $fields = $entry['fields'] ?? [];
            if (!is_array($fields) || !array_is_list($fields) || array_filter($fields, static fn (mixed $f): bool => !is_string($f)) !== []) {
                $problems[] = "\"features\"[{$i}]: \"fields\" must be a list such as [\"title:string\", \"pages:?int\"].";
                $fields = [];
            }
            foreach (['query', 'http'] as $flag) {
                if (isset($entry[$flag]) && !is_bool($entry[$flag])) {
                    $problems[] = "\"features\"[{$i}]: \"{$flag}\" must be true or false.";
                }
            }
            /** @var list<string> $fields */
            $features[] = ['name' => $entry['name'], 'query' => ($entry['query'] ?? false) === true, 'http' => ($entry['http'] ?? false) === true, 'fields' => $fields];
        }

        if ($problems !== [] || !is_string($module)) {
            throw new MakerException("The plan is not valid:\n  " . implode("\n  ", $problems));
        }

        return new self($module, ($data['web'] ?? false) === true, $entities, $valueObjects, $repositories, $features);
    }

    /**
     * The make: commands this plan stands for, in order; the module's only when it must be created.
     *
     * @return list<string>
     */
    public function commands(bool $createModule = true): array
    {
        $commands = $createModule ? ["make:module {$this->module}" . ($this->web ? ' --web' : '')] : [];
        foreach ($this->entities as $entity) {
            $commands[] = "make:entity {$this->module} {$entity}";
        }
        foreach ($this->valueObjects as $valueObject) {
            $commands[] = "make:value-object {$this->module} {$valueObject['name']}" . ($valueObject['type'] === 'string' ? '' : " --type={$valueObject['type']}");
        }
        foreach ($this->repositories as $entity) {
            $commands[] = "make:repository {$this->module} {$entity}";
        }
        foreach ($this->features as $feature) {
            $commands[] = "make:feature {$this->module} {$feature['name']}"
                . implode('', array_map(static fn (string $field): string => " --field={$field}", $feature['fields']))
                . ($feature['query'] ? ' --query' : '')
                . ($feature['http'] ? ' --http' : '');
        }

        return $commands;
    }

    /**
     * The plans of the generators, in the order of commands().
     *
     * @return list<\Closure(Generator): list<GeneratedFile>>
     */
    public function steps(bool $createModule = true): array
    {
        $steps = $createModule ? [fn (Generator $g): array => $g->planModule($this->module, $this->web)] : [];
        foreach ($this->entities as $entity) {
            $steps[] = fn (Generator $g): array => $g->planEntity($this->module, $entity);
        }
        foreach ($this->valueObjects as $valueObject) {
            $steps[] = fn (Generator $g): array => $g->planValueObject($this->module, $valueObject['name'], $valueObject['type']);
        }
        foreach ($this->repositories as $entity) {
            $steps[] = fn (Generator $g): array => $g->planRepository($this->module, $entity);
        }
        foreach ($this->features as $feature) {
            $steps[] = fn (Generator $g): array => $g->planFeature($this->module, $feature['name'], $feature['query'], $feature['fields'], $feature['http']);
        }

        return $steps;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string> $problems
     * @return array<int, mixed>
     */
    private static function list(array $data, string $key, array &$problems): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            $problems[] = "\"{$key}\" must be a list.";

            return [];
        }

        return $value;
    }
}
