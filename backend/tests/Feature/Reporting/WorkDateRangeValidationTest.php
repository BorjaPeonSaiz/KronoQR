<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El rango `?from=&to=` de jornadas, **con la misma tabla de casos sobre las tres
 * peticiones que lo usan** (RF-PA-03, RF-ID-05, RL-05, RQ-06).
 *
 * ## Por que la tabla se ejecuta sobre las tres y no sobre una
 *
 * `ValidatesWorkDateRange` nacio de tres copias de cuarenta y cinco lineas, y una
 * de ellas justificaba en su propio docblock por que no debia copiarse —«dos
 * formas de pedir el mismo rango acabarian aceptando rangos distintos»— siendo ya
 * la segunda copia. Extraer el trait quito la duplicacion; lo que impide que
 * vuelva es esta prueba: si alguien añade una regla a una de las tres peticiones y
 * no a las otras, o vuelve a copiar el metodo, aqui se ve.
 *
 * Probar solo una de las tres seria probar el trait, no la promesa. La promesa es
 * que **las tres se comportan igual**.
 *
 * ## Por parejas endpoint x caso, no con un bucle dentro de la prueba
 *
 * Dos conjuntos de datos encadenados. Asi el informe dice cual de las dieciocho
 * combinaciones fallo, en lugar de parar en la primera y dejar sin ejecutar las
 * demas.
 *
 * ## Los limites van escritos como fechas, no calculados
 *
 * El techo son 366 dias contados de forma inclusiva. Escribir
 * `date('Y-m-d', strtotime(...))` reproduciria aqui la aritmetica que se quiere
 * comprobar alli: si el codigo contara mal, la prueba contaria mal igual y
 * pasaria.
 */

uses(RefreshDatabase::class);

/**
 * Uno de los tres endpoints que piden rango, con la sesion que le corresponde.
 *
 * Se resuelve **dentro** de la prueba y no como conjunto de datos: las sesiones
 * se emiten contra la base de datos, que `RefreshDatabase` vacia entre pruebas, y
 * un conjunto de datos se construye antes de que exista.
 *
 * @return array{0: string, 1: string} La URI y el token.
 */
function workDateRangeEndpoint(string $key): array
{
    $site = WorkforceFixtures::site('Hotel del rango');
    $employee = WorkforceFixtures::employee($site);

    return [
        'panel' => fn (): array => [
            '/api/v1/employees/'.$employee.'/workdays',
            ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        ],
        'portal' => fn (): array => ['/api/v1/me/workdays', PortalLogins::open($employee)],
        'exportacion' => fn (): array => ['/api/v1/me/export', PortalLogins::open($employee)],
    ][$key]();
}

/**
 * Las tres peticiones que comparten el trait, como conjunto de datos.
 *
 * @return array<string, string>
 */
function workDateRangeEndpointKeys(): array
{
    return [
        'registro de un empleado desde el panel' => 'panel',
        'mi registro desde el portal' => 'portal',
        'mi exportacion desde el portal' => 'exportacion',
    ];
}

/**
 * Los rangos que ninguna de las tres puede aceptar.
 *
 * El nivel extra de array no es un descuido: Pest reparte los elementos del
 * conjunto como argumentos, y sin el envolvente tomaria `from` y `to` como
 * parametros con nombre de la clausura.
 *
 * @return array<string, array{0: array<string, string>}>
 */
function invalidWorkDateRanges(): array
{
    return [
        'invertido' => [['from' => '2026-03-31', 'to' => '2026-03-01']],
        'fecha que no existe en el calendario' => [['from' => '2026-02-30', 'to' => '2026-03-01']],
        'un dia por encima del techo de 366' => [['from' => '2026-01-01', 'to' => '2027-01-02']],
        'formato con barras' => [['from' => '01/03/2026', 'to' => '31/03/2026']],
        'formato sin dia' => [['from' => '2026-03', 'to' => '2026-03-31']],
        'parametro desconocido' => [['from' => '2026-03-01', 'to' => '2026-03-31', 'desde' => '2026-03-01']],
    ];
}

it('rechaza con 422 el mismo rango imposible en los tres endpoints', function (string $endpoint, array $range): void {
    [$uri, $token] = workDateRangeEndpoint($endpoint);

    Api::as($token)->get($uri, $range)
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->with(workDateRangeEndpointKeys())->with(invalidWorkDateRanges())
    ->group('RF-PA-03', 'RF-ID-05', 'RQ-06');

it('acepta en los tres endpoints un rango de exactamente 366 dias', function (string $endpoint): void {
    // El limite es «mas de 366 se rechaza», asi que 366 se acepta. Es el valor de
    // borde que distingue un `>` de un `>=`, y el que se equivoca cuando alguien
    // ajusta el techo: del 1 de enero de 2026 al 1 de enero de 2027 hay 366 dias
    // contados de forma inclusiva.
    [$uri, $token] = workDateRangeEndpoint($endpoint);

    Api::as($token)->get($uri, ['from' => '2026-01-01', 'to' => '2027-01-01'])->assertStatus(200);
})->with(workDateRangeEndpointKeys())->group('RF-PA-03', 'RF-ID-05', 'RQ-06');

it('acepta en los tres endpoints un rango de un solo dia', function (string $endpoint): void {
    // El otro borde: `from` y `to` iguales son un dia, no cero.
    [$uri, $token] = workDateRangeEndpoint($endpoint);

    Api::as($token)->get($uri, ['from' => '2026-03-29', 'to' => '2026-03-29'])->assertStatus(200);
})->with(workDateRangeEndpointKeys())->group('RF-PA-03', 'RF-ID-05', 'RQ-06');

it('rechaza en los tres endpoints un parametro de fecha vacio, y en los tres igual', function (string $endpoint): void {
    // **Divergencia documentada entre el codigo y su propio docblock.**
    //
    // `ValidatesWorkDateRange::isoDate()` dice, con estas palabras, que una cadena
    // vacia significa lo mismo que la ausencia: *«`?from=` es un parametro que el
    // navegador dejo puesto sin valor, no una fecha»*. Pero `rules()` declara
    // `from` como `sometimes|date_format:Y-m-d`, y una cadena vacia SI esta
    // presente: la validacion la rechaza con `422` antes de que `isoDate()` llegue
    // a mirarla. La rama `$value !== ''` de ese metodo no la alcanza ninguna
    // peticion HTTP.
    //
    // Cual de los dos comportamientos se quiere es una decision de diseño y no la
    // toma esta prueba. Lo que si fija —y es lo que importa para el trait— es que
    // **los tres endpoints hacen lo mismo**: si alguien resuelve la divergencia,
    // tendra que resolverla en los tres a la vez o esta prueba lo dira.
    [$uri, $token] = workDateRangeEndpoint($endpoint);

    Api::as($token)->get($uri, ['from' => '', 'to' => ''])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->with(workDateRangeEndpointKeys())->group('RF-PA-03', 'RF-ID-05', 'RQ-06');

it('acepta en los tres endpoints una sola de las dos fechas', function (string $endpoint, array $range): void {
    // Con una sola fecha, la otra la pone el caso de uso, que es quien sabe que
    // dia es hoy en la zona del centro. El `FormRequest` no puede validar un rango
    // que todavia no esta completo.
    [$uri, $token] = workDateRangeEndpoint($endpoint);

    Api::as($token)->get($uri, $range)->assertStatus(200);
})->with(workDateRangeEndpointKeys())->with([
    'solo el inicio' => [['from' => '2026-03-01']],
    'solo el fin' => [['to' => '2026-03-31']],
])->group('RF-PA-03', 'RF-ID-05', 'RQ-06');
