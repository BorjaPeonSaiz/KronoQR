<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DataExportCatalog;
use App\Modules\Product\Domain\ValueObject\DataExportFormat;
use App\Modules\Product\Domain\ValueObject\ExportedDataset;

/*
 * **Que se lleva el cliente, y que no puede llevarse nunca** (RF-PD-14, RL-20,
 * decision 2 de la ficha 5.10).
 *
 * ## Por que esta prueba es la que decide si la tarea esta bien hecha
 *
 * La exportacion integra es la unica salida del producto que contiene **todo**:
 * la plantilla, cuatro años de fichajes, la auditoria y las cuentas de gestion.
 * Dos cosas pueden ir mal, y las dos en silencio:
 *
 *   1. **Que salga un secreto.** Un `pin_hash` o un `token_hash` en un ZIP que
 *      viaja por correo es una credencial repartida. La lista de permitidos lo
 *      impide por construccion; esto convierte el intento en un fallo con nombre.
 *   2. **Que falte algo que RL-04 obliga a conservar.** Una exportacion que
 *      enseñara solo la ultima version de cada tramo seria un registro horario
 *      reescrito, y la skill `/informe-nuevo` lo dice con todas las letras: un
 *      informe que oculte las correcciones no cumple.
 *
 * Es unitaria y sin base de datos a proposito: el catalogo es una declaracion,
 * y lo que se afirma aqui se tiene que poder leer en dos segundos mientras
 * alguien añade una columna.
 */

it('no deja salir ningun secreto ni ningun hash de credencial', function (): void {
    $prohibidas = DataExportCatalog::forbiddenColumns();

    foreach (DataExportCatalog::datasets() as $dataset) {
        foreach ($dataset->columns() as $column) {
            expect(in_array($column, $prohibidas, true))->toBeFalse(
                'El conjunto «'.$dataset->name.'» declara la columna prohibida «'.$column.'». '
                .'Ninguna exportacion puede llevar secretos ni hashes de credencial (regla dura 21).',
            );
        }
    }

    // Y el control de la propia lista: si alguien la vaciara, el bucle de arriba
    // pasaria sin comprobar nada.
    expect($prohibidas)->toContain('pin_hash')
        ->and($prohibidas)->toContain('secret_hash')
        ->and($prohibidas)->toContain('token_hash')
        ->and($prohibidas)->toContain('signed_key')
        ->and($prohibidas)->toContain('password')
        ->and($prohibidas)->toContain('two_factor_secret');
})->group('RF-PD-14', 'RL-20', 'RS-08');

it('no saca ningun identificador interno salvo en la auditoria', function (): void {
    // Doc 01 §5.5: las referencias entre ficheros van por `uuid`. La UNICA
    // excepcion es `audit_log`, porque su hash se calcula sobre `actor_id` y
    // `subject_id`: sustituirlos entregaria un fichero imposible de verificar,
    // que es lo contrario de lo que pide RL-04.
    /*
     * Tres columnas acaban en `_id` y **no son claves internas**, asi que se
     * declaran aparte en lugar de aflojar el patron:
     *
     *   - `credentials.key_id` — el identificador de la clave de FIRMA (`a3`),
     *     que viaja en el propio payload del QR y sirve para saber a que tarjetas
     *     afecto una rotacion.
     *   - `scan_events.scan_id` — el UUID v7 que genera la tablet, que es lo que
     *     hace idempotente un fichaje reenviado desde la cola.
     *   - `license.license_id` — el identificador comercial de la licencia, el
     *     que aparece en el contrato del cliente.
     *   - `error_events.trace_id` — la traza W3C de la peticion, 32
     *     hexadecimales, con la que se correlaciona con el log tecnico. No
     *     referencia ninguna fila de este producto.
     *   - `error_events.device_id` — el UUID **publico** del quiosco, el mismo
     *     que sale en `devices.csv`. Se llama asi porque asi se llama la columna
     *     del doc 01 §5, y cambiarle el nombre en la exportacion romperia la
     *     correspondencia con la tabla que el cliente ve en el panel.
     */
    $noSonClavesInternas = [
        'credentials.key_id',
        'scan_events.scan_id',
        'license.license_id',
        'error_events.trace_id',
        'error_events.device_id',
    ];

    $conIdentificadorInterno = [];

    foreach (DataExportCatalog::datasets() as $dataset) {
        foreach ($dataset->columns() as $column) {
            $referencia = $dataset->name.'.'.$column;

            if (in_array($referencia, $noSonClavesInternas, true)) {
                continue;
            }

            if ($column === 'id' || str_ends_with($column, '_id')) {
                $conIdentificadorInterno[] = $referencia;
            }
        }
    }

    expect($conIdentificadorInterno)->toBe(
        ['audit_log.id', 'audit_log.actor_id', 'audit_log.subject_id'],
        'Un conjunto saca un identificador interno. Las referencias van por `uuid` (doc 01 §5.5); '
        .'la excepcion de `audit_log` existe solo para que la cadena de hash se pueda verificar fuera.',
    );

    // Y la mitad que hace legible esa excepcion: el actor tambien va resuelto.
    expect(DataExportCatalog::dataset('audit_log')?->columns())->toContain('actor_uuid');
})->group('RF-PD-14', 'RL-04');

it('saca todas las versiones de un tramo, no solo la vigente', function (): void {
    // Regla dura 5 y RL-04. La consulta no filtra por estado —eso se comprueba
    // con volumen— y aqui se fija que las columnas que permiten reconstruir la
    // historia esten declaradas: sin `version` y sin `superseded_by_uuid`, un
    // fichero con las cuatro versiones de un tramo seria indistinguible de
    // cuatro tramos distintos.
    $tramos = DataExportCatalog::dataset('shift_entries');

    expect($tramos)->not->toBeNull()
        ->and($tramos?->columns())->toContain('version')
        ->and($tramos?->columns())->toContain('superseded_by_uuid')
        ->and($tramos?->columns())->toContain('status');
})->group('RF-PD-14', 'RL-04');

it('saca las correcciones con su autor, su motivo y lo que habia antes', function (): void {
    // La otra mitad de RL-04: «nada se sobrescribe» solo se puede comprobar si
    // en el fichero constan el antes, el despues, quien lo hizo y por que.
    $correcciones = DataExportCatalog::dataset('shift_corrections');

    expect($correcciones)->not->toBeNull();

    $columnas = $correcciones?->columns() ?? [];

    foreach (['performed_by_user_uuid', 'performed_by_name', 'reason_code', 'reason_text', 'before', 'after'] as $column) {
        // `toContain($x, $mensaje)` buscaria el mensaje como un elemento mas del
        // array (trampa conocida de Pest, `HANDOFF.md`): para poder explicar el
        // fallo hay que afirmar el booleano.
        expect(in_array($column, $columnas, true))->toBeTrue(
            'Sin «'.$column.'» la exportacion oculta parte de una correccion (RL-04).',
        );
    }
})->group('RF-PD-14', 'RL-04', 'RN-13');

it('saca la auditoria entera con su cadena de hash', function (): void {
    // RL-04: el cliente tiene que poder verificar la cadena fuera del producto.
    $auditoria = DataExportCatalog::dataset('audit_log');

    expect($auditoria?->columns())->toContain('prev_hash')
        ->and($auditoria?->columns())->toContain('hash')
        ->and($auditoria?->columns())->toContain('payload')
        ->and($auditoria?->columns())->toContain('occurred_at');

    // Y los sellos anuales, que es lo que permite comprobar un año cerrado sin
    // recorrerlo entero.
    expect(DataExportCatalog::dataset('audit_chain_anchors')?->columns())
        ->toBe(['partition_year', 'first_hash', 'last_hash', 'row_count', 'sealed_at', 'sealed_by']);
})->group('RF-PD-14', 'RL-04');

it('declara los diecinueve conjuntos de la ficha, sin efimeros ni fontaneria', function (): void {
    // El catalogo completo, valor a valor: añadir o quitar un conjunto tiene que
    // ser un cambio visible que alguien revise, no un efecto colateral.
    expect(DataExportCatalog::names())->toBe([
        'site',
        'departments',
        'employees',
        'employment_contracts',
        'credentials',
        'devices',
        'shift_entries',
        'shift_corrections',
        'daily_totals',
        'incidents',
        'scan_events',
        'audit_log',
        'audit_chain_anchors',
        'users',
        'support_grants',
        'error_events',
        'installation_settings',
        'compliance_profiles',
        'license',
    ]);

    // Y lo que NUNCA puede aparecer: credenciales vivas, codigos de un solo uso
    // y fontaneria del framework.
    foreach (['personal_access_tokens', 'device_pairing_requests', 'setup_progress', 'failed_jobs', 'migrations'] as $prohibido) {
        expect(DataExportCatalog::dataset($prohibido))->toBeNull(
            'El conjunto «'.$prohibido.'» no puede entrar en la exportacion integra.',
        );
    }
})->group('RF-PD-14', 'RL-20');

it('declara `absences` como no instalado, no como vacio', function (): void {
    // La diferencia importa: un `absences.csv` con cero filas dice «no tienes
    // ausencias registradas», y lo cierto es «esta version no registra
    // ausencias».
    //
    // `error_events` estuvo en esta misma lista hasta la tarea 5.12, que creo la
    // tabla: ahora es un conjunto mas y **tiene que salir del `not_installed`**,
    // porque decirle al cliente que su instalacion no registra errores cuando si
    // lo hace es exactamente la mentira que este mecanismo existe para evitar.
    expect(DataExportCatalog::notInstalled())->toBe(['absences'])
        ->and(DataExportCatalog::dataset('absences'))->toBeNull()
        ->and(DataExportCatalog::dataset('error_events'))->not->toBeNull();
})->group('RF-PD-14', 'RF-PD-15');

it('usa JSON solo donde hay documentos anidados y CSV para el resto', function (): void {
    // CSV y no XLSX porque XLSX topa en 1.048.576 filas y cuatro años de
    // `scan_events` lo superan; JSON solo donde el dato ES un documento y en una
    // celda seria una cadena que hay que reinterpretar a mano.
    $enJson = [];

    foreach (DataExportCatalog::datasets() as $dataset) {
        if ($dataset->format === DataExportFormat::Json) {
            $enJson[] = $dataset->name;
        }
    }

    expect($enJson)->toBe(['site', 'installation_settings', 'compliance_profiles', 'license']);

    // Y cada uno declara cual de sus columnas es un documento: sin eso, el JSON
    // de la configuracion llevaria cadenas con JSON dentro.
    expect(DataExportCatalog::dataset('installation_settings')?->embedsJson('value'))->toBeTrue()
        ->and(DataExportCatalog::dataset('compliance_profiles')?->embedsJson('holiday_calendar'))->toBeTrue()
        ->and(DataExportCatalog::dataset('license')?->embedsJson('features'))->toBeTrue();
})->group('RF-PD-14');

it('ningun conjunto declara la misma columna dos veces ni se queda sin columnas', function (): void {
    // Una columna repetida se pisaria a si misma al aplicar la lista de
    // permitidos, y un conjunto sin columnas produciria un fichero con una
    // cabecera vacia que nadie sabria leer.
    foreach (DataExportCatalog::datasets() as $dataset) {
        $columns = $dataset->columns();

        expect($columns)->not->toBe([], 'El conjunto «'.$dataset->name.'» no declara ninguna columna.')
            ->and(array_values(array_unique($columns)))->toBe(
                $columns,
                'El conjunto «'.$dataset->name.'» declara una columna repetida.',
            );
    }
})->group('RF-PD-14');

it('la lista de permitidos descarta lo que la consulta traiga de mas', function (): void {
    // El mecanismo, ejercitado: aunque una consulta empezara a devolver
    // `pin_hash`, no llegaria al fichero. Es la defensa que hace que la lista
    // sea de permitidos y no de exclusiones.
    $empleados = DataExportCatalog::dataset('employees');

    expect($empleados)->toBeInstanceOf(ExportedDataset::class);

    $fila = $empleados?->allowlist->apply([
        'employee_uuid' => '0199f6a2-0000-7000-8000-000000000001',
        'first_name' => 'Marta',
        'pin_hash' => '$2y$10$secreto',
        'national_id_hash' => '49871234Z',
        'photo_path' => '/var/www/fotos/marta.jpg',
    ]) ?? [];

    expect($fila)->not->toHaveKey('pin_hash')
        ->and($fila)->not->toHaveKey('national_id_hash')
        ->and($fila)->not->toHaveKey('photo_path')
        ->and($fila['first_name'])->toBe('Marta')
        // Y lo que la consulta no traiga sale como nulo, no desaparece: asi el
        // fichero tiene siempre las mismas columnas.
        ->and($fila)->toHaveKey('email')
        ->and($fila['email'])->toBeNull();
})->group('RF-PD-14', 'RL-20');

it('el nombre del fichero de cada conjunto lleva su extension y no se repite', function (): void {
    $nombres = [];

    foreach (DataExportCatalog::datasets() as $dataset) {
        expect($dataset->fileName())->toEndWith('.'.$dataset->format->value);

        $nombres[] = $dataset->fileName();
    }

    // Dos conjuntos con el mismo nombre de fichero se pisarian dentro del ZIP y
    // el cliente perderia uno sin enterarse.
    expect(array_values(array_unique($nombres)))->toBe($nombres);
})->group('RF-PD-14');

/**
 * El valor al final de esa ruta de claves, o `null` si el camino se corta.
 *
 * Separado de {@see explicado()} porque recorrer y decidir son dos cosas, y
 * juntas dejaban la funcion con un `@var mixed` sobre una variable que empieza
 * siendo `array` — que es justo lo que PHPStan 9 rechaza.
 *
 * @param  array<array-key, mixed>  $textos
 * @param  list<string>  $ruta
 */
function hojaDe(array $textos, array $ruta): mixed
{
    $valor = $textos;

    foreach ($ruta as $clave) {
        if (! is_array($valor) || ! array_key_exists($clave, $valor)) {
            return null;
        }

        $valor = $valor[$clave];
    }

    return $valor;
}

/**
 * Si ese texto existe y no esta vacio en el fichero de idioma.
 *
 * Con nombre y no en linea: la clausura de la prueba se quedaba en complejidad
 * 11 —por encima del maximo del §3.5— acumulando condiciones que dicen todas lo
 * mismo.
 *
 * @param  array<array-key, mixed>  $textos
 */
function explicado(array $textos, string ...$ruta): bool
{
    $valor = hojaDe($textos, array_values($ruta));

    return is_string($valor) && trim($valor) !== '';
}

it('cada conjunto y cada columna estan explicados en los dos idiomas', function (string $locale): void {
    /*
     * **LO QUE HACE UTIL LA EXPORTACION DENTRO DE DOS AÑOS.**
     *
     * RL-20 no promete un ZIP: promete que el cliente pueda seguir cumpliendo su
     * obligacion de conservacion **aunque la relacion comercial termine**. Una
     * carpeta con dieciocho ficheros cuyas columnas nadie sabe interpretar no
     * cumple eso: sin KronoQR delante, `status = superseded` o
     * `clock_in_source = pin_fallback` no significan nada para nadie.
     *
     * El README se compone recorriendo el catalogo, y una columna sin traduccion
     * sale con una nota que dice que no esta descrita. Sin esta prueba, **la
     * columna que alguien añada mañana llegaria asi a un cliente**: el docblock
     * de `TranslatedDataExportGuide` afirmaba la cobertura y no habia nada que la
     * atara.
     *
     * Se leen los ficheros de idioma directamente y no por el traductor: lo que
     * se comprueba es que el TEXTO existe, no que el framework sepa cargarlo.
     */
    $textos = require __DIR__.'/../../../../lang/'.$locale.'/data-export.php';

    expect($textos)->toBeArray()->toHaveKey('files');

    $faltan = [];

    foreach (DataExportCatalog::datasets() as $dataset) {
        if (! explicado($textos, 'files', $dataset->name, 'summary')) {
            $faltan[] = $dataset->name.'.summary';
        }

        foreach ($dataset->columns() as $column) {
            if (! explicado($textos, 'files', $dataset->name, 'columns', $column)) {
                $faltan[] = $dataset->name.'.columns.'.$column;
            }
        }
    }

    // Y lo que esta version no registra tambien se explica: si no, el cliente lee
    // «no hay fichero de ausencias» y no sabe si es que no tiene ninguna o que el
    // producto no las guarda.
    foreach (DataExportCatalog::notInstalled() as $name) {
        if (! explicado($textos, 'not_installed_names', $name)) {
            $faltan[] = 'not_installed_names.'.$name;
        }
    }

    expect($faltan)->toBe(
        [],
        'El README de la exportacion integra en «'.$locale.'» no explica esto: '.implode(', ', $faltan)
        .'. Una columna sin descripcion sale en el fichero del cliente con una nota de «sin descripcion».',
    );
})->with(['es', 'en'])->group('RF-PD-14', 'RL-20');

it('no describe en el README ninguna columna que ya no exista', function (string $locale): void {
    // El trinquete al reves: una descripcion que sobrevive a su columna es texto
    // muerto que nadie borra, y que el dia que alguien lea el fichero de idioma
    // le hara buscar en el ZIP una columna que no esta.
    $textos = require __DIR__.'/../../../../lang/'.$locale.'/data-export.php';

    /** @var array<string, array{columns?: array<string, string>}> $ficheros */
    $ficheros = $textos['files'];

    $sobran = [];

    foreach ($ficheros as $nombre => $bloque) {
        $dataset = DataExportCatalog::dataset((string) $nombre);

        if ($dataset === null) {
            $sobran[] = (string) $nombre;

            continue;
        }

        foreach (array_keys($bloque['columns'] ?? []) as $column) {
            if (! in_array((string) $column, $dataset->columns(), true)) {
                $sobran[] = $nombre.'.'.$column;
            }
        }
    }

    expect($sobran)->toBe(
        [],
        'El README de la exportacion integra en «'.$locale.'» describe esto, que ya no sale en el ZIP: '
        .implode(', ', $sobran),
    );
})->with(['es', 'en'])->group('RF-PD-14', 'RL-20');
