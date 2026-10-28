<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;

/*
 * **Quien entra al portal y quien usa su PIN en cada puerta** (RN-14, RN-25,
 * RL-05; decision del propietario del 27-10-2026).
 *
 * La suspension impide fichar, no consultar: la persona suspendida entra al
 * portal personal y la de baja no. En el quiosco solo vale el PIN de quien puede
 * fichar. Un caso por estado y puerta, para que ningun cambio del enum pase sin
 * decidir los dos.
 */

it('deja entrar al portal a quien sigue en plantilla, tambien suspendida, y no a la baja', function (EmploymentStatus $status, bool $expected): void {
    expect($status->canSignInToPortal())->toBe($expected);
})->with([
    'activa' => [EmploymentStatus::ACTIVE, true],
    'suspendida' => [EmploymentStatus::SUSPENDED, true],
    'de baja' => [EmploymentStatus::TERMINATED, false],
])->group('RN-25', 'RN-14', 'RL-05');

it('admite el PIN segun la puerta: el quiosco solo a quien ficha, el portal a quien no esta de baja', function (EmploymentStatus $status, PinOrigin $origin, bool $expected): void {
    expect($status->admitsPinFrom($origin))->toBe($expected);
})->with([
    'activa en el quiosco' => [EmploymentStatus::ACTIVE, PinOrigin::KIOSK, true],
    'suspendida en el quiosco' => [EmploymentStatus::SUSPENDED, PinOrigin::KIOSK, false],
    'de baja en el quiosco' => [EmploymentStatus::TERMINATED, PinOrigin::KIOSK, false],
    'activa en el portal' => [EmploymentStatus::ACTIVE, PinOrigin::PORTAL, true],
    'suspendida en el portal' => [EmploymentStatus::SUSPENDED, PinOrigin::PORTAL, true],
    'de baja en el portal' => [EmploymentStatus::TERMINATED, PinOrigin::PORTAL, false],
])->group('RN-25', 'RN-14', 'RL-05', 'RF-AT-11');
