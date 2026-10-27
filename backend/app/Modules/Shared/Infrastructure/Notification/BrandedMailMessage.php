<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Notification;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

/**
 * El correo del producto con la marca de la instalacion y en el idioma de quien
 * lo recibe (RF-PD-08).
 *
 * ## Por que hace falta
 *
 * `new MailMessage` sale con la plantilla de Laravel, que escribe
 * `config('app.name')` en la cabecera, la despedida y el pie, y las frases
 * «Regards,» y «All rights reserved.» en el idioma del proceso. `APP_NAME` no es
 * la marca —es el prefijo de cache y colas, y la guia prohibe tocarlo—, asi que
 * a RRHH le llegaba «KronoQR» y en ingles aunque la instalacion se llamara como
 * el hotel y estuviera en castellano.
 *
 * Esta fabrica devuelve el mismo `MailMessage` de siempre —las notificaciones
 * siguen encadenando `subject()`, `greeting()` y `line()`—, pero con la plantilla
 * `mail.branded-notification`, el nombre de la instalacion y la despedida ya
 * traducida al idioma pedido. Se traduce aqui y no en la plantilla para que el
 * resultado no dependa del idioma del proceso que la pinte.
 *
 * **Lo que queda de Laravel, a sabiendas**: la envoltura HTML
 * (`mail::layout`) pone `config('app.name')` en el `<title>` y el idioma del
 * proceso en `lang`. Ningun cliente de correo enseña ese `<title>`, y el `lang`
 * sale bien al enviar porque cada notificacion fija su `locale` y Laravel envia
 * dentro de ese idioma. Sustituir la envoltura obligaria a copiarla con sus
 * `{!! !!}`, que `BladeTemplatesTest` prohibe con razon.
 *
 * ## Por que en `Shared`
 *
 * La usan `Compliance` (incidencias) y `Reporting` (resumen semanal e informe
 * listo), que no pueden importarse. Es presentacion de correo, no regla de
 * negocio de ninguno.
 */
final class BrandedMailMessage
{
    public const string VIEW = 'mail.branded-notification';

    /**
     * @param  string  $brandName  `Branding::$applicationName`, ya resuelto por el puerto.
     * @param  string  $locale  El idioma del destinatario (o el de la instalacion).
     */
    public static function create(string $brandName, string $locale): MailMessage
    {
        $salutation = Lang::get('mail.salutation', [], $locale);

        return (new MailMessage)
            ->markdown(self::VIEW, ['brandName' => $brandName])
            ->salutation(\is_string($salutation) ? $salutation : '');
    }
}
