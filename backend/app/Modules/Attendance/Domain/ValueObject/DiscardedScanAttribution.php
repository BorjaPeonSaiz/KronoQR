<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * **A quien se atribuye un aviso de fichaje descartado**, y por que via (RN-22,
 * ADR-047).
 *
 * Es el resultado de atribuir, que es lo unico que se guarda: ni el contenido
 * del QR ni el codigo tecleado salen de la peticion (RS-03, regla dura 21). El
 * dueño viaja por su `employee_uuid`.
 *
 * Por tarjeta lleva ademas la **emision** de la credencial (`credentials.
 * issued_at`): es la cota inferior con la que la revision diaria descarta un
 * `occurred_at` anterior a que la tarjeta existiera (F6 del dictamen del bloque
 * 18). El constructor privado hace imposibles los estados incoherentes, el mismo
 * `CHECK discarded_scan_reports_chk_attribution` escrito en PHP.
 */
final readonly class DiscardedScanAttribution
{
    private function __construct(
        public DiscardedScanAttributionMethod $method,
        public ?string $ownerUuid,
        public ?DateTimeImmutable $credentialIssuedAt,
    ) {}

    public static function credential(string $ownerUuid, DateTimeImmutable $issuedAt): self
    {
        self::assertOwner($ownerUuid);

        return new self(
            DiscardedScanAttributionMethod::CREDENTIAL,
            $ownerUuid,
            $issuedAt->setTimezone(new DateTimeZone('UTC')),
        );
    }

    public static function employeeCode(string $ownerUuid): self
    {
        self::assertOwner($ownerUuid);

        return new self(DiscardedScanAttributionMethod::EMPLOYEE_CODE, $ownerUuid, null);
    }

    public static function none(): self
    {
        return new self(DiscardedScanAttributionMethod::NONE, null, null);
    }

    public function isAttributed(): bool
    {
        return $this->ownerUuid !== null;
    }

    private static function assertOwner(string $ownerUuid): void
    {
        if ($ownerUuid === '') {
            throw new InvalidArgumentException('Un aviso atribuido necesita el UUID de su dueño.');
        }
    }
}
