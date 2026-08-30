<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\Model\Incident;

/**
 * El libro de incidencias: donde se abren y desde donde se cuentan (RF-PR-01).
 *
 * Lo implementa `Compliance/Infrastructure/Persistence` sobre la tabla
 * `incidents`. Habla en identificadores **publicos** —el UUID del empleado y el
 * del tramo— y es el adaptador quien los traduce a claves internas, igual que en
 * el resto del modulo: una clave interna que sube a la capa de aplicacion
 * obliga a quien la recibe a saber de que tabla salio.
 */
interface IncidentLedger
{
    /**
     * Abre la incidencia **si no hay ya una abierta igual**, y devuelve su
     * identificador; `null` si ya existia.
     *
     * «Igual» lo define el indice unico parcial de `incidents`: mismo empleado,
     * misma jornada, mismo tipo y mismo tramo, y solo entre las que siguen
     * abiertas. La deduplicacion se resuelve **con esa restriccion y no con un
     * `SELECT` previo**, que tiene condicion de carrera con la ejecucion manual
     * del comando mientras el planificador corre.
     *
     * Que devuelva `null` en vez de fallar es lo que hace idempotente al
     * comando: repetir la pasada no crea nada y tampoco es un error.
     */
    public function openIfAbsent(Incident $incident): ?int;

    /**
     * Cuantas incidencias hay abiertas de cada tipo y severidad.
     *
     * Alimenta el gauge `incidents_open{type,severity}` (doc 02 §8.2) y se lee
     * **de la base de datos**, no de lo que acabe de detectarse: la cifra tiene
     * que bajar cuando alguien resuelve una incidencia desde la bandeja
     * (tarea 2.5), no solo subir cuando la deteccion encuentra algo.
     *
     * @return list<IncidentTally>
     */
    public function openTally(): array;
}
