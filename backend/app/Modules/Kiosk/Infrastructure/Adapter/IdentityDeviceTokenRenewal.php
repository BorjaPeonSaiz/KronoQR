<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Infrastructure\Adapter;

use App\Modules\Identity\Application\Command\RotateDeviceTokenCommand;
use App\Modules\Identity\Application\UseCase\RotateDeviceTokenIfDue;
use App\Modules\Kiosk\Application\Port\DeviceTokenRenewal;
use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Kiosk\Domain\ValueObject\RenewedDeviceToken;
use App\Modules\Shared\Application\Support\SpanScope;
use Illuminate\Contracts\Debug\ExceptionHandler;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La rotacion del token del quiosco sobre el caso de uso publico de `Identity`
 * (RF-ID-04, ADR-044).
 *
 * **Nunca lanza** (regla dura 19, contrato de {@see DeviceTokenRenewal}). Un
 * fallo se informa por las tres vias por las que se ve un error del servidor
 * —el log tecnico, `error_events` a traves del manejador de excepciones y la
 * metrica `kiosk_token_rotations_total{result="failed"}`— y el latido responde
 * igual, sin relevo. Como la transaccion de `Identity` se deshace entera, el
 * token viejo sigue intacto y el latido siguiente lo vuelve a intentar.
 *
 * **Ni el valor ni el hash salen de aqui** hacia el log, la traza o la metrica:
 * solo el UUID del dispositivo y la caducidad (regla dura 21, RS-04).
 */
final readonly class IdentityDeviceTokenRenewal implements DeviceTokenRenewal
{
    private const string TRACER = 'kronoqr.kiosk';

    public function __construct(
        private RotateDeviceTokenIfDue $rotate,
        private KioskMetrics $metrics,
        private LoggerInterface $logger,
        private ExceptionHandler $exceptions,
    ) {}

    public function renewIfDue(string $deviceUuid, int $presentedTokenId): ?RenewedDeviceToken
    {
        $span = SpanScope::start(self::TRACER, 'kiosk.rotate_device_token', SpanKind::KIND_INTERNAL);

        try {
            $issued = $this->rotate->handle(new RotateDeviceTokenCommand($deviceUuid, $presentedTokenId));
            $token = $issued === null ? null : new RenewedDeviceToken($issued->plainTextToken, $issued->expiresAt);
        } catch (Throwable $failure) {
            $span->end(['device.id' => $deviceUuid, 'kiosk.token_rotation' => 'failed']);
            $this->metrics->tokenRotation('failed');
            $this->logger->warning('kiosk.device_token_rotation_failed', [
                'trace_id' => $span->traceId(),
                'device_id' => $deviceUuid,
                'exception' => $failure::class,
            ]);

            try {
                $this->exceptions->report($failure);
            } catch (Throwable) {
                // Informar tampoco puede tumbar el latido.
            }

            return null;
        }

        if ($token === null) {
            $span->end(['device.id' => $deviceUuid, 'kiosk.token_rotation' => 'none']);

            return null;
        }

        $span->end(['device.id' => $deviceUuid, 'kiosk.token_rotation' => 'issued']);
        $this->metrics->tokenRotation('issued');
        $this->logger->info('kiosk.device_token_rotated', [
            'trace_id' => $span->traceId(),
            'device_id' => $deviceUuid,
            'expires_at' => $token->expiresAt->format('Y-m-d\TH:i:s\Z'),
        ]);

        return $token;
    }
}
