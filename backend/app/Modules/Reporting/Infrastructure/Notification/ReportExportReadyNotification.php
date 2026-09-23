<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Notification;

use App\Modules\Reporting\Application\Port\ReportExportRecipient;
use App\Modules\Reporting\Domain\Model\ReportExport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

/**
 * «Tu informe esta listo» (**RF-IN-06**, decision 8 de la ficha 3.9).
 *
 * ## NUNCA lleva el enlace de descarga
 *
 * Y esto es lo mas importante de esta clase. El enlace es de **un solo uso**
 * (ADR-041): uno quemado en un buzon compartido —o consumido por el antivirus de
 * correo del hotel, que sigue los enlaces de todo lo que entra— seria un enlace
 * que no funciona justo cuando la persona lo pulsa. Ademas caduca en quince
 * minutos y un correo puede tardar mas en llegar.
 *
 * Lo que lleva es el enlace **a la pantalla** de informes, donde el boton pide un
 * enlace fresco. Por eso tampoco hay nada que proteger en este mensaje: un correo
 * interceptado no da acceso a nada que el destinatario no tuviera ya.
 *
 * ## Ruta relativa, no URL absoluta
 *
 * El panel de cada cliente vive en un dominio distinto (ADR-016, ADR-017). El
 * correo dice **que** hay que abrir, no una URL que en la instalacion de al lado
 * seria falsa. Mismo criterio que el resumen de incidencias.
 *
 * ## Lo que si dice, y lo que no
 *
 * El periodo, el formato, cuantas filas lleva y **hasta cuando existe el
 * fichero**: esa fecha es la mitad util del aviso, porque la retencion de serie
 * son siete dias y quien lo pidio tiene que saber que no puede dejarlo para el
 * mes que viene.
 *
 * **Ni un nombre de empleado ni una hora trabajada** (regla dura 21). Un correo
 * sale del servidor del cliente hacia un servidor de correo que puede ser de un
 * tercero; el contenido del informe se queda en el fichero, detras del enlace de
 * un solo uso.
 *
 * ## Sincrona, sin `ShouldQueue`
 *
 * Por lo mismo que el resumen de incidencias: encolada, el `try/catch` del
 * notificador veria un exito **siempre** —un SMTP mal configurado falla despues,
 * en el trabajador— y la fila quedaria sellada como `mail` sobre un aviso que
 * nadie recibio. Y aqui ademas ya estamos dentro de un trabajo en cola: encolar
 * desde la cola solo añadiria un salto.
 */
final class ReportExportReadyNotification extends Notification
{
    public function __construct(
        private readonly ReportExport $export,
        private readonly ReportExportRecipient $recipient,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->recipient->locale;

        return (new MailMessage)
            ->subject(Lang::get('report-exports.mail.subject', [], $locale))
            ->greeting(Lang::get('report-exports.mail.greeting', ['name' => $this->recipient->name], $locale))
            ->line(Lang::get('report-exports.mail.intro', [
                'from' => $this->export->parameters->from,
                'to' => $this->export->parameters->to,
                'format' => strtoupper($this->export->format),
            ], $locale))
            ->line(Lang::get('report-exports.mail.rows', [
                'rows' => (string) ($this->export->rowCount ?? 0),
            ], $locale))
            // La caducidad va en una linea propia: es lo que decide si quien lo
            // lee tiene que hacer algo hoy o puede esperar.
            ->line(Lang::get('report-exports.mail.expires', [
                'date' => $this->export->expiresAt?->format('Y-m-d H:i') ?? '',
            ], $locale))
            ->line(Lang::get('report-exports.mail.where', [], $locale))
            ->line(Lang::get('report-exports.mail.single_use', [], $locale))
            ->line(Lang::get('report-exports.mail.footer', [], $locale));
    }
}
