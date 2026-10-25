<?php

declare(strict_types=1);

namespace Zana\Maker\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Core\Application;
use Zana\Maker\Generator;
use Zana\Maker\MakerServiceProvider;

final class CommandsTest extends TestCase
{
    private string $base = '';

    private ConsoleKernel $kernel;

    protected function setUp(): void
    {
        $this->base = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-maker-app-' . bin2hex(random_bytes(4));
        mkdir($this->base);
        $container = Application::configure($this->base)
            ->withProviders(ConsoleServiceProvider::class, MakerServiceProvider::class)
            ->build()
            ->container();
        $this->assertSame($this->base, $container->get(Generator::class)->basePath());
        $this->kernel = $container->get(ConsoleKernel::class);
    }

    #[After]
    public function removeBase(): void
    {
        exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' . escapeshellarg(str_replace('/', '\\', $this->base)) : 'rm -rf ' . escapeshellarg($this->base));
    }

    public function testTheCommands(): void
    {
        [$code, $output] = $this->execute(['command' => 'make:module', 'name' => 'Patients', '--dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Dry run; nothing was written.', $output);
        $this->assertStringContainsString('would create modules/Patients/PatientsModule.php', $output);
        $this->assertStringContainsString('would update config/modules.php', $output);
        $this->assertDirectoryDoesNotExist("{$this->base}/modules");

        [$code, $output] = $this->execute(['command' => 'make:module', 'name' => 'Patients']);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('created modules/Patients/PatientsModule.php', $output);
        $this->assertStringContainsString('updated config/modules.php', $output);
        $this->assertStringContainsString('Generated 8 files.', $output);

        [$code, $output] = $this->execute(['command' => 'make:feature', 'module' => 'Patients', 'name' => 'FindPatient', '--query' => true]);
        $this->assertSame(0, $code, $output);
        $this->assertFileExists("{$this->base}/modules/Patients/Application/Features/FindPatient/FindPatientQuery.php");

        [$code] = $this->execute(['command' => 'make:entity', 'module' => 'Patients', 'name' => 'Patient']);
        $this->assertSame(0, $code);
        [$code, $output] = $this->execute(['command' => 'make:controller', 'module' => 'Patients', 'name' => 'Patients']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('updated modules/Patients/PatientsModule.php', $output, 'the controller is wired into the module');
        $this->assertStringContainsString('Generated 2 files.', $output);

        [$code, $output] = $this->execute(['command' => 'make:entity', 'module' => 'Patients', 'name' => 'Patient']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('use --force to replace them', $output);

        [$code, $output] = $this->execute(['command' => 'make:entity', 'module' => 'Patients', 'name' => 'Patient', '--dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('would replace modules/Patients/Domain/Patient.php', $output);

        [$code, $output] = $this->execute(['command' => 'make:entity', 'module' => 'Patients', 'name' => 'Patient', '--force' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('replaced modules/Patients/Domain/Patient.php', $output);

        [$code, $output] = $this->execute(['command' => 'make:module', 'name' => 'bad name']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('must be StudlyCase', $output);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function execute(array $input): array
    {
        $output = new BufferedOutput();
        $code = $this->kernel->run(new ArrayInput($input), $output);

        return [$code, (string) preg_replace('/\s+/', ' ', $output->fetch())];
    }
}
