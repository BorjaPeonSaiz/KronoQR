<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\InstructionsSheetRenderer;
use App\Modules\Shared\Application\Port\PdfRenderingSlots;
use App\Modules\Shared\Domain\ValueObject\Branding;

/**
 * La hoja de instrucciones pasa por el tope de Chromium simultaneos de la
 * instalacion (`reporting.pdf.max_concurrent`), delante de
 * {@see BrowsershotInstructionsSheetRenderer}. Sin hueco libre tras la espera,
 * el borde responde `503` `pdf-rendering-busy` con `Retry-After`.
 */
final readonly class ConcurrencyLimitedInstructionsSheetRenderer implements InstructionsSheetRenderer
{
    public function __construct(
        private InstructionsSheetRenderer $engine,
        private PdfRenderingSlots $slots,
        private int $waitSeconds,
    ) {}

    public function render(string $locale, Branding $brand, string $portalUrl): string
    {
        return $this->slots->compose(
            fn (): string => $this->engine->render($locale, $brand, $portalUrl),
            $this->waitSeconds,
        );
    }
}
