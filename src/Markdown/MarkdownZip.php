<?php

declare(strict_types=1);

namespace Thallo\Importers\Markdown;

/**
 * A folder of Markdown, uploaded as a .zip. The archive is a stranger's file, so what comes out
 * of it is decided here and nowhere else:
 *
 *   - only Markdown is written (`.md`, `.mdx`, `.markdown`) — never a script, never an image;
 *   - a name that would land outside the folder refuses the WHOLE archive (zip slip);
 *   - the bytes written are counted as they are read, not as the archive declares them, and the
 *     number of pages is capped (a zip bomb lies about its sizes);
 *   - hidden files and macOS's `__MACOSX` bookkeeping are passed over.
 *
 * A folder that holds every page — what zipping a folder called `docs` produces — is not part of
 * a page's path: a page is found again by its path, and it must be the same path whether the
 * folder was imported from disk or uploaded. Unless that folder is one the caller names (`$keep`:
 * the type's sections): an upload of nothing but `guides/` is the guides section, not a wrapper.
 */
final class MarkdownZip
{
    public const MAX_FILES = 2000;
    public const MAX_BYTES = 52_428_800;

    private const EXTENSIONS = ['md', 'mdx', 'markdown'];

    /**
     * How many pages an import of the archive would read.
     *
     * @param list<string> $keep
     * @param list<string> $exclude
     */
    public static function count(string $zipPath, array $keep = [], array $exclude = []): int
    {
        $zip = self::open($zipPath);
        try {
            return count(self::selected(self::pages($zip), $keep, $exclude));
        } finally {
            $zip->close();
        }
    }

    /**
     * Write the archive's Markdown under `$into` (an existing, empty folder).
     *
     * @param list<string> $keep top folders that are part of a page's path even when every page is in one
     * @param list<string> $exclude folders left out, by name or by path — the folder import's own rule
     * @return list<string> the pages written, relative to `$into`, sorted
     */
    public static function extract(
        string $zipPath,
        string $into,
        array $keep = [],
        array $exclude = [],
        int $maxFiles = self::MAX_FILES,
        int $maxBytes = self::MAX_BYTES,
    ): array {
        $zip = self::open($zipPath);
        try {
            $pages = self::selected(self::pages($zip), $keep, $exclude);
            if (count($pages) > $maxFiles) {
                throw new \InvalidArgumentException("The archive holds more than {$maxFiles} Markdown files.");
            }
            $written = [];
            $bytes = 0;
            try {
                foreach ($pages as $index => $relative) {
                    $bytes += self::write($zip, $index, $into . '/' . $relative, $maxBytes - $bytes);
                    $written[] = $relative;
                }
            } catch (\Throwable $e) {
                // Nothing half-extracted is left for an import to read.
                self::remove($into, keepRoot: true);
                throw $e;
            }
            sort($written);
            return $written;
        } finally {
            $zip->close();
        }
    }

    /** Remove a folder and everything in it. */
    public static function remove(string $dir, bool $keepRoot = false): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        if (!$keepRoot) {
            rmdir($dir);
        }
    }

    private static function open(string $zipPath): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \InvalidArgumentException(
                "A .zip cannot be read on this server: PHP's zip extension is not installed.",
            );
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new \InvalidArgumentException('The file is not a zip archive.');
        }
        return $zip;
    }

    /**
     * The archive's pages: index => name, in the archive's own separators normalised to `/`.
     *
     * @return array<int,string>
     */
    private static function pages(\ZipArchive $zip): array
    {
        $pages = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                continue;
            }
            $segments = explode('/', $name);
            // Refused, not skipped: an archive built to climb out is not one to take pages from.
            if ($name[0] === '/' || in_array('..', $segments, true) || preg_match('/\A[A-Za-z]:/', $name) === 1) {
                throw new \InvalidArgumentException("\"{$name}\" would be written outside the import folder.");
            }
            $hidden = array_filter($segments, static fn (string $s): bool => $s === '' || $s[0] === '.');
            if ($hidden !== [] || $segments[0] === '__MACOSX') {
                continue;
            }
            $pages[$i] = $name;
        }
        return $pages;
    }

    /**
     * The pages an import reads, by the path each has IN the import: the zipped folder set aside,
     * the folders left out gone.
     *
     * @param array<int,string> $pages
     * @param list<string> $keep
     * @param list<string> $exclude
     * @return array<int,string> index => relative path
     */
    private static function selected(array $pages, array $keep, array $exclude): array
    {
        $prefix = self::sharedFolder(array_values($pages));
        if (in_array(rtrim($prefix, '/'), $keep, true)) {
            $prefix = '';
        }
        $exclude = array_filter(array_map(static fn (string $e): string => trim($e, '/'), $exclude));
        $selected = [];
        foreach ($pages as $index => $name) {
            $relative = substr($name, strlen($prefix));
            $folders = explode('/', $relative);
            array_pop($folders);
            $path = '';
            foreach ($folders as $folder) {
                $path = $path === '' ? $folder : $path . '/' . $folder;
                if (in_array($folder, $exclude, true) || in_array($path, $exclude, true)) {
                    continue 2;
                }
            }
            $selected[$index] = $relative;
        }
        return $selected;
    }

    /**
     * The folder every page is inside, with its trailing slash, or '' when there is none.
     *
     * @param list<string> $names
     */
    private static function sharedFolder(array $names): string
    {
        if ($names === []) {
            return '';
        }
        $first = explode('/', $names[0]);
        if (count($first) < 2) {
            return '';
        }
        $folder = $first[0] . '/';
        foreach ($names as $name) {
            if (!str_starts_with($name, $folder)) {
                return '';
            }
        }
        return $folder;
    }

    /** Stream one page to disk, counting what is really read. Returns the bytes written. */
    private static function write(\ZipArchive $zip, int $index, string $target, int $allowed): int
    {
        $in = $zip->getStreamIndex($index);
        if ($in === false) {
            throw new \InvalidArgumentException('The archive could not be read.');
        }
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
            fclose($in);
            throw new \RuntimeException('The import folder could not be written.');
        }
        $out = fopen($target, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('The import folder could not be written.');
        }
        $bytes = 0;
        try {
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $bytes += strlen($chunk);
                if ($bytes > $allowed) {
                    throw new \InvalidArgumentException('The archive is too large once unpacked.');
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        return $bytes;
    }
}
