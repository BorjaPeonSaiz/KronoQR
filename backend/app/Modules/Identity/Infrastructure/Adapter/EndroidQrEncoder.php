<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Domain\ValueObject\QrPayload;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use SensitiveParameter;

/**
 * Dibuja el QR de una tarjeta (RF-QR-05).
 *
 * ## Nivel de correccion de errores Q, y por que importa
 *
 * RF-QR-05 lo exige y el Anexo B lo declara como `QR_ERROR_CORRECTION=Q`. El
 * doc 02 §5.1 justifica el margen: *«es lo que permite que una tarjeta sobreviva
 * una temporada de uso diario en una cocina, con roces, grasa y dobleces»*. Con
 * `Q` se recupera hasta el 25 % de los modulos dañados; con `L` —el valor por
 * defecto de la libreria, que aqui **no se usa nunca**— se recupera el 7 % y la
 * tarjeta deja de leerse en marzo.
 *
 * El nivel llega ya resuelto desde la configuracion, como los umbrales legales
 * (regla dura 14): esta clase no consulta `config()`.
 *
 * ## SVG y no PNG
 *
 * El QR va dentro de un PDF que se imprime. Un PNG hay que generarlo a una
 * resolucion concreta y, si la impresora es de 600 ppp y el bitmap de 300, los
 * modulos salen con el borde emborronado — que es exactamente lo que el nivel de
 * correccion existe para compensar, gastado antes de que la tarjeta salga de la
 * oficina. El SVG es vectorial: cada modulo es un rectangulo con aristas exactas
 * a cualquier resolucion. Ademas no necesita la extension GD.
 *
 * ## Margen cero
 *
 * La «zona tranquila» del estandar la pone la plantilla como espacio en blanco
 * alrededor del QR, en milimetros de la tarjeta. Dejarsela a la libreria daria un
 * margen en modulos que cambia de tamaño con el contenido, y el hueco reservado
 * en la tarjeta es fijo.
 *
 * ## El payload no se registra en ningun sitio
 *
 * El texto que entra aqui es `FH1.<key_id>.<token>.<sig>`: quien lo tenga puede
 * fichar por su dueno (regla dura 21, doc 02 §5.2). El parametro va marcado con
 * `#[SensitiveParameter]` para que no aparezca en la traza de ninguna excepcion.
 */
final readonly class EndroidQrEncoder
{
    public function __construct(
        /** `L`, `M`, `Q` o `H`. El producto usa `Q` (RF-QR-05). */
        private string $errorCorrection = 'Q',
    ) {}

    /**
     * Devuelve el QR como URI de datos SVG, listo para el `src` de una etiqueta
     * `<img>` de la plantilla.
     */
    public function dataUriFor(#[SensitiveParameter] QrPayload $payload): string
    {
        $qr = new QrCode(
            data: $payload->toString(),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: $this->level(),
            // El tamaño en pixeles del SVG es irrelevante —lo escala el CSS de la
            // plantilla a los milimetros de RF-QR-05—, pero tiene que ser
            // multiplo del numero de modulos para que ninguno quede con medio
            // pixel de ancho en el `viewBox`.
            size: 512,
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        return (new SvgWriter)->write($qr)->getDataUri();
    }

    /**
     * **Cualquier valor que no reconozca cae en `Quartile`, no en `Low`.**
     *
     * Es la unica forma segura de fallar aqui: una errata en la configuracion de
     * una instalacion —`QR_ERROR_CORRECTION=q1`— no puede degradar en silencio la
     * tolerancia de todas las tarjetas de ese hotel. El sintoma seria invisible
     * durante meses y despues seria una temporada entera de tarjetas ilegibles.
     */
    private function level(): ErrorCorrectionLevel
    {
        return match (strtoupper(trim($this->errorCorrection))) {
            'L' => ErrorCorrectionLevel::Low,
            'M' => ErrorCorrectionLevel::Medium,
            'H' => ErrorCorrectionLevel::High,
            default => ErrorCorrectionLevel::Quartile,
        };
    }
}
