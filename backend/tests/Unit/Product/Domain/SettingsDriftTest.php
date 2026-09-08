<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\SettingsDrift;

/*
 * «El `.env` dice una cosa y la base de datos otra» (RF-PD-01, ADR-017).
 *
 * La regla la consultan DOS sitios —la seccion `configuration` del paquete de
 * diagnostico y la sonda `settings.env_differs_from_db` de `product:doctor`— y
 * estaba escrita dos veces. Estas pruebas fijan la unica copia que queda.
 *
 * Lo que mas importa aqui no es que encuentre las diferencias: es **que no
 * devuelva los valores**. Con ellos, la seccion `configuration` se convertiria
 * en una via para sacar del `.env` cualquier variable que ademas fuera una clave
 * del catalogo, esquivando la lista de permitidos que la filtra.
 */

/**
 * La configuracion resuelta sobre lo que haya guardado en la base de datos.
 *
 * @param  array<string, mixed>  $stored
 */
function configuracionGuardada(array $stored = []): ResolvedSettings
{
    return ResolvedSettings::resolve($stored);
}

it('no ve diferencia cuando el entorno no menciona la clave', function (): void {
    // Lo normal: el `.env` no habla de la mayoria del catalogo. Eso es «no
    // opino», no «opino algo distinto».
    $drift = SettingsDrift::between([], configuracionGuardada());

    expect($drift->isEmpty())->toBeTrue()
        ->and($drift->keys)->toBe([])
        ->and($drift->toArray())->toBe([]);
})->group('RF-PD-01', 'RF-PD-13');

it('no ve diferencia cuando los dos dicen lo mismo', function (): void {
    $guardada = configuracionGuardada();
    $vigente = $guardada->integer(SettingKey::ATTENDANCE_MAX_SHIFT_HOURS);

    $drift = SettingsDrift::between(
        [SettingKey::ATTENDANCE_MAX_SHIFT_HOURS->value => (string) $vigente],
        $guardada,
    );

    expect($drift->isEmpty())->toBeTrue();
})->group('RF-PD-01');

it('señala la clave cuando el entorno dice otra cosa', function (): void {
    $drift = SettingsDrift::between(
        [SettingKey::ATTENDANCE_MAX_SHIFT_HOURS->value => '99'],
        configuracionGuardada(),
    );

    expect($drift->isEmpty())->toBeFalse()
        ->and($drift->keys)->toBe(['ATTENDANCE_MAX_SHIFT_HOURS'])
        ->and($drift->asText())->toBe('ATTENDANCE_MAX_SHIFT_HOURS');
})->group('RF-PD-01', 'RF-PD-13');

it('devuelve la clave y NUNCA los dos valores', function (): void {
    // LA PRUEBA QUE JUSTIFICA LA CLASE. Si algun dia alguien añade el valor
    // «para que se vea mejor en el panel», la seccion `configuration` del
    // paquete empezaria a sacar del `.env` variables que su lista de permitidos
    // deja fuera.
    $drift = SettingsDrift::between(
        [SettingKey::BRANDING_APP_NAME->value => 'Hotel Secreto de Pruebas'],
        configuracionGuardada(),
    );

    $serialized = json_encode($drift->toArray(), JSON_THROW_ON_ERROR);

    expect($drift->toArray())->toBe([['key' => 'BRANDING_APP_NAME', 'differs' => true]])
        ->and($serialized)->not->toContain('Hotel Secreto de Pruebas')
        ->and($serialized)->not->toContain('KronoQR');
})->group('RF-PD-09', 'RF-PD-01');

it('ignora lo que no es un escalar del entorno', function (): void {
    // Un array en `$_ENV` no es una opinion sobre la clave, y castearlo a texto
    // lanzaria.
    $drift = SettingsDrift::between(
        [SettingKey::ATTENDANCE_MAX_SHIFT_HOURS->value => ['12']],
        configuracionGuardada(),
    );

    expect($drift->isEmpty())->toBeTrue();
})->group('RF-PD-01');

it('compara una lista con la forma en que se escribe en el .env', function (): void {
    // `LOCALE_AVAILABLE` es una lista y en el `.env` se escribe separada por
    // comas. Sin esto, TODA clave de lista apareceria como distinta siempre, y
    // el aviso se volveria ruido que nadie mira.
    $guardada = configuracionGuardada();

    /** @var list<string> $vigente */
    $vigente = $guardada->get(SettingKey::LOCALE_AVAILABLE)->value();

    expect(SettingsDrift::between(
        [SettingKey::LOCALE_AVAILABLE->value => implode(',', $vigente)],
        $guardada,
    )->isEmpty())->toBeTrue();

    expect(SettingsDrift::between(
        [SettingKey::LOCALE_AVAILABLE->value => 'fr,de'],
        $guardada,
    )->keys)->toBe(['LOCALE_AVAILABLE']);
})->group('RF-PD-01');

it('devuelve las claves en el orden del catalogo', function (): void {
    // Para que dos paquetes de la misma instalacion se puedan comparar linea a
    // linea, y para que el texto de `doctor` no baile entre ejecuciones.
    $drift = SettingsDrift::between([
        SettingKey::LOCALE_DEFAULT->value => 'fr',
        SettingKey::ATTENDANCE_MAX_SHIFT_HOURS->value => '99',
    ], configuracionGuardada());

    $catalogo = array_map(static fn (SettingKey $key): string => $key->value, SettingKey::cases());

    expect($drift->keys)->toBe(array_values(array_intersect($catalogo, $drift->keys)));
})->group('RF-PD-01');
