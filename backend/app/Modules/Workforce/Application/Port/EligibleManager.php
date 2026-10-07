<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use InvalidArgumentException;

/**
 * Una cuenta que puede dirigir un departamento, tal como la resuelve
 * {@see ManagementAccountLookup}: su fila (`users.id`), que es lo que guarda
 * `departments.manager_user_id`, y su `uuid` publico en forma canonica, que es
 * lo unico que sale hacia el contrato y hacia `audit_log` (regla dura 21).
 */
final readonly class EligibleManager
{
    public function __construct(
        public int $userId,
        public string $uuid,
    ) {
        if ($userId < 1) {
            throw new InvalidArgumentException('El identificador de una cuenta es positivo.');
        }

        if ($uuid === '') {
            throw new InvalidArgumentException('Una cuenta necesita uuid.');
        }
    }
}
