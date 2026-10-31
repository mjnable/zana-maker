<?php

declare(strict_types=1);

namespace Zana\Maker\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Zana\Maker\GeneratedFile;
use Zana\Maker\Generator;
use Zana\Maker\MakerException;

/**
 * FE-44: AGENTS.md holds the rules for AI coding agents, linked to the
 * documentation of the installed Zana version; the other files only point to it.
 */
final class AgentsTest extends TestCase
{
    private string $base = '';

    #[Before]
    public function createBase(): void
    {
        $this->base = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-maker-agents-' . bin2hex(random_bytes(4));
        mkdir($this->base);
    }

    #[After]
    public function removeBase(): void
    {
        exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' . escapeshellarg(str_replace('/', '\\', $this->base)) : 'rm -rf ' . escapeshellarg($this->base));
    }

    public function testTheInstructionsAndThePointers(): void
    {
        $files = (new Generator($this->base))->planAgents('2.7');

        $this->assertSame(['AGENTS.md', 'CLAUDE.md', '.github/copilot-instructions.md'], array_map(static fn (GeneratedFile $f): string => $f->relativePath, $files));
        $agents = $files[0]->contents;
        $this->assertStringContainsString('This is a Zana 2.7 application', $agents);
        $this->assertStringContainsString('https://github.com/mjnable/zana-docs/tree/2.7', $agents, 'links to the docs of this version');
        $this->assertStringContainsString('https://raw.githubusercontent.com/mjnable/zana-docs/2.7/llms.txt', $agents);
        foreach (['php bin/console inspect', 'php bin/console check', 'composer test', 'A singleton must not depend on a scoped service', '->uses(Y::class)'] as $rule) {
            $this->assertStringContainsString($rule, $agents);
        }
        $this->assertStringNotContainsString('{{', $agents);

        $this->assertSame("@AGENTS.md\n", $files[1]->contents, 'Claude Code imports AGENTS.md');
        $this->assertStringContainsString('Follow the instructions in AGENTS.md', $files[2]->contents);
        $this->assertStringNotContainsString('singleton', $files[2]->contents, 'pointers hold no rules of their own');
    }

    public function testAnEditedFileIsNotReplacedWithoutForce(): void
    {
        $generator = new Generator($this->base);
        $generator->write($generator->planAgents('2.7'));
        file_put_contents("{$this->base}/AGENTS.md", "\n## Our own rules\n", FILE_APPEND);

        try {
            $generator->write($generator->planAgents('2.8'));
            $this->fail('An existing AGENTS.md must not be replaced without --force.');
        } catch (MakerException $e) {
            $this->assertStringContainsString('AGENTS.md', $e->getMessage());
        }
        $this->assertStringContainsString('## Our own rules', (string) file_get_contents("{$this->base}/AGENTS.md"));

        $generator->write($generator->planAgents('2.8'), force: true);
        $this->assertStringContainsString('This is a Zana 2.8 application', (string) file_get_contents("{$this->base}/AGENTS.md"));
    }

    public function testTheVersionIsAMinorVersion(): void
    {
        $this->expectException(MakerException::class);

        (new Generator($this->base))->planAgents('dev-main');
    }
}
