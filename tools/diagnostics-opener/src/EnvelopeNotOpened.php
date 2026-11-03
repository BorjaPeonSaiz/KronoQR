<?php

declare(strict_types=1);

namespace KronoQR\DiagnosticsOpener;

use RuntimeException;

/**
 * El sobre tiene la forma correcta y no se ha podido abrir o no se puede dar
 * por bueno: no hay clave para su `recipient_key_id`, la clave no es la suya,
 * la cabecera o el texto cifrado se han alterado o cortado, o lo descifrado no
 * coincide con lo que dice la cabecera. Codigo de salida `1`.
 */
final class EnvelopeNotOpened extends RuntimeException {}
