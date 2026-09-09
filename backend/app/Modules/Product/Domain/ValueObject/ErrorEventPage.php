<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Una pagina del historico de errores, con su sitio dentro del total y los dos
 * recuentos de la cabecera (RF-PD-15, esquema `ErrorEventCollection`).
 *
 * ## `total` es con filtros y los otros dos son sin ellos, a proposito
 *
 * `total` cuenta lo que casa con lo que se pidio —es lo que pagina—. Pero
 * `openErrors` y `openCritical` cuentan **toda la instalacion sin ningun
 * filtro**, porque responden a otra pregunta: la cabecera de la pantalla dice
 * «tienes 3 criticos abiertos» y esa cifra no puede cambiar segun el filtro que
 * tenga puesto quien mira. Si lo hiciera, alguien filtrando por `source=console`
 * leeria «0 criticos» con la cola cayendose al lado.
 *
 * A diferencia de la bandeja de incidencias, aqui **no hay alcance por
 * departamento** que acotar: el historico de errores es de la instalacion y solo
 * lo ve `admin` (regla dura 18). No hay cifra que pueda describir a personas que
 * quien pregunta no puede ver, porque no describe a personas.
 *
 * `totalPages()` se calcula y no se guarda, por lo mismo que el total de una
 * jornada se suma en vez de almacenarse: dos formas de decir lo mismo acaban
 * discrepando.
 */
final readonly class ErrorEventPage
{
    /**
     * @param  list<ErrorEvent>  $rows
     * @param  int  $total  Grupos que casan con los filtros.
     * @param  int  $openErrors  Grupos abiertos de nivel `error` en toda la instalacion.
     * @param  int  $openCritical  Grupos abiertos de nivel `critical` en toda la instalacion.
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $perPage,
        public int $openErrors,
        public int $openCritical,
    ) {}

    public function totalPages(): int
    {
        return $this->perPage < 1 ? 0 : (int) ceil($this->total / $this->perPage);
    }
}
