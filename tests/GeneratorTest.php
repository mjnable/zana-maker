<?php

declare(strict_types=1);

namespace Zana\Maker\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Zana\Maker\GeneratedFile;
use Zana\Maker\Generator;
use Zana\Maker\MakerException;
use Zana\Maker\ModuleList;
use Zana\Maker\Stub;

final class GeneratorTest extends TestCase
{
    private string $base = '';

    private Generator $generator;

    protected function setUp(): void
    {
        $this->base = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-maker-' . bin2hex(random_bytes(4));
        mkdir($this->base);
        $this->generator = new Generator($this->base);
    }

    #[After]
    public function removeBase(): void
    {
        self::remove($this->base);
    }

    public function testMakeModule(): void
    {
        $plan = $this->generator->planModule('Patients');
        $this->assertSame([
            'modules/Patients/PatientsModule.php',
            'modules/Patients/Contracts/.gitkeep',
            'modules/Patients/Domain/.gitkeep',
            'modules/Patients/Application/.gitkeep',
            'modules/Patients/Infrastructure/.gitkeep',
            'modules/Patients/Presentation/.gitkeep',
            'modules/Patients/Tests/.gitkeep',
            'config/modules.php',
        ], self::paths($plan));
        $this->assertFileDoesNotExist("{$this->base}/modules", 'planning writes nothing');

        $this->generator->write($plan);

        $module = $this->read('modules/Patients/PatientsModule.php');
        $this->assertStringContainsString('namespace Modules\Patients;', $module);
        $this->assertStringContainsString('final class PatientsModule extends Module', $module);
        $this->assertStringContainsString("return 'Patients';", $module);
        $this->assertDirectoryExists("{$this->base}/modules/Patients/Domain");
        $this->assertCompiles('modules/Patients/PatientsModule.php');

        $this->assertSame(['Modules\Patients\PatientsModule'], new ModuleList("{$this->base}/config/modules.php")->classes());
        $this->assertCompiles('config/modules.php');
    }

    public function testModulesAreAddedToAnExistingList(): void
    {
        mkdir("{$this->base}/config");
        file_put_contents("{$this->base}/config/modules.php", "<?php\n\n// Enabled modules, in order.\nreturn [\n    \\Modules\\Billing\\BillingModule::class // no trailing comma\n];\n");

        $this->generator->write($this->generator->planModule('Patients'));
        $this->generator->write($this->generator->planModule('Clinics'));

        $list = new ModuleList("{$this->base}/config/modules.php");
        $this->assertSame(['Modules\Billing\BillingModule', 'Modules\Patients\PatientsModule', 'Modules\Clinics\ClinicsModule'], $list->classes());
        $this->assertStringContainsString('// Enabled modules, in order.', $this->read('config/modules.php'), 'comments are kept');
        $this->assertCompiles('config/modules.php');

        $this->assertSame(
            ['modules/Patients/PatientsModule.php'],
            array_slice(self::paths($this->generator->planModule('Patients')), 0, 1),
        );
        $this->assertNotContains('config/modules.php', self::paths($this->generator->planModule('Patients')), 'an enabled module is not listed twice');
    }

    public function testEmptyAndDeclaredLists(): void
    {
        mkdir("{$this->base}/config");
        file_put_contents("{$this->base}/config/modules.php", "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");
        $this->generator->write($this->generator->planModule('Patients'));

        $this->assertSame(['Modules\Patients\PatientsModule'], new ModuleList("{$this->base}/config/modules.php")->classes());
        $this->assertCompiles('config/modules.php');
    }

    public function testInvalidModuleListsAreRefused(): void
    {
        mkdir("{$this->base}/config");
        foreach ([
            "<?php return array_merge([], []);",
            "<?php\n\$modules = [];\nreturn \$modules;",
            "<?php return ['Modules\\\\Patients\\\\PatientsModule'];",
            "<?php return [A::class B::class];",
            "<?php return [A::class]; echo 1;",
            'not php',
        ] as $contents) {
            file_put_contents("{$this->base}/config/modules.php", $contents);
            try {
                $this->generator->planModule('Patients');
                $this->fail("The list {$contents} must be refused.");
            } catch (MakerException $e) {
                $this->assertStringContainsString('must only return a list of Module::class entries', $e->getMessage());
            }
        }
    }

    public function testMakeFeature(): void
    {
        $this->generator->write($this->generator->planModule('Patients'));

        $command = $this->generator->planFeature('Patients', 'RegisterPatient');
        $this->assertSame([
            'modules/Patients/Application/Features/RegisterPatient/RegisterPatientCommand.php',
            'modules/Patients/Application/Features/RegisterPatient/RegisterPatientHandler.php',
            'modules/Patients/Tests/Application/RegisterPatientHandlerTest.php',
            'modules/Patients/PatientsModule.php',
        ], self::paths($command));
        $this->assertTrue($command[3]->update, 'the module class is updated, not replaced');
        $this->generator->write($command);
        $this->assertStringContainsString('namespace Modules\Patients\Application\Features\RegisterPatient;', $command[0]->contents);
        $this->assertStringContainsString('final class RegisterPatientCommand extends Command', $command[0]->contents);
        $this->assertStringContainsString('#[Handles(RegisterPatientCommand::class)]', $command[1]->contents);
        $this->assertStringContainsString('namespace Modules\Patients\Tests\Application;', $command[2]->contents);
        $this->assertStringContainsString('use Modules\Patients\Application\Features\RegisterPatient\RegisterPatientHandler;', $command[2]->contents);

        $query = $this->generator->planFeature('Patients', 'FindPatient', query: true);
        $this->assertSame('modules/Patients/Application/Features/FindPatient/FindPatientQuery.php', $query[0]->relativePath);
        $this->assertStringContainsString('extends Query', $query[0]->contents);
        $this->assertStringContainsString('#[Handles(FindPatientQuery::class)]', $query[1]->contents);
        $this->generator->write($query);

        foreach ([...$command, ...$query] as $file) {
            $this->assertCompiles($file->relativePath);
        }
    }

    public function testFeatureFields(): void
    {
        $this->generator->write($this->generator->planModule('Library'));

        $feature = $this->generator->planFeature('Library', 'AddBook', fields: ['pages:?int', 'title', 'price:float', 'signed:bool']);
        $this->assertStringContainsString("use Zana\\Ddd\\Application\\Command;\nuse Zana\\Validation\\Constraint\\NotBlank;", $feature[0]->contents);
        $this->assertStringContainsString(
            "public function __construct(\n        #[NotBlank]\n        public readonly string \$title,\n        public readonly float \$price,\n        public readonly bool \$signed,\n        public readonly ?int \$pages = null,\n    ) {",
            $feature[0]->contents,
            'required fields first; required text must not be blank',
        );
        $this->assertStringContainsString("new AddBookCommand(title: 'Example', price: 1.5, signed: true)", $feature[2]->contents);
        $this->generator->write($feature);
        $this->assertCompiles($feature[0]->relativePath);

        foreach (['Title:string', 'title:text', 'title:', 'title:string:x'] as $invalid) {
            try {
                $this->generator->planFeature('Library', 'Other', fields: [$invalid]);
                $this->fail("The field {$invalid} must be refused.");
            } catch (MakerException $e) {
                $this->assertStringContainsString('must be name:type', $e->getMessage());
            }
        }
        $this->expectExceptionMessage('The field "title" is given twice.');
        $this->generator->planFeature('Library', 'Other', fields: ['title', 'title:int']);
    }

    public function testMissingProvidersBecomeManualSteps(): void
    {
        $this->generator->write($this->generator->planModule('Library'));
        mkdir("{$this->base}/src");
        file_put_contents("{$this->base}/src/Bootstrap.php", "<?php // ->withProviders(MediatorServiceProvider::class, SerializationServiceProvider::class)");

        $this->generator->planFeature('Library', 'AddBook', fields: ['title'], http: true);
        $this->assertSame(['Register ValidationServiceProvider in src/Bootstrap.php: ->withProviders(..., \Zana\Validation\ValidationServiceProvider::class).'], $this->generator->manualSteps());

        $this->generator->write($this->generator->planEntity('Library', 'Book'));
        $this->generator->planRepository('Library', 'Book');
        $this->assertStringContainsString('Register DatabaseServiceProvider', $this->generator->manualSteps()[0]);
    }

    public function testValueObjects(): void
    {
        $this->generator->write($this->generator->planModule('Library'));

        $text = $this->generator->planValueObject('Library', 'BookTitle');
        $this->assertSame(['modules/Library/Domain/BookTitle.php', 'modules/Library/Tests/Domain/BookTitleTest.php'], self::paths($text));
        $this->assertStringContainsString('final readonly class BookTitle extends ValueObject', $text[0]->contents);
        $this->assertStringContainsString("throw new DomainException('Book title must not be empty.');", $text[0]->contents);
        $this->assertStringContainsString("new BookTitle('  ');", $text[1]->contents);

        $count = $this->generator->planValueObject('Library', 'PageCount', 'int');
        $this->assertStringContainsString('public int $value;', $count[0]->contents);
        $this->assertStringContainsString('if ($value < 0) {', $count[0]->contents);
        $this->generator->write([...$text, ...$count]);
        foreach ([...$text, ...$count] as $file) {
            $this->assertCompiles($file->relativePath);
        }

        $this->expectExceptionMessage('The value type "bool" must be string, int or float.');
        $this->generator->planValueObject('Library', 'Flag', 'bool');
    }

    public function testRepositories(): void
    {
        $this->generator->write($this->generator->planModule('Library'));
        try {
            $this->generator->planRepository('Library', 'Book');
            $this->fail('A repository needs its entity.');
        } catch (MakerException $e) {
            $this->assertStringContainsString('create the entity with make:entity Library Book', $e->getMessage());
        }

        $this->generator->write($this->generator->planEntity('Library', 'Book'));
        $plan = $this->generator->planRepository('Library', 'Book');
        $this->assertSame('modules/Library/Infrastructure/Persistence/DatabaseBookRepository.php', $plan[0]->relativePath);
        $this->assertMatchesRegularExpression('#^modules/Library/Infrastructure/Persistence/Migrations/\d{4}_\d{2}_\d{2}_\d{6}_create_books_table\.php$#', $plan[1]->relativePath);
        $this->assertSame([['method' => 'register', 'line' => '$services->scoped(\Modules\Library\Domain\BookRepositoryInterface::class, \Modules\Library\Infrastructure\Persistence\DatabaseBookRepository::class);']], $this->generator->wiring());
        $this->assertSame([], $this->generator->manualSteps());
        $this->generator->write($plan);
        foreach ($plan as $file) {
            $this->assertCompiles($file->relativePath);
        }
        $this->assertSame($plan[1]->relativePath, $this->generator->planRepository('Library', 'Book')[1]->relativePath, 'generating again reuses the migration');

        // An entity written before 2.8 has no restore().
        file_put_contents("{$this->base}/modules/Library/Domain/Book.php", str_replace('function restore(', 'function rebuild(', $this->read('modules/Library/Domain/Book.php')));
        $this->generator->planRepository('Library', 'Book');
        $this->assertStringContainsString('Add Book::restore(BookId $id, int $version): self', $this->generator->manualSteps()[0]);
    }

    public function testMakeEntityAndController(): void
    {
        $this->generator->write($this->generator->planModule('Patients'));

        $entity = $this->generator->planEntity('Patients', 'Patient');
        $this->assertSame(['modules/Patients/Domain/Patient.php', 'modules/Patients/Domain/PatientId.php', 'modules/Patients/Domain/PatientRepositoryInterface.php'], self::paths($entity));
        $this->assertStringContainsString('final class Patient extends AggregateRoot', $entity[0]->contents);
        $this->assertStringContainsString('final readonly class PatientId extends Identity', $entity[1]->contents);
        $this->assertStringContainsString('@extends RepositoryInterface<Patient>', $entity[2]->contents);

        $controller = $this->generator->planController('Patients', 'MedicalRecords');
        $this->assertSame(['modules/Patients/Presentation/Api/MedicalRecordsController.php', 'modules/Patients/PatientsModule.php'], self::paths($controller));
        $this->assertStringContainsString('namespace Modules\Patients\Presentation\Api;', $controller[0]->contents);
        $this->assertStringContainsString("#[Route('/api/medical-records')]", $controller[0]->contents);
        $this->assertStringContainsString("name: 'patients.medical-records.index'", $controller[0]->contents);

        $this->generator->write([...$entity, ...$controller]);
        foreach ([...$entity, ...$controller] as $file) {
            $this->assertCompiles($file->relativePath);
        }
    }

    public function testFeaturesAndControllersAreWiredIntoTheModule(): void
    {
        $this->generator->write($this->generator->planModule('Patients'));
        $this->generator->write($this->generator->planFeature('Patients', 'RegisterPatient'));
        $this->generator->write($this->generator->planController('Patients', 'Patients'));
        $this->assertSame([], $this->generator->manualSteps());

        $module = $this->read('modules/Patients/PatientsModule.php');
        $this->assertStringContainsString(
            "        \$services->scoped(\\Modules\\Patients\\Application\\Features\\RegisterPatient\\RegisterPatientHandler::class);\n"
            . "        \$services->transient(\\Modules\\Patients\\Presentation\\Api\\PatientsController::class);\n"
            . '        ' . Generator::SERVICES_MARKER,
            $module,
            'lines go above the marker, with its indentation, in generation order',
        );
        $this->assertStringContainsString("\$container->get(\\Zana\\Mediator\\HandlerMap::class)->register(\\Modules\\Patients\\Application\\Features\\RegisterPatient\\RegisterPatientHandler::class);", $module);
        $this->assertStringContainsString("\$container->get(\\Zana\\Routing\\Router::class)->loadAttributes(\\Modules\\Patients\\Presentation\\Api\\PatientsController::class);", $module);
        $this->assertCompiles('modules/Patients/PatientsModule.php');

        $again = $this->generator->planFeature('Patients', 'RegisterPatient');
        $this->assertNotContains('modules/Patients/PatientsModule.php', self::paths($again), 'already wired: the module is left alone');
    }

    public function testModulesWithoutMarkersGetManualSteps(): void
    {
        $this->generator->write($this->generator->planModule('Patients'));
        $path = "{$this->base}/modules/Patients/PatientsModule.php";
        file_put_contents($path, str_replace([Generator::SERVICES_MARKER, Generator::BOOT_MARKER], '', (string) file_get_contents($path)));

        $plan = $this->generator->planFeature('Patients', 'FindPatient', query: true);

        $this->assertNotContains('modules/Patients/PatientsModule.php', self::paths($plan));
        $this->assertSame([
            'In PatientsModule::register(): $services->scoped(\Modules\Patients\Application\Features\FindPatient\FindPatientHandler::class);',
            'In PatientsModule::boot(): $container->get(\Zana\Mediator\HandlerMap::class)->register(\Modules\Patients\Application\Features\FindPatient\FindPatientHandler::class);',
        ], $this->generator->manualSteps());

        $this->generator->planEntity('Patients', 'Patient');
        $this->assertSame([], $this->generator->manualSteps(), 'each plan starts afresh');
    }

    public function testNamesAreValidated(): void
    {
        $this->generator->write($this->generator->planModule('Patients'));

        foreach ([
            fn () => $this->generator->planModule('patients'),
            fn () => $this->generator->planModule('Patient-Records'),
            fn () => $this->generator->planModule(''),
            fn () => $this->generator->planFeature('Patients', '1Register'),
            fn () => $this->generator->planEntity('Patients', 'Patient_Id'),
            fn () => $this->generator->planController('Patients', '../Evil'),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid names must be rejected.');
            } catch (MakerException $e) {
                $this->assertStringContainsString('must be StudlyCase', $e->getMessage());
            }
        }

        $this->expectException(MakerException::class);
        $this->expectExceptionMessage('There is no module "Billing"; create it with make:module Billing.');
        $this->generator->planEntity('Billing', 'Invoice');
    }

    public function testExistingFilesAreNeverOverwrittenWithoutForce(): void
    {
        $this->generator->write($this->generator->planModule('Patients'));
        $this->generator->write($this->generator->planEntity('Patients', 'Patient'));
        file_put_contents("{$this->base}/modules/Patients/Domain/Patient.php", '<?php // edited');
        unlink("{$this->base}/modules/Patients/Domain/PatientId.php");

        try {
            $this->generator->write($this->generator->planEntity('Patients', 'Patient'));
            $this->fail('Existing files must not be replaced.');
        } catch (MakerException $e) {
            $this->assertStringContainsString("modules/Patients/Domain/Patient.php\n  modules/Patients/Domain/PatientRepositoryInterface.php", $e->getMessage());
        }
        $this->assertSame('<?php // edited', $this->read('modules/Patients/Domain/Patient.php'));
        $this->assertFileDoesNotExist("{$this->base}/modules/Patients/Domain/PatientId.php", 'nothing is written');

        $this->generator->write($this->generator->planEntity('Patients', 'Patient'), force: true);
        $this->assertStringContainsString('extends AggregateRoot', $this->read('modules/Patients/Domain/Patient.php'));
        $this->assertFileExists("{$this->base}/modules/Patients/Domain/PatientId.php");
    }

    public function testStubOverridesAndUnknownPlaceholders(): void
    {
        mkdir("{$this->base}/stubs");
        file_put_contents("{$this->base}/stubs/entity-id.stub", "<?php\n\nnamespace {{ namespace }};\n\n// custom\nfinal readonly class {{name}}Id extends \\App\\Shared\\Id {}\n");
        $this->generator->write($this->generator->planModule('Patients'));

        $entity = $this->generator->planEntity('Patients', 'Patient');
        $this->assertStringContainsString("// custom\nfinal readonly class PatientId extends \\App\\Shared\\Id {}", $entity[1]->contents);
        $this->assertStringContainsString('extends AggregateRoot', $entity[0]->contents, 'other stubs stay built in');

        file_put_contents("{$this->base}/stubs/entity.stub", 'class {{ name }} {{ author }} {{ year }} {{ author }}');
        try {
            $this->generator->planEntity('Patients', 'Patient');
            $this->fail('Unknown placeholders must be reported.');
        } catch (MakerException $e) {
            $this->assertSame('The stub "entity" uses unknown placeholders: author, year.', $e->getMessage());
        }

        $this->assertSame('a-b {{', new Stub('x', '{{ one }}-{{two}} {{')->render(['one' => 'a', 'two' => 'b']));
        $this->expectException(MakerException::class);
        Stub::load('missing', $this->base);
    }

    /**
     * @param list<GeneratedFile> $files
     *
     * @return list<string>
     */
    private static function paths(array $files): array
    {
        return array_map(static fn (GeneratedFile $file): string => $file->relativePath, $files);
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents("{$this->base}/{$relative}");
    }

    private function assertCompiles(string $relative): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("{$this->base}/{$relative}") . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, "{$relative} does not compile:\n" . implode("\n", $output));
    }

    private static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                is_dir("{$path}/{$item}") ? self::remove("{$path}/{$item}") : unlink("{$path}/{$item}");
            }
        }
        rmdir($path);
    }
}
