# Zana Maker

Code generators that follow the Zana module layout. Conventions produce
plain, explicit code you own, not runtime magic. Requires PHP 8.5 or
later.

```
composer require --dev mj-zana/maker
```

Register `MakerServiceProvider` (with `ConsoleServiceProvider`). Generated
classes use the namespace `Modules\<Module>` under `modules/<Module>/`, so
map it in your `composer.json`:

```json
"autoload": { "psr-4": { "Modules\\": "modules/" } }
```

## Commands

```
php bin/zana make:module Patients                      # PatientsModule, its folders, and config/modules.php
php bin/zana make:feature Patients RegisterPatient     # command, #[Handles] handler, handler test
php bin/zana make:feature Patients FindPatient --query # query instead of command
php bin/zana make:entity Patients Patient              # aggregate root, PatientId, repository interface
php bin/zana make:controller Patients Measurements     # Presentation/Api/MeasurementsController with #[Route]
```

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
| `command`, `query`, `command-handler`, `query-handler` | `module`, `name`, `namespace`, `message` |
| `handler-test` | the above, plus `featureNamespace` |
| `entity`, `entity-id`, `repository` | `module`, `name`, `namespace` |
| `controller` | `module`, `name`, `namespace`, `path`, `route` |

The generated code uses `mj-zana/ddd`, `mj-zana/mediator` and
`mj-zana/routing`. Install the ones your modules need.

## License

MIT — see [LICENSE](LICENSE).
