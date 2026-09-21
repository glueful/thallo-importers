<?php

declare(strict_types=1);

namespace Thallo\Importers\Markdown;

/**
 * One Markdown file of a folder, read as a page.
 *
 * The front matter (`title`, `slug`, `section`, `order`, `summary`, `draft`/`publish`) says what
 * it can; the file says the rest, so a folder written for GitHub imports as it stands:
 *
 *   slug     the file's name, without an `NN-` order prefix; a README or index is its folder
 *   order    that `NN-` prefix
 *   section  the top folder, when it is one of the type's sections
 *   title    the first `# heading`, which is then taken out of the body (the template prints
 *            the title); failing that, the name
 *
 * Links between `.md` files are rewritten to the pages' URLs by {@see withLinks()} once the whole
 * folder's slugs are known. Code — fenced or indented — is never rewritten.
 */
final class MarkdownPage
{
    /** @param list<string> $brokenLinks relative `.md` links that lead to no imported file */
    private function __construct(
        public readonly string $sourcePath,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $section,
        public readonly ?float $order,
        public readonly ?string $summary,
        public readonly string $body,
        public readonly bool $skip,
        public readonly array $brokenLinks = [],
    ) {
    }

    /**
     * @param string $relativePath the file's path inside the folder, `/`-separated
     * @param list<string> $sections the docs type's sections
     */
    public static function read(string $relativePath, string $contents, array $sections): self
    {
        ['front' => $front, 'body' => $body] = FrontMatter::split($contents);

        $segments = explode('/', $relativePath);
        $name = (string) pathinfo((string) end($segments), PATHINFO_FILENAME);
        $order = null;
        if (preg_match('/\A(\d{1,4})[-_. ]+(.+)\z/', $name, $m) === 1) {
            $order = (float) $m[1];
            $name = $m[2];
        }
        if (in_array(strtolower($name), ['readme', 'index'], true)) {
            $name = count($segments) > 1 ? $segments[count($segments) - 2] : 'index';
        }

        $title = trim($front['title'] ?? '');
        if ($title === '' && preg_match('/\A\s*#[ \t]+(.+?)[ \t]*#*[ \t]*(?:\r?\n|\z)/', $body, $m) === 1) {
            $title = trim($m[1]);
            $body = ltrim(substr($body, strlen($m[0])), "\r\n");
        }
        if ($title === '') {
            $title = ucfirst(trim((string) preg_replace('/[-_]+/', ' ', $name)));
        }

        $section = trim($front['section'] ?? '');
        if ($section === '' && count($segments) > 1) {
            $section = $segments[0];
        }
        $summary = trim($front['summary'] ?? $front['description'] ?? '');
        $isFalse = static fn (string $v): bool => in_array(strtolower($v), ['false', 'no', '0'], true);
        $isTrue = static fn (string $v): bool => in_array(strtolower($v), ['true', 'yes', '1'], true);

        return new self(
            sourcePath: $relativePath,
            slug: self::slugify(trim($front['slug'] ?? '') !== '' ? $front['slug'] : $name),
            title: $title,
            section: in_array($section, $sections, true) ? $section : null,
            order: is_numeric($front['order'] ?? null) ? (float) $front['order'] : $order,
            summary: $summary === '' ? null : $summary,
            body: $body,
            skip: $isTrue($front['draft'] ?? '') || $isFalse($front['publish'] ?? 'true'),
        );
    }

    /**
     * The page with its links to other imported files turned into links to their pages.
     *
     * @param array<string,string> $slugByPath every imported file's relative path → its slug
     * @param string $base the type's URL, e.g. `/docs`
     */
    public function withLinks(array $slugByPath, string $base): self
    {
        $broken = [];
        $resolve = function (string $target) use ($slugByPath, $base, &$broken): ?string {
            if (preg_match('~\A([^#?]+\.(?:md|mdx|markdown))(#.*)?\z~i', $target, $m) !== 1) {
                return null;
            }
            if (preg_match('~\A(?:[a-z][a-z0-9+.-]*:|//|/)~i', $m[1]) === 1) {
                return null; // another site, or a path of the site's own
            }
            $path = self::normalize(dirname($this->sourcePath), $m[1]);
            if ($path === null || !isset($slugByPath[$path])) {
                $broken[] = $target;
                return null;
            }
            return rtrim($base, '/') . '/' . $slugByPath[$path] . ($m[2] ?? '');
        };

        $lines = preg_split('/(?<=\n)/', $this->body) ?: [];
        $fence = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/\A {0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                if ($fence === null) {
                    $fence = $m[1][0];
                } elseif ($m[1][0] === $fence) {
                    $fence = null;
                }
                continue;
            }
            if ($fence !== null || preg_match('/\A(?: {4}|\t)/', $line) === 1) {
                continue; // code is quoted, not written
            }
            // Inline: [text](target "title"); reference: [label]: target "title". A code span is
            // quoted like a fence: the line is taken apart at its backtick runs and only the
            // text between them is rewritten.
            $parts = preg_split('~((`+).+?\2)~', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
            $line = '';
            for ($k = 0, $n = count($parts); $k < $n; $k += 3) {
                $line .= (string) preg_replace_callback(
                    '~(\]\()(<?)([^)\s>]+)(>?)((?:\s+"[^"]*")?\))~',
                    static fn (array $m): string => ($url = $resolve($m[3])) === null
                        ? $m[0]
                        : $m[1] . $m[2] . $url . $m[4] . $m[5],
                    $parts[$k],
                ) . ($parts[$k + 1] ?? '');
            }
            $lines[$i] = (string) preg_replace_callback(
                '~\A( {0,3}\[[^\]]+\]:\s+)(\S+)~',
                static fn (array $m): string => ($url = $resolve($m[2])) === null ? $m[0] : $m[1] . $url,
                $line,
            );
        }

        return new self(
            $this->sourcePath,
            $this->slug,
            $this->title,
            $this->section,
            $this->order,
            $this->summary,
            implode('', $lines),
            $this->skip,
            array_values(array_unique($broken)),
        );
    }

    private static function slugify(string $value): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
        return $slug === '' ? 'page' : $slug;
    }

    /** `guides` + `../production.md` → `production.md`; null when it climbs out of the folder. */
    private static function normalize(string $dir, string $relative): ?string
    {
        $parts = $dir === '.' || $dir === '' ? [] : explode('/', $dir);
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }
}
