<?php

declare(strict_types=1);

namespace App\Support\Health;

use RuntimeException;

/**
 * A dependency whose host did not resolve or did not accept a TCP connection
 * within the readiness budget (R3-CH-02).
 *
 * Only its CLASS travels, as `DependencyFailure::$failure`: never a host or a
 * port (hard rule 21). Never thrown: it names a kind of failure, and is handed
 * to the Redis circuit breaker as the reason it opened.
 */
final class EndpointUnreachable extends RuntimeException {}
