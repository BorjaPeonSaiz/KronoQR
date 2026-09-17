<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\AcceptedScan;
use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use DateTimeImmutable;

/**
 * El registro inmutable de **todo** escaneo, aceptado o no: `scan_events`
 * (doc 01 §5.5, RF-AT-01, RF-AT-07, RF-AT-09).
 *
 * Lo declara `Attendance` y lo implementa `Attendance/Infrastructure/
 * Persistence`, igual que {@see WorkDayRepository}: no es una arista de
 * ADR-025, es el modulo guardando lo suyo. Por eso puede hablar en tipos
 * propios.
 *
 * **Aqui vive la idempotencia de la regla dura 8, y vive de una forma
 * concreta.** `record()` **no pregunta antes si el escaneo existe**: lo intenta
 * escribir y deja que el UNIQUE de `scan_events.scan_id` decida. Un `SELECT`
 * previo tiene condicion de carrera —entre la consulta y la insercion cabe otra
 * peticion con el mismo `scan_id`— y bajo el pico de un cambio de turno eso
 * produce tramos duplicados en el registro legal de alguien. La comprobacion
 * previa daria una falsa sensacion de seguridad justo el dia que importa.
 *
 * **Nota de transaccionalidad.** Ninguna de las tres operaciones abre
 * transaccion propia: se unen a la del caso de uso. Es lo que hace que un
 * `scan_id` repetido deshaga el tramo que la misma transaccion acababa de
 * escribir, en lugar de dejar un tramo huerfano sin escaneo que lo justifique.
 */
interface ScanLog
{
    /**
     * Escribe la fila del escaneo.
     *
     * @return bool `true` si la fila se ha escrito; `false` si ese `scan_id` ya
     *              estaba registrado. **No lanza en el caso repetido**: un
     *              reenvio desde la cola offline es funcionamiento normal, no un
     *              error, y convertirlo en excepcion obligaria a distinguirla de
     *              las que si lo son.
     */
    public function record(ScanRecord $scan): bool;

    /**
     * El escaneo ya registrado con ese `scan_id`, si lo hay.
     *
     * Se consulta **despues** de que `record()` haya dicho que no, nunca antes:
     * es la lectura que reconstruye la respuesta original de RF-AT-07, no un
     * control de duplicados.
     */
    public function find(string $scanId): ?RecordedScan;

    /**
     * Los escaneos **aceptados** del empleado inmediatamente anterior y
     * posterior a ese instante, para evaluar la ventana de RF-AT-06.
     *
     * Dos y no uno porque la cola offline puede sincronizar un escaneo cuyo
     * `occurred_at` es anterior a otro ya registrado (regla dura 9): el vecino
     * que decide puede estar a cualquiera de los dos lados. Dos y no todos
     * porque uno mas lejano no puede ganar, y porque asi las dos consultas
     * caben en el indice `(employee_id, occurred_at DESC)` en lugar de ordenar
     * el historico del empleado en cada fichaje.
     *
     * Solo entran los aceptados —`clock_in`, `clock_out`, `break_start`,
     * `break_end`—: un `rejected_debounce` no reinicia la ventana, porque si lo
     * hiciera bastaria con pasar la tarjeta cada 50 segundos para prolongarla
     * indefinidamente.
     *
     * **Desde la tarea 3.5 lleva tambien QUE fue cada uno** (ADR-024): el
     * anti-rebote ya no puede decidir solo con el instante, porque un
     * `break_end` a veinte segundos de un `break_start` no es un rebote sino
     * alguien corrigiendo una pausa mal pulsada. El adaptador devuelve por tanto
     * `result` y `shift_entry_uuid` ademas de `occurred_at`, y como todos son
     * aceptados la accion siempre cabe en {@see ClockingAction}.
     *
     * @return list<AcceptedScan> Cero, uno o dos escaneos, con su instante en UTC.
     */
    public function acceptedScansAdjacentTo(string $employeeUuid, DateTimeImmutable $instant): array;

    /**
     * El **ultimo** escaneo aceptado del empleado, sea de cuando sea, o `null`
     * si nunca ficho.
     *
     * Es uno de los hechos que necesita {@see \App\Modules\Attendance\Domain\
     * Policy\ScanIntentPolicy} y responde a una pregunta que no es la de
     * {@see acceptedScansAdjacentTo()}: aquella busca vecinos de un instante
     * para medir una ventana de segundos; esta busca el **estado en que quedo la
     * persona**.
     *
     * **La consulta no lleva cota y eso es deliberado: la cota la pone el
     * dominio.** Cuanto puede durar una pausa antes de dejar de serlo es una
     * regla de negocio —es el descanso minimo entre jornadas de RN-10, resuelto
     * desde el perfil del centro (regla dura 14)— y vive en `ScanIntentPolicy`,
     * que compara el `occurred_at` del escaneo con el de esto. Escribir aqui un
     * `WHERE occurred_at > …` pondria el mismo umbral en dos sitios, y el de SQL
     * seria el que ganaria sin que ninguna prueba de dominio lo viera.
     *
     * «Ultimo» se mide por `occurred_at` y no por orden de llegada (regla dura
     * 9): un lote offline sincronizado ahora puede traer escaneos anteriores a
     * otros ya registrados, y el estado de la persona lo fija el momento real.
     *
     * Resuelve por el mismo indice `(employee_id, occurred_at DESC)` que ya usa
     * la ventana de RF-AT-06, asi que no anade una lectura cara al camino de
     * fichaje.
     */
    public function lastAcceptedScanOf(string $employeeUuid): ?AcceptedScan;
}
