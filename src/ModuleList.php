<?php

declare(strict_types=1);

namespace Zana\Maker;

/**
 * Reads and extends config/modules.php, which must return a list of
 * Module::class entries:
 *
 *     return [
 *         Modules\Patients\PatientsModule::class,
 *     ];
 *
 * New entries are inserted before the closing bracket, so comments and
 * formatting are kept.
 */
final class ModuleList
{
    public function __construct(private readonly string $file)
    {
    }

    /**
     * @return list<string> The listed classes, without a leading backslash
     */
    public function classes(): array
    {
        return is_file($this->file) ? $this->parse((string) file_get_contents($this->file))[0] : [];
    }

    /**
     * The file contents with $class listed (unchanged when it already is).
     */
    public function with(string $class): string
    {
        $class = ltrim($class, '\\');

        if (!is_file($this->file)) {
            return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    {$class}::class,\n];\n";
        }

        $code = (string) file_get_contents($this->file);
        [$classes, $closing, $commaAt] = $this->parse($code);
        if (in_array($class, $classes, true)) {
            return $code;
        }

        if ($commaAt !== null) {
            $code = substr($code, 0, $commaAt) . ',' . substr($code, $commaAt);
            $closing++;
        }

        return rtrim(substr($code, 0, $closing)) . "\n    {$class}::class,\n" . substr($code, $closing);
    }

    /**
     * @return array{list<string>, int, int|null} the classes, the offset of the closing "]",
     *                                            and where the last entry needs a comma (null when it has one)
     */
    private function parse(string $code): array
    {
        $tokens = [];
        $offset = 0;
        foreach (token_get_all($code) as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [$token, $token];
            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = [$id, $text, $offset];
            }
            $offset += strlen($text);
        }

        $i = 0;
        $expect = function (int|string ...$ids) use (&$i, $tokens): string {
            $token = $tokens[$i] ?? null;
            if ($token === null || !in_array($token[0], $ids, true)) {
                throw $this->invalid();
            }
            $i++;

            return $token[1];
        };
        $peek = static function () use (&$i, $tokens): int|string|null {
            return $tokens[$i][0] ?? null;
        };
        $offsetOf = static function () use (&$i, $tokens): int {
            return $tokens[$i][2] ?? 0;
        };

        $expect(T_OPEN_TAG);
        if ($peek() === T_DECLARE) {
            $expect(T_DECLARE);
            $expect('(');
            $expect(T_STRING);
            $expect('=');
            $expect(T_LNUMBER);
            $expect(')');
            $expect(';');
        }
        $expect(T_RETURN);
        $expect('[');

        $classes = [];
        $commaAt = null;
        while ($peek() !== ']') {
            $classes[] = ltrim($expect(T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_STRING), '\\');
            $expect(T_DOUBLE_COLON);
            $start = $offsetOf();
            $keyword = $expect(T_CLASS);
            if (strtolower($keyword) !== 'class') {
                throw $this->invalid();
            }
            $commaAt = $start + strlen($keyword);
            if ($peek() === ',') {
                $expect(',');
                $commaAt = null;
            } elseif ($peek() !== ']') {
                throw $this->invalid();
            }
        }

        $closing = $offsetOf();
        $expect(']');
        $expect(';');
        if (!in_array($peek(), [null, T_CLOSE_TAG], true)) {
            throw $this->invalid();
        }

        return [$classes, $closing, $commaAt];
    }

    private function invalid(): MakerException
    {
        return new MakerException("{$this->file} must only return a list of Module::class entries; update it by hand.");
    }
}
