<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\AuthenticateUserCommand;
use App\Modules\Identity\Application\Command\VerifyTwoFactorCommand;
use App\Modules\Identity\Application\Port\TwoFactorAuthenticator;
use App\Modules\Identity\Application\Port\TwoFactorSecrets;
use App\Modules\Identity\Domain\ValueObject\TwoFactorEnrolment;
use App\Modules\Identity\Infrastructure\Adapter\Google2faAuthenticator;
use App\Modules\Identity\Infrastructure\Persistence\EloquentTwoFactorSecrets;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;

/*
 * **Las credenciales no salen del proceso por la puerta de atras** (RS-08, ADR-020,
 * regla dura 21).
 *
 * Las respuestas ya estan cuidadas: ningun recurso serializa una contrasena, un
 * PIN ni un secreto TOTP. Las dos vias que quedaban abiertas son las que nadie
 * escribe a proposito:
 *
 * 1. **La traza de una excepcion.** PHP enumera en cada marco los argumentos que
 *    recibio la funcion. Un fallo del cifrado, un error del driver o un `500`
 *    cualquiera dejaba en el log la contrasena de gestion, el codigo del
 *    autenticador o el secreto TOTP en claro — y ese log viaja al fabricante
 *    dentro del paquete de diagnostico.
 * 2. **La serializacion accidental de un modelo Eloquent.** `two_factor_secret`
 *    lleva el `cast` `encrypted`, asi que se descifra AL LEERLO: un `dd($user)`,
 *    un `Log::info($user)` o un recurso descuidado lo sacaban en claro.
 *
 * **La defensa es doble en el primer caso y a proposito.** `#[SensitiveParameter]`
 * documenta en el codigo que ese argumento es una credencial y protege aunque
 * alguien ponga la directiva en `Off` para depurar; `zend.exception_ignore_args`
 * —que fija `QualityGatesTest` sobre el `php.ini` del producto— protege lo que
 * nadie marco, que es el caso que de verdad ocurre.
 *
 * **Se comprueba por reflexion y no leyendo el fichero**: lo que importa es que el
 * atributo este puesto en la firma que PHP va a mirar, no que exista la cadena en
 * alguna linea.
 */

uses(RefreshDatabase::class);

function marcaSensible(string $class, string $method, string $parameter): bool
{
    expect(class_exists($class) || interface_exists($class))->toBeTrue($class.' no existe.');

    /** @var class-string $class */
    $reflection = new ReflectionMethod($class, $method);

    foreach ($reflection->getParameters() as $candidate) {
        if ($candidate->getName() === $parameter) {
            return $candidate->getAttributes(SensitiveParameter::class) !== [];
        }
    }

    return false;
}

it('marca como sensible cada parametro que transporta una credencial', function (
    string $class,
    string $method,
    string $parameter,
): void {
    expect(marcaSensible($class, $method, $parameter))
        ->toBeTrue($class.'::'.$method.'($'.$parameter.') puede acabar en una traza.');
})->with([
    // La contrasena del panel, carencia heredada de la Fase 1: el PIN del portal y
    // el del quiosco ya la llevaban y esta no.
    'contrasena de gestion' => [AuthenticateUserCommand::class, '__construct', 'password'],

    // El codigo TOTP es una credencial de un solo uso: hasta que se gasta, vale
    // para entrar.
    'codigo del autenticador' => [VerifyTwoFactorCommand::class, '__construct', 'code'],

    // El secreto y la URI que lo lleva dentro como `?secret=`. Marcar solo el
    // primero dejaria el mismo valor en la traza por el segundo argumento.
    'secreto recien emitido' => [TwoFactorEnrolment::class, '__construct', 'secret'],
    'URI otpauth con el secreto dentro' => [TwoFactorEnrolment::class, '__construct', 'otpauthUri'],

    // El puerto y su adaptador. El puerto porque es la firma que lee quien
    // implementa otro adaptador; el adaptador porque es el marco que aparece en la
    // traza cuando la libreria falla.
    'puerto: guardar el secreto' => [TwoFactorSecrets::class, 'storeUnconfirmedSecret', 'secret'],
    'adaptador: guardar el secreto' => [EloquentTwoFactorSecrets::class, 'storeUnconfirmedSecret', 'secret'],
    'puerto: verificar (secreto)' => [TwoFactorAuthenticator::class, 'verify', 'secret'],
    'puerto: verificar (codigo)' => [TwoFactorAuthenticator::class, 'verify', 'code'],
    'adaptador: verificar (secreto)' => [Google2faAuthenticator::class, 'verify', 'secret'],
    'adaptador: verificar (codigo)' => [Google2faAuthenticator::class, 'verify', 'code'],
    'puerto: URI del autenticador' => [TwoFactorAuthenticator::class, 'otpauthUriFor', 'secret'],
    'adaptador: URI del autenticador' => [Google2faAuthenticator::class, 'otpauthUriFor', 'secret'],
])->group('RS-08', 'RS-06');

it('no deja salir el secreto TOTP al serializar la cuenta', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($user);

    $fresco = User::query()->where('uuid', $user->uuid)->firstOrFail();

    // El `cast` lo descifra al leerlo, asi que sin `$hidden` esto seria el secreto
    // en claro dentro de cualquier volcado.
    expect($fresco->two_factor_secret)->toBe(ManagementUsers::TOTP_SECRET)
        ->and($fresco->toArray())->not->toHaveKey('two_factor_secret')
        ->and((string) json_encode($fresco))->not->toContain(ManagementUsers::TOTP_SECRET)
        // Y las dos que ya estaban, para que nadie las quite al tocar la lista.
        ->and($fresco->toArray())->not->toHaveKey('password')
        ->and($fresco->toArray())->not->toHaveKey('remember_token');
})->group('RS-08', 'RS-06');

it('no consulta ningun guard de sesion antes del token portador', function (): void {
    // `Sanctum\Guard::__invoke()` pregunta primero a los guards de esta lista y
    // solo despues mira el `Bearer`. Cuando uno responde, adjunta un
    // `TransientToken`, cuyo `can()` devuelve `true` PARA CUALQUIER AMBITO: el
    // middleware `ability` —la mitad de la regla dura 18 que verifica el ambito—
    // dejaria pasar todo.
    //
    // El producto no tiene ninguna SPA de mismo origen con cookies: las tres
    // hablan con `/api/v1` por `Authorization` (doc 02 §7.3), no se expone
    // `/sanctum/csrf-cookie` y ninguna ruta de `api_v1.php` lleva el stack `web`.
    expect(config('sanctum.guard'))->toBe([])
        ->and(config('sanctum.stateful'))->toBe([])
        // La caducidad se pasa token a token: la sesion de gestion es corta, la del
        // portal dura dos horas y la del quiosco 90 dias. Un valor global aqui
        // sobreescribiria los tres.
        ->and(config('sanctum.expiration'))->toBeNull();
})->group('RS-04', 'RS-06');
