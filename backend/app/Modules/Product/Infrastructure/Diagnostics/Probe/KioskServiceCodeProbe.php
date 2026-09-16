<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Shared\Application\Port\KioskServiceCodeProvider;
use Throwable;

/**
 * Sonda `kiosk.service_code` de `product:doctor` (**RF-PD-13**, RF-KI-08,
 * tarea 3.3, decision 7).
 *
 * ## Que responde, y por que hace falta preguntarlo
 *
 * «¿Se puede abrir la pantalla de diagnostico de mis tablets sin saber nada?».
 * Sin codigo configurado, **cualquiera que tenga la tablet delante la abre**.
 * Esa pantalla no muestra ni un dato personal ni el token —eso esta decidido en
 * la propia pantalla— pero si dice si hay red, cuanta cola hay y que version
 * corre, y el IT del cliente tiene derecho a saber que su instalacion esta en
 * ese estado sin ir tablet por tablet.
 *
 * ## **Aviso, nunca fallo**, y es la decision que hace util la sonda
 *
 * Un `failure` devolveria `2`, y `update.sh` traduce el `2` a su codigo `6`:
 * **una actualizacion se abortaria porque el hotel no ha puesto un codigo de
 * servicio**. Eso es exactamente lo contrario de lo que esta sonda persigue. No
 * hay nada roto: sin codigo, la pantalla se abre sin el, y eso es el valor de
 * serie del producto (regla dura 19).
 *
 * ## Familia `kiosk`
 *
 * Y no `settings`, aunque el dato salga de `installation_settings`: `doctor`
 * ordena su informe por familias y el IT lee de arriba abajo buscando el area
 * del problema. Lo que aqui se comprueba es el estado de las tablets, no la
 * salud de la tabla de configuracion —de la que ya hablan
 * `settings.invalid_keys` y `settings.env_differs_from_db`—.
 *
 * ## Nunca lanza, como las demas
 *
 * El adaptador del puerto ya devuelve `null` con la base de datos caida, asi que
 * el caso normal de una instalacion rota es indistinguible del de una sin
 * codigo. El `try` cubre lo demas y sale como aviso: `doctor` se ejecuta cuando
 * algo esta roto, y una sonda que revienta es inutil justo entonces.
 *
 * **Ni el codigo ni su huella salen de aqui** (decision 6). El hallazgo dice
 * `configured: true|false` y nada mas; este informe viaja al fabricante dentro
 * del paquete de diagnostico (ADR-020, regla dura 16).
 */
final readonly class KioskServiceCodeProbe implements DoctorProbe
{
    public function __construct(private KioskServiceCodeProvider $serviceCodes) {}

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
            $configured = $this->serviceCodes->serviceCode() !== null;
        } catch (Throwable $failure) {
            // La CLASE y nunca el mensaje: un error de PostgreSQL puede llevar
            // dentro el valor de una fila (regla dura 21).
            return [DoctorFinding::warning(
                'kiosk.service_code',
                'unavailable',
                params: ['failure' => $failure::class],
                details: ['failure' => $failure::class],
            )];
        }

        return [$configured
            ? DoctorFinding::ok('kiosk.service_code', ['configured' => true])
            : DoctorFinding::warning('kiosk.service_code', details: ['configured' => false])];
    }
}
