<?php

declare(strict_types=1);

namespace Rapira;

use Rapira\Exception\NoDispatcherError;

/**
 * Psalm stub for the Rapira extension function.
 *
 * @param callable(): bool $handler
 */
function handle_request(callable $handler): bool
{
}

/**
 * Psalm stub for the Rapira extension function.
 */
function get_mode(): Mode
{
}

/**
 * Psalm stub for the Rapira extension function.
 *
 * @throws NoDispatcherError
 */
function get_dispatcher(): Dispatcher
{
}
