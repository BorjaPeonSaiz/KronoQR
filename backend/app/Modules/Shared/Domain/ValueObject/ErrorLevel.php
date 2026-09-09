<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Severidad de un grupo de errores (`error_events.level`, RF-PD-15, tarea
 * 5.12). Dos valores y no cinco: el lector es el IT del cliente, que necesita
 * saber si algo es «mirar cuando pueda» o «mirar ahora», no la escala de PSR-3.
 *
 * **`critical` es lo que nadie ve o lo que no puede parar** (ficha 5.12,
 * decision 3):
 *
 * - un trabajo de cola que **agota sus intentos** (`JobFailed`): nadie lo esta
 *   mirando y el resultado ya no llegara;
 * - cualquier tarea del **planificador**: la reconciliacion nocturna o la copia
 *   que fallan en silencio son justo lo que la alerta del doc 01 §9.3 quiere
 *   sacar a la luz;
 * - una peticion a las **rutas de fichaje** (`/api/v1/scan*`): es el registro
 *   legal;
 * - los codigos del quiosco que le **impiden fichar**: camara, escaner, almacen
 *   local, padron y sellado del PIN (regla dura 19: si la tablet no puede
 *   encolar, el empleado se queda sin fichar).
 *
 * Todo lo demas es `error`. La tabla la reproduce el runbook
 * `errores-en-el-panel.md`, y la alerta «errores nuevos de severidad critica»
 * se construye sobre `application_errors_total{level="critical"}`.
 *
 * En Shared por lo mismo que {@see ErrorSource}: `Kiosk` la necesita para
 * clasificar lo que le llega en el latido sin importar `Product`.
 */
enum ErrorLevel: string
{
    case Error = 'error';

    case Critical = 'critical';

    /**
     * Codigos de cliente que dejan al quiosco sin poder fichar. Es una lista
     * cerrada a proposito: el catalogo de codigos del quiosco vive en
     * `frontend-kiosk/src/shared/telemetry/errorReporter.ts` y aqui solo se
     * nombran los que cambian la severidad.
     *
     * @var list<string>
     */
    private const array CRITICAL_CLIENT_CODES = [
        'kiosk.camera.permission_denied',
        'kiosk.camera.unavailable',
        'kiosk.camera.stream_lost',
        'kiosk.scanner.start_failed',
        'kiosk.scanner.decoder_load_failed',
        'kiosk.offline.storage_unavailable',
        'kiosk.offline.confirm_not_persisted',
        'kiosk.roster.decrypt_failed',
        'kiosk.pin.seal_failed',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::cases());
    }

    /**
     * Severidad de un error reportado por un cliente a partir de su ORIGEN y
     * su codigo.
     *
     * **El origen va delante y lo decide el servidor por el token.** Sin el, una
     * sesion de portal —la credencial mas repartida del producto— podia enviar
     * `kiosk.camera.unavailable` y fabricar filas `critical` que disparan la
     * alerta al IT del cliente (revision de seguridad de la 5.12). Solo un
     * quiosco puede producir un `critical` de cliente, y solo con un codigo de
     * la lista.
     */
    public static function forClientCode(ErrorSource $source, string $code): self
    {
        if ($source !== ErrorSource::Kiosk) {
            return self::Error;
        }

        return in_array($code, self::CRITICAL_CLIENT_CODES, true) ? self::Critical : self::Error;
    }

    /**
     * @return list<string>
     */
    public static function criticalClientCodes(): array
    {
        return self::CRITICAL_CLIENT_CODES;
    }
}
