<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Query;

use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthReport;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthThresholds;
use DateTimeImmutable;

/**
 * La flota entera tal y como la sirve `GET /api/v1/devices`: el esquema
 * `DeviceList` con su `meta` (**RF-PA-07**, RF-PD-06, tarea 3.3, decision 3).
 *
 * ## Que anade `meta`, y por que no bastaba con la lista
 *
 * - **`generated_at`** — el reloj del SERVIDOR. El panel mide la antiguedad de
 *   cada latido contra este instante, extrapolado con lo que lleve mostrada la
 *   pantalla, y nunca contra el reloj del navegador: una estacion de trabajo con
 *   la hora desajustada pintaria media flota en rojo o toda en verde, y las dos
 *   cosas son peores que no pintar nada.
 * - **`timezone`** — la zona del centro, que es en la que el panel escribe los
 *   instantes. Los instantes viajan en UTC como todo el contrato (regla dura 3);
 *   esto es lo que permite convertirlos sin que el navegador adivine.
 * - **`thresholds`** — los umbrales REALES de esta instalacion, para que la
 *   leyenda del panel diga la cifra que se esta aplicando y no una supuesta.
 *
 * ## El veredicto se calcula una sola vez, aqui
 *
 * {@see KioskHealthReport::of()} es la misma llamada que hace `kiosk:health`, y
 * el orden de sus filas es el de la lista que recibe: panel, consola y alerta
 * cuentan lo mismo porque leen la misma regla, no porque se parezcan.
 *
 * ## Sin paginar
 *
 * Una instalacion es un hotel (ADR-040) con unos pocos quioscos y la lista cabe
 * entera en la pantalla. `meta` no es paginacion y no la prepara.
 */
final readonly class DeviceFleetView
{
    /**
     * @param  list<DeviceView>  $devices
     */
    private function __construct(
        public array $devices,
        public DateTimeImmutable $generatedAt,
        public string $timezone,
        public KioskHealthReport $health,
    ) {}

    /**
     * @param  list<DeviceSummary>  $devices  La lista tal cual la sirve el repositorio, ya ordenada.
     */
    public static function of(
        array $devices,
        DateTimeImmutable $now,
        KioskHealthThresholds $thresholds,
        string $timezone,
    ): self {
        $report = KioskHealthReport::of($devices, $now, $thresholds);

        $views = [];

        foreach ($devices as $index => $device) {
            // `KioskHealthReport::of()` recorre la lista en orden y devuelve una
            // fila por dispositivo: el indice es la correspondencia, y no hay un
            // segundo criterio que pueda desalinearse.
            $views[] = new DeviceView($device, $report->devices[$index]);
        }

        return new self($views, $now, $timezone, $report);
    }
}
