<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Query;

use App\Modules\Identity\Domain\ValueObject\CredentialLifecycleStatus;

/**
 * Lo que pide el panel de RF-QR-08 o el comando de consola.
 *
 * Sin centro (ADR-040): el alcance es la plantilla de la instalacion, acotada
 * como mucho a una persona. `unattended` distingue la ejecucion del
 * planificador —solo metricas, sin nadie que vea un nombre— de una lectura que
 * si divulga y deja asiento (ADR-037).
 */
final readonly class CredentialStatusQuery
{
    public function __construct(
        public bool $pendingOnly = false,
        public ?string $employeeUuid = null,
        public bool $unattended = false,
        /**
         * Solo quien sigue fichando con una tarjeta firmada por esa clave
         * (RF-QR-07, §5.3). Es lo que responde «a quien le falta reimprimir»
         * durante una rotacion, y lo que permite retirar la clave anterior
         * cuando ya no devuelve a nadie.
         */
        public ?string $keyId = null,
        /**
         * Subcadena del nombre completo o del codigo de empleado, sin distinguir
         * mayusculas ni acentos (2.3.0, HC1). **Nunca llega al asiento**: el
         * termino puede ser un nombre (regla dura 21).
         */
        public ?string $search = null,
        /** Solo la plantilla de ese departamento. */
        public ?int $departmentId = null,
        /** Solo quien no tiene departamento. No se combina con `departmentId`. */
        public bool $withoutDepartment = false,
        /** Solo las filas en ese estado de la tarjeta. */
        public ?CredentialLifecycleStatus $status = null,
        /**
         * La pagina, desde 1, o nula para **todas las filas**: el comando de
         * consola y el planificador las quieren enteras. El panel siempre pagina.
         */
        public ?int $page = null,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {}

    /** Las filas por pagina del panel si no se pide otra cosa: las de su tabla. */
    public const int DEFAULT_PER_PAGE = 30;

    /** El techo del contrato. */
    public const int MAX_PER_PAGE = 100;

    /** ¿Acota las filas algo mas que `pending` y `key_id`? */
    public function narrowsRows(): bool
    {
        return $this->search !== null || $this->departmentId !== null || $this->withoutDepartment || $this->status !== null;
    }
}
