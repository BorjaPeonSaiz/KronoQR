<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Domain\ValueObject\CardFormat;
use App\Modules\Identity\Domain\ValueObject\PrintableCard;

/**
 * El renderizador de tarjetas, sin Chromium.
 *
 * **Por que existe.** El adaptador de produccion arranca un navegador por proceso
 * externo (`spatie/laravel-pdf` sobre Browsershot). Una prueba de feature que lo
 * usara mediria dos cosas a la vez —la autorizacion y el acuñado por un lado, la
 * presencia de un binario en la maquina por otro— y fallaria de forma
 * intermitente en cuanto el runner de la CI o la imagen de desarrollo no lo
 * tuvieran. Eso no es una prueba, es un detector de entorno.
 *
 * El puerto {@see CardRenderer} existe justamente para esta separacion, y su
 * propio docblock lo dice: *«haria imposible probar la impresion sin un
 * navegador»*. Aqui se ejerce esa separacion.
 *
 * **Lo que sigue probandose de verdad** al usarlo: la ruta, el ambito, la policy,
 * la secuencia de acuñado de ADR-034 (`key_id` + `secret_hash` + `printed_at` en
 * la misma transaccion y una sola vez), el asiento de auditoria y la respuesta
 * HTTP. Lo unico que se sustituye son los bytes del PDF.
 *
 * **Guarda lo que se le pidio dibujar**, para que la prueba pueda afirmar
 * *cuantas* tarjetas se acuñaron y con que formato, que es la parte de la
 * impresion que si es regla de negocio.
 */
final class FakeCardRenderer implements CardRenderer
{
    /**
     * Cabecera de un PDF real. Importa: el controlador anuncia
     * `Content-Type: application/pdf`, y un cuerpo que no lo pareciera dejaria
     * pasar una respuesta mal etiquetada.
     */
    public const string PDF_BYTES = "%PDF-1.7\n% tarjetas de prueba\n%%EOF\n";

    /** @var list<list<PrintableCard>> */
    public array $renderedBatches = [];

    /** @var list<CardFormat> */
    public array $formats = [];

    /**
     * @param  list<PrintableCard>  $cards
     */
    public function render(array $cards, CardFormat $format): string
    {
        $this->renderedBatches[] = $cards;
        $this->formats[] = $format;

        return self::PDF_BYTES;
    }

    /**
     * Cuantas tarjetas se han acuñado en total, sumando todas las llamadas.
     */
    public function cardsRendered(): int
    {
        $total = 0;

        foreach ($this->renderedBatches as $batch) {
            $total += \count($batch);
        }

        return $total;
    }
}
