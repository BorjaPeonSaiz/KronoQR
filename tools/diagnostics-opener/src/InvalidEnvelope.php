<?php

declare(strict_types=1);

namespace KronoQR\DiagnosticsOpener;

use RuntimeException;

/**
 * El fichero no es un sobre de esta version: no es JSON, no dice
 * `kronoqr-diagnostics-sealed`, su `envelope_version` es otra o le falta un
 * campo. No se ha intentado abrir. Codigo de salida `2`.
 */
final class InvalidEnvelope extends RuntimeException {}
