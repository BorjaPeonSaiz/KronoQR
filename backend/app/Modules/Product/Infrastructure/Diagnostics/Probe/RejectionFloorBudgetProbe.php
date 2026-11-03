<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;

/**
 * **El suelo de tiempo de los rechazos, frente al corte del quiosco** (RS-03,
 * A-13, enmienda de ADR-005).
 *
 * En el fichaje, cada rechazo —de credencial, fuera del periodo de alta o
 * irreconciliable— se rellena hasta el **doble** de
 * `IDENTITY_CREDENTIAL_REJECTION_FLOOR_MS`, tambien cada elemento de
 * `/scan/batch`. Un lote de `KIOSK_BATCH_MAX_SIZE` rechazos tarda entonces
 * `2 x suelo x lote`, y el quiosco corta la peticion a los
 * {@see self::KIOSK_REQUEST_TIMEOUT_MS} ms y la reintenta mas tarde. La cota
 * «no lo subas de 60» de `docs/cliente/configuracion.md` §6.5 es justo ese
 * calculo con el lote de serie; esta sonda lo hace con los valores de la
 * instalacion y avisa cuando el peor lote pasa del
 * {@see self::WARNING_PERCENT} % del corte.
 *
 * **Aviso y nunca fallo**: no hay nada roto ni se pierde ningun fichaje —el
 * quiosco reintenta y la cola no se borra (regla dura 19)—, pero un lote que
 * agota el corte se reenvia una y otra vez sin avanzar.
 *
 * Recibe los valores ya leidos de la configuracion, como
 * {@see ScanDiscardBudgetProbe}, para no nombrar el dominio de otro modulo.
 */
final readonly class RejectionFloorBudgetProbe implements DoctorProbe
{
    /**
     * El corte de cada peticion del quiosco (`DEFAULT_TIMEOUT_MS` de
     * `frontend-kiosk/src/shared/api/client.ts`). No es configuracion: va
     * compilado en la PWA. `RejectionFloorBudgetProbeTest` ata los dos valores.
     */
    public const int KIOSK_REQUEST_TIMEOUT_MS = 8_000;

    /** A partir de que parte del corte se avisa. */
    public const int WARNING_PERCENT = 75;

    /** En el fichaje cada rechazo espera el doble del suelo (enmienda de ADR-005). */
    private const int SCAN_FLOOR_FACTOR = 2;

    public function __construct(
        private int $floorMs,
        private int $batchMaxSize,
    ) {}

    public function family(): string
    {
        return 'kiosk';
    }

    public function run(): array
    {
        $floor = max(0, $this->floorMs);
        $batch = max(1, $this->batchMaxSize);
        $worstCase = self::SCAN_FLOOR_FACTOR * $floor * $batch;
        $values = [
            'floor_ms' => $floor,
            'batch_size' => $batch,
            'worst_case_ms' => $worstCase,
            'timeout_ms' => self::KIOSK_REQUEST_TIMEOUT_MS,
            'max_floor_ms' => intdiv(self::KIOSK_REQUEST_TIMEOUT_MS * self::WARNING_PERCENT, 100 * self::SCAN_FLOOR_FACTOR * $batch),
        ];

        if ($worstCase * 100 > self::KIOSK_REQUEST_TIMEOUT_MS * self::WARNING_PERCENT) {
            return [DoctorFinding::warning('kiosk.rejection_floor_budget', params: $values, details: $values)];
        }

        return [DoctorFinding::ok('kiosk.rejection_floor_budget', $values, $values)];
    }
}
