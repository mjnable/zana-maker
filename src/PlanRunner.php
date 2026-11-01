<?php

declare(strict_types=1);

namespace Zana\Maker;

/**
 * Runs a Plan (FE-51, ADR-026): every step in a temporary copy of the files
 * it can touch (config/modules.php, the module, src/Bootstrap.php, stubs/),
 * then, when every step succeeded and this is not a dry run, writes the
 * results into the application. A plan therefore never half-applies.
 */
final readonly class PlanRunner
{
    public function __construct(private string $basePath)
    {
    }

    /**
     * @return list<array{command: string, files: list<array{action: string, path: string}>, wiring: list<array{method: string, line: string}>, manual_steps: list<string>}>
     *
     * @throws MakerException From the first step that fails; nothing is written then
     */
    public function run(Plan $plan, bool $dryRun = false, bool $force = false): array
    {
        $createModule = !is_file($this->path("modules/{$plan->module}/{$plan->module}Module.php"));
        $workspace = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-plan-' . bin2hex(random_bytes(6));
        $this->copy($workspace, $plan->module);

        try {
            $generator = new Generator($workspace);
            $commands = $plan->commands($createModule);
            $report = [];
            $touched = [];
            foreach ($plan->steps($createModule) as $i => $step) {
                try {
                    $files = $step($generator);
                    $actions = array_map(static fn (GeneratedFile $file): array => ['action' => match (true) {
                        $file->update => 'update',
                        $file->exists() => 'replace',
                        default => 'create',
                    }, 'path' => $file->relativePath], $files);
                    $generator->write($files, $force);
                } catch (MakerException $e) {
                    throw new MakerException("{$commands[$i]}: {$e->getMessage()}", previous: $e);
                }
                foreach ($files as $file) {
                    $touched[$file->relativePath] = true;
                }
                $report[] = ['command' => $commands[$i], 'files' => $actions, 'wiring' => $generator->wiring(), 'manual_steps' => $generator->manualSteps()];
            }

            if (!$dryRun) {
                foreach (array_keys($touched) as $relative) {
                    self::copyFile("{$workspace}/{$relative}", $this->path($relative));
                }
            }

            return $report;
        } finally {
            self::remove($workspace);
        }
    }

    /**
     * The files a plan for $module can read or change, copied into the workspace.
     */
    private function copy(string $workspace, string $module): void
    {
        mkdir($workspace, 0777, true);
        foreach (['config/modules.php', 'src/Bootstrap.php'] as $file) {
            if (is_file($this->path($file))) {
                self::copyFile($this->path($file), "{$workspace}/{$file}");
            }
        }
        foreach (["modules/{$module}", 'stubs'] as $folder) {
            $source = $this->path($folder);
            if (!is_dir($source)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = $folder . '/' . substr(str_replace('\\', '/', $file->getPathname()), strlen($source) + 1);
                self::copyFile($file->getPathname(), "{$workspace}/{$relative}");
            }
        }
    }

    private static function copyFile(string $from, string $to): void
    {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0777, true);
        }
        copy($from, $to);
    }

    private function path(string $relative): string
    {
        return rtrim(str_replace('\\', '/', $this->basePath), '/') . '/' . $relative;
    }

    private static function remove(string $folder): void
    {
        if (!is_dir($folder)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($folder);
    }
}
