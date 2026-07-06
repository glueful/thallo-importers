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
use Thallo\Importers\Concerns\ReadsImportSource;

/**
 * Base for CSV-backed importers over the glueful/import-export engine.
 *
 * It owns the generic lifecycle — reading the CSV (header + associative rows), slicing it into
 * batches, the per-row try/catch loop, error reporting, and value coercion/option helpers — so a
 * concrete importer only declares its identity and what a single mapped row means:
 *  - {@see validatePlan()} — fail fast on bad options/header before batches are queued.
 *  - {@see prepare()} — resolve per-batch context once (the target, schema, mapping, …).
 *  - {@see importRow()} — map + validate + persist one row (honouring dry-run via the context mode).
 *
 * The importer instance is shared, so per-batch state travels through {@see prepare()}'s return
 * value rather than instance properties.
 */
abstract class AbstractCsvImporter implements ImporterInterface, RetryableAdapterInterface
{
    use ReadsImportSource;

    public function __construct(
        protected readonly ApplicationContext $context,
        protected readonly Connection $db,
    ) {
    }

    abstract public function key(): string;

    abstract public function label(): string;

    public function supports(ImportSource $source): bool
    {
        return $source->path !== ''
            && strtolower((string) pathinfo($source->path, PATHINFO_EXTENSION)) === 'csv';
    }

    public function plan(ImportSource $source, ImportOptions $options): ImportPlan
    {
        $csv = $this->readCsv($this->resolveSourcePath($source->disk, $source->path));
        $this->validatePlan($csv['header'], $options);

        $total = count($csv['rows']);
        $batchSize = max(1, $options->batchSize);
        $batches = [];
        for ($offset = 0, $sequence = 1; $offset < $total; $offset += $batchSize, $sequence++) {
            $batches[] = new ImportBatch(
                // Random, not derived from adapter/sequence/offset: import_export_batches.uuid is
                // globally UNIQUE and rows outlive the job, so a deterministic uuid makes the
                // SECOND import with the same adapter collide on its first batch.
                uuid: Utils::generateNanoID(12),
                jobUuid: 'pending',
                sequence: $sequence,
                offset: $offset,
                limit: $batchSize,
            );
        }

        return new ImportPlan($total, $batches, retryable: true, metadata: $this->planMetadata($options));
    }

    public function process(ImportBatch $batch, ImportContext $context): ImportBatchResult
    {
        // Re-gate on the processing path: a job retried after the capability was
        // disabled must still fail closed, not finish its remaining batches ungated.
        $this->assertEnabled();

        $rows = array_slice($this->recordsForJob($context->jobUuid), $batch->offset, $batch->limit);
        $prepared = $this->prepare($context);
        $errors = [];
        $processed = 0;

        foreach ($rows as $index => $row) {
            $line = $batch->offset + $index + 1;
            try {
                $this->importRow($row, $prepared, $context);
                $processed++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'record_number' => $line,
                    'severity' => 'error',
                    'code' => $this->errorCode(),
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

    // ── Template methods ───────────────────────────────────────────────────────

    /**
     * Assert the owning capability is enabled; throw to fail closed. Called on both the
     * planning and the processing path so a retry can't run an ungated batch.
     */
    abstract protected function assertEnabled(): void;

    /**
     * Validate the options/header before batches are queued; throw to reject the import.
     *
     * @param list<string> $header
     */
    abstract protected function validatePlan(array $header, ImportOptions $options): void;

    /**
     * Resolve per-batch context once (target, schema, mapping, …); returned to {@see importRow()}.
     *
     * @return array<string,mixed>
     */
    abstract protected function prepare(ImportContext $context): array;

    /**
     * Map, validate, and persist one CSV row. Skip writes when `$context->mode !== 'commit'`.
     * Throw on a per-row failure — the base records it against the row's line number.
     *
     * @param array<string,string> $row
     * @param array<string,mixed> $prepared
     */
    abstract protected function importRow(array $row, array $prepared, ImportContext $context): void;

    /** Error `code` recorded for a failed row. */
    abstract protected function errorCode(): string;

    /**
     * Plan metadata stored for reporting (override to add adapter context).
     *
     * @return array<string,mixed>
     */
    protected function planMetadata(ImportOptions $options): array
    {
        return ['format' => 'csv'];
    }

    // ── Shared helpers ─────────────────────────────────────────────────────────

    /**
     * Read a CSV file into a header row + a list of associative rows keyed by column header.
     *
     * @return array{header: list<string>, rows: list<array<string,string>>}
     */
    protected function readCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Could not open CSV file "%s".', $path));
        }
        try {
            // Pass $escape explicitly — its default is deprecated/changing in PHP 8.4+.
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!is_array($header)) {
                return ['header' => [], 'rows' => []];
            }
            $header = array_map(static fn($h): string => (string) $h, $header);

            $rows = [];
            while (($data = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if (!is_array($data) || $data === [null]) {
                    continue; // skip blank lines
                }
                $assoc = [];
                foreach ($header as $i => $column) {
                    $assoc[$column] = isset($data[$i]) ? (string) $data[$i] : '';
                }
                $rows[] = $assoc;
            }
            return ['header' => $header, 'rows' => $rows];
        } finally {
            fclose($handle);
        }
    }

    /**
     * The job's CSV source rows (from the import_export_files table).
     *
     * @return list<array<string,string>>
     */
    protected function recordsForJob(string $jobUuid): array
    {
        return $this->readCsv($this->sourcePathForJob($jobUuid))['rows'];
    }
}
