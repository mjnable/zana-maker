<?php

declare(strict_types=1);

namespace Zana\Maker\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Core\Application;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Maker\Generator;
use Zana\Maker\Inspection\AgentsCheck;
use Zana\Maker\MakerException;
use Zana\Maker\MakerServiceProvider;
use Zana\Maker\Plan;
use Zana\Maker\PlanRunner;

/**
 * FE-51 and FE-52 (ADR-026): a plan file generates exactly what its make:
 * commands generate, all or nothing; check warns about AGENTS.md.
 */
final class PlanTest extends TestCase
{
    private const string PLAN = <<<'JSON'
        {
            "schema": "zana.plan/1",
            "module": "Library",
            "entities": ["Book"],
            "value_objects": ["BookTitle", {"name": "PageCount", "type": "int"}],
            "repositories": ["Book"],
            "features": [
                {"name": "AddBook", "http": true, "fields": ["title:string", "pages:?int"]},
                {"name": "ListBooks", "query": true, "http": true}
            ]
        }
        JSON;

    /** @var list<string> */
    private array $folders = [];

    #[Before]
    public function noFolders(): void
    {
        $this->folders = [];
    }

    #[After]
    public function removeFolders(): void
    {
        foreach ($this->folders as $folder) {
            exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' . escapeshellarg(str_replace('/', '\\', $folder)) : 'rm -rf ' . escapeshellarg($folder));
        }
    }

    public function testAPlanGeneratesWhatItsCommandsGenerate(): void
    {
        $plan = Plan::fromJson(self::PLAN);
        $this->assertSame([
            'make:module Library',
            'make:entity Library Book',
            'make:value-object Library BookTitle',
            'make:value-object Library PageCount --type=int',
            'make:repository Library Book',
            'make:feature Library AddBook --field=title:string --field=pages:?int --http',
            'make:feature Library ListBooks --query --http',
        ], $plan->commands());

        $byCommands = $this->folder();
        $console = $this->console($byCommands);
        foreach ($plan->commands() as $command) {
            $this->assertSame(0, $console->run(new ArrayInput(self::arguments($command)), new BufferedOutput()), $command);
        }

        $byPlan = $this->folder();
        $steps = (new PlanRunner($byPlan))->run($plan);

        $this->assertSame($plan->commands(), array_column($steps, 'command'));
        $this->assertSame(self::files($byCommands), self::files($byPlan), 'the same files, byte for byte (migration timestamps aside)');
    }

    public function testAnExistingModuleIsNotCreatedAgain(): void
    {
        $base = $this->folder();
        (new Generator($base))->write((new Generator($base))->planModule('Library'));

        $steps = (new PlanRunner($base))->run(Plan::fromJson('{"schema": "zana.plan/1", "module": "Library", "entities": ["Book"]}'));

        $this->assertSame(['make:entity Library Book'], array_column($steps, 'command'));
    }

    public function testADryRunAndAFailingPlanWriteNothing(): void
    {
        $base = $this->folder();
        $steps = (new PlanRunner($base))->run(Plan::fromJson(self::PLAN), dryRun: true);
        $this->assertSame('create', $steps[0]['files'][0]['action']);
        $this->assertSame(['config', 'var'], self::entries($base), 'a dry run writes nothing');

        // The repository step fails: there is no entity Shelf. The earlier steps are not written either.
        try {
            (new PlanRunner($base))->run(Plan::fromJson('{"schema": "zana.plan/1", "module": "Library", "entities": ["Book"], "repositories": ["Shelf"]}'));
            $this->fail('The plan must fail.');
        } catch (MakerException $e) {
            $this->assertStringStartsWith('make:repository Library Shelf: There is no modules/Library/Domain/Shelf.php', $e->getMessage());
        }
        $this->assertSame(['config', 'var'], self::entries($base), 'a plan never half-applies');
        $this->assertDirectoryDoesNotExist("{$base}/modules");
    }

    public function testInvalidPlansListEveryProblem(): void
    {
        try {
            Plan::fromJson('{"schema": "zana.plan/2", "modul": "Library", "features": [{"name": "AddBook", "feilds": []}, {"http": true}]}');
            $this->fail('The plan is invalid.');
        } catch (MakerException $e) {
            foreach ([
                '"modul" is not a plan key',
                '"schema" must be "zana.plan/1"',
                '"module" must name the module',
                '"features"[0]: "feilds" is not a feature key',
                '"features"[1] must be an object with a "name"',
            ] as $problem) {
                $this->assertStringContainsString($problem, $e->getMessage());
            }
        }

        $this->expectExceptionMessage('The plan is not valid JSON');
        Plan::fromJson('{"schema":');
    }

    public function testTheCommandReportsThePlanAsJson(): void
    {
        $base = $this->folder();
        file_put_contents("{$base}/library.json", self::PLAN);
        $output = new BufferedOutput();

        $code = $this->console($base)->run(new ArrayInput(['command' => 'make:plan', 'file' => 'library.json', '--dry-run' => true, '--format' => 'json']), $output);
        $report = json_decode($output->fetch(), true);

        $this->assertSame(0, $code);
        $this->assertIsArray($report);
        $this->assertSame(['zana.make/1', 'ok', true], [$report['schema'], $report['status'], $report['dry_run']]);
        $this->assertCount(7, $report['commands']);
        $this->assertContains(['action' => 'create', 'path' => 'modules/Library/Presentation/Api/AddBookAction.php'], $report['files']);
        $this->assertDirectoryDoesNotExist("{$base}/modules");
    }

    public function testCheckWarnsAboutMissingOrOutdatedAgentInstructions(): void
    {
        $base = $this->folder();
        $app = Application::configure($base)->withEnvironment('testing')->withProviders(ConsoleServiceProvider::class, MakerServiceProvider::class)->build();
        $check = array_values(array_filter($app->container()->tagged(ApplicationCheck::TAG), static fn (object $c): bool => $c instanceof AgentsCheck))[0] ?? null;
        $this->assertInstanceOf(AgentsCheck::class, $check);

        $missing = $check->findings($app);
        $this->assertSame(['agents.instructions', 'There is no AGENTS.md, the instructions for AI coding agents.'], [$missing[0]->id, $missing[0]->message]);

        $generator = new Generator($base);
        $generator->write($generator->planAgents('2.0'));
        $this->assertStringContainsString('does not name the installed Zana', $check->findings($app)[0]->message);

        $this->assertSame(1, preg_match('/^v?(\d+\.\d+)/', (string) \Composer\InstalledVersions::getPrettyVersion('mj-zana/core'), $installed));
        $generator->write($generator->planAgents($installed[1] ?? ''), force: true);
        $this->assertSame([], $check->findings($app));
    }

    private function console(string $base): ConsoleKernel
    {
        $kernel = Application::configure($base)->withProviders(ConsoleServiceProvider::class, MakerServiceProvider::class)->build()->container()->get(ConsoleKernel::class);
        assert($kernel instanceof ConsoleKernel);

        return $kernel;
    }

    /**
     * "make:feature Library AddBook --field=a --http" as console input.
     *
     * @return array<string, mixed>
     */
    private static function arguments(string $command): array
    {
        $words = explode(' ', $command);
        $names = ['make:module' => ['name'], 'make:feature' => ['module', 'name'], 'make:entity' => ['module', 'name'], 'make:value-object' => ['module', 'name'], 'make:repository' => ['module', 'entity']][$words[0]];
        $arguments = ['command' => $words[0]];
        $fields = [];
        foreach (array_slice($words, 1) as $i => $word) {
            if (!str_starts_with($word, '--')) {
                $arguments[$names[$i]] = $word;
            } elseif (str_starts_with($word, '--field=')) {
                $fields[] = substr($word, strlen('--field='));
            } elseif (str_contains($word, '=')) {
                [$option, $value] = explode('=', $word, 2);
                $arguments[$option] = $value;
            } else {
                $arguments[$word] = true;
            }
        }

        return $fields === [] ? $arguments : [...$arguments, '--field' => $fields];
    }

    private function folder(): string
    {
        $folder = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-maker-plan-' . bin2hex(random_bytes(4));
        mkdir("{$folder}/var", 0777, true);
        mkdir("{$folder}/config");
        $this->folders[] = $folder;

        return $folder;
    }

    /**
     * @return list<string>
     */
    private static function entries(string $base): array
    {
        return array_values(array_diff(scandir($base) ?: [], ['.', '..']));
    }

    /**
     * @return array<string, string>
     */
    private static function files(string $base): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$base}/modules", \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($base) + 1);
            $files[(string) preg_replace('/\d{4}_\d{2}_\d{2}_\d{6}_/', '', $relative)] = (string) file_get_contents($file->getPathname());
        }
        $files['config/modules.php'] = (string) file_get_contents("{$base}/config/modules.php");
        ksort($files);

        return $files;
    }
}
