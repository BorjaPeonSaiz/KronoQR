<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttribution;
use App\Modules\Shared\Domain\ValueObject\CredentialHolder;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use DateTimeImmutable;

/**
 * **¿De quien es este fichaje descartado?** (RN-22, ADR-047).
 *
 * Decide a quien atribuir un aviso, y solo con lo que **no se puede fabricar**
 * con un token de quiosco robado:
 *
 * - **Por tarjeta**, solo si el `qr_payload` es **autentico**: resuelve a una
 *   credencial vigente, o es una tarjeta retirada usada **antes** de su retirada
 *   y despues de su emision —la misma regla que RN-20, {@see WithdrawnCredentialPolicy}—.
 *   El `token_hash` del padron, que un quiosco robado si conoce, no atribuye:
 *   hace falta la firma, es decir, la tarjeta fisica.
 * - **Por PIN**, solo si el codigo es de una persona que **puede fichar**: el
 *   techo de RN-19 (ADR-043). El PIN no viaja ni se comprueba.
 *
 * En cualquier otro caso, `none`: el aviso se guarda y no abre nada.
 *
 * Pura: no conoce el reloj (regla dura 2). La recepcion llega resuelta.
 */
final readonly class DiscardedScanAttributionPolicy
{
    public function forCard(
        CredentialResolution $resolution,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $recordedAt,
    ): DiscardedScanAttribution {
        $owner = $resolution->employeeUuid();
        $issuedAt = $resolution->issuedAt();

        if ($owner !== null) {
            // Vigente: la emision acota por abajo en la revision diaria. Sin
            // emision conocida no hay cota, y sin cota no se atribuye (F6).
            return $issuedAt instanceof DateTimeImmutable
                ? DiscardedScanAttribution::credential($owner, $issuedAt)
                : DiscardedScanAttribution::none();
        }

        $holder = $resolution->holder();

        if ($holder instanceof CredentialHolder
            && (new WithdrawnCredentialPolicy)->requiresReview($occurredAt, $holder->issuedAt, $holder->withdrawnAt ?? $recordedAt)) {
            return DiscardedScanAttribution::credential($holder->employeeUuid, $holder->issuedAt);
        }

        return DiscardedScanAttribution::none();
    }

    public function forEmployeeCode(?EmployeeSnapshot $owner): DiscardedScanAttribution
    {
        return $owner instanceof EmployeeSnapshot && $owner->canClock()
            ? DiscardedScanAttribution::employeeCode($owner->employeeUuid)
            : DiscardedScanAttribution::none();
    }
}
