<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Support;

use App\Modules\Reporting\Domain\ValueObject\PresenceEntry;
use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * Los canales de la presencia en vivo y quien puede entrar en cada uno
 * (**ADR-011**, RF-ID-03, regla dura 18).
 *
 * ## Un solo sitio para tres preguntas
 *
 * Los nombres de canal aparecen en tres lugares y ninguno puede inventarselos:
 *
 *   1. `routes/channels.php`, que **autoriza** cada suscripcion.
 *   2. El difusor, que decide **a que canales** sale cada cambio.
 *   3. La respuesta de `GET /api/v1/attendance/live`, que le dice al panel a
 *      cuales puede suscribirse.
 *
 * Si cada uno compusiera la cadena por su cuenta, una errata en el segundo
 * emitiria a un canal que nadie escucha —fallo silencioso, la vista simplemente
 * no se refresca— y una en el primero autorizaria un canal que no existe. Aqui
 * estan escritos una vez.
 *
 * ## `presence.all` no es para todos
 *
 * Solo lo alcanzan las cuentas **sin restriccion de alcance** —`admin`, `rrhh` y
 * `auditor`—. Un `responsable_departamento` se suscribe a un
 * `presence.department.{id}` por cada departamento que dirige y **nunca** al
 * global: dejarle entrar ahi seria darle en tiempo real justo lo que RF-ID-03 le
 * niega en el listado.
 *
 * ## Son canales PRIVADOS del protocolo, no «presence channels»
 *
 * En el cable viajan como `private-presence.all` y `private-presence.department.3`.
 * El prefijo lo pone la libreria del cliente; el producto los nombra sin el.
 * Nada que ver con los *presence channels* de Pusher —los que publican la lista
 * de suscriptores—: aqui lo que se difunde es la presencia **de los empleados**,
 * y quien esta mirando el panel no se anuncia a nadie.
 *
 * ## Sin canal por centro
 *
 * ADR-040: hay exactamente un centro por instalacion. Un eje mas seria un canal
 * al que siempre estarian suscritos todos.
 */
final readonly class PresenceChannels
{
    /** Toda la instalacion. Solo para alcance sin restriccion. */
    public const string ALL = 'presence.all';

    /** El prefijo del canal por departamento, tal y como lo espera `routes/channels.php`. */
    public const string DEPARTMENT_PREFIX = 'presence.department.';

    public static function department(int $departmentId): string
    {
        return self::DEPARTMENT_PREFIX.$departmentId;
    }

    /**
     * A que canales puede suscribirse esta cuenta.
     *
     * Viaja en `meta.realtime.channels` para que el panel no tenga que
     * adivinarlo. **No sustituye a la autorizacion**: quien firma cada
     * suscripcion es `routes/channels.php`, y rechaza lo que no corresponda
     * venga en esta lista o no.
     *
     * @return list<string>
     */
    public static function forScope(AccessScope $scope): array
    {
        if ($scope->isUnrestricted()) {
            return [self::ALL];
        }

        return array_map(self::department(...), $scope->departmentIds());
    }

    /**
     * A que canales sale el cambio de una persona.
     *
     * Siempre al global y, si la tiene, al de su departamento. **Nunca al de
     * otro**: es la mitad de RF-ID-03 que vive en el difusor.
     *
     * Quien no tiene departamento solo viaja por el canal global, que es lo
     * coherente con {@see AccessScope::reaches()}: nadie dirige el departamento
     * de quien no tiene ninguno, y atribuirselo a un responsable cualquiera
     * seria inventar una jerarquia.
     *
     * @return list<string>
     */
    public static function forEntry(PresenceEntry $entry): array
    {
        if ($entry->departmentId === null) {
            return [self::ALL];
        }

        return [self::ALL, self::department($entry->departmentId)];
    }

    /**
     * Si esta cuenta puede escuchar la instalacion entera.
     */
    public static function mayJoinAll(AccessScope $scope): bool
    {
        return $scope->isUnrestricted();
    }

    /**
     * Si esta cuenta puede escuchar ese departamento.
     *
     * Se delega en {@see AccessScope::reaches()} y no se reimplementa: el
     * alcance por departamento tiene una definicion y solo una, y el canal no
     * puede ser mas generoso que el endpoint.
     */
    public static function mayJoinDepartment(AccessScope $scope, int $departmentId): bool
    {
        return $scope->reaches($departmentId);
    }
}
