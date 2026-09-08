<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Que paquete se ha pedido (contrato `DiagnosticsBundleRequest`, RL-19).
 *
 * ## El valor por defecto ES el producto
 *
 * `anonymized()` sin argumentos es lo que devuelven el endpoint sin cuerpo, el
 * comando sin banderas y el panel con la casilla desmarcada. La mayoria de los
 * clientes no cambiara nada, asi que el valor por defecto tiene que ser el
 * seguro: **anonimizado** (ADR-020).
 *
 * ## `periodDays` no tiene efecto sin datos personales
 *
 * Y no es un descuido: el paquete anonimizado **no lleva registros de jornada de
 * ningun periodo** (RL-19). Aceptar `period_days` a solas y no hacer nada con el
 * es preferible a rechazarlo, porque el panel envia los dos campos juntos.
 */
final readonly class DiagnosticsOptions
{
    /** Dias por defecto del periodo de `personal_data` (contrato, `default: 7`). */
    public const int DEFAULT_PERIOD_DAYS = 7;

    private function __construct(
        public bool $includePersonalData,
        public int $periodDays,
    ) {}

    public static function anonymized(): self
    {
        return new self(false, self::DEFAULT_PERIOD_DAYS);
    }

    public static function withPersonalData(int $periodDays): self
    {
        return new self(true, max(1, $periodDays));
    }
}
