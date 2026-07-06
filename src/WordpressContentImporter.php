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
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Imports a WordPress export (WXR) into entries of one content type.
 *
 * Each `<item>` whose `wp:post_type` is `post` or `page` becomes an entry. The HTML body
 * (`content:encoded`) goes to the chosen `body_field`; scalar WXR keys (`title`, `excerpt`, `slug`,
 * `date`, `status`, `author`) map to fields via the `mapping` option and are type-coerced like CSV
 * cells, then validated and written via the normal create-draft path. When `publish` is set, items
 * with WXR status `publish` are published. `dry_run` validates and reports without writing.
 *
 * v1 imports posts/pages only — media/attachments, authors, categories/tags, custom post types,
 * post meta and upsert-by-WP-id are deferred follow-ups.
 *
 * Writes via the {@see ContentWriter} contract; schema access via {@see ContentTypeReader}.
 * No direct dependency on engine repositories, services, or validators.
 */
final class WordpressContentImporter implements ImporterInterface, RetryableAdapterInterface
{
    use ReadsImportSource;
    use RequiresImportersCapability;

    /** WXR scalar keys a field can map to. */
    public const KEYS = ['title', 'excerpt', 'slug', 'date', 'status', 'author'];
    private const POST_TYPES = ['post', 'page'];

    private ?HtmlSanitizer $sanitizer = null;

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
        return 'wordpress.content';
    }

    public function label(): string
    {
        return 'WordPress (WXR)';
    }

    public function supports(ImportSource $source): bool
    {
        return $source->path !== ''
            && in_array($this->extension($source->path), ['xml', 'wxr'], true);
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

        $total = count($this->readItems($this->resolveSourcePath($source->disk, $source->path)));
        $batchSize = max(1, $options->batchSize);
        $batches = [];
        for ($offset = 0, $sequence = 1; $offset < $total; $offset += $batchSize, $sequence++) {
            $batches[] = new ImportBatch(
                // Random, not derived from adapter/sequence/offset: import_export_batches.uuid is
                // globally UNIQUE and rows outlive the job, so a deterministic uuid makes the
                // SECOND WXR import collide on its first batch.
                uuid: Utils::generateNanoID(12),
                jobUuid: 'pending',
                sequence: $sequence,
                offset: $offset,
                limit: $batchSize,
            );
        }

        return new ImportPlan($total, $batches, retryable: true, metadata: [
            'format' => 'wxr',
            'content_type' => $slug,
        ]);
    }

    public function process(ImportBatch $batch, ImportContext $context): ImportBatchResult
    {
        // Re-gate on the processing path so a retry after the capability was disabled fails closed.
        $this->assertImportersEnabled($this->capabilities);

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

        $items = array_slice($this->readItemsForJob($context->jobUuid), $batch->offset, $batch->limit);
        $errors = [];
        $processed = 0;

        foreach ($items as $index => $item) {
            $line = $batch->offset + $index + 1;
            try {
                $payload = [];
                foreach ($mapping as $field => $wxrKey) {
                    $def = $fieldsByName[$field] ?? null;
                    if ($def === null || !array_key_exists($wxrKey, $item)) {
                        continue;
                    }
                    $payload[$field] = $this->coerce($def->type(), (string) $item[$wxrKey]);
                }
                if ($bodyField !== '' && isset($fieldsByName[$bodyField])) {
                    // A WXR export is untrusted input (same threat model as the Markdown
                    // importer): sanitize the HTML body so a <script>/onload/javascript: in the
                    // source file can't become stored XSS served to delivery consumers. Normal
                    // WordPress markup (headings, lists, links, images, embeds-as-links) survives.
                    $payload[$bodyField] = $fieldsByName[$bodyField]->format() === 'plain'
                        ? trim(strip_tags($item['content']))
                        : $this->sanitizeHtml($item['content']);
                }

                $clean = $this->writer->validate($typeUuid, $locale, $payload);
                if ($context->mode === 'commit') {
                    $entryUuid = $this->writer->createDraft(
                        $typeUuid,
                        $locale,
                        $clean,
                        $context->actorUuid,
                    );
                    if ($publish && $item['status'] === 'publish') {
                        $this->writer->publish($entryUuid, $locale, $context->actorUuid);
                    }
                }
                $processed++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'record_number' => $line,
                    'severity' => 'error',
                    'code' => 'wordpress_import_failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return new ImportBatchResult($processed, count($errors), $errors, ['mode' => $context->mode]);
    }

    public function retryable(): bool
    {
        return true;
    }

    /**
     * Reject a mapping/body_field that doesn't match the target schema (or an unknown WXR key)
     * at plan time. Without this, a typo'd body_field or field name was silently skipped at
     * import time — a "successful" import of body-less entries with zero errors.
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
            throw new \InvalidArgumentException('A body_field or a WXR-key mapping is required.');
        }
        if ($bodyField !== '' && !isset($fieldNames[$bodyField])) {
            throw new \InvalidArgumentException(
                sprintf('Content type "%s" has no field "%s" (body_field).', $slug, $bodyField),
            );
        }
        foreach ($mapping as $field => $wxrKey) {
            if (!isset($fieldNames[$field])) {
                throw new \InvalidArgumentException(
                    sprintf('Content type "%s" has no field "%s" (mapped).', $slug, $field),
                );
            }
            if (!in_array($wxrKey, self::KEYS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown WXR key "%s" (mapped to field "%s"); valid keys: %s.',
                    $wxrKey,
                    $field,
                    implode(', ', self::KEYS),
                ));
            }
        }
    }

    private function sanitizeHtml(string $html): string
    {
        if ($this->sanitizer === null) {
            // allowSafeElements: standard content markup passes; script/iframe/event handlers and
            // javascript:/data: URLs are dropped. The default 20k input cap would silently
            // truncate long posts — raise it well past any real post body.
            $this->sanitizer = new HtmlSanitizer(
                (new HtmlSanitizerConfig())
                    ->allowSafeElements()
                    ->withMaxInputLength(1_000_000),
            );
        }
        return $this->sanitizer->sanitize($html);
    }

    /**
     * Parse the WXR file into a flat list of post/page records.
     *
     * @return list<array<string,string>> each: title, content, excerpt, slug, status, date, author
     */
    private function readItems(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Could not read WordPress export "%s".', $path));
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        libxml_use_internal_errors($previous);
        if ($xml === false || !isset($xml->channel)) {
            throw new \RuntimeException('The file is not a valid WordPress export (WXR).');
        }

        $ns = $xml->getDocNamespaces(true);
        $wp = $ns['wp'] ?? 'http://wordpress.org/export/1.2/';
        $content = $ns['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
        $excerpt = $ns['excerpt'] ?? 'http://wordpress.org/export/1.2/excerpt/';
        $dc = $ns['dc'] ?? 'http://purl.org/dc/elements/1.1/';

        $records = [];
        foreach ($xml->channel->item as $item) {
            $wpChildren = $item->children($wp);
            if (!in_array((string) $wpChildren->post_type, self::POST_TYPES, true)) {
                continue;
            }
            $records[] = [
                'title' => (string) $item->title,
                'content' => (string) $item->children($content)->encoded,
                'excerpt' => (string) $item->children($excerpt)->encoded,
                'slug' => (string) $wpChildren->post_name,
                'status' => (string) $wpChildren->status,
                'date' => (string) $wpChildren->post_date,
                'author' => (string) $item->children($dc)->creator,
            ];
        }

        return $records;
    }

    /**
     * @return list<array<string,string>>
     */
    private function readItemsForJob(string $jobUuid): array
    {
        return $this->readItems($this->sourcePathForJob($jobUuid));
    }

    private function extension(string $path): string
    {
        return strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    }
}
