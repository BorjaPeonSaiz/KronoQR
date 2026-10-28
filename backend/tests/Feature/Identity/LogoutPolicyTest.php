<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Policy\SessionPolicy;
use App\Modules\Identity\Http\Request\LogoutRequest;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Database\Eloquent\Model;

/*
 * La policy de `POST /api/v1/auth/logout` (regla dura 18, R4-QA-06 de la
 * re-verificacion de la 2.2.0).
 *
 * La ruta la usan los cuatro portadores que el producto emite, y eso lo prueba
 * por HTTP `AuthorizationMatrixTest` (siete actores, todos autorizados) y
 * `SupportTokenBehaviourTest` (el acceso de soporte). Lo que queda aqui es la
 * mitad que el HTTP no puede alcanzar: un `tokenable` de otra tabla no llega
 * nunca a la policy, porque el autenticador de Sanctum ya responde `401`. Se
 * prueba la policy directamente y que la peticion de la ruta la aplica.
 */

/** Un modelo cualquiera sobre la tabla dada, sin tocar la base de datos. */
function logoutPolicyBearerOn(string $table): Model
{
    $bearer = new class extends Model {};

    return $bearer->setTable($table);
}

it('deja cerrar su sesion a cada portador que el producto emite', function (string $table): void {
    expect((new SessionPolicy)->logOut(logoutPolicyBearerOn($table)))->toBeTrue();
})->with([
    'cuenta de gestion' => 'users',
    'sesion de portal' => 'employees',
    'quiosco' => 'devices',
    'acceso de soporte' => 'support_grants',
])->group('RQ-07', 'RF-ID-05');

it('no deja cerrar sesion a un portador de una tabla que el producto no emite', function (string $table): void {
    expect((new SessionPolicy)->logOut(logoutPolicyBearerOn($table)))->toBeFalse();
})->with([
    'centro' => 'sites',
    'departamento' => 'departments',
])->group('RQ-07');

it('no deja cerrar sesion sin portador', function (): void {
    expect((new SessionPolicy)->logOut(null))->toBeFalse();
})->group('RQ-07');

it('aplica la policy en la peticion de la ruta', function (): void {
    // Sin esto, la policy de arriba podria quedar sin usar —el controlador con
    // un `Request` a secas otra vez— y todo seguiria en verde.
    $ajeno = LogoutRequest::create('/api/v1/auth/logout', 'POST');
    $ajeno->setUserResolver(static fn (): Model => logoutPolicyBearerOn('sites'));

    $cuenta = LogoutRequest::create('/api/v1/auth/logout', 'POST');
    $cuenta->setUserResolver(static fn (): User => new User);

    expect($ajeno->authorize())->toBeFalse()
        ->and($cuenta->authorize())->toBeTrue();
})->group('RQ-07');

it('resuelve la ruta con la peticion que aplica la policy', function (): void {
    $action = resolve('router')->getRoutes()->getByName('auth.logout')?->getActionName();

    expect($action)->toBeString();

    /** @var class-string $action */
    $parameter = new ReflectionMethod($action, '__invoke')->getParameters()[0];

    expect((string) $parameter->getType())->toBe(LogoutRequest::class);
})->group('RQ-07');
