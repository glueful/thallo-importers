<?php

declare(strict_types=1);

namespace Thallo\Importers\Markdown;

/**
 * A folder of Markdown read by {@see MarkdownFolderImport::read()}: every page resolved against the
 * others (slugs, sections, the links between them), and the pages a previous import made from files
 * no longer in the folder. Nothing has been written. Each page is then written, one at a time, by
 * {@see MarkdownFolderImport::writePage()}: by `run()` in a loop, or by a caller that wraps each
 * page in its own transaction.
 */
final class MarkdownFolderRead
{
    /**
     * @param list<MarkdownPage> $pages every file's page, links resolved; skipped pages included
     * @param list<array{source_path: string, entry: string}> $missing
     * @param list<string> $fields the optional page fields the content type has
     */
    public function __construct(
        public readonly string $type,
        public readonly string $typeUuid,
        public readonly string $locale,
        public readonly string $bodyField,
        public readonly array $pages,
        public readonly array $missing,
        public readonly array $fields,
    ) {
    }
}
