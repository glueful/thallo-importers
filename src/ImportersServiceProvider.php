<?php

declare(strict_types=1);

namespace Thallo\Importers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Importers\CsvContentImporter;
use Thallo\Importers\CsvUserImporter;
use Thallo\Importers\MarkdownContentImporter;
use Thallo\Importers\WordpressContentImporter;

final class ImportersServiceProvider extends ServiceProvider
{
    /** @return array<string,mixed> */
    public static function services(): array
    {
        return [
            CsvUserImporter::class => [
                'class'    => CsvUserImporter::class,
                'shared'   => true,
                'autowire' => true,
                'tags'     => ['import_export.importer'],
            ],
            CsvContentImporter::class => [
                'class'    => CsvContentImporter::class,
                'shared'   => true,
                'autowire' => true,
                'tags'     => ['import_export.importer'],
            ],
            MarkdownContentImporter::class => [
                'class'    => MarkdownContentImporter::class,
                'shared'   => true,
                'autowire' => true,
                'tags'     => ['import_export.importer'],
            ],
            WordpressContentImporter::class => [
                'class'    => WordpressContentImporter::class,
                'shared'   => true,
                'autowire' => true,
                'tags'     => ['import_export.importer'],
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // No routes/config to load; adapters are tag-discovered by glueful/import-export.
    }

    public function boot(ApplicationContext $context): void
    {
        container($context)->get(CapabilityRegistry::class)->register(
            new Capability(
                'thallo.importers',
                label: 'Content importers',
                description: 'CSV, Markdown and WordPress content/user import adapters.',
            ),
        );
    }
}
