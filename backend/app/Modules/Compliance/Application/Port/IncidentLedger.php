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
     * Abre la incidencia **si ese hallazgo no esta ya escrito**, y devuelve su
     * identificador; `null` si ya existia.
     *
     * «El mismo hallazgo» lo define la restriccion `one_incident_per_finding`:
     * mismo empleado, misma jornada, mismo tipo y mismo tramo, **en cualquier
     * estado**. La deduplicacion se resuelve con esa restriccion y no con un
     * `SELECT` previo, que tiene condicion de carrera con la ejecucion manual del
     * comando mientras el planificador corre.
     *
     * **Cuenta tambien lo ya resuelto**, y esa es la parte que importa: si solo
     * mirase las abiertas, la incidencia que un responsable trabajo ayer volveria
     * a abrirse y a avisarle esta noche mientras la jornada siguiera en la
     * ventana. Un tramo cerrado es una fila inmutable y el mismo hallazgo sobre
     * el no es un hecho nuevo; uno que si lo sea entra igual, porque una
     * correccion estrena identificador de tramo (ADR-035).
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

    /**
     * Escribe la **resolucion** de una incidencia y dice si de verdad la escribio
     * (tarea 2.5, RF-PA-05).
     *
     * Devuelve `false` cuando la fila ya no estaba abierta. Lo decide un
     * `UPDATE ... WHERE id = ? AND status = 'open'` y no un `SELECT` previo, por
     * lo mismo que la apertura se decide con el indice unico parcial: entre leer
     * el estado y escribirlo cabe otra peticion, y dos responsables mirando la
     * misma bandeja es el caso normal, no el raro. Con la condicion dentro del
     * `UPDATE`, la segunda no escribe nada y su caso de uso responde `409`.
     *
     * **Solo toca las cuatro columnas del cierre.** Ni el tipo, ni la severidad,
     * ni el responsable, ni el contexto se reescriben: la incidencia es la que se
     * detecto, y lo unico que ha pasado es que alguien la ha trabajado.
     *
     * @param  Incident  $resolved  El agregado **ya cerrado** por
     *                              {@see Incident::resolvedBy()}, con su instante y su autor.
     */
    public function recordResolution(int $incidentId, Incident $resolved): bool;
}
