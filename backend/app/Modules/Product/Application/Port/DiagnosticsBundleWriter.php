<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;

/**
 * Deja el paquete en el disco de la instalacion (`product:diagnostics`).
 *
 * El borrado por antiguedad no esta aqui: lo hace
 * `SweepExpiredDiagnosticsBundles`, por el camino confinado comun de los
 * ficheros generados (ADR-045 §g).
 *
 * **Solo lo usa la consola.** El endpoint devuelve el paquete en la respuesta y
 * no escribe nada: un fichero por cada clic acumularia datos del cliente en
 * `storage/` sin que nadie los borre. Por consola si se escribe, porque quien
 * ejecuta el comando por SSH necesita una ruta que pasar a `scp`.
 */
interface DiagnosticsBundleWriter
{
    /**
     * @param  string|null  $path  Ruta pedida con `--output`, o nula para el
     *                             directorio de serie de la instalacion.
     * @return string La ruta absoluta donde quedo, para decirsela a quien lo pidio.
     */
    public function write(DiagnosticsBundle $bundle, ?string $path = null): string;

    /**
     * Relee un paquete escrito antes (`--verify`).
     *
     * @return array<string, mixed>|null Nulo si la ruta no existe o no es JSON.
     */
    public function read(string $path): ?array;
}
