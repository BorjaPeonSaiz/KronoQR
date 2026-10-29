<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use DateTimeImmutable;

/**
 * **¿Valia la tarjeta cuando se uso?** (RN-20, RN-14, ADR-047).
 *
 * Un escaneo con una tarjeta autentica que se rechaza porque la credencial ya
 * esta retirada —o porque su titular esta de baja— pide revision humana si su
 * hora real cae **dentro de la vida de la tarjeta**: desde su emision (incluida)
 * hasta su retirada (excluida). Es una tarjeta que valia cuando se paso,
 * tipicamente un fichaje de la cola offline del ultimo dia que llego despues de
 * registrarse la baja.
 *
 * - **Retirada: limite abierto.** En el mismo instante de la retirada, o
 *   despues, la tarjeta ya no valia. Un reloj de tablet adelantado que pone el
 *   `occurred_at` despues de la retirada cae del mismo lado: no se puede afirmar
 *   que el fichaje fuera anterior.
 * - **Emision: limite cerrado**. El `occurred_at`
 *   lo pone la tablet: sin cota inferior, quien tuviera la tarjeta podria pedir
 *   una incidencia por cada dia que eligiera. Antes de existir, la tarjeta no
 *   pudo usarse.
 *
 * - **El cese: limite abierto** (2.3.0). Si llega `$employmentEndsAt` —el final
 *   del dia civil del cese en la zona del centro—, un escaneo a partir de ese
 *   instante tampoco se marca: despues del cese no hay jornada que revisar. Es
 *   el escaneo offline del dia siguiente que sube despues de que la tarea de
 *   las bajas programadas revoque la tarjeta.
 *
 * Pura: no conoce el reloj (regla dura 2). Los tres instantes llegan resueltos —el
 * de la retirada lo pone el caso de uso: `credentials.revoked_at` o, si la
 * credencial seguia vigente, la recepcion del escaneo—.
 */
final readonly class WithdrawnCredentialPolicy
{
    public function requiresReview(
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $withdrawnAt,
        ?DateTimeImmutable $employmentEndsAt = null,
    ): bool {
        return $issuedAt <= $occurredAt && $occurredAt < $withdrawnAt
            && (! $employmentEndsAt instanceof DateTimeImmutable || $occurredAt < $employmentEndsAt);
    }
}
