<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Query;

use App\Modules\Identity\Application\Port\ManagementAccountDirectory;
use App\Modules\Identity\Application\Port\ManagementAccountFilter;
use App\Modules\Identity\Application\Port\ManagementAccountRecord;
use App\Modules\Identity\Domain\ValueObject\PasswordStatus;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;

/**
 * Las cuentas de gestion vistas por quien las administra (**RF-ID-10**;
 * `GET /api/v1/management-accounts` e `identity:list-users`).
 *
 * **El listado deja asiento** (bloque 11 de la 2.3.0, tarea 11.4, V4-BE-4;
 * RS-05, enmienda de ADR-037): lo que lista —nombre, correo y rol de quien entra
 * al panel— son datos personales de terceros, aunque no sean de la plantilla.
 * `personal_data.accessed` con conjunto `management_accounts`, el numero de
 * cuentas entregadas y los filtros, **nunca el termino buscado** (solo si lo
 * hubo). Sin agrupar: lo pide una persona, no un sondeo. Se escribe antes de
 * devolver; si no se puede escribir, no hay listado.
 *
 * `find()` no deja asiento: lo usan las respuestas de los cambios sobre una
 * cuenta, que ya dejan el suyo.
 *
 * El estado de la contrasena se resuelve aqui, con el reloj inyectado (regla
 * dura 2): el adaptador da la caducidad y el dominio decide que significa ahora.
 */
final readonly class ManagementAccountsQuery
{
    /** El conjunto del asiento de lectura (tarea 11.4). */
    public const string DATASET = 'management_accounts';

    public function __construct(
        private ManagementAccountDirectory $directory,
        private Clock $clock,
        private PersonalDataAccessLog $disclosures,
    ) {}

    public function page(ManagementAccountFilter $filter, int $page, int $perPage): ManagementAccountListing
    {
        return UseCaseInstrumentation::run('identity.list_management_accounts', fn (): ManagementAccountListing => $this->doPage($filter, $page, $perPage));
    }

    private function doPage(ManagementAccountFilter $filter, int $page, int $perPage): ManagementAccountListing
    {
        $result = $this->directory->page($filter, $page, $perPage);
        $now = $this->clock->now();

        $this->disclosures->recordDisclosure(self::DATASET, \count($result->items), self::context($filter, $result->page, $result->perPage));

        return new ManagementAccountListing(
            array_map(
                static fn (ManagementAccountRecord $account): ManagementAccountView => new ManagementAccountView(
                    $account,
                    PasswordStatus::of($account->temporaryPasswordExpiresAt, $now),
                ),
                $result->items,
            ),
            $result->page,
            $result->perPage,
            $result->total,
            $result->totalPages(),
        );
    }

    /**
     * Los filtros del listado, sin el termino buscado (regla dura 21).
     *
     * @return array<string, scalar>
     */
    private static function context(ManagementAccountFilter $filter, int $page, int $perPage): array
    {
        $context = [
            'search' => $filter->search !== null && $filter->search !== '',
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($filter->active !== null) {
            $context['status'] = $filter->active ? 'active' : 'deactivated';
        }

        if ($filter->role !== null) {
            $context['role'] = $filter->role->value;
        }

        return $context;
    }

    public function find(string $uuid): ?ManagementAccountView
    {
        return UseCaseInstrumentation::run('identity.find_management_account', fn (): ?ManagementAccountView => $this->doFind($uuid));
    }

    private function doFind(string $uuid): ?ManagementAccountView
    {
        $account = $this->directory->find($uuid);

        return $account === null
            ? null
            : new ManagementAccountView($account, PasswordStatus::of($account->temporaryPasswordExpiresAt, $this->clock->now()));
    }
}
