<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\ErrorMessageSanitizer;

/*
 * El saneado es la regla dura 21 convertida en codigo (RF-PD-15, RL-19,
 * decision 5 de la ficha 5.12).
 *
 * **Este historico viaja al fabricante dentro del paquete de diagnostico: si
 * lleva PII, se ha filtrado.** El cliente ya sanea antes de enviar; no se confia
 * en ello, y esta prueba es la que fija que la ultima linea de defensa es de
 * servidor.
 */

it('no deja pasar ningun dato personal reconocible', function (string $texto, string $prohibido): void {
    expect(ErrorMessageSanitizer::sanitize($texto))->not->toContain($prohibido);
})->with([
    'nombre entrecomillado' => ["Employee 'Ana Ruiz' not found", 'Ana Ruiz'],
    'nombre entre comillas dobles' => ['El empleado "Ana Ruiz" no existe', 'Ana Ruiz'],
    'nombre entre comillas angulares' => ['El empleado «Ana Ruiz» no existe', 'Ana Ruiz'],
    'correo' => ['aviso a ana.ruiz@hotel.es rechazado', 'ana.ruiz@hotel.es'],
    'dni' => ['documento 12345678Z duplicado', '12345678Z'],
    'nie' => ['documento X1234567L duplicado', 'X1234567L'],
    'telefono' => ['contacto 600123456 invalido', '600123456'],
    'telefono con prefijo' => ['contacto +34 600 123 456 invalido', '600 123 456'],
    'hora de fichaje' => ['tramo abierto a las 22:15 sin cierre', '22:15'],
    'fecha de jornada' => ['jornada del 2026-09-09 incompleta', '2026-09-09'],
    'cabecera Bearer' => ['fallo con Bearer eyJhbGciOiJIUzI1NiJ9.abc', 'eyJhbGciOiJIUzI1NiJ9'],
    'payload de credencial' => ['rechazado FH1.k1.tok3nt0k3n.s1gs1g', 'tok3nt0k3n'],
    'clave con nombre de secreto' => ['password=hunter2 no valida', 'hunter2'],
    // Valor de baja entropia a proposito: gitleaks (job `security`) marca
    // `token: <valor con entropia>` como clave aunque sea un ejemplo.
    'token con nombre de secreto' => ['token: abcabcabcabc caducado', 'abcabcabcabc'],
])->group('RF-PD-15', 'RL-19');

it('sustituye cada forma por el marcador que le toca', function (string $texto, string $esperado): void {
    expect(ErrorMessageSanitizer::sanitize($texto))->toContain($esperado);
})->with([
    'correo' => ['aviso a ana.ruiz@hotel.es', '[email]'],
    'dni' => ['documento 12345678Z', '[id]'],
    'telefono' => ['contacto 600123456', '[phone]'],
    'hora' => ['a las 22:15', '[time]'],
    'secreto' => ['password=hunter2', '[secret]'],
    'entrecomillado' => ["clave '…' duplicada", "'…'"],
])->group('RF-PD-15', 'RL-19');

it('no deja pasar los valores que Laravel interpola en el mensaje de una QueryException', function (
    string $mensaje,
    array $prohibidos,
): void {
    /*
     * LA RED DE SEGURIDAD DE LA REVISION. `QueryException::getMessage()` pega al
     * final la consulta **con los parametros ya interpolados y sin comillas**,
     * asi que ahi aparecen en claro el nombre y el apellido de una persona, su
     * codigo de empleado y el bcrypt de su PIN. El saneado general no los atrapa
     * porque no van entrecomillados.
     *
     * El enganche de captacion compone el mensaje con `getSql()` en lugar de
     * `getMessage()`, asi que en el camino normal esto no llega a activarse;
     * existe porque el camino normal no es el unico.
     */
    $limpio = ErrorMessageSanitizer::sanitize($mensaje);

    foreach ($prohibidos as $prohibido) {
        expect($limpio)->not->toContain($prohibido);
    }
})->with([
    'insert con los valores interpolados' => [
        'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint '
            .'"employees_employee_code_unique" (Connection: pgsql, SQL: insert into "employees" '
            .'("first_name", "last_name", "employee_code") values (Maria, Gonzalez Perez, EMP-0042))',
        ['Maria', 'Gonzalez Perez', 'EMP-0042', 'insert into'],
    ],
    'update con el hash del PIN' => [
        'SQLSTATE[22001]: String data right truncated (Connection: pgsql, SQL: update "employees" set '
            .'"pin_hash" = $2y$12$abcdefghijklmnopqrstuvQ9wXyZ0123456789abcdefghijklmn where "id" = 42)',
        ['$2y$12$', 'abcdefghijklmnopqrstuv', 'update "employees"'],
    ],
    'DETAIL con la clave que choco' => [
        'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint '
            .'"employees_employee_code_unique" DETAIL: Key (employee_code)=(EMP-0042) already exists.',
        ['EMP-0042'],
    ],
    /*
     * LA FUGA MAS GRANDE DE LAS TRES: ante un `NOT NULL` o un `CHECK`,
     * PostgreSQL vuelca **la fila entera** en el `DETAIL`. Ahi va la plantilla
     * en claro —nombre, apellidos, codigo de empleado— y en otras tablas iria el
     * hash del PIN.
     */
    'DETAIL con la fila entera' => [
        'SQLSTATE[23502]: Not null violation: 7 ERROR: null value in column "site_id" violates not-null '
            .'constraint DETAIL: Failing row contains (1, null, Maria, Gonzalez Perez, EMP-0042, '
            .'ana.ruiz@hotel.es, active, 2026-01-01).',
        ['Maria', 'Gonzalez Perez', 'EMP-0042', 'ana.ruiz@hotel.es'],
    ],
    'DETAIL con la fila entera y parentesis dentro de un valor' => [
        'SQLSTATE[23514]: Check violation: 7 ERROR: new row violates check constraint DETAIL: Failing row '
            .'contains (7, Maria (de la) Fuente, EMP-0042, 600123456).',
        ['Maria', 'de la', 'Fuente', 'EMP-0042'],
    ],
])->group('RF-PD-15', 'RL-19');

it('conserva de un volcado de fila lo unico que sirve: que restriccion se violo', function (): void {
    $limpio = ErrorMessageSanitizer::sanitize(
        'SQLSTATE[23502]: Not null violation: 7 ERROR: null value in column "site_id" violates not-null '
        .'constraint DETAIL: Failing row contains (1, null, Maria, Gonzalez Perez).',
    );

    expect($limpio)->toContain('SQLSTATE[23502]')
        ->and($limpio)->toContain('violates not-null constraint')
        // Y deja constancia de que ahi habia algo y se quito, en vez de cortar en
        // seco: soporte tiene que poder distinguir «no hubo detalle» de «el
        // detalle no viaja».
        ->and($limpio)->toContain('Failing row contains [redacted]');
})->group('RF-PD-15', 'RL-19');

it('conserva de una QueryException lo que sirve para diagnosticar', function (): void {
    // El saneado es destructivo, pero un `SQLSTATE` sin `SQLSTATE` no vale para
    // nada: lo que se corta es el valor, no el diagnostico.
    $limpio = ErrorMessageSanitizer::sanitize(
        'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint '
        .'"employees_employee_code_unique" DETAIL: Key (employee_code)=(EMP-0042) already exists. '
        .'(Connection: pgsql, SQL: insert into "employees" values (Maria))',
    );

    expect($limpio)->toContain('SQLSTATE[23505]')
        ->and($limpio)->toContain('duplicate key value violates unique constraint')
        // La forma del `DETAIL` se conserva: sigue diciendo «choco una clave».
        ->and($limpio)->toContain("Key ('…')=('…')");
})->group('RF-PD-15');

it('conserva lo que hace util el mensaje', function (): void {
    // El saneado es destructivo a proposito, pero lo que queda tiene que seguir
    // diciendo QUE paso: quien lo lee es el IT del cliente, no una maquina.
    $limpio = ErrorMessageSanitizer::sanitize(
        "SQLSTATE[23505] duplicate key value violates unique constraint 'employees_email_unique'",
    );

    expect($limpio)->toContain('SQLSTATE[23505]')
        ->and($limpio)->toContain('duplicate key value violates unique constraint');
})->group('RF-PD-15');

it('trunca a mil caracteres sin partir un caracter multibyte', function (): void {
    // `substr()` sobre UTF-8 parte una tilde por la mitad y deja un byte
    // invalido que revienta al serializar el JSON del paquete de diagnostico:
    // el peor sitio para descubrirlo.
    $limpio = ErrorMessageSanitizer::sanitize(str_repeat('á', 4000));

    expect(mb_strlen($limpio))->toBe(ErrorMessageSanitizer::MAX_LENGTH)
        ->and(mb_check_encoding($limpio, 'UTF-8'))->toBeTrue();
})->group('RF-PD-15');

it('nunca devuelve un mensaje vacio', function (): void {
    // Una fila con `message: ''` en el panel parece un fallo del panel.
    expect(ErrorMessageSanitizer::sanitize('   '))->toBe('(sin mensaje)');
})->group('RF-PD-15');

it('trunca los valores de contexto a doscientos caracteres', function (): void {
    expect(mb_strlen(ErrorMessageSanitizer::sanitizeContextValue(str_repeat('x', 900))))
        ->toBe(ErrorMessageSanitizer::MAX_CONTEXT_LENGTH);
})->group('RF-PD-15');

/*
 * ---------------------------------------------------------------------------
 * PR12, F4c-2 y L1 (verificacion de la 2.1.0): los patrones que faltaban.
 *
 * Codigo de empleado, DNI y NIE con puntos y espacios, fechas `dd-mm-aaaa`,
 * IBAN, pasaporte y el `Key (…)=(…)` anidado de PostgreSQL. Dos tablas que se
 * leen juntas: lo que tiene que desaparecer, y los mensajes tecnicos normales
 * que tienen que salir **identicos**. La segunda es la que impide que un patron
 * nuevo convierta el log en ruido.
 * ---------------------------------------------------------------------------
 */

it('no deja pasar los datos personales de F4c-2 y PR12', function (string $texto, string $prohibido): void {
    expect(ErrorMessageSanitizer::sanitize($texto))->not->toContain($prohibido)
        ->and(ErrorMessageSanitizer::redact($texto))->not->toContain($prohibido);
})->with([
    // Codigo de empleado: la forma que genera `EmployeeCode::generate()`, la de
    // la semilla (hexadecimal), la de los ejemplos del contrato (ocho) y la que
    // no tiene ni una cifra pero si el alfabeto sin ambiguos.
    'codigo canonico' => ['Employee E7K2M9QX4B not found', 'E7K2M9QX4B'],
    'codigo de ocho' => ['tarjeta de E7QK2MXPR revocada', 'E7QK2MXPR'],
    'codigo de la semilla' => ['fallo con E3A4F0B91C al fichar', 'E3A4F0B91C'],
    'codigo sin cifras' => ['fallo con EKMNPQRSTU al fichar', 'KMNPQRSTU'],
    'codigo heredado con etiqueta =' => ['employee_code=739104 rejected', '739104'],
    'codigo heredado con etiqueta :' => ['employee code: AB12C3 rejected', 'AB12C3'],
    'codigo heredado en castellano' => ['codigo de empleado 739104 no existe', '739104'],
    'codigo con tilde' => ['código de empleado: ZX81 no existe', 'ZX81'],
    // DNI y NIE como se teclean a mano.
    'dni con puntos y guion' => ['documento 12.345.678-Z duplicado', '345.678'],
    'dni con espacios' => ['documento 12 345 678 Z duplicado', '345 678'],
    'dni de siete cifras con puntos' => ['documento 1.234.567-L duplicado', '234.567'],
    'nie con guiones' => ['documento X-1234567-L duplicado', '1234567'],
    'nie con puntos y espacios' => ['documento Y 1.234.567 L duplicado', '234.567'],
    'dni con guion pegado' => ['documento 12345678-Z duplicado', '12345678'],
    // Fechas.
    'fecha dd-mm-aaaa' => ['jornada del 14-03-2026 incompleta', '14-03-2026'],
    'fecha dd/mm/aaaa' => ['jornada del 14/03/2026 incompleta', '14/03/2026'],
    'fecha dd.mm.aaaa' => ['jornada del 14.03.2026 incompleta', '14.03.2026'],
    'fecha aaaa/mm/dd' => ['jornada del 2026/03/14 incompleta', '2026/03/14'],
    'fecha d-m-aaaa' => ['alta el 1-3-2026', '1-3-2026'],
    // IBAN, compacto y agrupado, espanol y extranjero.
    'iban agrupado' => ['nomina a ES91 2100 0418 4502 0005 1332 rechazada', '2100 0418'],
    'iban compacto' => ['nomina a ES9121000418450200051332 rechazada', '21000418'],
    'iban aleman' => ['nomina a DE89370400440532013000 rechazada', '370400440532013000'],
    'iban portugues agrupado' => ['nomina a PT50 0002 0123 1234 5678 9015 4', '0123 1234'],
    // Pasaporte.
    'pasaporte espanol' => ['pasaporte PAA123456 caducado', 'PAA123456'],
    'pasaporte con etiqueta' => ['passport no. 987654321 expired', '987654321'],
    'pasaporte con numero' => ['pasaporte numero: X12345678 caducado', 'X12345678'],
])->group('RF-PD-15', 'RL-19', 'RL-08');

it('quita los valores del Key anidado de PostgreSQL', function (string $mensaje, array $prohibidos): void {
    /*
     * F4c-2: el patron anterior cortaba en el primer `)`, asi que con una clave
     * de expresion o de exclusion NO casaba y salian el `employee_id` y las
     * horas del tramo. Un indice unico sobre CITEXT se expresa como
     * `lower(...)`, y la exclusion de solapes de `shift_entries` (RN-01) lleva
     * un `tstzrange(...)` con un rango semiabierto que ni siquiera tiene los
     * parentesis equilibrados.
     */
    $limpio = ErrorMessageSanitizer::sanitize($mensaje);

    foreach ($prohibidos as $prohibido) {
        expect($limpio)->not->toContain($prohibido);
    }

    expect($limpio)->toContain("Key ('…')=('…')");
})->with([
    'indice de expresion' => [
        'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint '
            .'"employees_code_lower_unique" DETAIL: Key (lower((employee_code)::text))=(e7k2m9qx4b) already exists.',
        ['e7k2m9qx4b'],
    ],
    'exclusion con rango semiabierto' => [
        'SQLSTATE[23P01]: Exclusion violation: 7 ERROR: conflicting key value violates exclusion constraint '
            .'"shift_entries_no_overlap" DETAIL: Key (employee_id, tstzrange(started_at, ended_at, \'[)\'::text))'
            .'=(4242, ["2026-03-14 07:02:00+00","2026-03-14 15:00:00+00")) conflicts with existing key '
            .'(employee_id, tstzrange(started_at, ended_at, \'[)\'::text))=(4242, ["2026-03-14 06:00:00+00",'
            .'"2026-03-14 08:00:00+00")).',
        ['4242', '07:02', '15:00', '06:00'],
    ],
    'clave compuesta con un valor con parentesis' => [
        'DETAIL: Key (site_id, note)=(3, Maria (de la) Fuente) already exists.',
        ['Maria', 'Fuente', 'de la'],
    ],
    'clave foranea ausente' => [
        'DETAIL: Key (employee_id)=(98765) is not present in table "employees".',
        ['98765'],
    ],
])->group('RF-PD-15', 'RL-19', 'RL-08');

it('conserva lo que sigue al Key: dice que paso sin decir de quien', function (): void {
    $limpio = ErrorMessageSanitizer::redact(
        'DETAIL: Key (employee_id)=(98765) is still referenced from table "shift_entries".',
    );

    expect($limpio)->toBe("DETAIL: Key ('…')=('…') is still referenced from table '…'.");
})->group('RF-PD-15');

it('no toca un mensaje tecnico normal', function (string $texto): void {
    /*
     * LA OTRA MITAD. Un patron nuevo que se coma versiones, UUID, rutas,
     * numeros de linea o tamanos convierte el log tecnico en ruido, y un log que
     * no se puede leer no protege a nadie: se deja de mirar. Estos salen
     * identicos, caracter a caracter.
     */
    expect(ErrorMessageSanitizer::redact($texto))->toBe($texto);
})->with([
    'versiones' => ['PHP 8.4.12, Laravel v13.2.1, PostgreSQL 17.6, KronoQR 2.2.0'],
    'version de cuatro cifras' => ['version 13.0.1234 build 2026.10.01'],
    'uuid en minusculas' => ['employee 0199a1f0-0000-7000-8000-000000000000 not found'],
    'uuid en mayusculas' => ['device 0199A1F0-ABCD-7000-8000-00000000AB12 unknown'],
    'trace_id' => ['trace a1b2c3d4e5f60718293a4b5c6d7e8f90 span 00f067aa0ba902b7'],
    'ruta con linea' => ['at /var/www/html/app/Modules/Product/Domain/ValueObject/Foo.php:123'],
    'numero de linea' => ['Undefined array key 3 on line 1234'],
    'sqlstate y http' => ['SQLSTATE[23505] HTTP 500 Internal Server Error'],
    'memoria' => ['Allowed memory size of 1073741824 bytes exhausted (tried to allocate 20480 bytes)'],
    'puertos y pid' => ['Connection refused tcp://redis:6379 port 5432 pid 12345'],
    'huella en mayusculas' => ['E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855'],
    'huella en minusculas' => ['sha256 de12a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3'],
    'palabras en mayusculas' => ['EXCEPTIONS EVERYTHING ENCRYPTED ERROR_CODE E_WARNING'],
    'clase y metodo' => ['App\Modules\Kiosk\Application\UseCase\RecordHeartbeat::handle()'],
    'duracion' => ['timeout after 30000 ms, retry 3 of 5'],
    'clave sin valores' => ['missing key (config) in file'],
    'numero y conjuncion' => ['procesados 120 y descartados 4'],
])->group('RF-PD-15');

it('es idempotente: sanear lo saneado no cambia nada', function (string $texto): void {
    // La pila de log aplica el processor una vez por canal; la segunda pasada
    // no puede estropear la primera.
    $una = ErrorMessageSanitizer::redact($texto);

    expect(ErrorMessageSanitizer::redact($una))->toBe($una);
})->with([
    'key anidado' => ['DETAIL: Key (lower((employee_code)::text))=(e7k2m9qx4b) already exists.'],
    'de todo' => ['E7K2M9QX4B 12.345.678-Z ES91 2100 0418 4502 0005 1332 "Ana" 14-03-2026 22:15 a@b.es'],
])->group('RF-PD-15');

it('ningun codigo, DNI ni IBAN generado sobrevive, sea cual sea la plantilla', function (string $texto, string $dato): void {
    /*
     * Propiedad sobre valores generados, con semilla fija para que un fallo se
     * reproduzca. No hay `Pest\Faker` en el repositorio y no hace falta: el
     * generador sigue las mismas reglas que `EmployeeCode::generate()`, la
     * letra de control del DNI y el formato agrupado del IBAN espanol.
     */
    expect(ErrorMessageSanitizer::redact($texto))->not->toContain($dato);
})->with(static function (): iterable {
    mt_srand(20261001);

    $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $letrasDni = 'TRWAGMYFPDXBNJZSQVHLCKE';
    $plantillas = [
        'Employee %s not found',
        'SQLSTATE[23505]: Unique violation: valor %s ya existe',
        '[2026-10-01T10:00:00Z] kiosk.error: fallo con %s (trace a1b2c3d4e5f60718293a4b5c6d7e8f90)',
        "Error: %s\n#0 /var/www/html/app/Foo.php(12): bar()",
        '%s',
    ];

    for ($i = 0; $i < 60; $i++) {
        $codigo = 'E';

        for ($j = 0; $j < 9; $j++) {
            $codigo .= $alfabeto[mt_rand(0, 30)];
        }

        $numero = mt_rand(10_000_000, 99_999_999);
        $dni = $numero.$letrasDni[$numero % 23];
        $dniConPuntos = sprintf('%s.%s.%s-%s', substr($dni, 0, 2), substr($dni, 2, 3), substr($dni, 5, 3), $dni[8]);

        $iban = 'ES'.mt_rand(10, 99);

        for ($j = 0; $j < 5; $j++) {
            $iban .= ' '.str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        }

        $plantilla = $plantillas[$i % count($plantillas)];

        yield "codigo {$i}" => [sprintf($plantilla, $codigo), $codigo];
        yield "dni {$i}" => [sprintf($plantilla, $dniConPuntos), $dniConPuntos];
        yield "iban {$i}" => [sprintf($plantilla, $iban), $iban];
    }
})->group('RF-PD-15', 'RL-19', 'RL-08');
