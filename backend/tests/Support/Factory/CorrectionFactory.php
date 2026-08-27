<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use App\Modules\Attendance\Domain\ValueObject\Correction;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReasonCode;
use DateTimeImmutable;
use Tests\Support\Time\Instants;

/**
 * La firma de una correccion —autor, momento y motivo— para las pruebas de
 * dominio (RN-13).
 *
 * El momento por defecto es posterior a las jornadas que usan las pruebas, que
 * es lo normal: se corrige despues de trabajar. Ninguna prueba que hable del
 * momento lo da por sabido; lo escribe con `at()`.
 */
final class CorrectionFactory
{
    private int $performedByUserId = 7;

    private DateTimeImmutable $performedAt;

    private CorrectionReasonCode $code = CorrectionReasonCode::OLVIDO_FICHAJE_SALIDA;

    private ?string $text = null;

    private function __construct()
    {
        $this->performedAt = Instants::utc('2026-03-16 09:00');
    }

    public static function new(): self
    {
        return new self;
    }

    /** Motivo de catalogo, autor 7 y momento fijo: lo que sirve cuando la prueba habla de otra cosa. */
    public static function standard(): Correction
    {
        return self::new()->build();
    }

    public function by(int $performedByUserId): self
    {
        $clone = clone $this;
        $clone->performedByUserId = $performedByUserId;

        return $clone;
    }

    public function at(string $utcWallClock): self
    {
        $clone = clone $this;
        $clone->performedAt = Instants::utc($utcWallClock);

        return $clone;
    }

    public function because(CorrectionReasonCode $code, ?string $text = null): self
    {
        $clone = clone $this;
        $clone->code = $code;
        $clone->text = $text;

        return $clone;
    }

    public function build(): Correction
    {
        return Correction::by(
            $this->performedByUserId,
            $this->performedAt,
            CorrectionReason::of($this->code, $this->text),
        );
    }
}
