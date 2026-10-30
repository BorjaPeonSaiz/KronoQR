<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Resource;

use App\Modules\Compliance\Application\Port\LegalExportEmployeeMatch;
use App\Modules\Compliance\Application\UseCase\LegalExportEmployeeMatches;
use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `LegalExportEmployeeMatches` del contrato (RF-IN-05).
 *
 * **Lo que no sale, y es lo importante**: ni `employee_code`, ni el correo, ni
 * el documento ni su digest. Cada campo se nombra aqui a mano, sin volcar el
 * objeto, para que un campo nuevo del puerto no llegue a la respuesta sin que
 * nadie lo decida; el esquema del contrato lleva ademas
 * `additionalProperties: false` y la prueba de contrato lo comprueba.
 */
final class LegalExportEmployeeMatchesResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    public function __construct(LegalExportEmployeeMatches $matches)
    {
        parent::__construct($matches);
    }

    /**
     * @return array{data: list<array<string, mixed>>, truncated: bool}
     */
    public function toArray(Request $request): array
    {
        /** @var LegalExportEmployeeMatches $matches */
        $matches = $this->resource;

        return [
            'data' => array_map($this->match(...), $matches->matches),
            'truncated' => $matches->truncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function match(LegalExportEmployeeMatch $match): array
    {
        return [
            'uuid' => $match->uuid,
            'first_name' => $match->firstName,
            'last_name' => $match->lastName,
            'department' => $match->departmentId === null || $match->departmentName === null
                ? null
                : ['id' => $match->departmentId, 'name' => $match->departmentName],
            'status' => $match->status->value,
            'hired_at' => $match->hiredAt,
            'terminated_at' => $match->terminatedAt,
            'employment_periods' => array_map(static fn (EmploymentPeriod $period): array => [
                'uuid' => $period->uuid,
                'hired_at' => $period->hiredOn,
                'terminated_at' => $period->terminatedOn,
                'state' => $period->state(),
            ], $match->employmentPeriods),
        ];
    }
}
