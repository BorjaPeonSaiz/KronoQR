<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Por donde se avisó de que el informe estaba listo (**RF-IN-06**, decision 8 de
 * la ficha 3.9).
 *
 * ## Dos valores, y `panel` no significa «no se aviso»
 *
 * - `panel` — el aviso es la propia pantalla de informes, que sondea la lista
 *   mientras haya una exportacion en curso y enseña «lista para descargar».
 *   **Siempre ocurre**, y es el unico canal en una instalacion sin salida a
 *   internet o con `MAIL_MAILER=log` (doc 02 §11.6.2).
 * - `mail` — ademas, se envio un correo a la cuenta de gestion que lo pidio, con
 *   el enlace **a la pantalla** y la fecha de caducidad del fichero. Nunca con
 *   el enlace de descarga (decision 3: el enlace es de un solo uso, y uno
 *   quemado en un buzon compartido seria un enlace que no funciona).
 *
 * Guardar cual de los dos fue es lo que permite responder «¿por que no me llego
 * el correo?» sin mirar los logs: si la fila dice `panel`, el producto no lo
 * intento —no hay SMTP configurado o la cuenta no tiene direccion— y no hay nada
 * que buscar en el servidor de correo.
 *
 * **Un fallo de envio deja `panel`**, no `failed`: el producto no depende del
 * correo (regla dura 12) y un informe generado sigue estando generado.
 */
enum ReportExportNotificationChannel: string
{
    case Panel = 'panel';

    case Mail = 'mail';

    /**
     * El catalogo, para el `CHECK` de la migracion.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $channel): string => $channel->value, self::cases());
    }
}
