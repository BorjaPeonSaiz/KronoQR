<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Broadcasting;

use App\Modules\Attendance\Domain\Event\EmployeeClockedIn;
use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Reporting\Application\Port\LivePresenceReader;
use App\Modules\Shared\Application\Port\Clock;
use App\Support\Database\DatabaseUnavailable;
use App\Support\Resilience\DependencyFailure;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Traduce los eventos de dominio del fichaje al mensaje que ve el panel
 * (**RF-PA-01**, ADR-011, doc 02 §1.6).
 *
 * `Attendance` no sabe que esto existe: emite, y `Reporting` reacciona. Es la
 * misma via por la que `Compliance` escribe el asiento de `audit_log`, y la
 * unica que las fronteras del §1.6 conceden.
 *
 * ## Por que hay que volver a consultar
 *
 * El evento de dominio **no lleva nombre, ni departamento, ni quiosco** (regla
 * dura 21): lleva `employeeUuid`. Esos tres datos hay que resolverlos, y se
 * resuelven con **la misma consulta** que produce el listado
 * ({@see LivePresenceReader::stateOf()}). Componer el mensaje a mano a partir del
 * evento seria una segunda forma de construir la misma fila, y el dia que
 * discreparan el panel enseñaria una cosa al sondear y otra al recibir.
 *
 * Consultar despues del commit tiene ademas una ventaja: lo que sale es el
 * estado **actual**, no el que tenia el agregado cuando se emitio el evento. Si
 * dos escaneos se cruzan, gana el ultimo, que es lo que hay que enseñar.
 *
 * ## Encolado y despues del commit
 *
 * `ShouldQueue` con `$afterCommit = true`, tal y como advierte el docblock de
 * `Attendance\Infrastructure\Adapter\LaravelEventBus`: los eventos se publican
 * **dentro** de la transaccion del caso de uso, asi que un listener sincrono
 * difundiria un fichaje que todavia puede revertir —y el panel enseñaria a
 * alguien dentro que nunca entro—, ademas de meter una llamada de red en el
 * camino critico del fichaje (RNF-P-02).
 *
 * ## Y aun asi no puede romper un fichaje
 *
 * Las reglas duras 15 y 19 no admiten «normalmente no bloquea». Con una cola de
 * verdad —Horizon en produccion— este listener ya esta fuera del camino, pero la
 * cola `sync` es una configuracion legitima de una instalacion pequeña, y ahi el
 * listener corre dentro de la peticion: un Reverb caido convertiria un fichaje
 * **ya escrito** en un `500`. Por eso cualquier fallo se atrapa aqui.
 *
 * ## Se reintenta durante 60 s si falta PostgreSQL, y nada mas (ADR-059 §5)
 *
 * Con el WebSocket conectado el panel **no sondea** (ADR-011): un mensaje
 * perdido deja a esa persona mal pintada hasta su siguiente fichaje. Por eso,
 * si lo que falla es la base de datos —`stateOf()` la encuentra inalcanzable o
 * pierde la conexion—, el trabajo **vuelve a la cola** con una espera igual al
 * `Retry-After` del cortacircuitos, y lo intenta hasta 60 s despues de
 * **encolarse** (`createdAt` del payload, que Laravel fija al despachar), no
 * desde `occurred_at`: un fichaje que llega de la cola offline con horas de
 * retraso tambien se difunde. Pasado ese plazo, un `warning` y se abandona.
 *
 * El plazo lo mide **el propio trabajo**, no `retryUntil()`. Con
 * `retryUntil()`, `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts` falla el
 * trabajo con `MaxAttemptsExceededException` **antes** de ejecutarlo en cuanto
 * vence el plazo: deja una fila en `failed_jobs` y otra en `error_events`, nunca
 * sale el `warning` de arriba, y bastaria con que Horizon fuera 60 s atrasado,
 * sin averia ninguna, para que una difusion fallara sin haberse intentado. Por
 * eso `$tries = 0` (sin tope de intentos: el tope es el plazo) y
 * `$maxExceptions = 1` (una excepcion que escape —el contenedor no resuelve el
 * listener, por ejemplo— falla a la primera en vez de reintentarse sin fin).
 *
 * Reintentar es seguro por dos motivos: el trabajo **relee** el estado al
 * ejecutarse, asi que difunde el actual y no el del evento; y el panel descarta,
 * por persona, un `occurred_at` anterior al ultimo que aplico
 * (`presence.store.ts`). No se puede quitar ese orden sin revisar esto.
 *
 * Cualquier otro fallo —Reverb caido, por ejemplo— no se reintenta: un `warning`
 * con `employee_uuid` (nunca el nombre, regla dura 21) y la clase de la
 * excepcion, nunca su mensaje. Y en `deferred` o `sync` no hay cola a la que
 * volver: `warning`, igual.
 *
 * ## Toda correccion difunde, no solo la de un tramo abierto
 *
 * Decidir cuales afectan a la presencia exigiria exactamente la misma consulta
 * que resuelve la fila, asi que no ahorraria nada; y una anulacion que se
 * olvidara de difundir dejaria el panel enseñando dentro a alguien cuyo tramo se
 * acaba de anular. Se difunde siempre y gana la simplicidad.
 */
final class BroadcastPresenceChange implements ShouldQueue
{
    use InteractsWithQueue;

    /** Cuanto se reintenta, desde que se encola, si falta PostgreSQL (ADR-059 §5). */
    public const int RETRY_WINDOW_SECONDS = 60;

    /** La espera si no hay `Retry-After` del cortacircuitos que leer. */
    private const int DEFAULT_RELEASE_SECONDS = 10;

    /**
     * Los eventos del fichaje se publican dentro de la transaccion del caso de
     * uso: sin esto, la difusion saldria sobre una escritura que aun puede
     * revertir.
     */
    public bool $afterCommit = true;

    /**
     * La cola que no tumba el fichaje (CH1): si Redis no responde, la difusion
     * se ejecuta en el mismo proceso despues de enviar la respuesta, en vez de
     * convertir en `500` un fichaje ya confirmado. Ver `resilient` en
     * `config/queue.php`.
     */
    public string $connection = 'resilient';

    /**
     * Sin tope de intentos: el que manda es {@see self::RETRY_WINDOW_SECONDS},
     * medido aqui. Pisa el `tries` del supervisor de Horizon, que es 1.
     */
    public int $tries = 0;

    /** Lo que no sea devolverse a la cola a proposito no se reintenta. */
    public int $maxExceptions = 1;

    public function __construct(
        private readonly LivePresenceReader $presence,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
    ) {}

    public function clockedIn(EmployeeClockedIn $event): void
    {
        $this->announce($event->employeeUuid, $event->clockedInAt);
    }

    public function clockedOut(EmployeeClockedOut $event): void
    {
        $this->announce($event->employeeUuid, $event->clockedOutAt);
    }

    public function corrected(ShiftCorrected $event): void
    {
        // El momento de la CORRECCION, no el de las horas corregidas: lo que ha
        // cambiado en el panel ha cambiado ahora.
        $this->announce($event->employeeUuid, $event->correction->performedAt);
    }

    private function announce(string $employeeUuid, DateTimeImmutable $occurredAt): void
    {
        try {
            $entry = $this->presence->stateOf($employeeUuid);

            if ($entry === null) {
                // Ya no esta en plantilla: no hay fila que pintar. Ocurre cuando
                // se corrige el registro de alguien despues de darle de baja, que
                // es una operacion legitima (regla dura 5).
                return;
            }

            $this->events->dispatch(new PresenceUpdated($entry, $occurredAt));
        } catch (Throwable $failure) {
            if (DependencyFailure::dependencyOf($failure) === DependencyFailure::DATABASE && $this->retryLater($employeeUuid, $failure)) {
                return;
            }

            // Regla dura 19: nada de la vista en vivo puede impedir un fichaje.
            // El identificador si, el nombre jamas (regla dura 21), y la clase
            // de la excepcion, nunca su mensaje: el del driver lleva el host.
            $this->logger->warning('reporting.presence_broadcast_failed', [
                'employee_uuid' => $employeeUuid,
                'exception' => $failure::class,
            ]);
        }
    }

    /**
     * Devuelve el trabajo a la cola si hay cola y queda plazo. `true` si se ha
     * ocupado del fallo (devuelto, o abandonado con su aviso).
     */
    private function retryLater(string $employeeUuid, Throwable $failure): bool
    {
        $job = $this->job;

        if ($job === null || $job instanceof SyncJob) {
            // `deferred` o `sync`: no hay cola a la que volver.
            return false;
        }

        // `createdAt` lo fija Laravel al despachar y viaja intacto en cada
        // `release()`. Sin el no hay plazo que medir, y devolverlo seria
        // reintentarlo sin fin: se abandona.
        $queuedAt = $job->payload()['createdAt'] ?? null;

        if (! is_int($queuedAt) || $this->clock->now()->getTimestamp() - $queuedAt >= self::RETRY_WINDOW_SECONDS) {
            $this->logger->warning('reporting.presence_broadcast_expired', [
                'employee_uuid' => $employeeUuid,
                'exception' => $failure::class,
                'retry_window_seconds' => self::RETRY_WINDOW_SECONDS,
            ]);

            return true;
        }

        $this->release(DatabaseUnavailable::foundIn($failure)->retryAfterSeconds ?? self::DEFAULT_RELEASE_SECONDS);

        return true;
    }
}
