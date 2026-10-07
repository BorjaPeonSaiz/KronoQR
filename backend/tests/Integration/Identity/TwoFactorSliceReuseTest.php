<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\EloquentTwoFactorSecrets;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;

/*
 * La franja del ultimo codigo TOTP aceptado se gasta con UNA escritura
 * condicionada (RS-06, RF-ID-10): de dos peticiones con el mismo codigo solo
 * vale una. Antes se leia y despues se guardaba, y las dos pasaban.
 */

uses(RefreshDatabase::class);

it('acepta la franja una sola vez y nunca una anterior', function (): void {
    $user = ManagementUsers::withRole(UserRole::ADMIN);
    $secrets = new EloquentTwoFactorSecrets;

    expect($secrets->rememberAcceptedSlice($user->uuid, 100))->toBeTrue()
        ->and($secrets->rememberAcceptedSlice($user->uuid, 100))->toBeFalse()
        ->and($secrets->rememberAcceptedSlice($user->uuid, 99))->toBeFalse()
        ->and($secrets->rememberAcceptedSlice($user->uuid, 101))->toBeTrue()
        ->and(DB::table('users')->where('uuid', $user->uuid)->value('two_factor_last_slice'))->toBe(101);
})->group('RS-06', 'RF-ID-10');

it('no acepta la franja de una cuenta que no existe', function (): void {
    expect((new EloquentTwoFactorSecrets)->rememberAcceptedSlice('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b99', 1))->toBeFalse();
})->group('RS-06');
