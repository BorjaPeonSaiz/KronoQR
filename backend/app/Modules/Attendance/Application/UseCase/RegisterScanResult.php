<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Port\ScanResult;
use DateTimeImmutable;

/**
 * Lo que el caso de uso devuelve, ya decidido: **un resultado tipado**, no un
 * array ni un modelo.
 *
 * Tres desenlaces y ni uno mas (ADR-031, contrato `POST /api/v1/scan`):
 *
 * | Desenlace | Que paso | Que responde el endpoint |
 * |---|---|---|
 * | Aceptado | Se abrio o se cerro un tramo | `200` con `ScanAccepted` |
 * | Anti-rebote | Se proceso y **no cambio nada** (RF-AT-06) | `200` con `ScanDebounced` |
 * | Rechazado | La credencial no resolvio o el empleado no puede fichar | `422` generico |
 *
 * **`result` es el desenlace DETALLADO y no puede salir por la API.** Lleva
 * `rejected_signature`, `rejected_revoked` o `rejected_unknown` porque la
 * metrica `scans_total{device,result}` y el log tienen que distinguirlos
 * (doc 02 §8.2, escenario *QR falsificado* del doc 01 §11), pero el cuerpo del
 * `422` es identico para los tres (RS-03, regla dura 17). Quien lo serializa es
 * un `Resource` que solo mira los campos del esquema, y el contrato lo blinda:
 * `ScanRejected` tiene sus cuatro campos clavados a un valor unico y
 * `additionalProperties: false`.
 *
 * **`employeeDisplayName` es lo unico personal que sale**, y sale en su forma
 * minima —nombre de pila e inicial del primer apellido— porque RF-AT-05 obliga
 * al quiosco a confirmar con el nombre. **Jamas puede acabar en un log ni en
 * `error_events`** (regla dura 21): ahi se identifica por `employeeUuid`.
 */
final readonly class RegisterScanResult
{
    /**
     * @param  string|null  $workDate  Jornada del tramo, `YYYY-MM-DD`. Nula en el anti-rebote
     *                                 y en el rechazo: no se creo ningun tramo que atribuir.
     * @param  int  $workedMinutes  Acumulado de la jornada, **recalculado** (RN-06,
     *                              regla dura 7). En el anti-rebote es el mismo que devolvio
     *                              el escaneo anterior.
     * @param  DateTimeImmutable|null  $lastAcceptedAt  Solo en el anti-rebote: el escaneo contra
     *                                                  el que se midio la ventana de gracia.
     */
    private function __construct(
        public string $scanId,
        public ScanResult $result,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $recordedAt,
        public ?string $employeeUuid = null,
        public ?string $employeeDisplayName = null,
        public ?string $workDate = null,
        public int $workedMinutes = 0,
        public ?DateTimeImmutable $lastAcceptedAt = null,
        /** `true` cuando este resultado se reconstruyo de un escaneo ya registrado (RF-AT-07). */
        public bool $isReplay = false,
    ) {}

    /**
     * Se abrio o se cerro un tramo.
     */
    public static function accepted(
        string $scanId,
        ScanResult $result,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $recordedAt,
        string $employeeUuid,
        string $employeeDisplayName,
        string $workDate,
        int $workedMinutes,
        bool $isReplay = false,
    ): self {
        return new self(
            scanId: $scanId,
            result: $result,
            occurredAt: $occurredAt,
            recordedAt: $recordedAt,
            employeeUuid: $employeeUuid,
            employeeDisplayName: $employeeDisplayName,
            workDate: $workDate,
            workedMinutes: $workedMinutes,
            isReplay: $isReplay,
        );
    }

    /**
     * RF-AT-06: el escaneo se proceso y deliberadamente no cambio nada.
     */
    public static function debounced(
        string $scanId,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $recordedAt,
        string $employeeUuid,
        string $employeeDisplayName,
        int $workedMinutes,
        DateTimeImmutable $lastAcceptedAt,
        bool $isReplay = false,
    ): self {
        return new self(
            scanId: $scanId,
            result: ScanResult::REJECTED_DEBOUNCE,
            occurredAt: $occurredAt,
            recordedAt: $recordedAt,
            employeeUuid: $employeeUuid,
            employeeDisplayName: $employeeDisplayName,
            workedMinutes: $workedMinutes,
            lastAcceptedAt: $lastAcceptedAt,
            isReplay: $isReplay,
        );
    }

    /**
     * El escaneo no produjo tramo y **la causa no sale de aqui**.
     *
     * `employeeUuid` puede ser conocido —una credencial valida de alguien de
     * baja (RN-14)— y se conserva para el log y para `scan_events`, jamas para
     * la respuesta.
     */
    public static function rejected(
        string $scanId,
        ScanResult $result,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $recordedAt,
        ?string $employeeUuid = null,
        bool $isReplay = false,
    ): self {
        return new self(
            scanId: $scanId,
            result: $result,
            occurredAt: $occurredAt,
            recordedAt: $recordedAt,
            employeeUuid: $employeeUuid,
            isReplay: $isReplay,
        );
    }

    public function isAccepted(): bool
    {
        return $this->result->isAccepted();
    }

    public function isDebounced(): bool
    {
        return $this->result->isDebounce();
    }

    /**
     * Si el endpoint tiene que responder el `422` generico.
     *
     * El anti-rebote **no** entra aqui aunque el dominio lo registre como
     * `rejected_debounce`: es un desenlace aceptado y viaja en un `200`
     * (ADR-031). Preguntarlo por este metodo, y no comparando enums en el
     * controlador, es lo que impide que los dos conceptos se confundan en el
     * unico sitio donde confundirlos rompe la cola offline.
     */
    public function isRejected(): bool
    {
        return $this->result->isRejection() && ! $this->result->isDebounce();
    }
}
