<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\Feature;
use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * **NINGUNA LECTURA DE LICENCIA FUERA DEL PUNTO UNICO DE DECISION**
 * (ADR-018, ADR-023 y ADR-028 lo exigen los tres con las mismas palabras).
 *
 * ## Que problema resuelve
 *
 * ADR-023 lo dice sin rodeos: el riesgo no es no tener la frontera, es que cada
 * quien la decida en el sitio con un `if (license.expired)` repartido por el
 * codigo. Eso hace imposible responder a *«¿que deja de funcionar
 * exactamente?»* —una pregunta que un cliente hace antes de firmar— y convierte
 * cada funcionalidad nueva en una decision implicita sobre si el registro legal
 * es rehen del negocio.
 *
 * Con un `if` disperso, ademas, el fallo no se ve en una revision: se ve en casa
 * de un cliente el dia que su licencia caduca.
 *
 * ## Que se comprueba, exactamente
 *
 * 1. **La tabla `license` solo la nombra `Product`**, y dentro de `Product` solo
 *    su capa de persistencia.
 * 2. **El estado de licencia no sale de `Product`.** Fuera de ese modulo nadie
 *    importa `LicenseStatus`, `LicenseState` ni `License`: quien necesita
 *    decidir usa el puerto `FeatureGate`, que devuelve disponibilidad y no
 *    estado comercial.
 * 3. **No hay condicionales propios sobre la caducidad** en ningun modulo que no
 *    sea `Product`.
 * 4. **El catalogo `Feature` coincide con la tabla «Degradable» de ADR-023.** La
 *    lista es contractual antes que tecnica, asi que la manda el documento: si
 *    alguien añade un caso que el ADR no lista, o retira uno que si, falla aqui.
 * 5. **Las funcionalidades retrofitadas de 2.4 y 2.8 pasan por el puerto**, que
 *    es lo que el paso 10 de la tarea exige comprobar.
 *
 * Se lee el codigo como TEXTO y no por reflexion, como el resto de las pruebas
 * de arquitectura: hay que poder hablar de ficheros que ni siquiera se cargan.
 */

/**
 * Ficheros de modulos, salvo los del modulo indicado.
 *
 * @return list<string>
 */
function moduleFilesOutside(string $module): array
{
    return array_values(array_filter(
        ModuleTree::filesIn(''),
        static fn (string $file): bool => ! str_starts_with(
            ModuleTree::relative($file),
            $module.'/',
        ),
    ));
}

it('solo la persistencia de Product nombra la tabla license', function (): void {
    $offenders = [];

    foreach (ModuleTree::filesIn('') as $file) {
        $source = (string) file_get_contents($file);
        $relative = ModuleTree::relative($file);

        // La forma en la que se nombra una tabla en este repositorio: `table(...)`
        // del constructor de consultas o un `FROM` de SQL escrito a mano.
        $namesTable = preg_match("/table\(\s*'license'\s*\)/", $source) === 1
            || preg_match('/\bFROM\s+license\b/i', $source) === 1;

        if ($namesTable && ! str_starts_with($relative, 'Product/Infrastructure/Persistence/')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe(
        [],
        'La tabla `license` solo se lee desde `Product/Infrastructure/Persistence/`. '
        .'Quien necesite decidir algo usa el puerto FeatureGate (ADR-023). Infractores: '
        .implode(', ', $offenders)
    );
})->group('RF-PD-05');

it('el estado de la licencia no sale del modulo Product', function (): void {
    // Fuera de `Product` nadie puede razonar sobre «esta caducada»: recibe una
    // `FeatureAvailability`, que dice si puede pintar algo y por que no.
    $forbidden = [
        'App\Modules\Product\Domain\ValueObject\LicenseStatus',
        'App\Modules\Product\Domain\ValueObject\LicenseState',
        'App\Modules\Product\Domain\ValueObject\License',
        'App\Modules\Product\Domain\ValueObject\LicenseOverview',
        'App\Modules\Product\Application\UseCase\GetLicenseStatusHandler',
        'App\Modules\Product\Application\UseCase\DescribeLicenseHandler',
    ];

    $offenders = [];

    foreach (moduleFilesOutside('Product') as $file) {
        foreach (ModuleTree::importsOf($file) as $import) {
            if (\in_array($import, $forbidden, true)) {
                $offenders[] = ModuleTree::relative($file).' → '.$import;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Fuera de `Product` nadie conoce el estado de la licencia: se pide `FeatureGate` y se recibe '
        .'una `FeatureAvailability` (ADR-025, ADR-023). Infractores: '.implode(', ', $offenders)
    );
})->group('RF-PD-05');

it('ningun modulo escribe su propia condicion sobre la caducidad', function (): void {
    // El `if (license.expired)` que ADR-023 nombra por escrito. Se busca por
    // texto y no por tipo porque el peligro es justamente el atajo: alguien que
    // consulta una columna y compara fechas a mano.
    $patterns = [
        '/license.*->\s*(isExpired|expired)\b/i',
        "/'valid_until'/",
        '/license_expired\s*===/i',
    ];

    /*
     * Los dos listeners de auditoria de `Compliance` COPIAN los campos del
     * evento al asiento —incluido `valid_until`— y no deciden nada con ellos.
     * Es su trabajo: `Product` no puede importar `Compliance` (§1.6), asi que la
     * unica via para sellar la activacion es que un listener lea el evento.
     *
     * Se exceptuan por nombre y no por carpeta a proposito: si mañana apareciera
     * un tercer fichero de `Compliance` que mirase la caducidad para DECIDIR
     * algo, esta prueba lo diria.
     */
    $allowed = [
        'Compliance/Infrastructure/Listener/RecordLicenseActivation.php',
    ];

    $offenders = [];

    foreach (moduleFilesOutside('Product') as $file) {
        $relative = ModuleTree::relative($file);

        if (\in_array($relative, $allowed, true)) {
            continue;
        }

        $source = (string) file_get_contents($file);

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $offenders[] = $relative;

                break;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'La caducidad se decide en un solo sitio (ADR-023). Infractores: '.implode(', ', $offenders)
    );
})->group('RF-PD-05');

it('el catalogo de funcionalidades es exactamente la tabla «Degradable» de ADR-023', function (): void {
    // La lista es CONTRACTUAL antes que tecnica: es lo que se le puede decir a
    // un cliente que perdera. Por eso la manda el documento y no el codigo, y
    // por eso esta prueba lee el ADR.
    $adr = Repo::contents('docs/adr/ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md');

    // Las filas de la tabla «Degradable — funcionalidad accesoria», que es la
    // segunda del ADR. Se recorta entre sus dos encabezados para no confundirla
    // con la primera, la del registro legal.
    $start = strpos($adr, '### Degradable');
    $end = strpos($adr, '### Cómo se implementa');

    expect($start)->not->toBeFalse('ADR-023 ha perdido el encabezado «### Degradable».')
        ->and($end)->not->toBeFalse('ADR-023 ha perdido el encabezado «### Cómo se implementa».');

    $degradable = substr($adr, (int) $start, (int) $end - (int) $start);

    $expectations = [
        ['advanced_reports', 'Informes avanzados y comparación entre periodos'],
        ['impact_dashboard', 'Cuadro de impacto y adopción'],
        ['payroll_export', 'Exportación configurable para nómina'],
        ['weekly_email_summary', 'Resumen semanal por correo'],
        ['realtime_presence', 'Presencia en tiempo real'],
        ['white_label', 'Marca blanca'],
        ['telemetry', 'Telemetría opcional'],
    ];

    // Cada caso del enum tiene su fila en el ADR...
    foreach ($expectations as [$case, $row]) {
        expect(Feature::tryFrom($case))->not->toBeNull(
            'ADR-023 lista «'.$row.'» como degradable y el enum `Feature` no tiene el caso `'.$case.'`.'
        );

        expect(str_contains($degradable, $row))->toBeTrue(
            'El caso `'.$case.'` no aparece en la tabla «Degradable» de ADR-023. '
            .'La lista es contractual: ampliarla o restringirla exige un ADR nuevo, no un caso nuevo.'
        );
    }

    // ...y no hay ningun caso de mas ni de menos.
    expect(Feature::cases())->toHaveCount(\count($expectations));
})->group('RF-PD-05');

it('las funcionalidades retrofitadas de 2.4 y 2.8 pasan por el puerto', function (string $file, string $feature): void {
    // El paso 10 de la tarea 5.3, comprobado. Si alguien retirara la
    // comprobacion —o la sustituyera por una condicion propia—, esta prueba lo
    // dice antes que el cliente.
    $source = (string) file_get_contents(ModuleTree::root().'/'.$file);

    expect($source)->toContain('FeatureGate')
        ->and($source)->toContain($feature);
})->with([
    'informes avanzados (2.8)' => [
        'Reporting/Http/Controller/PeriodReportController.php',
        'Feature::AdvancedReports',
    ],
    'exportacion del informe (2.9)' => [
        'Reporting/Http/Controller/PeriodReportExportController.php',
        'Feature::AdvancedReports',
    ],
    'presencia en tiempo real (2.4)' => [
        'Reporting/Http/Controller/LivePresenceController.php',
        'Feature::RealtimePresence',
    ],
])->group('RF-PD-05');

it('el camino de fichaje no menciona la licencia por ninguna via', function (string $file): void {
    // Regla dura 19 y ADR-019: «la verificacion de licencia no esta en el camino
    // del fichaje». Se comprueba sobre los ficheros que ese camino recorre.
    $source = (string) file_get_contents(ModuleTree::root().'/'.$file);

    foreach (['FeatureGate', 'Feature::', 'License'] as $forbidden) {
        expect($source)->not->toContain(
            $forbidden,
            $file.' menciona `'.$forbidden.'`. El camino de fichaje no puede enterarse de la licencia '
            .'(regla dura 15 y 19, ADR-019).'
        );
    }
})->with([
    'Attendance/Http/Controller/ScanController.php',
    'Attendance/Http/Controller/ScanBatchController.php',
    'Attendance/Http/Controller/PinScanController.php',
    'Attendance/Application/UseCase/RegisterScanHandler.php',
    'Attendance/Application/UseCase/RegisterScanBatchHandler.php',
    'Attendance/Application/UseCase/RegisterPinScanHandler.php',
    'Kiosk/Http/Controller/RosterController.php',
    'Kiosk/Http/Controller/HeartbeatController.php',
])->group('RF-PD-05');

it('implemented() es exactamente el conjunto de funcionalidades con consumidor', function (): void {
    /*
     * **LA CONTRADICCION QUE ESTA PRUEBA IMPIDE** (RF-PD-05, ADR-019, ADR-023).
     *
     * `Feature::implemented()` separa «esto se apagara» de «esto se apagara
     * cuando exista», y de ella cuelgan cuatro consumidores: `LicenseShowCommand`,
     * `LicenseResource`, `LicenseCollector` y la pantalla de licencia del panel.
     *
     * Mientras la lista se mantuvo a mano, tres casos —`payroll_export`,
     * `impact_dashboard` y `weekly_email_summary`— estrenaron consumidor en la
     * Fase 3 sin entrar en ella, y el resultado fue el peor posible: con la
     * licencia caducada el endpoint respondia `402` y la pantalla, a la vez,
     * anunciaba que esa funcionalidad todavia no existe y que **no se pierde
     * nada**. Justo la promesa incumplida que la lista existia para evitar.
     *
     * Asi que la lista deja de mantenerse a mano y pasa a comprobarse, **en las
     * dos direcciones**:
     *
     *   - un caso **con** consumidor fuera de `implemented()` le oculta al
     *     cliente una perdida real (el fallo de la Fase 3);
     *   - un caso **sin** consumidor dentro de `implemented()` le anuncia la
     *     perdida de algo que todavia no ha visto nunca (el fallo simetrico, que
     *     aparece en cuanto alguien declare un caso nuevo —ADR-023 los declara
     *     antes de que exista quien los use— y no ajuste la lista).
     *
     * «Tener consumidor» es una propiedad del codigo y no una nota en un
     * docblock: alguien pregunta por el caso a traves del puerto `FeatureGate`.
     * Se buscan las tres formas en las que se pregunta —`isEnabled()`,
     * `statusOf()` y `allows()`— y no una mencion cualquiera de `Feature::`,
     * porque el catalogo de etiquetas de `license:show` nombra los siete y eso no
     * es consumirlos.
     */
    $sources = array_map(
        static fn (string $file): string => (string) file_get_contents($file),
        ModuleTree::filesIn(''),
    );

    $consumed = [];

    foreach (Feature::cases() as $feature) {
        $pattern = '/(?:isEnabled|statusOf|allows)\(\s*Feature::'.preg_quote($feature->name, '/').'\b/';

        foreach ($sources as $source) {
            if (preg_match($pattern, $source) === 1) {
                $consumed[] = $feature->value;

                break;
            }
        }
    }

    $declared = array_map(static fn (Feature $feature): string => $feature->value, Feature::implemented());

    sort($consumed);
    sort($declared);

    expect($declared)->toBe(
        $consumed,
        '`Feature::implemented()` ya no describe lo que el codigo consulta por `FeatureGate`. '
        .'Sobran: ['.implode(', ', array_diff($declared, $consumed)).']; '
        .'faltan: ['.implode(', ', array_diff($consumed, $declared)).']. '
        .'Lo primero le anuncia al cliente la perdida de algo que no existe; lo segundo le oculta '
        .'una perdida real (ADR-019, ADR-023).'
    );
})->group('RF-PD-05');
