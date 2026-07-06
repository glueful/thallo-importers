<?php

declare(strict_types=1);

namespace Thallo\Importers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\ImportExport\Contracts\ImporterInterface;
use Glueful\Extensions\ImportExport\Contracts\RetryableAdapterInterface;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportBatchResult;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportPlan;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Glueful\Helpers\Utils;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Importers\Concerns\ReadsImportSource;
use Thallo\Importers\Concerns\RequiresImportersCapability;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Imports a single Markdown/MDX document as one entry.
 *
 * The YAML front matter (the `---` block) maps to fields via the `mapping` option (field => key),
 * and the Markdown body is converted to HTML and stored in the `body_field` (raw Markdown when that
 * field's editor is `plain`). Front-matter values are strings, so they're type-coerced like CSV
 * cells, then validated against the schema before the normal create-draft write. `dry_run` validates
 * and reports without writing.
 *
 * v1 is single-file (one document per import); a folder/zip of files is a follow-up. MDX component
 * bodies pass through commonmark unparsed (JSX isn't interpreted) — a documented v1 limitation.
 *
 * Writes via the {@see ContentWriter} contract; schema access via {@see ContentTypeReader}.
 * No direct dependency on engine repositories, services, or validators.
 */
final class MarkdownContentImporter implements ImporterInterface, RetryableAdapterInterface
{
    use ReadsImportSource;
    use RequiresImportersCapability;

    private ?MarkdownConverter $markdown = null;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $db,
        private readonly ContentWriter $writer,
        private readonly ContentTypeReader $types,
        private readonly CapabilityRegistry $capabilities,
    ) {
    }

    public function key(): string
    {
        return 'markdown.content';
    }

    public function label(): string
    {
        return 'Markdown / MDX';
    }

    public function supports(ImportSource $source): bool
    {
        return $source->path !== ''
            && in_array($this->extension($source->path), ['md', 'mdx', 'markdown'], true);
    }

    public function plan(ImportSource $source, ImportOptions $options): ImportPlan
    {
        $this->assertImportersEnabled($this->capabilities);

        $slug = $this->stringOption($options->options, 'content_type');
        if ($slug === '') {
            throw new \InvalidArgumentException('A target content_type is required.');
        }
        $typeUuid = $this->types->findUuidBySlug($slug);
        if ($typeUuid === null) {
            throw new \InvalidArgumentException(sprintf('Unknown content type "%s".', $slug));
        }
        $this->validateTargets($typeUuid, $slug, $options->options);

        // One file is one document → one record. Random uuid: import_export_batches.uuid is
        // globally UNIQUE and rows outlive the job — the previous constant hash made every
        // SECOND markdown import collide on insert.
        $batch = new ImportBatch(
            uuid: Utils::generateNanoID(12),
            jobUuid: 'pending',
            sequence: 1,
            offset: 0,
            limit: 1,
        );

        return new ImportPlan(1, [$batch], retryable: true, metadata: [
            'format' => 'markdown',
            'content_type' => $slug,
        ]);
    }

    public function process(ImportBatch $batch, ImportContext $context): ImportBatchResult
    {
        // Re-gate on the processing path so a retry after the capability was disabled fails closed.
        $this->assertImportersEnabled($this->capabilities);

        if ($batch->offset > 0) {
            return new ImportBatchResult(0, 0, [], ['mode' => $context->mode]);
        }

        $options = $context->options;
        $slug = $this->stringOption($options, 'content_type');
        $mapping = $this->mappingOption($options);
        $bodyField = $this->stringOption($options, 'body_field');
        $locale = $this->stringOption($options, 'locale');
        $locale = $locale !== '' ? $locale : 'en';
        $publish = (bool) ($options['publish'] ?? false);

        $typeUuid = $this->types->findUuidBySlug($slug);
        if ($typeUuid === null) {
            throw new \RuntimeException(sprintf('Content type "%s" no longer exists.', $slug));
        }
        $schema = $this->types->schemaFor($typeUuid);
        if ($schema === null) {
            throw new \RuntimeException(sprintf('Schema for content type "%s" could not be loaded.', $slug));
        }
        $fieldsByName = [];
        foreach ($schema->fields() as $field) {
            $fieldsByName[$field->name()] = $field;
        }

        try {
            $document = $this->parseDocument($this->readSource($context->jobUuid));

            $payload = [];
            foreach ($mapping as $field => $key) {
                $def = $fieldsByName[$field] ?? null;
                if ($def === null || !array_key_exists($key, $document['front'])) {
                    continue;
                }
                $payload[$field] = $this->coerce($def->type(), $document['front'][$key]);
            }
            if ($bodyField !== '' && isset($fieldsByName[$bodyField])) {
                $payload[$bodyField] = $fieldsByName[$bodyField]->format() === 'plain'
                    ? rtrim($document['body'])
                    : $this->toHtml($document['body']);
            }

            $clean = $this->writer->validate($typeUuid, $locale, $payload);
            if ($context->mode === 'commit') {
                $entryUuid = $this->writer->createDraft($typeUuid, $locale, $clean, $context->actorUuid);
                if ($publish) {
                    $this->writer->publish($entryUuid, $locale, $context->actorUuid);
                }
            }

            return new ImportBatchResult(1, 0, [], ['mode' => $context->mode]);
        } catch (\Throwable $e) {
            return new ImportBatchResult(0, 1, [[
                'record_number' => 1,
                'severity' => 'error',
                'code' => 'markdown_import_failed',
                'message' => $e->getMessage(),
            ]], ['mode' => $context->mode]);
        }
    }

    public function retryable(): bool
    {
        return true;
    }

    /**
     * Reject a mapping/body_field that doesn't match the target schema at plan time. Without
     * this, a typo'd body_field or field name was silently skipped at import time — a
     * "successful" import of body-less entries with zero errors.
     *
     * @param array<string,mixed> $options
     */
    private function validateTargets(string $typeUuid, string $slug, array $options): void
    {
        $schema = $this->types->schemaFor($typeUuid);
        if ($schema === null) {
            throw new \InvalidArgumentException(sprintf('Schema for content type "%s" could not be loaded.', $slug));
        }
        $fieldNames = [];
        foreach ($schema->fields() as $field) {
            $fieldNames[$field->name()] = true;
        }

        $mapping = $this->mappingOption($options);
        $bodyField = $this->stringOption($options, 'body_field');
        if ($mapping === [] && $bodyField === '') {
            throw new \InvalidArgumentException('A body_field or a front-matter mapping is required.');
        }
        if ($bodyField !== '' && !isset($fieldNames[$bodyField])) {
            throw new \InvalidArgumentException(
                sprintf('Content type "%s" has no field "%s" (body_field).', $slug, $bodyField),
            );
        }
        foreach (array_keys($mapping) as $field) {
            if (!isset($fieldNames[$field])) {
                throw new \InvalidArgumentException(
                    sprintf('Content type "%s" has no field "%s" (mapped).', $slug, $field),
                );
            }
        }
    }

    /**
     * Split a document into its flat front matter (the leading `---` block, parsed as `key: value`)
     * and the remaining body. Only flat scalar front matter is supported in v1.
     *
     * @return array{front: array<string,string>, body: string}
     */
    private function parseDocument(string $raw): array
    {
        $raw = ltrim($raw);
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
                $front[$m[1]] = $this->unquote(trim($m[2]));
            }
        }

        return ['front' => $front, 'body' => implode("\n", array_slice($lines, $end + 1))];
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            if (($first === '"' || $first === "'") && $value[strlen($value) - 1] === $first) {
                return substr($value, 1, -1);
            }
        }
        return $value;
    }

    private function toHtml(string $markdown): string
    {
        if ($this->markdown === null) {
            // Imported markdown is untrusted → the rendered HTML is stored and later served as a
            // content body. Strip raw HTML blocks and drop unsafe (javascript:/data:) link schemes
            // so a `<script>` or `javascript:` href in a source file can't become stored XSS.
            // Normal markdown still renders identically.
            $environment = new Environment([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new DisallowedRawHtmlExtension());
            $this->markdown = new MarkdownConverter($environment);
        }
        return $this->markdown->convert($markdown)->getContent();
    }

    private function readSource(string $jobUuid): string
    {
        $path = $this->sourcePathForJob($jobUuid);
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Could not read Markdown file "%s".', $path));
        }
        return $contents;
    }

    private function extension(string $path): string
    {
        return strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    }
}
