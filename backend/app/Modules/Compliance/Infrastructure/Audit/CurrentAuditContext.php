<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Audit;

use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Quien esta haciendo esto, desde donde y con que cliente: los tres datos de la
 * peticion en curso que `audit_log` necesita y que un evento de dominio no
 * puede llevar.
 *
 * **Por que hace falta y por que vive aqui.** `audit_log` tiene columnas `ip` y
 * `user_agent` (doc 01 §5.5): son propiedades de la **peticion**, no del hecho
 * de negocio, y por eso ningun evento de dominio las transporta —hacerlo
 * obligaria a que el dominio conociera el transporte—. Lo mismo pasa con el
 * actor: un fichaje lo produce un quiosco autenticado, y quien sabe cual es el
 * token de Sanctum, no `WorkDay`.
 *
 * **Los facades son legitimos en esta capa.** El §3.5 los prohibe en `Domain/` y
 * en `Application/`, que es donde convierten una clase en algo imposible de
 * probar sin framework. Aqui son lo contrario: el estado de la peticion vive en
 * el contenedor y leerlo de otra forma seria reimplementar el contenedor.
 *
 * **El actor se clasifica por la tabla del `tokenable`, no por su clase.** Es
 * deliberado: `Compliance` no puede importar el modelo `Device` de `Identity`
 * (doc 02 §1.6, verificado por Deptrac), y tampoco deberia — la clase puede
 * cambiar de nombre, la tabla no, porque `audit_log.actor_id` es una clave
 * foranea logica hacia ella. Esto funciona sin cambios con los tokens de
 * dispositivo que emite la tarea 1.5.
 *
 * **Sin peticion, `system()`.** El scheduler, las colas y los comandos de
 * consola no tienen a nadie detras, y decir «usuario desconocido» seria peor que
 * decir la verdad.
 */
final readonly class CurrentAuditContext
{
    private const string DEVICES_TABLE = 'devices';

    private const string USERS_TABLE = 'users';

    public function actor(): AuditActor
    {
        $tokenable = Auth::user();

        if (! $tokenable instanceof Model) {
            return AuditActor::system();
        }

        $key = $tokenable->getKey();

        if (! is_int($key) && ! is_numeric($key)) {
            return AuditActor::system();
        }

        return match ($tokenable->getTable()) {
            self::DEVICES_TABLE => AuditActor::device((int) $key),
            self::USERS_TABLE => AuditActor::user((int) $key),
            default => AuditActor::system(),
        };
    }

    /**
     * La IP de origen, si hay peticion.
     *
     * La columna es `INET` y el valor puede ser el de una red interna: en una
     * instalacion tipica el quiosco esta en la VLAN del hotel. Se guarda igual,
     * porque lo que responde ante una brecha (RL-15) es poder decir desde donde
     * se entro, no si la direccion era publica.
     */
    public function ip(): ?string
    {
        return Request::instance()->ip();
    }

    /**
     * El `User-Agent`, recortado.
     *
     * La columna es `TEXT` y aceptaria cualquier longitud; el recorte es de
     * higiene, no de esquema. `audit_log` se conserva **cuatro anos** (RL-02) y
     * se lee en una inspeccion: guardar entero el manifiesto de versiones que
     * anuncia un navegador engorda la tabla sin anadir nada que sirva para
     * identificar el cliente.
     */
    public function userAgent(): ?string
    {
        $agent = Request::instance()->userAgent();

        if ($agent === null || $agent === '') {
            return null;
        }

        return mb_substr($agent, 0, 255);
    }
}
