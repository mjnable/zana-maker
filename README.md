# Zana Maker

Code generators that follow the Zana module layout. Conventions produce
plain, explicit code you own, not runtime magic. Requires PHP 8.5 or
later.

```
composer require --dev mj-zana/maker
```

Generated classes use the namespace `Modules\<Module>` under
`modules/<Module>/`, so map it in your `composer.json`:

```json
"autoload": { "psr-4": { "Modules\\": "modules/" } }
```

Maker is a dev dependency, so register `MakerServiceProvider` (with
`ConsoleServiceProvider`) only where it is installed, for example in
`bin/zana`:

```php
if (class_exists(\Zana\Maker\MakerServiceProvider::class)) {
    $builder->withProviders(\Zana\Maker\MakerServiceProvider::class);
}
```

Run the generators with `APP_ENV=development`. In production a compiled
container that no longer matches the registrations is an error, and every
`make:*` command changes the registrations.

## Commands

```
php bin/zana make:module Patients                      # PatientsModule, its folders, and config/modules.php
php bin/zana make:feature Patients RegisterPatient     # command, #[Handles] handler, handler test
php bin/zana make:feature Patients FindPatient --query # query instead of command
php bin/zana make:entity Patients Patient              # aggregate root, PatientId, repository interface
php bin/zana make:controller Patients Measurements     # Presentation/Api/MeasurementsController, routed at /api/measurements
```

Whole use cases (2.8):

```
php bin/zana make:feature Library AddBook --field=title:string --field=pages:?int --http
    # the command with typed, validated fields, its handler, POST /api/library/add-book
    # (Presentation/Api/AddBookAction), the module's ApiInput, a handler test and an HTTP test
php bin/zana make:feature Library ListBooks --query --http   # the same for a query, on GET
php bin/zana make:value-object Library BookTitle [--type=string|int|float]   # a value object with an example rule, and its test
php bin/zana make:repository Library Book              # DatabaseBookRepository (optimistic versions), its migration, its registration
```

Field types are `string`, `int`, `float` and `bool`; `?type` makes a field
optional (null by default), and a required `string` must not be blank. The
generated tests pass as generated, except the handler tests, which are
marked incomplete until the handler does something. The HTTP test boots
`App\Bootstrap::builder()`, the skeletons' convention. When the code needs
a provider that `src/Bootstrap.php` does not register, or a package that is
not installed, the command says so.

Every `make:` command takes `--format=json` (with or without `--dry-run`):

```json
{"schema": "zana.make/1", "status": "ok", "dry_run": true,
 "files": [{"action": "create", "path": "modules/Library/…"}],
 "wiring": [{"method": "register", "line": "$services->scoped(…);"}],
 "manual_steps": []}
```

On an error, `status` is `error`, `error` holds the message and the exit
code is 1.

A whole feature from one file (2.11): `make:plan library.json [--dry-run]`.

```json
{
    "schema": "zana.plan/1",
    "module": "Library",
    "entities": ["Book"],
    "value_objects": [{"name": "BookTitle", "type": "string"}],
    "repositories": ["Book"],
    "features": [
        {"name": "AddBook", "http": true, "fields": ["title:string", "pages:?int"]},
        {"name": "ListBooks", "query": true, "http": true}
    ]
}
```

Each entry is one `make:` command (`make:module` when the module does not
exist yet; `"web": true` as `--web`), run in that order, and the files are
exactly what those commands write. The plan runs in a temporary copy first:
nothing is written unless every step succeeds. Its JSON report adds the
equivalent `commands` and the `steps`. Unknown keys are errors.

`check` warns (`agents.instructions`, 2.11) when `AGENTS.md` is missing or
names another Zana version than the installed one; `make:agents` writes the
commands with the console script it was run with, e.g. `php bin/zana`.

For websites:

```
php bin/zana make:module Site --web                    # plus Presentation/Web, Resources/views, Resources/translations
php bin/zana make:controller Site News --web           # Presentation/Web/NewsController returning a View, and its template
php bin/zana make:page Site About --path=en:/about --path=fr:/a-propos   # a localised page with SEO metadata and its template
php bin/zana make:crud Blog Post --translatable        # back-office screens for posts in every locale
```

`make:crud` generates a back office that works as soon as it is written.
It needs `mj-zana/view`, `session`, `security` (with a
`PasswordUserProviderInterface`) and `database`.

- **Status:** a draft, published or archived enum.
- **Storage:** a migration with a translations table (one row per locale,
  with a slug), and a repository.
- **Screens:** list, create and edit, with one fieldset per locale. Publish,
  archive and delete are POST requests with CSRF, and delete has a
  confirmation step.
- **Preview:** drafts get a signed preview link, valid for an hour.
- **Access:** the screens are behind session login and the `<items>.edit`
  permission (e.g. `posts.edit`).

- **Wiring:** a generated module works straight away.
  - `make:module` writes two marker comments into the module class, one in
    `register()` and one in `boot()`.
  - `make:feature` adds lines above them that register the handler and map
    it in the `HandlerMap`.
  - `make:controller` adds lines that register the controller and load its
    `#[Route]` attributes.
  - Generating the same thing twice wires it once. Keep the marker lines.
  - A module class without markers, such as one written by hand, is left
    alone. The command prints the lines to add instead.
- **Names:** module and class names are StudlyCase.
- **`config/modules.php`:** `make:module` adds the module there, creating
  the file if needed. The file must only return a list of `::class`
  entries. Comments are kept.
- **Existing files:** they are never replaced. The command lists them and
  writes nothing. Add `--force` to replace them, or `--dry-run` to see what
  would be written.

## Stubs

The built-in stubs live in this package's `stubs/` folder. Copy one to
`<project>/stubs/<name>.stub` to change it. Placeholders look like
`{{ name }}`, and a placeholder the generator does not know is an error.

| Stub | Placeholders |
|---|---|
| `module` | `name`, `module`, `namespace` |
| `command`, `query`, `command-handler`, `query-handler` | `module`, `name`, `namespace`, `message`; `command` and `query` also `imports` and `constructor` (2.8) |
| `handler-test` | the above, plus `featureNamespace` and `arguments` (2.8) |
| `api-input`, `api-command-action`, `api-query-action` | `module`, `name`, `namespace`, `message`, `featureNamespace`, `path`, `route` |
| `api-test` | the above, plus `request` and `invalid` |
| `entity`, `entity-id`, `repository` | `module`, `name`, `namespace` |
| `value-object`, `value-object-test` | `module`, `name`, `namespace`, `label`, `type`, `rule`, `check`; the test also `domainNamespace`, `valid`, `other`, `invalid` |
| `database-repository`, `repository-migration` | `module`, `name`, `namespace`, `table`, `label` |
| `controller` | `module`, `name`, `namespace`, `path`, `route` |

A `command` or `query` stub copied before 2.8 still works, without the
fields. Stubs are read with LF line endings, so generated files are the
same on every system.

The generated code uses `mj-zana/ddd`, `mj-zana/mediator` and
`mj-zana/routing`; HTTP use cases also `mj-zana/serialization`,
`mj-zana/validation` and `mj-zana/testing`, and repositories
`mj-zana/database`. Install the ones your modules need.

## License

MIT — see [LICENSE](LICENSE).
