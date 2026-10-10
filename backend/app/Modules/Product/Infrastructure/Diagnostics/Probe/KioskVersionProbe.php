<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Shared\Application\Port\KioskAppVersions;
use App\Modules\Shared\Domain\ValueObject\KioskAppVersionEntry;
use Throwable;

/**
 * Sonda `kiosk.app_version` de `product:doctor` (**RF-PD-13**, RF-KI-07;
 * bloque 1 de la 2.2.1).
 *
 * ## Que responde
 *
 * «¿Hay alguna tablet que siga con la aplicacion de la version anterior, y se
 * la puede tocar ya?». Quiosco y servidor salen del mismo `VERSION` (DC8): una
 * tablet que declara una version mas antigua corre una PWA que su *service
 * worker* no ha renovado. Se nombra cada tablet por su `uuid` para encontrarla
 * en el panel.
 *
 * ## Dos clases de tablet desfasada, porque el remedio puede borrar fichajes
 *
 * Recargar la PWA —o desregistrar su *service worker*— en una tablet con la
 * cola en memoria (`queue_storage` distinto de `durable`, ADR-047) **borra los
 * fichajes que no ha enviado**. Por eso la sonda separa:
 *
 * - **lista** (`ready`): cola duradera y vacia. Se le puede aplicar el remedio;
 * - **esperando** (`draining`): con fichajes pendientes o con la cola en
 *   memoria. No se toca hasta que drene y vuelva a disco.
 *
 * Las variantes `warning`, `warning_draining` y `warning_mixed` dicen cual de
 * los dos grupos hay, y cada una lleva su «que hacer».
 *
 * ## **Aviso, nunca fallo**
 *
 * Un `failure` hace que `product:doctor` salga con `2`, que `doctor.sh` traduce
 * a su codigo `6` de «el diagnostico ha encontrado un fallo». Una tablet
 * desfasada no es eso: sigue fichando y encolando con cualquier version (regla
 * dura 19), y justo despues de actualizar es lo normal durante un rato.
 *
 * ## Lo que no pide nada, y lo que si avisa sin tablets de por medio
 *
 * - **Por delante** (una vuelta atras del servidor, ADR-054): `ok_ahead`. La
 *   tablet se pondra en la version del servidor sola.
 * - **Sin quioscos latiendo**: `ok`. Una tablet callada ya sale en
 *   `kiosk:health` y en el panel.
 * - **Servidor sin version comparable** (`0.0.0` o un build `-dev`): en
 *   desarrollo, `ok_unchecked` y ningun juicio —marcaria como desfasadas todas
 *   las tablets de un entorno de pruebas—. **En produccion es
 *   `warning_unchecked`**: una imagen construida sin `APP_VERSION` deja el
 *   aviso de tablets desfasadas apagado, y eso hay que saberlo.
 *
 * ## `details` sin datos personales
 *
 * Este informe viaja en el paquete de diagnostico (ADR-020, regla dura 21): el
 * `uuid` de cada dispositivo, la version que declara y el estado de su cola,
 * **nunca su nombre** —que lo pone el cliente y puede ser «Tablet de Maria»—.
 *
 * ## Nunca lanza
 *
 * Si la lectura falla —la base de datos caida— sale un aviso con la clase de
 * la excepcion y las demas sondas siguen. `doctor` se ejecuta cuando algo esta
 * roto, y una sonda que revienta es inutil justo entonces.
 */
final readonly class KioskVersionProbe implements DoctorProbe
{
    private const string ID = 'kiosk.app_version';

    public function __construct(
        private KioskAppVersions $versions,
        private string $environment,
    ) {}

    public function family(): string
    {
        return 'kiosk';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        try {
            $survey = $this->versions->survey();
        } catch (Throwable $failure) {
            // La CLASE y nunca el mensaje: un error de PostgreSQL puede llevar
            // dentro el valor de una fila (regla dura 21).
            return [DoctorFinding::warning(
                self::ID,
                'unavailable',
                params: ['failure' => $failure::class],
                details: ['failure' => $failure::class],
            )];
        }

        if (! $survey->isEnforced()) {
            $details = ['enforced' => false, 'app_env' => $this->environment];

            return [$this->environment === 'production'
                ? DoctorFinding::warning(self::ID, 'unchecked', details: $details)
                : new DoctorFinding(self::ID, DoctorStatus::Ok, details: $details, variant: 'unchecked')];
        }

        $ready = array_values(array_filter($survey->behind, static fn (KioskAppVersionEntry $entry): bool => $entry->isReadyToReload()));
        $draining = array_values(array_filter($survey->behind, static fn (KioskAppVersionEntry $entry): bool => ! $entry->isReadyToReload()));
        $minimum = (string) $survey->minimumAppVersion;

        $details = [
            'enforced' => true,
            'minimum_app_version' => $survey->minimumAppVersion,
            'examined' => $survey->examined,
            'behind' => $this->listed($survey->behind),
            'ahead' => $this->listed($survey->ahead),
        ];

        if ($survey->behind !== []) {
            $variant = match (true) {
                $draining === [] => null,
                $ready === [] => 'draining',
                default => 'mixed',
            };

            return [DoctorFinding::warning(self::ID, $variant, params: [
                'minimum' => $minimum,
                'devices' => $this->phrase($ready),
                'waiting' => $this->phrase($draining),
            ], details: $details)];
        }

        if ($survey->ahead !== []) {
            return [new DoctorFinding(self::ID, DoctorStatus::Ok, params: [
                'minimum' => $minimum,
                'devices' => $this->phrase($survey->ahead),
            ], details: $details, variant: 'ahead')];
        }

        return [DoctorFinding::ok(self::ID, $details, [
            'count' => $survey->examined,
            'minimum' => $minimum,
        ])];
    }

    /**
     * @param  list<KioskAppVersionEntry>  $entries
     * @return list<array{device: string, app_version: string|null, queue_storage: string, pending_queue_size: int|null, ready: bool}>
     */
    private function listed(array $entries): array
    {
        return array_map(static fn (KioskAppVersionEntry $entry): array => [
            'device' => $entry->deviceUuid,
            'app_version' => $entry->appVersion,
            'queue_storage' => $entry->queueStorage,
            'pending_queue_size' => $entry->pendingQueueSize,
            'ready' => $entry->isReadyToReload(),
        ], $entries);
    }

    /**
     * `uuid (version, queue=..., pending=...)` separados por coma. Son datos y
     * no frases, asi que no se traducen: `-` es «no lo declaro».
     *
     * @param  list<KioskAppVersionEntry>  $entries
     */
    private function phrase(array $entries): string
    {
        return implode(', ', array_map(static fn (KioskAppVersionEntry $entry): string => \sprintf(
            '%s (%s, queue=%s, pending=%s)',
            $entry->deviceUuid,
            $entry->appVersion ?? '-',
            $entry->queueStorage,
            $entry->pendingQueueSize ?? '-',
        ), $entries));
    }
}
