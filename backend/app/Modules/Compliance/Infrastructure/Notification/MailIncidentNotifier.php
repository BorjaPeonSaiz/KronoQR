<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Notification;

use App\Modules\Compliance\Application\Port\IncidentDigest;
use App\Modules\Compliance\Application\Port\IncidentNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Entrega el resumen por correo a la direccion del responsable (RF-PR-01).
 *
 * ## Por que correo, si el producto no depende del correo del empleado
 *
 * La regla dura 12 y ADR-015 hablan del **empleado**: su correo es opcional y el
 * portal se abre con codigo y PIN. Las cuentas de gestion son otra cosa —
 * `users.email` es obligatorio y unico desde la migracion de `users`— y el
 * responsable de un departamento es una de ellas.
 *
 * ## Notificacion «on demand», sin modelo de usuario
 *
 * Se envia a la direccion, no a una entidad: `Compliance` no puede importar el
 * modelo `User` de `Identity` (doc 02 §1.6, verificado por Deptrac) y tampoco lo
 * necesita. La direccion y el idioma vienen ya resueltos en el resumen.
 *
 * ## Un fallo de correo nunca rompe la deteccion
 *
 * Atrapa `Throwable` y devuelve `false`, con lo que el caso de uso **no sella**
 * `notified_at` y el aviso vuelve a intentarse en la pasada siguiente. Un
 * servidor SMTP mal configurado es lo mas comun de una instalacion recien puesta
 * en marcha, y no puede convertirse en una deteccion que falla cada noche.
 *
 * **El log no lleva nombres ni direcciones** (regla dura 21): dice a que cuenta
 * de gestion iba y cuantas incidencias llevaba. La direccion es dato personal de
 * esa cuenta y el identificador basta para diagnosticar.
 */
final readonly class MailIncidentNotifier implements IncidentNotifier
{
    public function notify(IncidentDigest $digest): bool
    {
        if ($digest->incidents === []) {
            return false;
        }

        try {
            Notification::route('mail', $digest->email)
                ->notify(new IncidentDigestNotification($digest));

            return true;
        } catch (Throwable $exception) {
            Log::warning('incidents.digest_not_delivered', [
                'manager_user_id' => $digest->managerUserId,
                'incidents' => \count($digest->incidents),
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
