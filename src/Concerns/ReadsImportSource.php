<?php

declare(strict_types=1);

namespace Thallo\Importers\Concerns;

use function config;

/**
 * Shared source-file access + option/value helpers for import adapters. Previously triplicated
 * across the CSV base, the Markdown importer, and the WordPress importer (where the three
 * coerce() copies had already started to drift).
 *
 * Requires the using class to expose ApplicationContext $context and Connection $db properties.
 */
trait ReadsImportSource
{
    /** Absolute path of the job's source file (from the import_export_files table). */
    protected function sourcePathForJob(string $jobUuid): string
    {
        $file = $this->db->table('import_export_files')
            ->where('job_uuid', '=', $jobUuid)
            ->where('role', '=', 'source')
            ->orderBy('id')
            ->first();
        if ($file === null) {
            throw new \RuntimeException(sprintf('Import source file for job "%s" was not found.', $jobUuid));
        }

        return $this->resolveSourcePath((string) $file['disk'], (string) $file['path']);
    }

    protected function resolveSourcePath(string $disk, string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        $roots = config($this->context, 'import_export.source_roots', []);
        $root = is_array($roots) && isset($roots[$disk]) && is_string($roots[$disk]) && $roots[$disk] !== ''
            ? $roots[$disk]
            : $this->context->getBasePath() . DIRECTORY_SEPARATOR . $disk;

        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Coerce a raw source string (CSV cell, front-matter value, WXR scalar) to a simple type;
     * an empty value becomes null (absent).
     */
    protected function coerce(string $type, string $raw): mixed
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        return match ($type) {
            'number' => is_numeric($raw)
                ? ((string) (int) $raw === $raw ? (int) $raw : (float) $raw)
                : $raw, // non-numeric stays a string so the validator reports the mismatch
            'boolean' => in_array(strtolower($raw), ['true', '1', 'yes', 'on'], true),
            'json' => is_array($decoded = json_decode($raw, true)) ? $decoded : $raw,
            default => $raw,
        };
    }

    /** @param array<string,mixed> $options */
    protected function stringOption(array $options, string $key): string
    {
        return isset($options[$key]) && is_string($options[$key]) ? $options[$key] : '';
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,string> field/target name => source column/key
     */
    protected function mappingOption(array $options): array
    {
        $raw = $options['mapping'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $mapping = [];
        foreach ($raw as $field => $column) {
            if (is_string($field) && is_string($column) && $field !== '' && $column !== '') {
                $mapping[$field] = $column;
            }
        }
        return $mapping;
    }
}
