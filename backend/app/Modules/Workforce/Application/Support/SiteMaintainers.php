<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Support;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Los roles que responden del centro: lo modifican por la API
 * (`SitePolicy::update`) y corrigen su zona por consola
 * (`CorrectSiteTimezoneHandler`, RN-23).
 *
 * Una sola lista para los dos caminos, de modo que la consola nunca permita lo
 * que la API niega.
 */
final class SiteMaintainers
{
    /** @var list<UserRole> */
    public const array ROLES = [UserRole::ADMIN, UserRole::RRHH];
}
