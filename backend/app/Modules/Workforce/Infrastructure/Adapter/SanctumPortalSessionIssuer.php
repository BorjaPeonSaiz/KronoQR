<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\PortalSessionIssuer;
use App\Modules\Shared\Domain\ValueObject\PortalSession;
use App\Modules\Workforce\Infrastructure\Persistence\Employee;
use App\Modules\Workforce\Infrastructure\Persistence\Site;
use DateTimeImmutable;

/**
 * El token del portal del empleado, sobre Laravel Sanctum (RF-ID-05, RF-ID-07,
 * ADR-015).
 *
 * **Es la arista de ADR-025 al reves de la habitual**, igual que
 * `HashedEmployeePinVerifier`: el puerto lo declara `Shared` —porque quien lo
 * necesita es `Identity` y quien tiene la tabla es este modulo— y lo implementa
 * `Workforce`. El enlace se declara en `WorkforceServiceProvider`
 * (restriccion 3).
 *
 * ## Emitir no retira las sesiones vivas, y sí las caducadas
 *
 * Al reves que el emisor del quiosco, que borra el token anterior antes de crear
 * el nuevo. Alli tenia sentido: un dispositivo con dos tokens vivos sigue
 * fichando cuando se revoca uno. Aqui no: quien abre el portal en el movil y
 * despues en el ordenador de casa no espera que el primero deje de funcionar, y
 * echarle de una sesion que no ha cerrado es exactamente la clase de sorpresa
 * que hace que alguien deje de usar el portal — que es lo que RL-05 no permite.
 *
 * Lo que si se limpia son **los tokens ya caducados de esa misma persona**. Cada
 * acceso deja una fila en `personal_access_tokens`, y sin esto un empleado que
 * entra todos los dias durante cuatro años acumularia mil filas muertas. Se
 * borran aqui, en el momento en que ya se esta escribiendo en esa tabla y por esa
 * persona, en vez de con un barrido global: el coste es una sentencia acotada por
 * la clave del propietario y no hay ninguna tarea programada que pueda no estar
 * corriendo en la instalacion de un cliente.
 *
 * ## La zona horaria sale del centro, no del servidor
 *
 * `sites.timezone` del centro al que la persona esta adscrita **hoy** (RN-04).
 * Es la que decide que dia es «hoy» para ella y en la que se le presentan sus
 * horas; la del navegador no se usa nunca (regla dura 3). La de cada tramo
 * historico viaja aparte, con el tramo, porque un traslado de centro no reescribe
 * donde ocurrieron las jornadas anteriores.
 *
 * ## Nada de lo que sale de aqui puede ir a un log
 *
 * El objeto que devuelve lleva nombre y token. Quien lo recibe registra
 * `employee_uuid` y nada mas (regla dura 21).
 */
final readonly class SanctumPortalSessionIssuer implements PortalSessionIssuer
{
    public function __construct(private Clock $clock) {}

    public function issueFor(
        string $employeeUuid,
        string $sessionName,
        array $abilities,
        DateTimeImmutable $expiresAt,
    ): ?PortalSession {
        $employee = Employee::query()->where('uuid', $employeeUuid)->first();

        if (! $employee instanceof Employee) {
            // La carrera del contrato del puerto: le dieron de baja de la ficha
            // entre la comprobacion del PIN y esta linea. No se degrada a
            // «sesion sin dueño».
            return null;
        }

        $timeZone = $this->timeZoneOf($employee->site_id);

        if ($timeZone === null) {
            // Un empleado sin centro resoluble no puede tener portal: todas sus
            // horas se presentarian en una zona inventada.
            return null;
        }

        $this->forgetExpiredTokensOf($employee);

        $token = $employee->createToken($sessionName, $abilities, $expiresAt);

        return new PortalSession(
            employeeUuid: $employee->uuid,
            displayName: trim($employee->first_name.' '.$employee->last_name),
            employeeCode: $employee->employee_code,
            locale: $employee->locale,
            timeZone: $timeZone,
            plainTextToken: $token->plainTextToken,
            expiresAt: $expiresAt,
        );
    }

    private function timeZoneOf(int $siteId): ?string
    {
        $timeZone = Site::query()->whereKey($siteId)->value('timezone');

        return \is_string($timeZone) && $timeZone !== '' ? $timeZone : null;
    }

    /**
     * Las sesiones de esta persona que ya no valen. Ver el docblock de la clase:
     * las vivas no se tocan.
     *
     * El instante lo da el puerto `Clock` y no `now()` (ADR-021, regla dura 2):
     * es lo que permite que una prueba mueva el reloj y compruebe que la limpieza
     * ocurre, en vez de esperar a que caduque un token de verdad.
     */
    private function forgetExpiredTokensOf(Employee $employee): void
    {
        $employee->tokens()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $this->clock->now())
            ->delete();
    }
}
