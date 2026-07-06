<?php

declare(strict_types=1);

namespace Thallo\Importers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Importers\Concerns\RequiresImportersCapability;

/**
 * Imports a CSV file into entries of one content type — one entry per row.
 *
 * The {@see AbstractCsvImporter} base handles reading/batching/error-reporting; this class supplies
 * the content specifics: the `options` bag (`content_type` slug, `mapping` of field => column, and
 * optional `locale`/`publish`), and a per-row map → type-coerce → validate → create-draft (publish
 * optional). v1 is create-only (no upsert by a stable key).
 *
 * Writes via the {@see ContentWriter} contract; schema access via {@see ContentTypeReader}.
 * No direct dependency on engine repositories, services, or validators.
 */
final class CsvContentImporter extends AbstractCsvImporter
{
    use RequiresImportersCapability;

    public function __construct(
        ApplicationContext $context,
        Connection $db,
        private readonly ContentWriter $writer,
        private readonly ContentTypeReader $types,
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($context, $db);
    }

    public function key(): string
    {
        return 'csv.content';
    }

    public function label(): string
    {
        return 'CSV';
    }

    protected function assertEnabled(): void
    {
        $this->assertImportersEnabled($this->capabilities);
    }

    protected function validatePlan(array $header, ImportOptions $options): void
    {
        $this->assertEnabled();

        $slug = $this->stringOption($options->options, 'content_type');
        $mapping = $this->mappingOption($options->options);
        if ($slug === '') {
            throw new \InvalidArgumentException('A target content_type is required.');
        }
        if ($mapping === []) {
            throw new \InvalidArgumentException('A column mapping is required.');
        }
        $typeUuid = $this->types->findUuidBySlug($slug);
        if ($typeUuid === null) {
            throw new \InvalidArgumentException(sprintf('Unknown content type "%s".', $slug));
        }
        $schema = $this->types->schemaFor($typeUuid);
        if ($schema === null) {
            throw new \InvalidArgumentException(sprintf('Schema for content type "%s" could not be loaded.', $slug));
        }
        $fieldNames = [];
        foreach ($schema->fields() as $field) {
            $fieldNames[$field->name()] = true;
        }
        foreach ($mapping as $field => $column) {
            // A typo'd field name was silently skipped at import time — that column's data was
            // dropped from every row with zero errors. Reject at plan like unknown columns.
            if (!isset($fieldNames[$field])) {
                throw new \InvalidArgumentException(
                    sprintf('Content type "%s" has no field "%s" (mapped to column "%s").', $slug, $field, $column),
                );
            }
            if (!in_array($column, $header, true)) {
                throw new \InvalidArgumentException(
                    sprintf('CSV has no column "%s" (mapped to field "%s").', $column, $field),
                );
            }
        }
    }

    protected function planMetadata(ImportOptions $options): array
    {
        return ['format' => 'csv', 'content_type' => $this->stringOption($options->options, 'content_type')];
    }

    protected function prepare(ImportContext $context): array
    {
        $slug = $this->stringOption($context->options, 'content_type');
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
        $locale = $this->stringOption($context->options, 'locale');

        return [
            'typeUuid' => $typeUuid,
            'fieldsByName' => $fieldsByName,
            'mapping' => $this->mappingOption($context->options),
            'locale' => $locale !== '' ? $locale : 'en',
            'publish' => (bool) ($context->options['publish'] ?? false),
        ];
    }

    protected function importRow(array $row, array $prepared, ImportContext $context): void
    {
        $payload = [];
        foreach ($prepared['mapping'] as $field => $column) {
            $def = $prepared['fieldsByName'][$field] ?? null;
            if ($def === null) {
                continue; // mapped to a field that isn't in the schema — ignore
            }
            $payload[$field] = $this->coerce($def->type(), (string) ($row[$column] ?? ''));
        }

        $clean = $this->writer->validate($prepared['typeUuid'], $prepared['locale'], $payload);
        if ($context->mode === 'commit') {
            $entryUuid = $this->writer->createDraft(
                $prepared['typeUuid'],
                $prepared['locale'],
                $clean,
                $context->actorUuid,
            );
            if ($prepared['publish']) {
                $this->writer->publish($entryUuid, $prepared['locale'], $context->actorUuid);
            }
        }
    }

    protected function errorCode(): string
    {
        return 'csv_import_failed';
    }
}
