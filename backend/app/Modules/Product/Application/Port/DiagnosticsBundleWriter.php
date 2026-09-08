<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use DateTimeImmutable;

/**
 * Deja el paquete en el disco de la instalacion (`product:diagnostics`).
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
     * Borra los paquetes anteriores a un instante, y devuelve cuantos.
     *
     * **Existe porque un paquete es material caducado el dia que se envia.** El
     * anonimizado ya ocupa sitio sin aportar nada; el que se pidio con datos
     * personales es una copia de la plantilla y de los fichajes de un periodo,
     * en el disco del cliente, sin ninguna fecha de caducidad. RL-19 autoriza a
     * generarlo para una incidencia concreta, no a conservarlo indefinidamente.
     *
     * Se hace al generar y no en una tarea programada: el comando es el unico
     * momento en el que alguien esta mirando, y una tarea nocturna que borrase
     * ficheros por su cuenta seria una sorpresa.
     */
    public function purgeOlderThan(DateTimeImmutable $moment): int;

    /**
     * Relee un paquete escrito antes (`--verify`).
     *
     * @return array<string, mixed>|null Nulo si la ruta no existe o no es JSON.
     */
    public function read(string $path): ?array;
}
