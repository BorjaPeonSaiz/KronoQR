<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Port\ComplianceProfileRepository;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSource;
use App\Modules\Shared\Domain\ValueObject\HolidayCalendar;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Psr\Log\LoggerInterface;

/**
 * `compliance_profiles` para la gestion desde el panel (RF-PD-07, tarea 5.2).
 *
 * ## Por que no es un modelo Eloquent
 *
 * Porque no hay nada que un modelo aporte aqui: no hay relaciones que cargar, no
 * hay eventos de modelo —los del dominio los publica el caso de uso— y el
 * resultado se convierte en un objeto de valor inmediatamente. Un modelo seria
 * una capa mas por la que pasar y una tentacion de `Profile::first()->update()`
 * desde cualquier sitio, saltandose el candado y el asiento.
 *
 * ## La consulta es la misma que la del proveedor del nucleo, y esta duplicada
 *
 * `Product\Infrastructure\Adapter\DbCompliancePolicyProvider` resuelve el mismo
 * perfil con la misma cascada. Se mantienen separados a proposito: aquel esta en
 * el camino de fichaje y devuelve minutos sin nombre ni identificador, y este
 * devuelve lo que el panel edita. Fundirlos obligaria a uno de los dos a llevar
 * datos que no usa por un camino que se recorre cincuenta veces por segundo.
 * Una prueba de integracion ata que los dos resuelven el mismo perfil.
 *
 * ## La memoria de `forSite()` y la falta de ella en `forSiteForWrite()`
 *
 * `forSite()` memoiza por peticion —el panel puede pedirlo dos veces al pintar—;
 * `forSiteForWrite()` no, porque lo llama la transaccion que va a escribir y el
 * asiento declara un valor anterior: leerlo de una copia podria declarar un valor
 * que nunca rigio.
 */
final class DatabaseComplianceProfileRepository implements ComplianceProfileRepository
{
    /** @var array<int, ComplianceProfileSnapshot|null> */
    private array $memoised = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly LoggerInterface $logger,
    ) {}

    public function forSite(int $siteId): ?ComplianceProfileSnapshot
    {
        if (array_key_exists($siteId, $this->memoised)) {
            return $this->memoised[$siteId];
        }

        return $this->memoised[$siteId] = $this->resolve($siteId);
    }

    public function forSiteForWrite(int $siteId): ?ComplianceProfileSnapshot
    {
        return $this->resolve($siteId);
    }

    /**
     * Otro perfil con ese nombre, que la columna no admite.
     *
     * Se comprueba **dentro de la transaccion y bajo el candado**, no antes: una
     * consulta previa suelta dejaria una ventana entre la comprobacion y la
     * escritura. El `UNIQUE` del esquema sigue siendo la garantia; esto es lo que
     * convierte su violacion en un `422` que dice cual es el campo.
     */
    public function nameIsTakenByAnotherProfile(int $profileId, string $name): bool
    {
        return $this->connection->table('compliance_profiles')
            ->where('name', $name)
            ->where('id', '!=', $profileId)
            ->exists();
    }

    public function save(ComplianceProfileSnapshot $profile, ?int $actorUserId, DateTimeImmutable $at): void
    {
        $this->connection->table('compliance_profiles')
            ->where('id', $profile->id)
            ->update([
                'name' => $profile->name,
                'min_rest_hours' => $profile->minRestHours,
                'max_daily_hours' => $profile->maxDailyHours,
                'max_weekly_hours' => $profile->maxWeeklyHours,
                'break_required_after_hours' => $profile->breakRequiredAfterHours,
                'week_starts_on' => $profile->weekStartsOn,
                // El JSONB se escribe como texto: la lista ya viene ordenada y
                // sin repetidos del objeto de valor.
                'holiday_calendar' => json_encode($profile->holidayCalendar, JSON_THROW_ON_ERROR),
                'retention_years' => $profile->retentionYears,
                // La marca de «alguien ha tocado esto», que es lo que distingue
                // un perfil ajustado al convenio de uno tal como se instalo. El
                // instante entra por parametro (regla dura 2) y el actor puede
                // ser `null`: la consola no tiene sesion detras.
                'updated_at' => $at->format(DateTimeInterface::RFC3339_EXTENDED),
                'updated_by_user_id' => $actorUserId,
            ]);

        // La escritura invalida la memoria: dentro de la misma peticion, quien
        // vuelva a leer tiene que ver lo que se acaba de guardar.
        $this->memoised = [];
    }

    /**
     * La cascada de dos escalones: el perfil del centro, o el de `is_default`.
     */
    private function resolve(int $siteId): ?ComplianceProfileSnapshot
    {
        $assigned = $this->row(
            $this->connection->table('compliance_profiles')
                ->join('sites', 'sites.compliance_profile_id', '=', 'compliance_profiles.id')
                ->where('sites.id', $siteId)
        );

        if ($assigned !== null) {
            return $this->snapshot($assigned, ComplianceProfileSource::Site);
        }

        $byDefault = $this->row(
            $this->connection->table('compliance_profiles')->where('is_default', true)
        );

        if ($byDefault === null) {
            return null;
        }

        return $this->snapshot($byDefault, ComplianceProfileSource::InstallationDefault);
    }

    /**
     * @param  Builder  $query
     * @return object{id: int, name: string, jurisdiction: string, min_rest_hours: int, max_daily_hours: int, max_weekly_hours: int, break_required_after_hours: int, week_starts_on: int, holiday_calendar: string, retention_years: int, is_default: bool, updated_at: string|null}|null
     */
    private function row(mixed $query): ?object
    {
        /** @var object{id: int, name: string, jurisdiction: string, min_rest_hours: int, max_daily_hours: int, max_weekly_hours: int, break_required_after_hours: int, week_starts_on: int, holiday_calendar: string, retention_years: int, is_default: bool, updated_at: string|null}|null $row */
        $row = $query->select([
            'compliance_profiles.id',
            'compliance_profiles.name',
            'compliance_profiles.jurisdiction',
            'compliance_profiles.min_rest_hours',
            'compliance_profiles.max_daily_hours',
            'compliance_profiles.max_weekly_hours',
            'compliance_profiles.break_required_after_hours',
            'compliance_profiles.week_starts_on',
            'compliance_profiles.holiday_calendar',
            'compliance_profiles.retention_years',
            'compliance_profiles.is_default',
            'compliance_profiles.updated_at',
        ])->first();

        return $row;
    }

    /**
     * @param  object{id: int, name: string, jurisdiction: string, min_rest_hours: int, max_daily_hours: int, max_weekly_hours: int, break_required_after_hours: int, week_starts_on: int, holiday_calendar: string, retention_years: int, is_default: bool, updated_at: string|null}  $row
     */
    private function snapshot(object $row, ComplianceProfileSource $source): ComplianceProfileSnapshot
    {
        return new ComplianceProfileSnapshot(
            id: $row->id,
            name: $row->name,
            jurisdiction: $row->jurisdiction,
            minRestHours: $row->min_rest_hours,
            maxDailyHours: $row->max_daily_hours,
            maxWeeklyHours: $row->max_weekly_hours,
            breakRequiredAfterHours: $row->break_required_after_hours,
            weekStartsOn: $row->week_starts_on,
            holidayCalendar: $this->decodeCalendar($row->id, $row->holiday_calendar),
            retentionYears: $row->retention_years,
            isDefault: (bool) $row->is_default,
            source: $source,
            updatedAt: $row->updated_at === null
                ? null
                : new DateTimeImmutable($row->updated_at, new DateTimeZone('UTC')),
        );
    }

    /**
     * El calendario de festivos, que llega como el texto del JSONB.
     *
     * Tolerante con el mismo criterio y por la misma razon que el proveedor del
     * nucleo —una fila editada a mano no puede dejar la pantalla del perfil sin
     * abrirse, que es justo donde hay que ir a arreglarla— y con el **mismo
     * parseo**: {@see HolidayCalendar}, un solo sitio. Tener dos copias es lo que
     * hizo que el borde HTTP y el nucleo dejaran de estar de acuerdo sobre que es
     * un festivo valido.
     *
     * Lo que la pantalla enseñe se corrige guardando: el camino de escritura si
     * es estricto y devuelve `422` sobre la fecha que no lo sea.
     *
     * @return list<string>
     */
    private function decodeCalendar(int $profileId, string $raw): array
    {
        $calendar = HolidayCalendar::fromStoredJson($raw);

        if (! $calendar->isClean()) {
            $this->logger->warning('product.compliance_profile_calendar_discarded', [
                'profile_id' => $profileId,
                'rejected' => count($calendar->rejected),
                'had_duplicates' => $calendar->hadDuplicates,
            ]);
        }

        return $calendar->days;
    }
}
