<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Kiosk\Application\Query\KioskRoster;
use App\Modules\Kiosk\Application\Query\RosterEntry;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\ClockingEmployees;
use App\Modules\Shared\Application\Port\CredentialFingerprints;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;

/**
 * Compone el padron minimo del centro de un quiosco (`GET /api/v1/kiosk/roster`,
 * RF-KI-03, doc 02 §7.3).
 *
 * ## Dos consultas y un cruce en memoria, a proposito
 *
 * El padron necesita dos cosas que viven en dos modulos distintos: los nombres
 * (`Workforce`) y el hash de la tarjeta (`Identity`). Un `JOIN` entre
 * `employees` y `credentials` habria sido una sola consulta y una violacion de la
 * frontera del §1.6 que ninguna herramienta habria detectado, porque Deptrac lee
 * imports y no SQL. Se pregunta a cada modulo por su puerto y se cruzan aqui: dos
 * consultas para seiscientas personas, una vez cada varias horas.
 *
 * ## Quien no tiene tarjeta no aparece, y no es un error
 *
 * El padron sirve para responder «¿de quien es esta tarjeta?». Quien no tiene
 * credencial activa e impresa no tiene tarjeta que resolver (ADR-034), asi que
 * incluirlo solo añadiria un nombre que el quiosco nunca podria emparejar — y un
 * nombre de mas en una tablet es un nombre de mas filtrado si alguien se la lleva.
 *
 * **Que no aparezca no le impide fichar.** Si su tarjeta existe pero el padron
 * cacheado no la conoce, el quiosco encola igual y confirma sin nombre (regla
 * dura 19, RN-15): el servidor resolvera la credencial al sincronizar.
 *
 * ## Toda entrega queda auditada (RS-05)
 *
 * Esto es una divulgacion de datos personales de terceros a un dispositivo, y
 * RS-05 no admite matices. Se registra **el alcance** —el centro, cuantos
 * registros, que quiosco— y nunca lo divulgado: ni un nombre, ni un hash (regla
 * dura 21). Es tambien lo que permite responder «¿que se llevo esa tablet?» si
 * alguna desaparece.
 *
 * El apunte se escribe **antes** de devolver el padron y no despues: si la
 * escritura de auditoria falla, la divulgacion no ocurre. Es la misma decision que
 * en el fichaje (regla dura 6, ADR-027) y con la misma consecuencia deliberada —un
 * `audit_log` averiado deja al quiosco sin refrescar el padron—, que es preferible
 * a repartir la plantilla sin dejar constancia.
 */
final readonly class BuildKioskRoster
{
    public function __construct(
        private ClockingEmployees $employees,
        private CredentialFingerprints $credentials,
        private PersonalDataAccessLog $disclosures,
        private Clock $clock,
    ) {}

    public function forSite(int $siteId, string $deviceUuid): KioskRoster
    {
        $members = $this->employees->atSite($siteId);

        $ids = [];

        foreach ($members as $member) {
            $ids[] = $member->employeeId;
        }

        $fingerprints = $this->credentials->forEmployees($ids);

        $entries = [];

        foreach ($members as $member) {
            $hash = $fingerprints[$member->employeeId] ?? null;

            if ($hash !== null) {
                $entries[] = new RosterEntry($hash, $member->displayName);
            }
        }

        $this->disclosures->recordDisclosure('kiosk_roster', \count($entries), [
            'site_id' => $siteId,
            'device_uuid' => $deviceUuid,
        ]);

        return new KioskRoster($this->clock->now(), $entries);
    }
}
