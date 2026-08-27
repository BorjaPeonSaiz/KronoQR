<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\ValueObject\CardFormat;
use App\Modules\Identity\Domain\ValueObject\PrintableCard;

/**
 * Convierte tarjetas en un PDF (RF-QR-04, RF-QR-05).
 *
 * **Es un puerto porque detras hay un proceso externo.** El adaptador de
 * produccion dibuja el QR con `endroid/qr-code` y el PDF con `spatie/laravel-pdf`,
 * que arranca un Chromium. Nada de eso puede entrar en un caso de uso: haria
 * imposible probar la impresion sin un navegador y ataria la secuencia de
 * acuñado —que si es regla de negocio— a una libreria de terceros.
 *
 * **Devuelve los bytes, no una ruta.** El PDF de una tarjeta es un instrumento al
 * portador: quien lo tenga puede fabricar la tarjeta de otra persona y fichar por
 * ella. No se escribe en `storage/`, no se sube a ningun disco, no viaja en la
 * carga util de un trabajo en cola y no se envia por correo. El endpoint lo
 * **transmite** y el comando de consola lo escribe donde el operador pida,
 * asumiendo el operador la responsabilidad de borrarlo tras imprimir (runbook
 * `alta-nuevo-empleado.md`). Una firma que devolviera una ruta invitaria
 * exactamente a lo contrario.
 *
 * **Un documento, no N documentos.** El lote de un centro completo es una sola
 * llamada con las N tarjetas dentro: una invocacion de Chromium en lugar de
 * sesenta, y una sola respuesta que imprimir de una tirada.
 */
interface CardRenderer
{
    /**
     * @param  list<PrintableCard>  $cards
     * @return string Los bytes del PDF. **Nunca se registran en un log.**
     */
    public function render(array $cards, CardFormat $format): string;
}
