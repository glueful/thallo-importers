<?php

declare(strict_types=1);

namespace Thallo\Importers\Markdown;

use Thallo\Contracts\Authoring\ContentUpserter;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Contracts\Authoring\PublishBlocked;
use Thallo\Contracts\Authoring\ValidationFailed;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Importers\Concerns\RequiresImportersCapability;

/**
 * A folder of Markdown as the pages of a content type — documentation kept in git and published
 * by a deploy (`thallo:import:markdown`). Built to be run again and again:
 *
 *   - a file lands on the entry a previous run made for it: found by its URL, else by the
 *     `source_path` it carries — so a page whose slug changed keeps its entry, and its old URL
 *     redirects;
 *   - an entry is written only when what the file says differs from what it holds;
 *   - a file that is gone is REPORTED, never deleted — removing a page is a decision, not a
 *     side effect of a deploy;
 *   - one file's failure is that file's: the rest of the folder still imports.
 *
 * Each file is read by {@see MarkdownPage}. The body is stored as the Markdown it is (the type's
 * body is a plain text field; the theme renders it with `markdown()`), so nothing is lost on the
 * way in and nothing here has to be trusted as HTML. Only fields the type has are written.
 */
final class MarkdownFolderImport
{
    use RequiresImportersCapability;

    private const EXTENSIONS = ['md', 'mdx', 'markdown'];

    public function __construct(
        private readonly ContentWriter $writer,
        private readonly ContentUpserter $upserter,
        private readonly ContentTypeReader $types,
        private readonly CapabilityRegistry $capabilities,
    ) {
    }

    /**
     * @param array{
     *     type?: string, locale?: string, publish?: bool, dry_run?: bool, exclude?: list<string>,
     *     body_field?: string, edit_base?: ?string, actor?: ?string
     * } $options
     * @return array{
     *     type: string, dry_run: bool,
     *     files: list<array<string,mixed>>,
     *     missing: list<array{source_path: string, entry: string}>,
     *     counts: array{created:int,updated:int,unchanged:int,skipped:int,failed:int}
     * }
     */
    public function run(string $dir, array $options): array
    {
        $this->assertImportersEnabled($this->capabilities);

        $slug = (string) ($options['type'] ?? '');
        $typeUuid = $slug === '' ? null : $this->types->findUuidBySlug($slug);
        $schema = $typeUuid === null ? null : $this->types->schemaFor($typeUuid);
        if ($typeUuid === null || $schema === null) {
            throw new \InvalidArgumentException(
                "There is no content type \"{$slug}\". Make one with: php glueful thallo:docs:setup --type={$slug}",
            );
        }
        $bodyField = (string) ($options['body_field'] ?? 'body');
        if ($schema->field($bodyField) === null || $schema->field('title') === null) {
            throw new \InvalidArgumentException(
                "The content type \"{$slug}\" needs a \"title\" and a \"{$bodyField}\" field to hold a Markdown page.",
            );
        }
        $root = realpath($dir);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException("\"{$dir}\" is not a folder.");
        }

        $locale = (string) ($options['locale'] ?? 'en');
        $publish = (bool) ($options['publish'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $actor = is_string($options['actor'] ?? null) ? $options['actor'] : null;
        $editBase = is_string($options['edit_base'] ?? null) ? rtrim($options['edit_base'], '/') : null;
        $sections = $schema->field('section')?->enumValues() ?? [];
        $has = static fn (string $field): bool => $schema->field($field) !== null;

        // First every file as a page, so the links between them can be resolved.
        $pages = [];
        foreach ($this->files($root, array_map('strval', (array) ($options['exclude'] ?? []))) as $relative) {
            $pages[$relative] = MarkdownPage::read(
                $relative,
                (string) file_get_contents($root . '/' . $relative),
                $sections,
            );
        }
        $slugByPath = [];
        foreach ($pages as $relative => $page) {
            if (!$page->skip) {
                $slugByPath[$relative] = $page->slug;
            }
        }

        $files = [];
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];
        $claimed = [];
        foreach ($pages as $relative => $page) {
            if ($page->skip) {
                $files[] = ['path' => $relative, 'slug' => $page->slug, 'status' => 'skipped'];
                $counts['skipped']++;
                continue;
            }
            $page = $page->withLinks($slugByPath, '/' . $slug);
            $row = ['path' => $relative, 'slug' => $page->slug];
            if ($page->brokenLinks !== []) {
                $row['broken_links'] = $page->brokenLinks;
            }
            try {
                if (isset($claimed[$page->slug])) {
                    throw new \RuntimeException(
                        "The URL \"{$page->slug}\" is already {$claimed[$page->slug]}'s in this folder.",
                    );
                }
                $claimed[$page->slug] = $relative;

                $fields = ['title' => $page->title, $bodyField => rtrim($page->body) . "\n"];
                foreach (
                    [
                        'section' => $page->section,
                        'order' => $page->order,
                        'summary' => $page->summary,
                        'source_path' => $relative,
                        'edit_url' => $editBase === null || self::neverEdited($relative)
                            ? null
                            : $editBase . '/' . $relative,
                    ] as $field => $value
                ) {
                    if ($value !== null && $has($field)) {
                        $fields[$field] = $value;
                    }
                }
                $row += $this->write($typeUuid, $locale, $page, $fields, $publish, $dryRun, $actor);
            } catch (ValidationFailed $e) {
                $row += ['status' => 'failed', 'message' => 'Not valid: ' . json_encode($e->errors())];
            } catch (\Throwable $e) {
                $row += ['status' => 'failed', 'message' => $e->getMessage()];
            }
            $counts[$row['status']]++;
            $files[] = $row;
        }

        // Pages a previous run made from files that are no longer here: said, never deleted.
        $missing = [];
        if ($has('source_path')) {
            foreach ($this->upserter->fieldValues($typeUuid, $locale, 'source_path') as $entry => $source) {
                if (!isset($pages[$source])) {
                    $missing[] = ['source_path' => $source, 'entry' => $entry];
                }
            }
        }

        return ['type' => $slug, 'dry_run' => $dryRun, 'files' => $files, 'missing' => $missing, 'counts' => $counts];
    }

    /**
     * @param array<string,mixed> $fields
     * @return array{status: string, message?: string}
     */
    private function write(
        string $typeUuid,
        string $locale,
        MarkdownPage $page,
        array $fields,
        bool $publish,
        bool $dryRun,
        ?string $actor,
    ): array {
        $entry = $this->upserter->findBySlug($typeUuid, $locale, $page->slug)
            ?? $this->upserter->findByField($typeUuid, $locale, 'source_path', $page->sourcePath);

        if ($entry === null) {
            $clean = $this->writer->validate($typeUuid, $locale, $fields);
            if ($dryRun) {
                return ['status' => 'created'];
            }
            $entry = $this->writer->createDraft($typeUuid, $locale, $clean, $actor);
            $this->upserter->assignSlug($entry, $typeUuid, $locale, $page->slug);
            return ['status' => 'created'] + $this->publish($entry, $locale, $publish, $actor);
        }

        $now = $this->upserter->current($entry, $locale);
        $clean = $this->writer->validate($typeUuid, $locale, $fields);
        $sameFields = $now !== null && self::canonical($now['fields']) === self::canonical($clean);
        $sameSlug = $now !== null && $now['slug'] === $page->slug;
        $needsPublish = $publish && $now !== null && !$now['published'];
        if ($sameFields && $sameSlug && !$needsPublish) {
            return ['status' => 'unchanged'];
        }
        if ($dryRun) {
            return ['status' => $sameFields && $sameSlug ? 'unchanged' : 'updated'];
        }
        if (!$sameFields) {
            $this->upserter->updateDraft($entry, $locale, $fields, $actor);
        }
        if (!$sameSlug) {
            $this->upserter->assignSlug($entry, $typeUuid, $locale, $page->slug);
        }
        return ['status' => $sameFields && $sameSlug ? 'unchanged' : 'updated']
            + $this->publish($entry, $locale, $publish, $actor);
    }

    /**
     * The draft is the import's; publishing may be the site's to gate (a review workflow). A gated
     * publish leaves the draft written and says so, rather than failing the file.
     *
     * @return array{message?: string, publish_held?: bool}
     */
    private function publish(string $entry, string $locale, bool $publish, ?string $actor): array
    {
        if (!$publish) {
            return [];
        }
        try {
            $this->writer->publish($entry, $locale, $actor);
            return [];
        } catch (PublishBlocked $e) {
            return ['publish_held' => true, 'message' => 'Saved as a draft, not published: ' . $e->getMessage()];
        }
    }

    /** @param array<string,mixed> $fields */
    private static function canonical(array $fields): string
    {
        $fields = array_filter($fields, static fn ($v): bool => $v !== null && $v !== '');
        ksort($fields);
        // Numbers compare by value: a stored 1 and a parsed 1.0 are the same order.
        array_walk($fields, static function (&$v): void {
            if (is_int($v) || is_float($v)) {
                $v = (float) $v;
            }
        });
        return (string) json_encode($fields);
    }

    /**
     * Every Markdown file under the folder, as sorted `/`-separated relative paths. Hidden files
     * and folders are skipped, and so is any folder named in `$exclude`.
     *
     * @param list<string> $exclude
     * @return list<string>
     */
    private function files(string $root, array $exclude): array
    {
        $exclude = array_map(static fn (string $e): string => trim($e, '/'), $exclude);
        $out = [];
        $walk = function (string $relative) use (&$walk, &$out, $root, $exclude): void {
            foreach (scandir($root . ($relative === '' ? '' : '/' . $relative)) ?: [] as $name) {
                if ($name === '' || $name[0] === '.') {
                    continue;
                }
                $path = $relative === '' ? $name : $relative . '/' . $name;
                $full = $root . '/' . $path;
                if (is_link($full)) {
                    continue; // a folder of documents is read, not followed out of
                }
                if (is_dir($full)) {
                    if (!in_array($path, $exclude, true) && !in_array($name, $exclude, true)) {
                        $walk($path);
                    }
                } elseif (in_array(strtolower((string) pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                    $out[] = $path;
                }
            }
        };
        $walk('');
        sort($out);
        return $out;
    }

    /**
     * A changelog or a licence page offers no "Edit this page" link: the one is written by the
     * release process and the other is held word for word to the project's licence — and a
     * CHANGELOG.md copied in from the project root has no file at the edit address anyway.
     * Matched by file name, an `NN-` prefix aside: changelog, license, licence.
     */
    private static function neverEdited(string $relative): bool
    {
        $name = strtolower((string) pathinfo($relative, PATHINFO_FILENAME));
        $name = (string) preg_replace('/\A\d{1,4}[-_. ]+/', '', $name);
        return in_array($name, ['changelog', 'license', 'licence'], true);
    }
}
