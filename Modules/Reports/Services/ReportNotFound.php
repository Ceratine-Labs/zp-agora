<?php

namespace Modules\Reports\Services;

use RuntimeException;

/**
 * A report slug that is not in the registry.
 *
 * Its own type rather than a 404 thrown from the service, so the controller
 * decides what the user sees and the service stays a service.
 */
class ReportNotFound extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("There is no report called [{$key}].");
    }
}
