<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Domain\Event\EmployeeHired;
use App\Modules\Workforce\Domain\Event\EmployeeOffboarded;
use App\Modules\Workforce\Domain\Event\EmployeeProfileUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * CRUD de plantilla (RF-GP-01, RF-GP-03), validado contra el contrato.
 *
 * Cada respuesta pasa por Spectator: el cliente TypeScript de los tres frontends
 * se genera de `openapi.yaml`, asi que una desviacion aqui rompe a los tres a la
 * vez y sin aviso.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, site: int, department: int}
 */
function hrContext(): array
{
    $site = WorkforceFixtures::site();

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'department' => WorkforceFixtures::department($site),
    ];
}

it('lista la plantilla paginada', function (): void {
    $context = hrContext();

    for ($i = 0; $i < 3; $i++) {
        WorkforceFixtures::employee($context['site'], $context['department']);
    }

    Api::as($context['token'])
        ->get('/api/v1/employees', ['per_page' => 2])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.total_pages', 2);
})->group('RF-GP-01');

it('incluye a quien esta de baja salvo que se filtre', function (): void {
    // El historico se conserva (RF-GP-03, RL-02) y un listado que escondiera a
    // los cesados haria invisible justo lo que una inspeccion viene a mirar.
    $context = hrContext();

    WorkforceFixtures::employee($context['site'], $context['department']);
    WorkforceFixtures::employee($context['site'], $context['department'], 'terminated');

    Api::as($context['token'])
        ->get('/api/v1/employees')
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 2);

    Api::as($context['token'])
        ->get('/api/v1/employees', ['status' => 'active'])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 1);
})->group('RF-GP-01', 'RF-GP-03');

it('rechaza un filtro que no existe', function (): void {
    $context = hrContext();

    Api::as($context['token'])
        ->get('/api/v1/employees', ['status' => 'vacaciones'])
        ->assertValidResponse(422);
})->group('RF-GP-01');

it('devuelve la ficha de un empleado por su UUID publico', function (): void {
    $context = hrContext();
    $uuid = WorkforceFixtures::employee($context['site'], $context['department']);

    Api::as($context['token'])
        ->get('/api/v1/employees/'.$uuid)
        ->assertValidResponse(200)
        ->assertJsonPath('uuid', $uuid);
})->group('RF-GP-01');

it('responde 404 por un empleado que no existe', function (): void {
    $context = hrContext();

    Api::as($context['token'])
        ->get('/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-000000000000')
        ->assertValidResponse(404)
        ->assertJsonPath('type', 'urn:kronoqr:problem:not-found');
})->group('RF-GP-01');

it('genera el codigo de empleado en el servidor y no lo acepta del cliente', function (): void {
    $context = hrContext();

    Api::as($context['token'])
        ->post('/api/v1/employees', [
            'site_id' => $context['site'],
            'first_name' => 'Lucia',
            'last_name' => 'Ferrer',
            'hired_at' => '2026-08-14',
            'employee_code' => 'RECEPCION-01',
        ])
        // El contrato no admite propiedades adicionales y el FormRequest rechaza
        // lo desconocido en lugar de ignorarlo: quien lo envia se enteraria de
        // que el codigo lo pone el servidor (RF-ID-06).
        ->assertValidResponse(422);
})->group('RF-GP-01', 'RF-ID-06');

it('no almacena el documento de identidad en claro', function (): void {
    // RL-08. Entra en la peticion, sale como digest y no vuelve en la respuesta.
    $context = hrContext();

    $response = Api::as($context['token'])->post('/api/v1/employees', [
        'site_id' => $context['site'],
        'first_name' => 'Lucia',
        'last_name' => 'Ferrer',
        'national_id' => '00000000T',
        'hired_at' => '2026-08-14',
    ]);

    $response->assertValidResponse(201);

    expect($response->content())->not->toContain('00000000T');

    $uuid = $response->json('employee.uuid');

    /** @var object{hash: string|null}|null $row */
    $row = DB::selectOne(
        "SELECT encode(national_id_hash, 'hex') AS hash FROM employees WHERE uuid = ?",
        [is_string($uuid) ? $uuid : ''],
    );

    expect($row?->hash)->toBe(hash('sha256', '00000000T'));
})->group('RL-08', 'RF-GP-01');

it('no admite un departamento de otro centro', function (): void {
    // Un empleado adscrito a un departamento de otro hotel deja ambiguo de que
    // centro sale su zona horaria, que es de lo que depende RN-05.
    $context = hrContext();
    $otherSite = WorkforceFixtures::site('Otro hotel', 'Atlantic/Canary');
    $otherDepartment = WorkforceFixtures::department($otherSite);

    Api::as($context['token'])
        ->post('/api/v1/employees', [
            'site_id' => $context['site'],
            'department_id' => $otherDepartment,
            'first_name' => 'Lucia',
            'last_name' => 'Ferrer',
            'hired_at' => '2026-08-14',
        ])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.department_id.0', 'El departamento no pertenece al centro indicado.');
})->group('RF-GP-01', 'RN-05');

it('modifica la ficha sin tocar lo que no se envia', function (): void {
    $context = hrContext();
    $uuid = WorkforceFixtures::employee($context['site'], $context['department']);

    Api::as($context['token'])
        ->patch('/api/v1/employees/'.$uuid, ['first_name' => 'Lucia'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('first_name', 'Lucia')
        ->assertJsonPath('last_name', 'De Prueba')
        ->assertJsonPath('status', 'active');
})->group('RF-GP-01');

it('no deja dar de baja por la puerta de atras del PATCH', function (): void {
    // La baja lleva fecha de cese y consecuencias (RN-14). Un PATCH que pudiera
    // darla acabaria produciendo bajas sin fecha.
    $context = hrContext();
    $uuid = WorkforceFixtures::employee($context['site'], $context['department']);

    Api::as($context['token'])
        ->patch('/api/v1/employees/'.$uuid, ['status' => 'terminated'])
        ->assertValidResponse(422);
})->group('RF-GP-03');

it('da de baja sin borrar nada', function (): void {
    // Regla dura 5 y RF-GP-03: la fila sigue ahi con todo su historial.
    $context = hrContext();
    $uuid = WorkforceFixtures::employee($context['site'], $context['department']);

    Api::as($context['token'])
        ->post('/api/v1/employees/'.$uuid.'/offboard', [
            'terminated_at' => '2026-09-30',
            'reason' => 'Fin de contrato de temporada',
        ])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'terminated')
        ->assertJsonPath('terminated_at', '2026-09-30');

    expect(DB::table('employees')->where('uuid', $uuid)->exists())->toBeTrue();
})->group('RF-GP-03');

it('no repite una baja ya registrada', function (): void {
    $context = hrContext();
    $uuid = WorkforceFixtures::employee($context['site'], $context['department'], 'terminated');

    Api::as($context['token'])
        ->post('/api/v1/employees/'.$uuid.'/offboard', ['terminated_at' => '2026-09-30'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');
})->group('RF-GP-03');

it('publica los eventos de plantilla que otros modulos necesitan', function (): void {
    // Son el enganche de la revocacion de credencial (tarea 1.5) y del asiento
    // de audit_log (tarea 1.14): sin ellos, esas tareas tendrian que volver a
    // abrir estos casos de uso.
    Event::fake([EmployeeHired::class, EmployeeProfileUpdated::class, EmployeeOffboarded::class]);

    $context = hrContext();

    $created = Api::as($context['token'])->post('/api/v1/employees', [
        'site_id' => $context['site'],
        'first_name' => 'Lucia',
        'last_name' => 'Ferrer',
        'hired_at' => '2026-08-14',
    ])->json('employee.uuid');

    $uuid = is_string($created) ? $created : '';

    Api::as($context['token'])->patch('/api/v1/employees/'.$uuid, ['locale' => 'en']);
    Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/offboard', [
        'terminated_at' => '2026-09-30',
    ]);

    Event::assertDispatched(EmployeeHired::class);
    Event::assertDispatched(EmployeeProfileUpdated::class);
    Event::assertDispatched(
        EmployeeOffboarded::class,
        static fn (EmployeeOffboarded $event): bool => $event->employeeUuid === $uuid
            && $event->terminatedOn === '2026-09-30',
    );
})->group('RF-GP-01', 'RF-GP-03');

/*
 * Busqueda libre del listado de plantilla (RF-GP-01, `q`).
 *
 * El panel ofrece un unico cuadro para «busca a esta persona», y quien lo usa no
 * sabe —ni tiene por que saber— si esta escribiendo un nombre, un apellido o un
 * codigo de tarjeta. Por eso `q` casa contra los cuatro campos y basta con que
 * acierte uno.
 */

/**
 * Tres personas con nombres distintos, que es lo que la busqueda necesita para
 * significar algo: con «Persona De Prueba» tres veces, cualquier consulta
 * «encuentra» a la persona correcta por accidente.
 *
 * @return array{token: string, site: int, department: int, otroSite: int}
 */
function searchContext(): array
{
    $context = hrContext();
    $otroSite = WorkforceFixtures::site('Hotel Marina');

    WorkforceFixtures::employee($context['site'], $context['department'], 'active', 'Youssef', 'Amrani', 'EK7Q2MXPR');
    WorkforceFixtures::employee($context['site'], $context['department'], 'active', 'Lucia', 'Bermejo', 'EZ3T9WLDN');
    WorkforceFixtures::employee($otroSite, null, 'active', 'Youssef', 'Cabrera', 'EM5H1VBQK');

    return [...$context, 'otroSite' => $otroSite];
}

it('encuentra por nombre, apellido, nombre completo o codigo', function (string $q, int $total): void {
    $context = searchContext();

    Api::as($context['token'])
        ->get('/api/v1/employees', ['q' => $q])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', $total)
        ->assertJsonCount($total, 'data');
})->with([
    // Dos personas se llaman Youssef: el nombre solo no desempata, y el listado
    // tiene que devolver las dos en vez de elegir una.
    'por nombre' => ['Youssef', 2],
    'por apellido' => ['Amrani', 1],
    // El caso que obliga a concatenar en SQL: «Youssef Amrani» no esta ni en
    // `first_name` ni en `last_name`.
    'por nombre completo' => ['Youssef Amrani', 1],
    'por codigo' => ['EZ3T9WLDN', 1],
    // Por subcadena, no por prefijo: quien recuerda media palabra la escribe.
    'por un trozo del apellido' => ['erme', 1],
    'por un trozo del codigo' => ['9WLD', 1],
    // Insensible a mayusculas en los dos sentidos. `employee_code` es `citext`
    // y los nombres son `text`: sin `ILIKE`, los segundos no casarian.
    'en minusculas' => ['amrani', 1],
    'en mayusculas' => ['AMRANI', 1],
    'codigo en minusculas' => ['ez3t9wldn', 1],
    'sin ninguna coincidencia' => ['Nadie', 0],
])->group('RF-GP-01');

it('no deja que los comodines de LIKE actuen como tales', function (string $q): void {
    // Sin escapar, `%` casaria con la plantilla entera y `_` con cualquier
    // caracter: el usuario estaria manejando una sintaxis que nadie le ha
    // contado, y una busqueda que no encuentra nada hace mucho menos daño que
    // una que devuelve el directorio completo creyendo haber filtrado.
    $context = searchContext();

    Api::as($context['token'])
        ->get('/api/v1/employees', ['q' => $q])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 0);
})->with([
    'comodin de varios caracteres' => ['%'],
    'comodin combinado' => ['%rani'],
    'comodin de un caracter' => ['Amran_'],
    // La barra invertida es el caracter de escape de `LIKE` en PostgreSQL: si
    // no se escapara ella misma, un `q` de `\` dejaria un escape colgando.
    'barra invertida' => ['\\'],
])->group('RF-GP-01');

it('trata una busqueda vacia como si no se hubiera enviado', function (string $q): void {
    // El panel omite `q` cuando el cuadro esta vacio, asi que esto no es lo que
    // hace el cliente: es lo que ocurre con un enlace copiado que arrastra un
    // `?q=` de una busqueda anterior. Devolver `422` por eso seria un callejon
    // sin salida para quien pega la URL. Por eso el contrato declara `q` sin
    // `minLength` y `assertValidRequest()` puede exigirse aqui.
    $context = searchContext();

    Api::as($context['token'])
        ->get('/api/v1/employees', ['q' => $q])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 3);
})->with([
    'cadena vacia' => [''],
    'solo espacios' => ['   '],
])->group('RF-GP-01');

it('recorta el termino antes de buscar', function (): void {
    $context = searchContext();

    Api::as($context['token'])
        ->get('/api/v1/employees', ['q' => '  Amrani  '])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 1);
})->group('RF-GP-01');

it('combina la busqueda con el resto de filtros con AND', function (): void {
    // Los dos «Youssef» estan en centros distintos. Si la busqueda se aplicara
    // al mismo nivel que `site_id` en vez de en su propio grupo, el `OR` se
    // comeria el filtro de centro y saldrian los dos.
    $context = searchContext();

    Api::as($context['token'])
        ->get('/api/v1/employees', ['q' => 'Youssef', 'site_id' => $context['site']])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.last_name', 'Amrani');
})->group('RF-GP-01');

it('pagina la busqueda como cualquier otro filtro', function (): void {
    // `meta.total` describe lo que casa con la busqueda, no la plantilla: si
    // contara la plantilla, el panel pediria paginas que no existen.
    $context = searchContext();

    Api::as($context['token'])
        ->get('/api/v1/employees', ['q' => 'Youssef', 'per_page' => 1])
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.total_pages', 2);
})->group('RF-GP-01');

it('rechaza una busqueda mas larga que el techo del contrato', function (): void {
    // 100 caracteres es el `maxLength` que declara `EmployeeSearch`. El techo no
    // es una regla de negocio: es lo que impide mandar un patron de megabytes a
    // un `ILIKE` que no puede usar indice.
    $context = searchContext();

    Api::as($context['token'])
        ->get('/api/v1/employees', ['q' => str_repeat('a', 101)])
        ->assertStatus(422);

    Api::as($context['token'])
        ->get('/api/v1/employees', ['q' => str_repeat('a', 100)])
        ->assertValidResponse(200);
})->group('RF-GP-01');

/**
 * Acentos (RF-GP-01).
 *
 * Su propio contexto porque las cuatro personas de aqui existen solo para esto:
 * meterlas en `searchContext()` cambiaria los recuentos de todo lo de arriba sin
 * que ninguna de esas pruebas trate sobre diacriticos.
 *
 * Hay un «García» y un «Garcia» a la vez, escritos como se escriben de verdad en
 * un alta hecha con prisa. Los dos son la misma consulta para quien busca, asi
 * que toda variante del termino tiene que devolver los dos.
 *
 * @return array{token: string, site: int, department: int}
 */
function accentedSearchContext(): array
{
    $context = hrContext();

    WorkforceFixtures::employee($context['site'], $context['department'], 'active', 'Marta', 'García', 'EG4R1CIAB');
    WorkforceFixtures::employee($context['site'], $context['department'], 'active', 'Sara', 'Garcia', 'EG4R1CIAC');
    WorkforceFixtures::employee($context['site'], $context['department'], 'active', 'Ivan', 'Núñez', 'ENU3N1EZD');
    WorkforceFixtures::employee($context['site'], $context['department'], 'active', 'Lucia', 'Bermejo', 'EZ3T9WLDN');

    return $context;
}

it('ignora los acentos en los dos sentidos', function (string $q, int $total): void {
    // `ILIKE` ignora las mayusculas pero NO los diacriticos: sin `unaccent()`,
    // «garcia» no encuentra a «García» y «garcía» no encuentra a «Garcia». El
    // panel de credenciales ya normalizaba acentos en el navegador y los dos
    // cuadros llevan la misma etiqueta: el que no lo hiciera pareceria averiado.
    $context = accentedSearchContext();

    Api::as($context['token'])
        ->get('/api/v1/employees', ['q' => $q])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', $total)
        ->assertJsonCount($total, 'data');
})->with([
    // Termino con tilde: tiene que encontrar tambien a quien esta dado de alta
    // sin ella, que es el caso «a la inversa».
    'con tilde encuentra a los dos' => ['García', 2],
    'sin tilde encuentra a los dos' => ['Garcia', 2],
    'en minusculas y con tilde' => ['garcía', 2],
    'en minusculas y sin tilde' => ['garcia', 2],
    'en mayusculas y sin tilde' => ['GARCIA', 2],
    // La eñe ademas de la tilde: `unaccent` la trata igual que cualquier otro
    // diacritico, y en un apellido español las dos aparecen juntas a menudo.
    'eñe y tilde' => ['Núñez', 1],
    'eñe y tilde escritas en llano' => ['nunez', 1],
    'eñe y tilde en mayusculas' => ['NUNEZ', 1],
    // Por subcadena, tambien normalizando: quien recuerda media palabra la
    // escribe sin pararse a poner la tilde.
    'trozo sin tilde' => ['unez', 1],
    // El control negativo: normalizar acentos no puede convertir la busqueda en
    // un comodin que case con quien no toca.
    'sin coincidencia' => ['Nadie', 0],
])->group('RF-GP-01');
