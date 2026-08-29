<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

/**
 * Catalogo de ambitos de token del doc 02 §7.3.
 *
 * **Un ambito es lo que un token puede hacer; una policy decide sobre que
 * datos.** Los dos se comprueban, y no uno de los dos (regla dura 18): el
 * middleware `ability` de Sanctum verifica esta lista y la policy del recurso
 * verifica el alcance. Es lo que impide que un token de quiosco robado lea la
 * plantilla completa aunque alguien se equivoque en una policy.
 *
 * **Por que un enum y no cadenas sueltas.** Estas cadenas viajan en tres sitios
 * —`personal_access_tokens.abilities`, el middleware de cada ruta y el contrato
 * OpenAPI— y una errata en cualquiera de ellos no rompe nada visible: el token
 * simplemente no autoriza, o autoriza de mas. Con el enum, la errata es un error
 * de PHPStan.
 *
 * **Los valores estan escritos aqui y en dos sitios mas a proposito**: en el
 * contrato (`components.securitySchemes`) porque OpenAPI no puede referenciar
 * codigo, y en la migracion del catalogo de roles porque el esquema de una
 * instalacion no puede depender de una clase de la aplicacion. Las tres copias
 * las ata una prueba, no la buena fe.
 */
enum TokenAbility: string
{
    // Quiosco (RF-ID-04). Caducidad de 90 dias con rotacion automatica.
    case SCAN_WRITE = 'scan:write';
    case ROSTER_READ = 'roster:read';
    case HEARTBEAT_WRITE = 'heartbeat:write';

    // Portal del empleado (ADR-015, RF-ID-07). Solo lectura y solo lo propio.
    case SELF_READ = 'self:read';

    /**
     * Sesion **pendiente de segundo factor** (RS-06, tarea 2.1).
     *
     * No es un ambito del §7.3 y no concede nada del producto: solo abre los tres
     * endpoints de `/auth/2fa/*`. Se modela como ambito y no como un estado del
     * token para que cada ruta lo rechace con el mismo mecanismo con el que
     * rechaza a un quiosco —el middleware `ability`— en lugar de con una
     * comprobacion nueva que habria que acordarse de poner en cada sitio.
     */
    case TWO_FACTOR_PENDING = '2fa:pending';

    // Gestion.
    case ATTENDANCE_READ = 'attendance:read';
    case ATTENDANCE_CORRECT = 'attendance:correct';
    case INCIDENTS_ALL = 'incidents:*';
    /**
     * **Leer** plantilla y fichas, con el alcance del rol (RF-ID-03).
     *
     * Se separa de `employees:*` en la tarea 2.1 y no antes por un motivo
     * concreto: el `responsable_departamento` tiene que poder ver a su gente
     * —Anexo B del doc 01: `GET /employees` es `manager+`— y no debe poder
     * modificarla. Con un unico ambito de familia, darle lo primero era darle lo
     * segundo, y la unica defensa quedaba en la policy. Ahora son dos.
     */
    case EMPLOYEES_READ = 'employees:read';
    case EMPLOYEES_ALL = 'employees:*';
    case CREDENTIALS_ALL = 'credentials:*';
    case REPORTS_ALL = 'reports:*';
    case REPORTS_LEGAL = 'reports:legal';
    case AUDIT_READ = 'audit:read';
    case SETTINGS_ALL = 'settings:*';
    case LICENSE_ALL = 'license:*';
    case SUPPORT_ALL = 'support:*';
    case DIAGNOSTICS_ALL = 'diagnostics:*';

    /**
     * Los tres ambitos —y solo los tres— que lleva el token de un quiosco
     * (§7.3, RF-ID-04, RS-04).
     *
     * **Escritos aqui una vez y en ningun otro sitio.** Es lo que sostiene la
     * promesa del §7.3: *«un token de quiosco comprometido no da acceso a la
     * plantilla completa»*. Si la lista se repitiera en el emisor, en la ruta y
     * en la prueba, bastaria con que una de las tres ganara un ambito de mas
     * para que la promesa dejara de ser cierta sin que nada fallara.
     *
     * @return list<self>
     */
    public static function kioskAbilities(): array
    {
        return [self::SCAN_WRITE, self::ROSTER_READ, self::HEARTBEAT_WRITE];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $ability): string => $ability->value, self::cases());
    }

    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom($name);
    }
}
