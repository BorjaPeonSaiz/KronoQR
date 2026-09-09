<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Kiosk\Application\Port\DeviceRegistry;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthReport;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthThresholds;
use App\Modules\Shared\Application\Port\Clock;

/**
 * El estado de todos los quioscos, para `php artisan kiosk:health`
 * (**RF-PA-07**, doc 02 Anexo C).
 *
 * ## La MISMA lectura que la pantalla del panel
 *
 * Usa `DeviceRegistry::all()`, que es la consulta que sirve
 * `GET /api/v1/devices` a traves de {@see ListDevices}. No hay una segunda
 * consulta ni un segundo criterio de ordenacion: si la consola y el panel
 * dijeran cosas distintas del mismo quiosco, la conversacion siguiente seria
 * cual de los dos miente.
 *
 * Lo que este caso de uso añade sobre aquel es el **veredicto**, que el panel
 * pinta con color y la consola tiene que decir con palabras y con un codigo de
 * salida.
 *
 * ## Solo lectura: ni transaccion, ni auditoria, ni evento
 *
 * No escribe nada, asi que no hay nada que envolver. Y **no deja asiento en
 * `audit_log`** por el mismo motivo que {@see ListDevices}: aqui no se divulga
 * ni un dato personal —un quiosco es un aparato en una pared (regla dura 21)— y
 * auditar cada vez que alguien mira si sus tablets responden llenaria de ruido
 * la tabla que hay que enseñar en una inspeccion.
 *
 * ## El reloj entra por el puerto (regla dura 2)
 *
 * Es lo unico que hace comprobables las fronteras de los dos plazos: con
 * `now()` dentro, «a los 120 segundos exactos todavia esta correcto» no se puede
 * probar sin esperar dos minutos.
 *
 * Los umbrales llegan **ya resueltos** desde la raiz de composicion, como los del
 * emparejamiento: un caso de uso que consultara `config()` no se podria probar
 * con dos valores sin tocar la configuracion global, y `Application` no usa
 * facades (§3.5, verificado por Deptrac).
 */
final readonly class CheckKioskHealth
{
    public function __construct(
        private DeviceRegistry $devices,
        private Clock $clock,
        private KioskHealthThresholds $thresholds,
    ) {}

    public function handle(): KioskHealthReport
    {
        return KioskHealthReport::of($this->devices->all(), $this->clock->now(), $this->thresholds);
    }
}
