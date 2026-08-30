<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * La foto de la presencia en un instante (**RF-PA-01**, RF-PA-02).
 *
 * ## `generatedAt` no es decorativo
 *
 * Es la referencia contra la que la pantalla calcula el tiempo transcurrido de
 * cada persona. **No se usa el reloj del cliente**: un portatil o una tablet con
 * la hora desfasada mostraria minutos trabajados que nadie ha trabajado, y en
 * una pantalla que se mira en el cambio de turno eso se lee como un dato del
 * registro (regla dura 3; RF-AT-10 mide ese mismo desfase en los quioscos).
 *
 * ## Los recuentos describen el alcance, no la plantilla
 *
 * `presentCount` y `absentCount` se calculan con el alcance por departamento ya
 * aplicado (RF-ID-03) **dentro de la consulta**, junto con el filtro de
 * departamento y la busqueda. Lo unico que no se les aplica es el filtro de
 * situacion, para que el panel pueda enseñar los dos numeros habiendo pedido una
 * sola lista. Un recuento que describiera a personas que quien pregunta no puede
 * ver seria una fuga por si mismo, exactamente igual que el `meta.total` del
 * listado de plantilla.
 *
 * ## No se pagina
 *
 * Una instalacion es un hotel (ADR-040) con hasta 500 empleados (doc 02, Anexo
 * A): una fila por persona del alcance cabe en una respuesta y el panel la
 * virtualiza (RNF-P-04). Paginar obligaria al panel a reconciliar cada mensaje
 * del WebSocket contra una pagina que quiza no contiene a esa persona, que es
 * como una vista en vivo empieza a enseñar a alguien dos veces.
 */
final readonly class PresenceBoard
{
    /**
     * @param  list<PresenceEntry>  $entries  Ya ordenadas y ya filtradas por situacion.
     */
    public function __construct(
        public array $entries,
        public int $presentCount,
        public int $absentCount,
        public DateTimeImmutable $generatedAt,
        /** Zona IANA del centro (ADR-040: hay uno). Se muestra, no se adivina. */
        public string $timeZone,
    ) {
        if ($presentCount < 0 || $absentCount < 0) {
            throw new InvalidArgumentException('Un recuento de presencia no puede ser negativo.');
        }

        if ($timeZone === '') {
            throw new InvalidArgumentException('El tablero de presencia viaja con la zona del centro (regla dura 3).');
        }
    }

    public function total(): int
    {
        return $this->presentCount + $this->absentCount;
    }
}
