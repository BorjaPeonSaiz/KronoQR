<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Kiosk\Application\Port\DeviceRegistry;
use App\Modules\Kiosk\Application\Query\DeviceFleetView;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthThresholds;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;

/**
 * Los quioscos de la instalacion **con su veredicto de salud**, para la pantalla
 * «Quioscos» del panel (`GET /api/v1/devices`, **RF-PD-06**, RF-PA-07).
 *
 * ## Es la pantalla desde la que se descubre un quiosco averiado
 *
 * Antes de que alguien reclame una jornada. `last_seen_at`, `pending_queue_size`
 * y la bateria los alimenta el latido y son informacion de operacion: el
 * dispositivo los declara y nadie los comprueba. Un quiosco que mienta sobre su
 * cola no cambia ni un fichaje.
 *
 * ## Una sola regla de salud para el panel, la consola y la alerta
 *
 * El veredicto no se calcula aqui: lo calcula `KioskHealthReport`, la misma
 * clase que usa `php artisan kiosk:health` y con los mismos umbrales
 * (`config/kiosk.php`), que son ademas los de la alerta «Quiosco sin latido >
 * 10 min» del doc 01 §9.3. Si el panel y la consola dijeran cosas distintas del
 * mismo quiosco, la conversacion siguiente seria cual de los dos miente
 * (decision 2 de la ficha 3.3).
 *
 * **Una sola consulta**, no dos: el informe se construye sobre la lista que ya
 * se ha leido. Pedirsela a {@see CheckKioskHealth} habria vuelto a consultar
 * `devices` para juzgar exactamente las mismas filas.
 *
 * ## El reloj y los umbrales entran por el borde (regla dura 2, ADR-021)
 *
 * El instante sale del puerto `Clock` y los umbrales llegan **ya resueltos**
 * desde la raiz de composicion, igual que en {@see CheckKioskHealth}: un caso de
 * uso que consultara `config()` no se podria probar con dos valores sin tocar la
 * configuracion global, y `Application` no usa facades (§3.5, Deptrac).
 *
 * ## Sin paginar y sin filtros
 *
 * Una instalacion es un hotel (ADR-040) con unos pocos quioscos, y la lista cabe
 * entera en la pantalla. `meta` no es paginacion: es el reloj del servidor, la
 * zona del centro y los umbrales, sin los cuales el panel tendria que medir con
 * el reloj del navegador y suponer el umbral de la alerta.
 *
 * ## Sin auditoria, al contrario que el padron
 *
 * `GET /kiosk/roster` deja asiento de divulgacion (RS-05) porque reparte datos
 * personales de terceros. Aqui no hay ninguno: un dispositivo es un aparato en
 * una pared y su nombre es el del sitio (regla dura 21). Auditar cada vez que un
 * administrador abre su propia pantalla de quioscos llenaria de ruido la tabla
 * que hay que enseñar en una inspeccion.
 *
 * ## El centro no es un parametro
 *
 * Hay uno por instalacion (ADR-040), asi que la lista es la de la instalacion y
 * la zona horaria de `meta` es la suya.
 */
final readonly class ListDevices
{
    public function __construct(
        private DeviceRegistry $devices,
        private Clock $clock,
        private KioskHealthThresholds $thresholds,
        private InstallationSiteProvider $sites,
    ) {}

    public function handle(): DeviceFleetView
    {
        // `UTC` antes de la puesta en marcha, cuando todavia no hay centro
        // (RF-PD-03). El panel pinta igual: la hora sale en UTC y lo dice, en
        // lugar de quedarse sin lista por no saber la zona.
        $site = $this->sites->installationSite();

        return DeviceFleetView::of(
            $this->devices->all(),
            $this->clock->now(),
            $this->thresholds,
            $site instanceof InstallationSite ? $site->timezone : 'UTC',
        );
    }
}
