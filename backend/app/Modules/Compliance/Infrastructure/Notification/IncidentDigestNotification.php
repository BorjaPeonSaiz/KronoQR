<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Notification;

use App\Modules\Compliance\Application\Port\IncidentDigest;
use App\Modules\Compliance\Application\Port\IncidentNotice;
use App\Modules\Compliance\Application\UseCase\NotifyPendingIncidents;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

/**
 * El resumen de incidencias que recibe un responsable (RF-PR-01: «se notifica al
 * responsable del departamento»).
 *
 * ## Sincrona, y esto no es un descuido
 *
 * **No lleva `ShouldQueue`, y la lleve antes.** Encolada parecia mas prudente y
 * era justo lo contrario: `notify()` sobre una notificacion encolada solo mete un
 * trabajo en Redis, asi que el `try/catch` de {@see MailIncidentNotifier} veia un
 * exito **siempre** —un SMTP mal configurado falla despues, en el worker— y
 * {@see NotifyPendingIncidents}
 * sellaba `notified_at` de incidencias que nadie llego a recibir. El aviso se
 * perdia en silencio, que es el unico desenlace que RF-PR-01 no admite.
 *
 * Enviandola en linea, el fallo ocurre donde alguien lo puede ver: el adaptador
 * lo atrapa, no sella nada y la incidencia entra en el resumen de la noche
 * siguiente.
 *
 * **El coste es asumible y esta acotado**: esto corre en un comando programado de
 * madrugada, fuera de cualquier peticion y fuera de la transaccion de la
 * incidencia, y son unos pocos correos —uno por responsable de departamento, no
 * uno por incidencia—. Si algun dia una instalacion tuviera cien departamentos y
 * un SMTP lento, la respuesta seria encolar **el comando entero**, no la
 * notificacion suelta: lo que no puede volver es un sello sin entrega.
 *
 * ## Por que aqui si van nombres
 *
 * La regla dura 21 prohibe nombres de empleado en **logs tecnicos** y en
 * `error_events`, que viajan al fabricante en el paquete de diagnostico. Esto es
 * otra cosa: un aviso dirigido al responsable del departamento de esa persona,
 * que ya ve su jornada en el panel. Un correo que dijera «incidencia del empleado
 * 018f…c3» obligaria a buscar el UUID a mano para saber a quien llamar, y un
 * aviso que cuesta trabajo leer es un aviso que se ignora.
 *
 * ## Y por que no lleva enlace absoluto
 *
 * El panel de cada cliente vive en un dominio distinto (ADR-016, ADR-017). El
 * correo dice **que** hay que abrir, no una URL que en la instalacion de al lado
 * seria falsa. La ruta relativa la resuelve quien lo lee, que tiene el panel en
 * marcadores.
 */
final class IncidentDigestNotification extends Notification
{
    /**
     * Cuantas incidencias se detallan en el cuerpo antes de resumir el resto.
     *
     * Una madrugada mala —una configuracion recien cambiada, una importacion—
     * puede dejar cientos de incidencias del mismo tipo. Un correo con
     * quinientas lineas no lo lee nadie y ademas rebota en muchos servidores.
     * Se detallan las mas graves y las mas antiguas, y del resto se dice cuantas
     * son: el sitio para trabajarlas es la bandeja, no la bandeja de entrada.
     *
     * Es presentacion, no una regla de negocio: ninguna incidencia se pierde ni
     * cambia de estado por no salir en el correo.
     */
    private const int DETAILED_LINES = 20;

    public function __construct(private readonly IncidentDigest $digest) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->digest->locale;

        $message = (new MailMessage)
            ->subject(Lang::get('incidents.mail.subject', [], $locale))
            ->greeting(Lang::get('incidents.mail.greeting', [], $locale))
            ->line(Lang::get('incidents.mail.intro', ['count' => \count($this->digest->incidents)], $locale));

        foreach (\array_slice($this->digest->incidents, 0, self::DETAILED_LINES) as $notice) {
            $message->line($this->lineFor($notice, $locale));
        }

        $remaining = \count($this->digest->incidents) - self::DETAILED_LINES;

        if ($remaining > 0) {
            $message->line(Lang::get('incidents.mail.more', ['count' => $remaining], $locale));
        }

        // Lo que RN-08 impone, dicho en el correo: si no, quien lo lee puede dar
        // por hecho que el sistema «ya lo ha arreglado».
        return $message
            ->line(Lang::get('incidents.mail.no_auto_close', [], $locale))
            ->line(Lang::get('incidents.mail.action', [], $locale))
            ->line(Lang::get('incidents.mail.footer', [], $locale));
    }

    private function lineFor(IncidentNotice $notice, string $locale): string
    {
        return Lang::get('incidents.mail.line', [
            'date' => $notice->workDate,
            'employee' => $notice->employeeName,
            'type' => Lang::get('incidents.types.'.$notice->type->value, [], $locale),
            'severity' => Lang::get('incidents.severities.'.$notice->severity->value, [], $locale),
        ], $locale);
    }
}
