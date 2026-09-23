<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Notification;

use App\Modules\Reporting\Application\Port\ReportExportNotifier;
use App\Modules\Reporting\Application\Port\ReportExportRecipients;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportNotificationChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Entrega el aviso por correo si la instalacion tiene por donde (**RF-IN-06**,
 * decision 8 de la ficha 3.9).
 *
 * ## Tres condiciones, y si falta una el canal es `panel`
 *
 * 1. **Un transporte real.** `MAIL_MAILER` distinto de `log` y de `array`: con
 *    cualquiera de esos dos, «enviar» significa escribir en un fichero o en
 *    memoria, y sellar la fila como `mail` seria mentir en la pantalla. Doc 02
 *    §11.6.2 admite instalaciones sin salida a internet, donde lo unico que se
 *    pierde es el correo.
 * 2. **Una cuenta resoluble.** Desactivada o borrada, no hay a quien escribir.
 * 3. **Una direccion.** Las cuentas de gestion la tienen —`users.email` es
 *    obligatorio y unico—, pero la comprobacion se hace igual: una fila antigua o
 *    importada puede no traerla.
 *
 * Que falte cualquiera de las tres **no es un fallo**. El canal de serie es la
 * pantalla, que sondea la lista mientras haya una exportacion en curso; el correo
 * es comodidad.
 *
 * ## Un fallo de correo nunca marca la exportacion como fallida
 *
 * Regla dura 12: el producto no depende del correo. Se atrapa `Throwable`, se
 * registra como `warning` y se devuelve `panel`. Un servidor SMTP mal configurado
 * es lo mas comun de una instalacion recien puesta en marcha, y no puede
 * convertirse en un informe que «ha fallado» cuando esta generado y descargable.
 *
 * **Para que ese `catch` signifique algo, el envio es SINCRONO.** La notificacion
 * no lleva `ShouldQueue`: con ella, `notify()` solo encolaba otro trabajo, este
 * bloque veia un exito siempre y el sello se escribia sobre avisos que nadie
 * llegaba a recibir. Un aviso perdido en silencio es peor que uno que falla,
 * porque el segundo deja rastro.
 *
 * ## Notificacion «on demand», sin modelo de usuario
 *
 * Se envia a la direccion, no a una entidad: `Reporting` no puede importar el
 * modelo `User` de `Identity` (doc 02 §1.6) y tampoco lo necesita. La direccion y
 * el idioma vienen ya resueltos por el puerto.
 *
 * ## El log no lleva ni direcciones ni nombres (regla dura 21)
 *
 * Dice que exportacion era y que clase de excepcion fue. La direccion es dato
 * personal de esa cuenta y el `uuid` basta para diagnosticar; el log tecnico
 * viaja al fabricante dentro del paquete de diagnostico.
 */
final readonly class MailReportExportNotifier implements ReportExportNotifier
{
    /**
     * Los transportes que **no** son un envio de verdad.
     *
     * `log` escribe el correo en `laravel.log` y `array` lo guarda en memoria para
     * las pruebas. Con cualquiera de los dos, sellar `mail` en la fila diria en la
     * pantalla «te hemos avisado por correo» sobre un correo que no salio.
     *
     * @var list<string>
     */
    private const array SILENT_MAILERS = ['log', 'array'];

    public function __construct(
        private ReportExportRecipients $recipients,
        /** `MAIL_MAILER` ya resuelto (regla dura 14): el adaptador no lee configuracion. */
        private string $mailer,
    ) {}

    public function notify(ReportExport $export): ReportExportNotificationChannel
    {
        if (\in_array($this->mailer, self::SILENT_MAILERS, true)) {
            return ReportExportNotificationChannel::Panel;
        }

        $recipient = $this->recipients->find($export->requestedByUserId);

        if ($recipient === null || ! $recipient->reachableByMail()) {
            return ReportExportNotificationChannel::Panel;
        }

        try {
            Notification::route('mail', $recipient->email)
                ->notify(new ReportExportReadyNotification($export, $recipient));

            return ReportExportNotificationChannel::Mail;
        } catch (Throwable $exception) {
            Log::warning('report_export.notification_not_delivered', [
                'report_export_uuid' => $export->uuid,
                'exception' => $exception::class,
            ]);

            return ReportExportNotificationChannel::Panel;
        }
    }
}
