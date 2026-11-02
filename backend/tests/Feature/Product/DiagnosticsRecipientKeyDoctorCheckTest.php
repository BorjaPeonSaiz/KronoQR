<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\DiagnosticsRecipient;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `product:doctor` dice si el paquete de diagnostico con datos personales se
 * puede generar (`diagnostics.recipient_key`; RF-PD-13, RL-19; ADR-020,
 * enmienda del 01-11-2026).
 *
 * Sin clave publica de soporte ese paquete no se genera nunca, y quien lo
 * descubre sin esta sonda es el cliente con soporte al telefono. **Aviso y
 * nunca fallo**: fichar, el registro legal y el paquete anonimizado no
 * dependen de la clave, y un fallo aqui abortaria una actualizacion por algo
 * que no impide trabajar (regla dura 15, por analogia).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
});

function comprobacionDeLaClaveDeSoporte(): DoctorCheck
{
    $encontrada = array_values(array_filter(
        app(RunDoctorHandler::class)->handle('es')->checks,
        static fn (DoctorCheck $check): bool => $check->id === 'diagnostics.recipient_key',
    ));

    expect($encontrada)->not->toBeEmpty('`doctor` no incluye la comprobacion de la clave de soporte.');

    return $encontrada[0];
}

it('sale en verde con la clave instalada y dice cual, la misma que lleva el sobre', function (): void {
    $recipient = DiagnosticsRecipient::install();

    $comprobacion = comprobacionDeLaClaveDeSoporte();

    expect($comprobacion->status)->toBe(DoctorStatus::Ok)
        ->and($comprobacion->summary)->toContain($recipient->keyId)
        ->and($comprobacion->details)->toBe(['configured' => true, 'key_id' => $recipient->keyId])
        // Ni un byte de la clave publica: el identificador corto basta.
        ->and((string) json_encode($comprobacion->toArray()))->not->toContain($recipient->publicKeyHex);
})->group('RF-PD-13', 'RL-19');

it('avisa, sin fallar, si no hay clave valida, y dice que hacer', function (string $clave): void {
    config(['product.diagnostics_recipient_public_key' => $clave]);

    $comprobacion = comprobacionDeLaClaveDeSoporte();

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and($comprobacion->details)->toBe(['configured' => false])
        ->and($comprobacion->summary)->toContain('anonimizado')
        ->and($comprobacion->fix)->toContain('DIAGNOSTICS_RECIPIENT_PUBLIC_KEY')
        ->and($comprobacion->fix)->toContain('update.sh');
})->with([
    'vacia' => '',
    'mal formada' => 'no-es-una-clave',
    'de ceros' => str_repeat('00', 32),
])->group('RF-PD-13', 'RL-19');
