<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * Imprimir en **una sola hoja A4** todas las credenciales pendientes de un
 * centro (RF-QR-04).
 *
 * El doc 02 §5.5 explica por que no es un lujo: *«La hoja A4 con varias tarjetas
 * por pagina es lo que hace viable dar de alta a 40 personas de temporada en una
 * tarde.»*
 *
 * **`--pending` no es un filtro opcional: es la unica seleccion posible.** No
 * existe una variante que reimprima, porque no existe la reimpresion (ADR-034).
 * Su idempotencia es esa: la segunda pasada no encuentra nada pendiente y no
 * produce ningun PDF. Es lo que impide que dos ejecuciones del mismo lote den dos
 * juegos de tarjetas con QR distinto y solo el ultimo valido.
 *
 * **Y es todo o nada.** Si alguna de las seleccionadas resulta estar ya impresa
 * cuando se va a escribir —otro proceso llego antes—, no se imprime ninguna. Un
 * lote a medias es peor que ninguno: nadie sabria cuales de las sesenta tarjetas
 * de la hoja valen.
 */
final readonly class PrintCredentialBatchCommand
{
    public function __construct(
        /** Centro cuyas credenciales pendientes se imprimen. `null` = toda la instalacion. */
        public ?int $siteId = null,
        /** Quien lo pidio, o `null` si fue un comando de consola sin sesion. */
        public ?int $actorUserId = null,
    ) {}
}
