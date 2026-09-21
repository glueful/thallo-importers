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
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Importers\Concerns\ReadsImportSource;
use Thallo\Importers\Concerns\RequiresImportersCapability;
use Thallo\Importers\Markdown\MarkdownFolderImport;
use Thallo\Importers\Markdown\MarkdownZip;

/**
 * A folder of Markdown uploaded as a .zip (Settings › Import / Export): the import the deploy
 * command runs ({@see MarkdownFolderImport}), as an import job, for a site whose owner has the
 * admin and not a shell. Same rules, because it IS the same import: a page is found again by its
 * URL or its path, only what changed is written, nothing is deleted.
 *
 * One batch, always: the links between pages are resolved against every page in the folder, so
 * the folder cannot be cut up. What comes out of the archive is {@see MarkdownZip}'s to decide.
 *
 * The folder import's report becomes the job's rows, which is what the admin can show: an `info`
 * row per page, a `warning` for a link that leads nowhere, a publish the site's review workflow
 * held back and a page whose file is gone, an `error` for a page that failed.
 *
 * Options: `content_type` (required), `publish`, `exclude` (folders), `edit_base`, `locale`.
 */
final class MarkdownZipImporter implements ImporterInterface, RetryableAdapterInterface
{
    use ReadsImportSource;
    use RequiresImportersCapability;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $db,
        private readonly MarkdownFolderImport $folders,
        private readonly ContentTypeReader $types,
        private readonly CapabilityRegistry $capabilities,
    ) {
    }

    public function key(): string
    {
        return 'markdown.folder';
    }

    public function label(): string
    {
        return 'Markdown folder (.zip)';
    }

    public function supports(ImportSource $source): bool
    {
        return strtolower((string) pathinfo($source->path, PATHINFO_EXTENSION)) === 'zip';
    }

    public function plan(ImportSource $source, ImportOptions $options): ImportPlan
    {
        $this->assertImportersEnabled($this->capabilities);

        $slug = $this->stringOption($options->options, 'content_type');
        $typeUuid = $slug === '' ? null : $this->types->findUuidBySlug($slug);
        $schema = $typeUuid === null ? null : $this->types->schemaFor($typeUuid);
        if ($schema === null) {
            throw new \InvalidArgumentException(
                $slug === '' ? 'Choose the content type the pages belong to.' : "There is no content type \"{$slug}\".",
            );
        }
        if ($schema->field('title') === null || $schema->field('body') === null) {
            throw new \InvalidArgumentException(
                "The content type \"{$slug}\" needs a \"title\" and a \"body\" field to hold a Markdown page.",
            );
        }

        $pages = MarkdownZip::count(
            $this->resolveSourcePath($source->disk, $source->path),
            $schema->field('section')?->enumValues() ?? [],
            $this->excluded($options->options),
        );
        if ($pages === 0) {
            throw new \InvalidArgumentException('The archive holds no Markdown files (.md, .mdx, .markdown).');
        }
        if ($pages > MarkdownZip::MAX_FILES) {
            throw new \InvalidArgumentException(
                'The archive holds more than ' . MarkdownZip::MAX_FILES . ' Markdown files.',
            );
        }

        $batch = new ImportBatch(
            uuid: Utils::generateNanoID(12),
            jobUuid: 'pending',
            sequence: 1,
            offset: 0,
            limit: $pages,
        );
        return new ImportPlan($pages, [$batch], retryable: true, metadata: [
            'format' => 'markdown-folder',
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
        $dryRun = $context->mode !== 'commit';
        $dir = sys_get_temp_dir() . '/thallo-md-upload-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700) && !is_dir($dir)) {
            throw new \RuntimeException('The upload could not be unpacked: no temporary folder.');
        }
        try {
            $slug = $this->stringOption($options, 'content_type');
            $typeUuid = $this->types->findUuidBySlug($slug);
            $schema = $typeUuid === null ? null : $this->types->schemaFor($typeUuid);
            MarkdownZip::extract(
                $this->sourcePathForJob($context->jobUuid),
                $dir,
                $schema?->field('section')?->enumValues() ?? [],
                $this->excluded($options),
            );
            $editBase = $this->stringOption($options, 'edit_base');
            $locale = $this->stringOption($options, 'locale');
            $report = $this->folders->run($dir, [
                'type' => $slug,
                'locale' => $locale !== '' ? $locale : 'en',
                'publish' => (bool) ($options['publish'] ?? false),
                'dry_run' => $dryRun,
                'exclude' => $this->excluded($options),
                'edit_base' => $editBase !== '' ? $editBase : null,
                'actor' => $context->actorUuid,
            ]);
        } catch (\InvalidArgumentException $e) {
            // The archive or the target was refused as a whole: one failed record that says why.
            return new ImportBatchResult(0, 1, [[
                'record_number' => 1,
                'severity' => 'error',
                'code' => 'markdown_folder_refused',
                'message' => $e->getMessage(),
            ]], ['mode' => $context->mode]);
        } finally {
            MarkdownZip::remove($dir);
        }

        $counts = $report['counts'];
        return new ImportBatchResult(
            $counts['created'] + $counts['updated'] + $counts['unchanged'] + $counts['skipped'],
            $counts['failed'],
            self::rows($report, $dryRun),
            ['mode' => $context->mode, 'counts' => $counts],
        );
    }

    /**
     * @param array<string,mixed> $options
     * @return list<string>
     */
    private function excluded(array $options): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $folder): string => is_string($folder) ? trim($folder) : '',
            (array) ($options['exclude'] ?? []),
        )));
    }

    public function retryable(): bool
    {
        return true;
    }

    /**
     * The folder import's report, as the rows a job keeps.
     *
     * @param array<string,mixed> $report
     * @return list<array<string,mixed>>
     */
    private static function rows(array $report, bool $dryRun): array
    {
        $rows = [];
        $did = [
            'created' => $dryRun ? 'would be created at' : 'was created at',
            'updated' => $dryRun ? 'would be updated at' : 'was updated at',
            'unchanged' => 'is unchanged at',
        ];
        foreach ($report['files'] as $index => $file) {
            $number = $index + 1;
            $path = (string) $file['path'];
            $status = (string) $file['status'];
            $row = static fn (string $severity, string $code, string $message): array => [
                'record_number' => $number,
                'severity' => $severity,
                'code' => $code,
                'message' => $message,
            ];
            if ($status === 'failed') {
                $rows[] = $row('error', 'markdown_page_failed', $path . ': ' . ($file['message'] ?? 'failed'));
                continue;
            }
            $rows[] = $status === 'skipped'
                ? $row('info', 'markdown_page_skipped', "{$path} is marked as a draft and was passed over")
                : $row('info', "markdown_page_{$status}", "{$path} {$did[$status]} /{$report['type']}/{$file['slug']}");
            if (isset($file['message'])) {
                $rows[] = $row('warning', 'markdown_publish_held', "{$path}: {$file['message']}");
            }
            foreach ($file['broken_links'] ?? [] as $link) {
                $rows[] = $row(
                    'warning',
                    'markdown_broken_link',
                    "{$path} links to {$link}, which is not in this import. The link was left as written.",
                );
            }
        }
        foreach ($report['missing'] as $gone) {
            $rows[] = [
                'record_number' => null,
                'severity' => 'warning',
                'code' => 'markdown_page_gone',
                'message' => "{$gone['source_path']} is not in this upload. Its page was left as it is: "
                    . 'remove it under Content if you mean to.',
            ];
        }
        return $rows;
    }
}
