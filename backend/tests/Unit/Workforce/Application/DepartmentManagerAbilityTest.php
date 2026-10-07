<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Workforce\Application\Port\EligibleManager;
use App\Modules\Workforce\Http\Request\UpdateDepartmentRequest;

/*
 * Lo que sostiene la autorizacion por campo de `manager_user_uuid` (RF-ID-10,
 * ADR-051 §2 y §5).
 *
 * `Workforce` no puede importar `TokenAbility` de `Identity` (Deptrac), asi que
 * el `FormRequest` lleva una copia del nombre del ambito. Si un dia se renombra
 * en un lado y no en el otro, nadie podria asignar responsable —o, peor, lo
 * comprobaria contra un ambito que no existe—, y esta prueba es la que lo caza.
 */

it('exige el mismo ambito accounts:* que emite Identity', function (): void {
    expect(UpdateDepartmentRequest::ASSIGN_MANAGER_ABILITY)->toBe(TokenAbility::ACCOUNTS_ALL->value);
})->group('RF-ID-10');

it('rechaza una cuenta elegible sin fila o sin uuid', function (int $id, string $uuid): void {
    expect(fn (): EligibleManager => new EligibleManager($id, $uuid))->toThrow(InvalidArgumentException::class);
})->with([
    'id cero' => [0, '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91'],
    'id negativo' => [-1, '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91'],
    'uuid vacio' => [1, ''],
])->group('RF-ID-10');

it('conserva la fila y el uuid de la cuenta elegible', function (): void {
    $cuenta = new EligibleManager(7, '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91');

    expect($cuenta->userId)->toBe(7)
        ->and($cuenta->uuid)->toBe('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91');
})->group('RF-ID-10');
