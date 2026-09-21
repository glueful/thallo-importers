<?php

declare(strict_types=1);

namespace Thallo\Importers\Markdown;

/**
 * A Markdown file's flat front matter: the leading `---` block, read as `key: value` lines.
 * Only flat scalars — no lists, no nesting — which is all a page's own description needs, and
 * keeps a YAML parser (and what one will happily construct) out of an import path.
 */
final class FrontMatter
{
    /** @return array{front: array<string,string>, body: string} */
    public static function split(string $raw): array
    {
        $raw = ltrim($raw, "\u{FEFF} \t\r\n");
        if (!str_starts_with($raw, '---')) {
            return ['front' => [], 'body' => $raw];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $end = null;
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            if (trim($lines[$i]) === '---') {
                $end = $i;
                break;
            }
        }
        if ($end === null) {
            return ['front' => [], 'body' => $raw];
        }

        $front = [];
        foreach (array_slice($lines, 1, $end - 1) as $line) {
            if (preg_match('/^([A-Za-z0-9_\-]+)\s*:\s*(.*)$/', $line, $m) === 1) {
                $front[$m[1]] = self::unquote(trim($m[2]));
            }
        }

        return ['front' => $front, 'body' => implode("\n", array_slice($lines, $end + 1))];
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            if (($first === '"' || $first === "'") && $value[strlen($value) - 1] === $first) {
                return substr($value, 1, -1);
            }
        }
        return $value;
    }
}
