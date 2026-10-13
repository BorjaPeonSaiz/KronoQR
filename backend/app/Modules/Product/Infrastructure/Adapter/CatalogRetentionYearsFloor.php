<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Domain\ValueObject\ComplianceProfileField;
use App\Modules\Shared\Application\Port\RetentionYearsFloor;
use App\Modules\Shared\Domain\ValueObject\Jurisdiction;
use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * El suelo legal de `compliance_profiles.retention_years` por jurisdiccion,
 * leido del catalogo `compliance.retention_floors` (RL-02, RF-PD-07, regla dura
 * 14, ADR-057 enmienda del 10-10-2026).
 *
 * Sustituye al antiguo `ProfileRetentionYearsFloor`, que devolvia el minimo
 * estructural del campo (1) para todas las jurisdicciones: con el, un perfil
 * español podia bajar a un año y la conciliacion creia admisible una purga a
 * tres.
 *
 * ## El catalogo se valida al construirse
 *
 * Las claves son paises ISO 3166-1 alfa-2 en mayusculas y los valores, enteros
 * entre `default` y el maximo del campo. Si no cumple, `RuntimeException` con la
 * clave culpable: es un fallo del producto, no del cliente (el catalogo no
 * tiene variable de entorno), y fallar al arrancar es mejor que resolver un
 * suelo que nadie ha querido escribir.
 *
 * Recibe el catalogo ya leido —lo construye `ProductServiceProvider` con
 * `Config`— para que su prueba unitaria no necesite la aplicacion.
 */
final class CatalogRetentionYearsFloor implements RetentionYearsFloor
{
    /** @var array<string, int> */
    private readonly array $jurisdictions;

    /**
     * Memoria de `forSite` dentro de una ejecucion: se enlaza `scoped`, como
     * {@see DbCompliancePolicyProvider}, asi que un cambio de la fila surte
     * efecto en la siguiente peticion o pasada sin reiniciar nada.
     *
     * @var array<int, LegalRetentionFloor>
     */
    private array $bySite = [];

    /**
     * @param  array<mixed>  $jurisdictions  `compliance.retention_floors.jurisdictions`
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $defaultYears,
        array $jurisdictions,
    ) {
        $maximum = ComplianceProfileField::RetentionYears->maximum();

        if ($defaultYears < 1 || $defaultYears > $maximum) {
            throw new RuntimeException(
                'compliance.retention_floors.default tiene que estar entre 1 y '.$maximum.'; vale '.$defaultYears.'.'
            );
        }

        $catalog = [];

        foreach ($jurisdictions as $country => $years) {
            if (! is_string($country) || preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
                throw new RuntimeException(
                    'compliance.retention_floors.jurisdictions: la clave «'.$country.'» no es un pais ISO 3166-1 '
                    .'alfa-2 en mayusculas.'
                );
            }

            if (! is_int($years) || $years < $defaultYears || $years > $maximum) {
                throw new RuntimeException(
                    'compliance.retention_floors.jurisdictions.'.$country.' tiene que ser un entero entre '
                    .$defaultYears.' y '.$maximum.'.'
                );
            }

            $catalog[$country] = $years;
        }

        $this->jurisdictions = $catalog;
    }

    public function forJurisdiction(Jurisdiction $jurisdiction): LegalRetentionFloor
    {
        $years = $this->jurisdictions[$jurisdiction->country()] ?? null;

        return $years === null
            ? LegalRetentionFloor::byDefault($jurisdiction, $this->defaultYears)
            : LegalRetentionFloor::fromCatalog($jurisdiction, $years);
    }

    public function forSite(int $siteId): LegalRetentionFloor
    {
        return $this->bySite[$siteId] ??= $this->forJurisdiction(
            Jurisdiction::fromCode($this->jurisdictionOf($siteId)),
        );
    }

    /**
     * La jurisdiccion del perfil vigente del centro, con la misma cascada que
     * {@see DbCompliancePolicyProvider}: el asignado o el de `is_default`.
     */
    private function jurisdictionOf(int $siteId): string
    {
        $assigned = $this->connection->table('compliance_profiles')
            ->join('sites', 'sites.compliance_profile_id', '=', 'compliance_profiles.id')
            ->where('sites.id', $siteId)
            ->value('compliance_profiles.jurisdiction');

        if (is_string($assigned)) {
            return $assigned;
        }

        $default = $this->connection->table('compliance_profiles')
            ->where('is_default', true)
            ->value('jurisdiction');

        if (is_string($default)) {
            return $default;
        }

        throw new RuntimeException(
            'No hay perfil de cumplimiento para el centro '.$siteId.' ni perfil por defecto en la '
            .'instalacion. Lo siembra la migracion de compliance_profiles (perfil ES-hosteleria); '
            .'revisa si se ha editado a mano.'
        );
    }
}
