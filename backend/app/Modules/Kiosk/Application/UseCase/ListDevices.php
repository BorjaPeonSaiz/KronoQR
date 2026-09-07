<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Kiosk\Application\Port\DeviceRegistry;
use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;

/**
 * Los quioscos de la instalacion, para la pantalla «Quioscos» del panel
 * (`GET /api/v1/devices`, **RF-PD-06**, RF-PA-07).
 *
 * ## Es la pantalla desde la que se descubre un quiosco averiado
 *
 * Antes de que alguien reclame una jornada. `last_seen_at` y
 * `pending_queue_size` los alimenta el latido y son informacion de operacion: el
 * dispositivo los declara y nadie los comprueba. Un quiosco que mienta sobre su
 * cola no cambia ni un fichaje.
 *
 * ## Sin paginar y sin filtros
 *
 * Una instalacion es un hotel (ADR-040) con unos pocos quioscos, y la lista cabe
 * entera en la pantalla. Un `meta` de paginacion seria contrato que nadie usaria
 * y que despues no se puede quitar sin `v2` (ADR-012). Si algun dia hiciera falta,
 * añadirlo es aditivo.
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
 * Hay uno por instalacion (ADR-040), asi que la lista es la de la instalacion.
 * Aceptar `site_id` seria la primera pieza de un multicentro que no existe.
 */
final readonly class ListDevices
{
    public function __construct(private DeviceRegistry $devices) {}

    /**
     * @return list<DeviceSummary>
     */
    public function handle(): array
    {
        return $this->devices->all();
    }
}
