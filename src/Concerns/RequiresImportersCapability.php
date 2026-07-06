<?php

declare(strict_types=1);

namespace Thallo\Importers\Concerns;

use Glueful\Http\Exceptions\Client\ForbiddenException;
use Thallo\Contracts\Capability\CapabilityRegistry;

trait RequiresImportersCapability
{
    private function assertImportersEnabled(CapabilityRegistry $capabilities): void
    {
        if (!$capabilities->isEnabled('thallo.importers')) {
            throw new ForbiddenException('The lemma.importers capability is disabled.');
        }
    }
}
