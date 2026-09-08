<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Domain\ValueObject\TelemetryBlockReason;
use App\Modules\Product\Domain\ValueObject\TelemetryDelivery;

/**
 * Lo que ocurrio al pedir un envio, o al pedir la vista previa (**RF-PD-12**).
 *
 * ## Tres formas y una sola clase
 *
 * 1. **Bloqueado** (`blocked()`): falta alguna de las tres condiciones. **No hay
 *    informe**, y eso es la garantia, no un detalle: con la telemetria apagada no
 *    se construye nada, no se lee ni un contador y no se toca la red.
 * 2. **Vista previa** (`preview()`): el documento que se enviaria, con el motivo
 *    por el que hoy no se envia si lo hay. Es lo que imprime
 *    `php artisan product:telemetry` sin `--send`.
 * 3. **Intentado** (`attempted()`): informe y resultado de la entrega, que puede
 *    ser un fallo. Un fallo aqui **no es un fallo del comando**.
 */
final readonly class TelemetryOutcome
{
    private function __construct(
        public ?TelemetryBlockReason $blockedBy,
        public ?TelemetryDraft $draft,
        public ?TelemetryDelivery $delivery,
        /**
         * Si el `installation_id` del informe es el que ya esta guardado.
         *
         * `false` en una vista previa de una instalacion que todavia no ha
         * enviado nunca: el identificador que se enseña es **provisional** y el
         * definitivo sera el del primer envio. El comando lo dice con esas
         * palabras, porque un identificador que cambia sin avisar entre dos
         * ejecuciones es lo que haria dudar de que este documento sea de fiar.
         */
        public bool $identityStored = true,
    ) {}

    public static function blocked(TelemetryBlockReason $reason): self
    {
        return new self($reason, null, null);
    }

    public static function preview(TelemetryDraft $draft, ?TelemetryBlockReason $reason, bool $identityStored): self
    {
        return new self($reason, $draft, null, $identityStored);
    }

    public static function attempted(TelemetryDraft $draft, TelemetryDelivery $delivery): self
    {
        return new self(null, $draft, $delivery);
    }

    public function isEnabled(): bool
    {
        return ! $this->blockedBy instanceof TelemetryBlockReason;
    }
}
