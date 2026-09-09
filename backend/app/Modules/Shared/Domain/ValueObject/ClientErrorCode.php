<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * El catalogo CERRADO de codigos que un cliente puede reportar, por origen
 * (RF-PD-15, tarea 5.12, revision de seguridad).
 *
 * ## Por que existe
 *
 * El contrato solo exige que `code` tenga forma de codigo (`^[a-z]...`), y con
 * eso el «catalogo cerrado» del reporter de cada cliente vivia en la prosa y no
 * en la validacion. Dos consecuencias que la revision encontro: una sesion de
 * portal podia enviar `kiosk.camera.unavailable` y fabricar filas `critical`
 * que disparan la alerta al IT del cliente, y un codigo libre daba una huella
 * distinta por envio —600 filas nuevas por minuto y actor, sin techo—.
 *
 * Aqui esta la lista, por origen, y {@see self::isKnown()} es lo que los dos
 * puntos de entrada (`KioskHeartbeatRequest` y `ReportClientErrorsRequest`)
 * consultan: un codigo fuera de la lista **de su origen** es una peticion
 * invalida. `ErrorLevel::forClientCode()` decide el nivel con el origen delante
 * por la misma razon.
 *
 * ## Atada a los ficheros de los clientes
 *
 * Las listas son copia literal de los tipos `ClientErrorCode`
 * (`frontend-kiosk/src/shared/telemetry/errorReporter.ts`) y `WebErrorCode`
 * (`packages/web-kit/src/clientErrors.ts`). `tests/Architecture` compara este
 * fichero con esos dos: un codigo nuevo en un cliente sin su fila aqui rompe la
 * suite, que es mejor que romper en produccion con un `400` en cada latido.
 */
final class ClientErrorCode
{
    /** @var list<string> */
    private const array KIOSK = [
        'kiosk.audio.blocked',
        'kiosk.camera.permission_denied',
        'kiosk.camera.stream_lost',
        'kiosk.camera.unavailable',
        'kiosk.clock.skew_detected',
        'kiosk.heartbeat.failed',
        'kiosk.offline.confirm_not_persisted',
        'kiosk.offline.item_not_processed',
        'kiosk.offline.malformed_batch_response',
        'kiosk.offline.storage_unavailable',
        'kiosk.offline.sync_failed',
        'kiosk.offline.sync_throttled',
        'kiosk.offline.sync_unauthorized',
        'kiosk.pin.seal_failed',
        'kiosk.roster.decrypt_failed',
        'kiosk.roster.fetch_failed',
        'kiosk.roster.not_cacheable',
        'kiosk.scan.malformed_payload',
        'kiosk.scan.submit_failed',
        'kiosk.scanner.decoder_load_failed',
        'kiosk.scanner.start_failed',
        'kiosk.scanner.watchdog_restart',
        'kiosk.service_worker.failed',
        'kiosk.unhandled_error',
        'kiosk.wake_lock.denied',
    ];

    /** Panel y portal comparten reporter (`web-kit`) y por tanto catalogo. @var list<string> */
    private const array WEB = [
        'web.unhandled_error',
        'web.unhandled_rejection',
        'web.vue_error',
    ];

    /**
     * @return list<string>
     */
    public static function forSource(ErrorSource $source): array
    {
        return match ($source) {
            ErrorSource::Kiosk => self::KIOSK,
            ErrorSource::Admin, ErrorSource::Portal => self::WEB,
            ErrorSource::Api, ErrorSource::Worker, ErrorSource::Scheduler, ErrorSource::Console => [],
        };
    }

    public static function isKnown(ErrorSource $source, string $code): bool
    {
        return in_array($code, self::forSource($source), true);
    }
}
