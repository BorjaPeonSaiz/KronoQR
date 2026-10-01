<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * **LA MARCA DE TELETRABAJO ES INFORMATIVA: NINGUN CALCULO LA LEE** (RF-GP-01,
 * decision comercial de la 2.1.0, Bloque 9 del plan de la 2.2.0).
 *
 * El propietario lo decidio asi: un si o no por persona que RRHH ve en el
 * listado, y que **no cambia como se ficha ni se calcula nada**. La prueba de
 * comportamiento (`Feature/Workforce/TeleworkingIsInformativeTest`) lo
 * comprueba con dos personas identicas salvo la marca; esta lo comprueba sobre
 * el TEXTO del codigo, que es donde empieza el cambio que lo romperia: el dia
 * que alguien escriba `teleworking` en `Attendance` o en `Reporting`, la marca
 * habra dejado de ser informativa, y eso exige una decision del propietario y
 * un requisito nuevo, no un `if`.
 *
 * La lista es cerrada: cada fichero que nombra la marca esta aqui con su motivo.
 */

/**
 * Los ficheros de `app/Modules/` que pueden nombrar la marca, y por que.
 *
 * @var array<string, string>
 */
const TELEWORKING_INFORMATIVE_ALLOWED = [
    'Workforce/Domain/Model/Employee.php' => 'el dato de la ficha',
    'Workforce/Domain/Event/EmployeeHired.php' => 'el valor inicial, para el asiento del alta',
    'Workforce/Application/Command/RegisterEmployeeCommand.php' => 'el alta',
    'Workforce/Application/Command/UpdateEmployeeCommand.php' => 'la modificacion',
    'Workforce/Application/UseCase/RegisterEmployeeHandler.php' => 'el alta',
    'Workforce/Application/UseCase/UpdateEmployeeHandler.php' => 'la modificacion y su lista de campos tocados',
    'Workforce/Application/Port/EmployeeRepository.php' => 'el filtro del listado',
    'Workforce/Application/Query/EmployeeQueries.php' => 'el filtro del listado y su asiento de divulgacion',
    'Workforce/Infrastructure/Persistence/Employee.php' => 'la columna',
    'Workforce/Infrastructure/Persistence/EloquentEmployeeRepository.php' => 'la columna y el filtro',
    'Workforce/Http/Controller/EmployeeController.php' => 'el filtro del listado',
    'Workforce/Http/Request/IndexEmployeeRequest.php' => 'el filtro del listado',
    'Workforce/Http/Request/StoreEmployeeRequest.php' => 'el alta',
    'Workforce/Http/Request/UpdateEmployeeRequest.php' => 'la modificacion',
    'Workforce/Http/Resource/EmployeeResource.php' => 'la ficha en la API',
    'Compliance/Infrastructure/Listener/RecordWorkforceChange.php' => 'el asiento employee.hired',
    'Product/Domain/ValueObject/DataExportCatalog.php' => 'la exportacion integra (RF-PD-14, RL-20)',
    'Product/Infrastructure/Persistence/DatabaseDataExportSource.php' => 'la exportacion integra (RF-PD-14, RL-20)',
];

/**
 * @return list<string> Rutas relativas a `app/Modules/` que nombran la marca.
 */
function teleworkingInformativeMentions(): array
{
    // Una sola lectura del arbol por proceso: las tres pruebas preguntan lo
    // mismo y recorrerlo por el bind mount cuesta segundos.
    /** @var list<string>|null $cached */
    static $cached = null;

    if (\is_array($cached)) {
        return $cached;
    }

    $mentions = [];

    foreach (ModuleTree::phpFilesUnder(ModuleTree::root()) as $file) {
        $source = file_get_contents($file);

        if (\is_string($source) && preg_match('/teleworking/i', $source) === 1) {
            $mentions[] = ModuleTree::relative($file, ModuleTree::root());
        }
    }

    sort($mentions);

    return $cached = $mentions;
}

it('solo nombran la marca los ficheros de la ficha, su auditoria y la exportacion integra', function (): void {
    $unexpected = array_values(array_diff(teleworkingInformativeMentions(), array_keys(TELEWORKING_INFORMATIVE_ALLOWED)));

    expect($unexpected)->toBe([], "La marca de teletrabajo es informativa y estos ficheros la leen:\n- "
        .implode("\n- ", $unexpected)
        ."\nSi un calculo, el fichaje o un informe tiene que depender de ella, es una decision del propietario "
        .'y un requisito nuevo en el doc 01, no un cambio de codigo.');
})->group('RF-GP-01');

it('ningun fichero del fichaje, los informes ni las reglas de cumplimiento la nombra', function (): void {
    // Redundante con la lista cerrada, a proposito: es la afirmacion que el
    // propietario hizo, escrita tal cual, y el mensaje que hay que leer si falla.
    $forbidden = array_values(array_filter(
        teleworkingInformativeMentions(),
        static fn (string $relative): bool => str_starts_with($relative, 'Attendance/')
            || str_starts_with($relative, 'Reporting/')
            || str_starts_with($relative, 'Kiosk/')
            || str_starts_with($relative, 'Compliance/Domain/')
            || str_starts_with($relative, 'Compliance/Application/'),
    ));

    expect($forbidden)->toBe([]);
})->group('RF-GP-01');

it('ha recorrido el arbol y encuentra la marca donde tiene que estar', function (): void {
    // Sin esto, las dos de arriba pasan en verde recorriendo un arbol vacio.
    $mentions = teleworkingInformativeMentions();

    expect($mentions)->toContain('Workforce/Domain/Model/Employee.php')
        ->and($mentions)->toContain('Workforce/Application/UseCase/UpdateEmployeeHandler.php')
        ->and(Repo::contents('backend/database/migrations/2026_10_01_100000_add_teleworking_to_employees_table.php'))
        ->toContain('BOOLEAN NOT NULL DEFAULT false');
})->group('RF-GP-01');
