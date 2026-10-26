<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Domain\ValueObject\CardFormat;
use App\Modules\Shared\Application\Port\PdfRenderingSlots;

/**
 * Las tarjetas pasan por el tope de Chromium simultaneos de la instalacion
 * (`reporting.pdf.max_concurrent`), delante de
 * {@see BrowsershotCardRenderer}. Imprimir un lote mientras otras dos personas
 * descargan un PDF no arranca un tercer Chromium: espera un hueco y, si no lo
 * hay, el borde responde `503` `pdf-rendering-busy` con `Retry-After`.
 *
 * El PDF se compone ANTES de la transaccion de `MintCards`: un rechazo aqui no
 * marca ninguna credencial como impresa, y reintentar es seguro.
 */
final readonly class ConcurrencyLimitedCardRenderer implements CardRenderer
{
    public function __construct(
        private CardRenderer $engine,
        private PdfRenderingSlots $slots,
        private int $waitSeconds,
    ) {}

    public function render(array $cards, CardFormat $format): string
    {
        if ($cards === []) {
            // El motor no arranca Chromium para un documento vacio; tampoco se
            // toma un hueco para el.
            return $this->engine->render($cards, $format);
        }

        return $this->slots->compose(
            fn (): string => $this->engine->render($cards, $format),
            $this->waitSeconds,
        );
    }
}
