<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\Port\SetupFacts;
use App\Modules\Product\Application\Port\SetupProgressRepository;
use App\Modules\Product\Domain\Event\SetupCompleted;
use App\Modules\Product\Domain\Exception\SetupNotCompletable;
use App\Modules\Product\Domain\ValueObject\SetupStep;
use App\Modules\Product\Domain\ValueObject\SetupSummary;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * Cierra el asistente de puesta en marcha **para siempre** (**RF-PD-03**,
 * `POST /api/v1/setup/complete`).
 *
 * ## No se cierra solo
 *
 * Se podria haber calculado «cerrado» como «todos los pasos resueltos» y
 * ahorrarse la marca. Se descarto por una razon concreta: el asistente pasaria a
 * `available: false` en el instante de resolver el ultimo paso, y el panel
 * saltaria a la pantalla de acceso **justo antes** de enseñar el resumen final —
 * que es la pantalla que dice cuantas tarjetas quedan por imprimir, y la unica
 * oportunidad de decirlo a tiempo.
 *
 * ## Y no se reabre
 *
 * No hay metodo para volver atras. Un asistente reabrible seria una via para
 * reconfigurar la instalacion —zona horaria del centro incluida— sin el asiento
 * que RL-04 exige. Lo que se cambia despues se cambia por su recurso.
 *
 * ## El resumen se calcula **despues** de cerrar
 *
 * Para que las cifras describan el estado con el que la instalacion se queda, no
 * el de un instante antes. La diferencia es de milisegundos y aun asi es la
 * correcta: el resumen es lo que la persona se lleva apuntado.
 *
 * ## Y el cierre se **audita**
 *
 * La marca de `setup_progress` y el asiento de `audit_log` se escriben en la
 * misma transaccion. El oyente de `Compliance` es sincrono (ADR-027): si el
 * asiento falla, el asistente no se cierra y se puede reintentar. Cerrar sin
 * traza la unica puerta que se cierra para siempre seria justo lo contrario de
 * lo que justifica que no se reabra (RL-04, regla dura 6).
 */
final readonly class CompleteSetupHandler
{
    /**
     * Clave del candado consultivo del cierre del asistente.
     *
     * Misma convencion que el resto del producto: un entero fijo y unico,
     * compuesto del numero de fase y de tarea, porque el espacio de
     * `pg_advisory_lock` es global a la base de datos. 5.5 → `5_050_00X`; el
     * `1` lo tiene el alta del primer administrador.
     *
     * Serializa dos cierres simultaneos —dos pestañas del panel, o un doble
     * clic— para que el segundo lea la marca del primero **ya confirmada** y
     * salga por el `409`, en vez de escribir un segundo asiento `setup.completed`
     * de un cierre que ya habia ocurrido.
     */
    private const int LOCK_KEY = 5_050_002;

    public function __construct(
        private SetupProgressRepository $progress,
        private GetSetupStateHandler $state,
        private SetupFacts $facts,
        private GetLicenseStatusHandler $license,
        private ProductEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws SetupNotCompletable si queda algun paso sin resolver, o ya estaba cerrado
     */
    public function handle(?string $actorUuid): CompletedSetup
    {
        $this->connection->transaction(function () use ($actorUuid): void {
            $this->connection->statement('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            // Dentro del candado, para que la comprobacion valga: leerlo fuera
            // dejaria pasar dos cierres que se cruzan.
            $state = $this->state->handle();

            if (! $state->isAvailable()) {
                throw SetupNotCompletable::becauseItAlreadyIs();
            }

            $pending = $state->unresolvedSteps();

            if ($pending !== []) {
                throw SetupNotCompletable::withPendingSteps($pending);
            }

            $at = $this->clock->now();

            $this->progress->complete($at, $actorUuid);

            // Sincrono y dentro de la transaccion (ADR-027): sin asiento no hay
            // cierre. Quien lo hizo NO viaja en el evento —lo resuelve el oyente
            // con la sesion en curso, igual que en todo `Compliance`—; el
            // `$actorUuid` de arriba es otra cosa: la columna
            // `setup_progress.recorded_by_user_id`.
            $this->events->publish(new SetupCompleted(
                skippedSteps: array_map(
                    static fn (SetupStep $step): string => $step->value,
                    $state->skippedSteps(),
                ),
                occurredAt: $at,
            ));
        });

        return new CompletedSetup($this->state->handle(), $this->summary());
    }

    private function summary(): SetupSummary
    {
        return new SetupSummary(
            employees: $this->facts->activeEmployees(),
            departments: $this->facts->departments(),
            credentialsPending: $this->facts->employeesWithoutUsableCredential(),
            // Por el punto unico de resolucion de la 5.3 y no por una consulta
            // propia: es el mismo estado que ve `GET /api/v1/license`, la sonda
            // de salud y `license:show`. Aqui solo se informa —`absent` es lo
            // normal en una puesta en marcha— y no condiciona nada (ADR-019).
            license: $this->license->handle()->state,
            kiosks: $this->facts->activeKiosks(),
        );
    }
}
