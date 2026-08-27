<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Domain\ValueObject\CredentialLifecycleStatus;
use App\Modules\Identity\Domain\ValueObject\SiteCredentialCoverage;

/**
 * El panel de estado completo (RF-QR-08): las filas y el recuento por centro.
 *
 * **El recuento se calcula sobre TODA la plantilla, aunque las filas vengan
 * filtradas.** Es la diferencia entre un resumen y un total parcial: si
 * `--pending` deja tres filas, el resumen tiene que seguir diciendo «3 de 60»,
 * porque el numero que importa es cuanta gente falta **de la que hay**. Un
 * denominador que cambiara con el filtro convertiria la metrica en una
 * tautologia.
 *
 * `coverage` es tambien lo que se publica como
 * `employees_without_delivered_credential{site}` y `credentials_pending_print{site}`
 * (doc 02 §8.2), y por eso incluye **todos los centros**, tambien los que estan a
 * cero: una serie ausente y una serie en cero se ven igual en un panel, y solo la
 * segunda dice «ya esta todo entregado».
 */
final readonly class CredentialStatusReport
{
    /**
     * @param  list<CredentialStatusRow>  $rows  Ya filtradas segun la consulta.
     * @param  list<SiteCredentialCoverage>  $coverage  Todos los centros del alcance, sin filtrar.
     */
    public function __construct(
        public array $rows,
        public array $coverage,
    ) {}

    /**
     * Cuantas filas hay en cada uno de los cinco estados, **sobre las filas
     * devueltas**.
     *
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        $counts = [];

        foreach (CredentialLifecycleStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        foreach ($this->rows as $row) {
            $counts[$row->status->value]++;
        }

        return $counts;
    }

    public function employeesWithoutDeliveredCredential(): int
    {
        return array_sum(array_map(
            static fn (SiteCredentialCoverage $site): int => $site->withoutDeliveredCredential,
            $this->coverage,
        ));
    }

    public function pendingPrint(): int
    {
        return array_sum(array_map(
            static fn (SiteCredentialCoverage $site): int => $site->pendingPrint,
            $this->coverage,
        ));
    }
}
