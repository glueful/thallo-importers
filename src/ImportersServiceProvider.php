<?php

declare(strict_types=1);

namespace Thallo\Importers;

use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Importers\CsvContentImporter;
use Thallo\Importers\CsvUserImporter;
use Thallo\Importers\MarkdownContentImporter;
use Thallo\Importers\WordpressContentImporter;

final class ImportersServiceProvider extends ServiceProvider implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [];
    }

    /**
     * Post-extension tier (modules-not-extensions spec §5.2): app-integrated modules load
     * AFTER the extension universe, reproducing the pre-conversion order in which they lived
     * at the tail of config/extensions.php. Inter-module order comes from the
     * serviceproviders.php list (the orderer's stable tie-break).
     */
    public static function loadPriority(): int
    {
        return 100;
    }

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
                owningPackage: 'glueful/import-export',
            ),
        );
    }
}
