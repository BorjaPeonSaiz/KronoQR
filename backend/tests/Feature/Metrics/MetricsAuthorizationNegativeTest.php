<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **NINGUNA IDENTIDAD ABRE `GET /metrics`, Y NINGUNA LO CIERRA** (RS-09, RQ-07,
 * regla dura 18, doc 02 §9.5, tarea 3.1).
 *
 * ## Por que no esta en `AuthorizationNegativeTest`
 *
 * Aquel fichero es la lista canonica de parejas rol x endpoint, y toda su forma
 * da por hecho dos cosas que aqui no se cumplen: que la ruta vive bajo
 * `/api/v1` con `auth:sanctum` y una policy, y que **sin token responde `401`**
 * con su `problem+json`. `/metrics` no tiene ni policy ni `auth:sanctum` —su
 * unica guarda es {@see \App\Http\Middleware\RestrictToMetricsNetwork}, que
 * mira la RED— y sin token responde `403` o `200` segun de donde venga la
 * peticion. Meterla en `managementEndpoints()` habria roto el caso «deniega el
 * acceso sin token» de aquel fichero y, peor, habria descrito esta ruta como
 * algo que se abre presentando credenciales.
 *
 * ## Que se afirma, y por que hacen falta las dos mitades
 *
 * `MetricsEndpointTest` ya comprueba que desde fuera del CIDR **un anonimo**
 * recibe `403`. Lo que faltaba es la otra pregunta, que es la que hace la regla
 * dura 18: **si presentar credenciales cambia algo**. Son dos fallos distintos y
 * ninguno de los dos lo veria el otro fichero:
 *
 *   · que un token de gestion —o el de una tablet colgada en una pared, o la
 *     sesion del portal de un empleado— **abra** la ruta desde fuera de la red
 *     de sondeo. Ahi lo que se publica es la operacion entera del hotel:
 *     quioscos, ritmo de fichaje y errores;
 *   · que alguien, al «arreglar» la autorizacion de esta ruta, le ponga
 *     `auth:sanctum` y **cierre** el scrape desde dentro de la red. Prometheus
 *     no tiene cuenta: el objetivo se marcaria `DOWN` y con el se apagarian las
 *     alertas del producto sin que nadie lo note hasta que hiciera falta una.
 *
 * De ahi que el control positivo de abajo mire las dos direcciones a la vez.
 *
 * ## Con base de datos, al contrario que sus vecinos
 *
 * `MetricsEndpointTest` y `TrustedProxiesTest` no la necesitan porque el
 * endpoint no toca PostgreSQL. Aqui si: los tokens de verdad se emiten contra
 * cuentas de verdad, y un token fabricado a mano en la prueba comprobaria la
 * lista de ambitos de la prueba y no la del emisor.
 */

uses(RefreshDatabase::class);

/** Un rango de laboratorio, nunca el de la instalacion. */
const RED_DE_SONDEO_AUTORIZADA = '10.91.0.0/24';

const IP_DENTRO_DE_LA_RED_DE_SONDEO = '10.91.0.5';

const IP_DE_INTERNET = '203.0.113.7';

beforeEach(function (): void {
    config(['observability.metrics.allow_cidr' => RED_DE_SONDEO_AUTORIZADA]);
});

/**
 * Los seis roles del catalogo, cada uno con el token que emitiria su login.
 *
 * **Los seis y no los cuatro de gestion**: `kiosk` es el token de la tablet
 * —`scan:write`, `roster:read`, `heartbeat:write`— y es el que mas importa de
 * todos, porque vive en un dispositivo compartido colgado en una pared y sale
 * del hotel en el bolsillo de quien se lo lleve (RS-04). `empleado` entra
 * porque es la cuenta mas numerosa de cualquier instalacion.
 *
 * @return array<string, array{0: UserRole}>
 */
function rolesDelCatalogo(): array
{
    return [
        'admin' => [UserRole::ADMIN],
        'rrhh' => [UserRole::RRHH],
        'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
        'auditor' => [UserRole::AUDITOR],
        'empleado' => [UserRole::EMPLEADO],
        'quiosco' => [UserRole::KIOSK],
    ];
}

it('no abre /metrics desde fuera de la red de sondeo a ningun rol del catalogo', function (UserRole $role): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($role));

    $respuesta = Api::as($token)->fromIp(IP_DE_INTERNET)->get('/metrics');

    $respuesta->assertForbidden();

    // Y sin cuerpo, igual que el rechazo del anonimo: un `403` que explicara el
    // motivo le diria a quien acaba de probar su token que la ruta existe.
    expect($respuesta->getContent())->toBe('');
})->with(rolesDelCatalogo())->group('RS-09', 'RQ-07');

it('no abre /metrics desde fuera de la red de sondeo a la sesion de portal de un empleado', function (): void {
    // Entra por su propia puerta —codigo de empleado y PIN, ADR-015— y no como
    // una cuenta de gestion mas: el token del portal lleva `self:read` y lo
    // emite otro emisor. Que aquellos no abran la ruta no dice nada de este.
    $employee = WorkforceFixtures::employee(WorkforceFixtures::site());
    $token = PortalLogins::open($employee);

    $respuesta = Api::as($token)->fromIp(IP_DE_INTERNET)->get('/metrics');

    $respuesta->assertForbidden();

    expect($respuesta->getContent())->toBe('');
})->group('RS-09', 'RQ-07', 'RF-ID-07');

it('responde a un token de gestion exactamente lo mismo que a un anonimo', function (): void {
    // La forma fuerte de la afirmacion: no es que el token tambien reciba `403`,
    // es que la respuesta es **la misma**. Si algun dia presentar credenciales
    // cambiara aunque fuera una cabecera, esta ruta habria empezado a distinguir
    // quien llama, que es justo lo que no debe hacer.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    $conToken = Api::as($token)->fromIp(IP_DE_INTERNET)->get('/metrics');
    $sinToken = Api::guest()->fromIp(IP_DE_INTERNET)->get('/metrics');

    expect($conToken->getStatusCode())->toBe($sinToken->getStatusCode())
        ->and($conToken->getContent())->toBe($sinToken->getContent());
})->group('RS-09', 'RQ-07');

it('deja pasar el scrape desde dentro de la red sin credenciales, y tambien con ellas', function (): void {
    /*
     * El control positivo, y las dos mitades cuentan.
     *
     * **Sin token**: Prometheus no tiene cuenta. Sin esta linea, los `403` de
     * arriba pasarian identicos el dia que alguien pusiera `auth:sanctum` a la
     * ruta, y el sondeo se apagaria en silencio.
     *
     * **Con token**: presentar credenciales tampoco cierra la puerta. Un
     * `403` aqui significaria que la ruta ha empezado a exigir un ambito
     * concreto, y el primero que lo notaria seria un operador con sesion abierta
     * mirando por que no hay datos.
     */
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR));

    Api::guest()->fromIp(IP_DENTRO_DE_LA_RED_DE_SONDEO)->get('/metrics')->assertOk();
    Api::as($token)->fromIp(IP_DENTRO_DE_LA_RED_DE_SONDEO)->get('/metrics')->assertOk();
})->group('RS-09', 'RQ-07');
