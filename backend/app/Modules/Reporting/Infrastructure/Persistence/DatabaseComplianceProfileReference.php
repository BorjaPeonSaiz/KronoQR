<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\ComplianceProfileReference;
use App\Modules\Reporting\Domain\ValueObject\ComplianceProfileRef;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * Como se llama el perfil de cumplimiento del centro (RF-PA-06).
 *
 * **La cascada es la misma que la de `DbCompliancePolicyProvider`**, y tiene que
 * serlo: perfil asignado al centro (`sites.compliance_profile_id`) y, si no tiene,
 * el perfil por defecto de la instalacion (`compliance_profiles.is_default`, con
 * su indice unico parcial, asi que la caida no es ambigua). Si las dos difirieran,
 * la pantalla nombraria un perfil distinto del que ha puesto los umbrales, que es
 * la peor forma posible de equivocarse: el aviso seguiria siendo correcto y su
 * justificacion, falsa.
 *
 * **Tres columnas y ningun umbral.** Los umbrales llegan por
 * `CompliancePolicyProvider`, ya resueltos a minutos. Leerlos tambien aqui seria
 * una segunda fuente para el mismo numero.
 *
 * Dos consultas de una fila y sin memoria por peticion: esto se pide **una vez por
 * respuesta**, al contrario que la politica, que la revision diaria pide en cada
 * jornada.
 */
final readonly class DatabaseComplianceProfileReference implements ComplianceProfileReference
{
    public function __construct(private ConnectionInterface $connection) {}

    public function forSite(int $siteId): ?ComplianceProfileRef
    {
        $row = $this->assigned($siteId) ?? $this->default();

        if ($row === null) {
            return null;
        }

        $reader = Row::of($row);

        return new ComplianceProfileRef(
            id: $reader->int('id'),
            name: $reader->string('name'),
            jurisdiction: $reader->string('jurisdiction'),
        );
    }

    private function assigned(int $siteId): ?object
    {
        /** @var object|null $row */
        $row = $this->connection->table('compliance_profiles')
            ->join('sites', 'sites.compliance_profile_id', '=', 'compliance_profiles.id')
            ->where('sites.id', $siteId)
            ->select($this->columns())
            ->first();

        return $row;
    }

    private function default(): ?object
    {
        /** @var object|null $row */
        $row = $this->connection->table('compliance_profiles')
            ->where('is_default', true)
            ->select($this->columns())
            ->first();

        return $row;
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'compliance_profiles.id',
            'compliance_profiles.name',
            'compliance_profiles.jurisdiction',
        ];
    }
}
