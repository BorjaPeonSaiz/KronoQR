<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\TelemetryState;

/**
 * Lo que la telemetria recuerda entre un envio y el siguiente (**RF-PD-12**).
 *
 * ## `load()` acuña la identidad si no existe
 *
 * Y la persiste en el acto. Es deliberado que no haya un `mint()` aparte: si la
 * identidad se generara solo al enviar, un `product:telemetry` sin `--send`
 * enseñaria un `installation_id` distinto en cada ejecucion y el cliente no
 * podria comprobar que es el mismo que ve el fabricante.
 *
 * ## Un fichero y no una fila
 *
 * `storage/app/telemetry/state.json`, con el directorio a `0700` y el fichero a
 * `0600`. No va a la base de datos a proposito: no es un dato del negocio, no
 * tiene que salir en la exportacion integra ni en una copia de seguridad, y
 * borrarlo tiene que ser tan facil como `rm` para que estrenar identidad sea una
 * operacion que el cliente pueda hacer solo.
 */
interface TelemetryStateStore
{
    /**
     * El estado guardado, **acuñando y persistiendo** `installation_id` la
     * primera vez. Lo usa el envio, y solo el envio.
     *
     * Un fichero corrupto o ilegible se trata como ausente y se vuelve a acuñar:
     * dejar sin telemetria a una instalacion por un JSON truncado seria peor, y
     * no hay nada ahi que no se pueda perder.
     */
    public function establish(): TelemetryState;

    /**
     * Lo que hay guardado, o `null`. **Nunca escribe.**
     */
    public function stored(): ?TelemetryState;

    /**
     * Una identidad acuñada **en memoria**, que no se guarda en ningun sitio.
     *
     * ## Por que existe, y por que no basta con {@see self::establish()}
     *
     * `php artisan product:telemetry` sin `--send` **no envia nada**, y por eso
     * mismo no debe dejar rastro: es el comando que alguien ejecuta para *ver*
     * lo que se enviaria antes de decidir si activa la telemetria. Si esa
     * consulta acuñara y guardara la identidad, mirar tendria efectos, y el
     * fichero apareceria en el disco de instalaciones que nunca activaron nada.
     *
     * El identificador que se enseña es entonces provisional: el que quede
     * fijado sera el del primer envio. La salida del comando lo dice.
     */
    public function provisional(): TelemetryState;

    public function save(TelemetryState $state): void;
}
