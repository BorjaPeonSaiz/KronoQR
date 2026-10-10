<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * La version del producto desplegada en esta instalacion (DC8, doc 02 §10.5).
 *
 * Es la misma que publica `GET /api/v1/health`: la resuelve
 * `App\Support\Version\DeployedVersion` al cargar la configuracion
 * (`config('app.version')`) y este puerto solo la entrega. Vive en `Shared`
 * porque la necesitan modulos que no pueden importarse entre si —`Kiosk` para
 * juzgar la version de cada tablet (`AppVersionPolicy`) y anunciarla en el
 * latido, `Product` para la sonda de `product:doctor`— y porque el dominio la
 * recibe resuelta en vez de leer la configuracion (regla dura 1).
 *
 * La segunda implementacion es la de las pruebas: fijar la version del
 * servidor sin depender del fichero `VERSION` del repositorio, que cambia con
 * cada publicacion.
 */
interface DeployedVersionProvider
{
    /**
     * SemVer (`2.2.1`, `2.2.2-ci`, `0.0.0-dev`), nunca vacia.
     *
     * `0.0.0` significa «esta instalacion no sabe que version tiene» y no es un
     * error: quien la consume decide que hacer con ella.
     */
    public function deployedVersion(): string;
}
