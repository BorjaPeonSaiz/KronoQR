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
     * Escribe el paquete entero en un temporal junto a su destino, `0600`, sin
     * publicarlo todavia. El temporal casa con el patron de los paquetes, asi
     * que si el proceso muere antes de publicarlo lo barre la purga por
     * antiguedad.
     *
     * @param  string|null  $path  Como en {@see self::write()}.
     */
    public function stage(DiagnosticsBundle $bundle, ?string $path = null): StagedDiagnosticsBundle;

    /**
     * Renombra el temporal a su destino, de forma atomica.
     *
     * @return string La ruta absoluta donde quedo.
     */
    public function publish(StagedDiagnosticsBundle $staged): string;

    /** Borra el temporal de un paquete que no se va a publicar. */
    public function discard(StagedDiagnosticsBundle $staged): void;

    /**
     * Relee un paquete escrito antes (`--verify`).
     *
     * @return array<string, mixed>|null Nulo si la ruta no existe o no es JSON.
     */
    public function read(string $path): ?array;
}
