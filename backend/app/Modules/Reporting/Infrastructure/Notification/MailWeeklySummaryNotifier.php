<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Notification;

use App\Modules\Reporting\Application\Port\WeeklySummaryMailer;
use App\Modules\Reporting\Application\Port\WeeklySummaryRecipient;
use App\Modules\Reporting\Domain\ValueObject\WeeklySummary;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Entrega el resumen semanal a la direccion del responsable (**RF-PR-05**).
 *
 * ## Notificacion «on demand», sin modelo de usuario
 *
 * Se envia a la direccion y no a una entidad: `Reporting` no puede importar el
 * modelo `User` de `Identity` (doc 02 §1.6) y tampoco lo necesita. La direccion
 * y el idioma vienen ya resueltos en el destinatario.
 *
 * ## El `false` de aqui deshace una transaccion
 *
 * Se atrapa `Throwable` y se devuelve `false`, y el caso de uso lo convierte en
 * el fin de su transaccion: se deshacen la fila de `weekly_summary_deliveries` y
 * el asiento de divulgacion, porque no se divulgo nada. La semana queda
 * pendiente y entra en la pasada del lunes siguiente.
 *
 * **Para que ese `catch` signifique algo, el envio es SINCRONO.** Con la
 * notificacion encolada, `notify()` solo metia un trabajo en Redis, esto veia un
 * exito siempre y la semana quedaba marcada como enviada sin que nadie recibiera
 * el correo.
 *
 * ## El log no lleva ni direcciones ni nombres (regla dura 21)
 *
 * Dice a que cuenta de gestion iba, de que semana era y de que clase fue la
 * excepcion. La direccion es dato personal de esa cuenta y el identificador
 * basta para diagnosticar; este log viaja al fabricante dentro del paquete de
 * diagnostico (ADR-020).
 */
final readonly class MailWeeklySummaryNotifier implements WeeklySummaryMailer
{
    public function send(WeeklySummaryRecipient $recipient, WeeklySummary $summary): bool
    {
        try {
            Notification::route('mail', $recipient->email)
                ->notify(new WeeklySummaryNotification($recipient, $summary));

            return true;
        } catch (Throwable $exception) {
            Log::warning('reporting.weekly_summary_not_delivered', [
                'manager_user_id' => $recipient->userId,
                'week' => $summary->week->label(),
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
