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
    // el peor sitio para descubrirlo. Palabras del vocabulario con tilde, para
    // que la lista blanca no las convierta antes en `…`.
    $limpio = ErrorMessageSanitizer::sanitize(str_repeat('código ', 600));

    expect(mb_strlen($limpio))->toBeLessThanOrEqual(ErrorMessageSanitizer::MAX_LENGTH)
        ->and(mb_strlen($limpio))->toBeGreaterThan(ErrorMessageSanitizer::MAX_LENGTH - 10)
        ->and(mb_check_encoding($limpio, 'UTF-8'))->toBeTrue()
        ->and($limpio)->toEndWith('…');
})->group('RF-PD-15');

it('nunca devuelve un mensaje vacio', function (): void {
    // Una fila con `message: ''` en el panel parece un fallo del panel.
    expect(ErrorMessageSanitizer::sanitize('   '))->toBe('(sin mensaje)')
        // Y el texto de relleno sobrevive a su propio saneado: el colector
        // vuelve a sanear al leer.
        ->and(ErrorMessageSanitizer::sanitize(ErrorMessageSanitizer::EMPTY_MESSAGE))
        ->toBe(ErrorMessageSanitizer::EMPTY_MESSAGE);
})->group('RF-PD-15');

it('trunca los valores de contexto a doscientos caracteres', function (): void {
    expect(mb_strlen(ErrorMessageSanitizer::sanitizeContextValue(str_repeat('error ', 150))))
        ->toBeLessThanOrEqual(ErrorMessageSanitizer::MAX_CONTEXT_LENGTH)
        ->toBeGreaterThan(ErrorMessageSanitizer::MAX_CONTEXT_LENGTH - 10);
})->group('RF-PD-15');

it('vuelve a filtrar lo truncado, y por eso sigue siendo idempotente', function (string $texto): void {
    /*
     * El corte puede dejar media palabra («Connec…»). Sin la segunda pasada,
     * el colector —que vuelve a sanear al leer— la convertiria en `…` y el
     * mismo grupo diria dos cosas distintas en la tabla y en el paquete.
     */
    $una = ErrorMessageSanitizer::sanitize($texto);
    $valor = ErrorMessageSanitizer::sanitizeContextValue($texto);

    expect(ErrorMessageSanitizer::sanitize($una))->toBe($una)
        ->and(ErrorMessageSanitizer::sanitizeContextValue($valor))->toBe($valor)
        ->and(mb_strlen($una))->toBeLessThanOrEqual(ErrorMessageSanitizer::MAX_LENGTH)
        ->and(mb_strlen($valor))->toBeLessThanOrEqual(ErrorMessageSanitizer::MAX_CONTEXT_LENGTH);
})->with(static function (): iterable {
    foreach ([0, 1, 2, 3, 4, 5, 6, 7] as $desplazamiento) {
        yield 'corte desplazado '.$desplazamiento => [
            str_repeat('x', $desplazamiento).' '.str_repeat('Connection refused 0199a1f0-0000-7000-8000-000000000000 ', 40),
        ];
    }
})->group('RF-PD-15', 'RL-19');

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

/*
 * ---------------------------------------------------------------------------
 * Bordes que fija la mutacion (job 3 de la CI sobre el Bloque 6, PR12/L1).
 * ---------------------------------------------------------------------------
 */

it('falla cerrado ante UTF-8 invalido: pierde el texto, no lo deja pasar sin sanear', function (): void {
    /*
     * `preg_replace()` devuelve `null` con un patron `/u` sobre bytes UTF-8
     * invalidos. El saneador lo convierte en cadena vacia en un unico sitio, y
     * esta prueba lo fija: ni lanza un `TypeError` (que en el enganche de
     * captacion seria un error dentro del registro de otro error) ni devuelve el
     * correo, el nombre o la hora que iban en el mismo texto.
     */
    $roto = "Employee 'Ana Ruiz' (ana.ruiz@hotel.es) fichó a las 22:15 \xC3\x28 sin cierre";

    expect(ErrorMessageSanitizer::redact($roto))->toBe('')
        ->and(ErrorMessageSanitizer::sanitize($roto))->toBe(ErrorMessageSanitizer::EMPTY_MESSAGE)
        ->and(ErrorMessageSanitizer::sanitizeContextValue($roto))->toBe(ErrorMessageSanitizer::EMPTY_MESSAGE);
})->group('RF-PD-15', 'RL-19');

it('no trunca un texto que mide exactamente el techo', function (): void {
    // El techo es el `maxLength` del contrato y el de la columna: un mensaje que
    // cabe justo se guarda entero, sin el `…`.
    $mensaje = str_repeat('ok ', 333).'x';
    $valor = str_repeat('ok ', 66).'ok';

    expect(mb_strlen($mensaje))->toBe(ErrorMessageSanitizer::MAX_LENGTH)
        ->and(mb_strlen($valor))->toBe(ErrorMessageSanitizer::MAX_CONTEXT_LENGTH)
        ->and(ErrorMessageSanitizer::sanitize($mensaje))->toBe($mensaje)
        ->and(ErrorMessageSanitizer::sanitizeContextValue($valor))->toBe($valor);
})->group('RF-PD-15');

it('al truncar conserva el principio del texto y cierra con el indicador', function (): void {
    // Lo que diagnostica —`SQLSTATE`, la clase de la excepcion— va al
    // principio: el corte se lleva la cola, nunca la cabeza.
    $mensaje = ErrorMessageSanitizer::sanitize('SQLSTATE[23505] '.str_repeat('ok ', 600));
    $valor = ErrorMessageSanitizer::sanitizeContextValue('TypeError '.str_repeat('ok ', 200));

    expect($mensaje)->toStartWith('SQLSTATE[23505] ok ok')
        ->and($mensaje)->toEndWith('…')
        ->and(mb_strlen($mensaje))->toBe(ErrorMessageSanitizer::MAX_LENGTH)
        ->and($valor)->toStartWith('TypeError ok ok')
        ->and($valor)->toEndWith('…')
        ->and(mb_strlen($valor))->toBeLessThanOrEqual(ErrorMessageSanitizer::MAX_CONTEXT_LENGTH);
})->group('RF-PD-15');

it('corta la consulta de una QueryException sin dejar marcador en su lugar', function (): void {
    // La consulta ENTERA es el problema: no se sustituye por nada, se corta.
    expect(ErrorMessageSanitizer::redact(
        'SQLSTATE[23505] duplicate key (Connection: pgsql, SQL: insert into t values (Maria))',
    ))->toBe('SQLSTATE[23505] duplicate key (Connection: pgsql');
})->group('RF-PD-15', 'RL-19');

/*
 * ---------------------------------------------------------------------------
 * ADR-048 (bloque 19 de la 2.2.0): lista blanca por palabra, patrones ampliados
 * y las condiciones del dictamen de seguridad (H1, H3, H4). Una prueba por
 * forma: la fuga que cerro el bloque era siempre la forma que nadie probo.
 * ---------------------------------------------------------------------------
 */

it('no deja pasar un nombre sin comillas en ninguna forma', function (string $texto, string $prohibido): void {
    expect(ErrorMessageSanitizer::sanitize($texto))->not->toContain($prohibido)
        ->and(ErrorMessageSanitizer::redactText($texto))->not->toContain($prohibido);
})->with([
    'capitalizado' => ['Employee Rosa Ficticiana not found', 'Ficticiana'],
    'mayusculas' => ['EMPLOYEE ROSA FICTICIANA NOT FOUND', 'FICTICIANA'],
    'minusculas' => ['employee rosa ficticiana not found', 'ficticiana'],
    'apellido, nombre' => ['Employee Ficticiana, Rosa not found', 'Rosa'],
    'Li Wang' => ['No se pudo fichar a Li Wang', 'Wang'],
    'Max Campos' => ['No se pudo fichar a Max Campos', 'Campos'],
    'Max suelto' => ['No se pudo fichar a Max Campos', 'Max'],
])->group('RF-PD-15', 'RL-19');

it('sustituye cada forma de IBAN', function (string $iban): void {
    expect(ErrorMessageSanitizer::redact('cuenta '.$iban.' rechazada'))->toBe('cuenta [iban] rechazada');
})->with([
    'mayusculas con espacios' => ['ES91 2100 0418 4502 0005 1332'],
    'minusculas con espacios' => ['es91 2100 0418 4502 0005 1332'],
    'compacto' => ['ES9121000418450200051332'],
    'con guiones' => ['ES91-2100-0418-4502-0005-1332'],
    'minusculas con guiones' => ['es91-2100-0418-4502-0005-1332'],
    'aleman compacto' => ['DE89370400440532013000'],
    'aleman en minusculas agrupado' => ['de89 3704 0044 0532 0130 00'],
    'britanico con letras en el banco' => ['GB29 NWBK 6016 1331 9268 19'],
])->group('RF-PD-15', 'RL-19');

it('no confunde con un IBAN una palabra que empieza como uno', function (): void {
    // `id42` y tres palabras de cuatro letras no suman diez cifras.
    expect(ErrorMessageSanitizer::redact('id42 user test case'))->toBe('id42 user test case');
})->group('RF-PD-15');

it('sustituye cada forma de codigo de empleado', function (string $texto, string $prohibido): void {
    expect(ErrorMessageSanitizer::sanitize($texto))->not->toContain($prohibido);
})->with([
    'canonico en mayusculas' => ['tarjeta E7K2M9QX4B revocada', 'E7K2M9QX4B'],
    'canonico en minusculas' => ['tarjeta e7k2m9qx4b revocada', 'e7k2m9qx4b'],
    'con etiqueta en castellano' => ['Ya existe un empleado con el codigo E7K2M9QX4B.', 'E7K2M9QX4B'],
    'con etiqueta y tilde' => ['el código 739104 no existe', '739104'],
    'con etiqueta en ingles' => ['employee code AB12C3 rejected', 'AB12C3'],
    'con etiqueta corta en ingles' => ['code 739104 rejected', '739104'],
    'heredado alfanumerico sin etiqueta' => ['tarjeta HTL2019X0042 revocada', 'HTL2019X0042'],
    'heredado numerico sin etiqueta' => ['tarjeta 739104 revocada', '739104'],
    'heredado corto sin etiqueta' => ['tarjeta AB12C3 revocada', 'AB12C3'],
])->group('RF-PD-15', 'RL-19');

it('sustituye la etiqueta corta solo si lo que sigue lleva cifras', function (): void {
    expect(ErrorMessageSanitizer::redact('code 739104 rejected'))->toBe('code [code] rejected')
        ->and(ErrorMessageSanitizer::redact('code is required'))->toBe('code is required');
})->group('RF-PD-15');

it('sustituye cada forma de DNI y NIE', function (string $documento): void {
    expect(ErrorMessageSanitizer::redact('documento '.$documento.' duplicado'))->toBe('documento [id] duplicado');
})->with([
    'dni compacto' => ['45678912K'],
    'dni con puntos y guion' => ['45.678.912-K'],
    'dni con espacios' => ['45 678 912 K'],
    'dni con guion' => ['45678912-K'],
    'nie compacto' => ['X7654321L'],
    'nie con guiones' => ['X-7654321-L'],
    'nie en minusculas con guiones' => ['x-7654321-l'],
    'nie en minusculas con espacios' => ['x 7654321 l'],
    'nie con puntos' => ['Y 7.654.321 M'],
])->group('RF-PD-15', 'RL-19');

it('sustituye un DNI sin letra por la regla de las siete cifras', function (): void {
    expect(ErrorMessageSanitizer::sanitize('document 45678912 duplicate'))->toBe('document [n] duplicate');
})->group('RF-PD-15', 'RL-19');

it('sustituye cada forma de pasaporte', function (string $texto, string $esperado): void {
    expect(ErrorMessageSanitizer::redact($texto))->toBe($esperado);
})->with([
    'espanol' => ['pasaporte PAA654321 caducado', 'pasaporte [id] caducado'],
    'espanol sin etiqueta' => ['documento PAA654321 caducado', 'documento [id] caducado'],
    'extranjero sin etiqueta' => ['documento K98765432 caducado', 'documento [id] caducado'],
    'extranjero en minusculas' => ['documento k98765432 caducado', 'documento [id] caducado'],
    'extranjero de dos letras' => ['documento AB1234567 caducado', 'documento [id] caducado'],
    'con etiqueta en ingles' => ['passport no. 987654321 expired', 'passport no. [id] expired'],
])->group('RF-PD-15', 'RL-19');

it('sustituye cada forma de NAF', function (string $naf): void {
    expect(ErrorMessageSanitizer::redact('afiliacion '.$naf.' duplicada'))->toBe('afiliacion [id] duplicada');
})->with([
    'compacto' => ['281234567840'],
    'con barras' => ['28/12345678/40'],
    'con espacios' => ['28 12345678 40'],
    'con guiones' => ['28-1234567-40'],
])->group('RF-PD-15', 'RL-19');

it('sustituye cada forma de telefono', function (string $telefono): void {
    expect(ErrorMessageSanitizer::redact('contacto '.$telefono.' invalido'))->toBe('contacto [phone] invalido');
})->with([
    '3-3-3' => ['612 345 678'],
    'compacto' => ['612345678'],
    '2-3-2-2' => ['91 234 56 78'],
    '3-2-2-2' => ['912 34 56 78'],
    '2-2-2-2-1' => ['61 23 45 67 8'],
    'con puntos' => ['612.345.678'],
    'con prefijo +34' => ['+34 612 345 678'],
    'con prefijo 0034' => ['0034612345678'],
    'britanico' => ['+44 20 7946 0958'],
    'frances' => ['+33 1 23 45 67 89'],
])->group('RF-PD-15', 'RL-19');

it('sustituye un correo con tildes o en otra escritura', function (string $correo): void {
    expect(ErrorMessageSanitizer::redact('aviso a '.$correo.' rechazado'))->toBe('aviso a [email] rechazado');
})->with([
    'ascii' => ['rosa.ficticiana@hotel-ejemplo.es'],
    'con tildes y eñe' => ['josé.ñúñez@hotel-ejemplo.es'],
    'en mayusculas' => ['ROSA.FICTICIANA@HOTEL-EJEMPLO.ES'],
    'dominio con tilde' => ['rosa@hotél-ejemplo.es'],
])->group('RF-PD-15', 'RL-19');

it('sustituye lo que va entre cualquiera de las siete comillas', function (string $texto): void {
    expect(ErrorMessageSanitizer::redact('Employee '.$texto.' not found'))->toBe("Employee '…' not found");
})->with([
    'rectas dobles' => ['"Rosa Ficticiana"'],
    'rectas simples' => ["'Rosa Ficticiana'"],
    'angulares' => ['«Rosa Ficticiana»'],
    'inglesas dobles' => ['“Rosa Ficticiana”'],
    'inglesas simples' => ['‘Rosa Ficticiana’'],
    'alemanas' => ['„Rosa Ficticiana“'],
    'angulares simples' => ['‹Rosa Ficticiana›'],
    'invertidas' => ['`Rosa Ficticiana`'],
])->group('RF-PD-15', 'RL-19');

it('sustituye las direcciones IP v4 y v6', function (string $ip): void {
    expect(ErrorMessageSanitizer::redact('desde '.$ip.' rechazado'))->toBe('desde [ip] rechazado');
})->with([
    'v4' => ['192.168.1.10'],
    'v6 completa' => ['2001:0db8:85a3:0000:0000:8a2e:0370:7334'],
    'v6 abreviada' => ['fe80::1'],
    'v6 de bucle' => ['::1'],
])->group('RF-PD-15', 'RL-19');

it('sustituye una tarjeta de 16 cifras: no es un hexadecimal que proteger (H1)', function (string $tarjeta): void {
    expect(ErrorMessageSanitizer::sanitize('card '.$tarjeta.' rejected'))->toBe('card [n] rejected');
})->with([
    'compacta' => ['4111111111111111'],
    'con espacios' => ['4111 1111 1111 1111'],
    'con guiones' => ['4111-1111-1111-1111'],
])->group('RF-PD-15', 'RL-19');

it('conserva intactos los identificadores tecnicos protegidos', function (string $identificador): void {
    $texto = 'Connection refused for '.$identificador.' at line 12';

    expect(ErrorMessageSanitizer::sanitize($texto))->toBe($texto)
        ->and(ErrorMessageSanitizer::redactText($texto))->toBe($texto);
})->with([
    'uuid en minusculas' => ['0199a1f0-0000-7000-8000-000000000000'],
    'uuid en mayusculas' => ['0199A1F0-ABCD-7000-8000-00000000AB12'],
    'span de 16' => ['00f067aa0ba902b7'],
    'trace_id de 32' => ['a1b2c3d4e5f60718293a4b5c6d7e8f90'],
    'sha1 de 40' => ['da39a3ee5e6b4b0d3255bfef95601890afd80709'],
    'sha256 de 64' => ['e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'],
])->group('RF-PD-15', 'RL-19');

it('no protege un hexadecimal de otra longitud ni uno sin letras', function (string $hex): void {
    expect(ErrorMessageSanitizer::sanitize('id '.$hex))->toBe('id [n]');
})->with([
    'quince' => ['00f067aa0ba902b'],
    'diecisiete' => ['00f067aa0ba902b78'],
    'dieciseis cifras' => ['1234567890123456'],
])->group('RF-PD-15', 'RL-19');

it('conserva SQLSTATE aunque su codigo lleve letras', function (): void {
    expect(ErrorMessageSanitizer::sanitize('SQLSTATE[23P01]: Exclusion violation'))
        ->toBe('SQLSTATE[23P01]: Exclusion violation');
})->group('RF-PD-15');

it('convierte un payload de credencial en [secret] antes de proteger nada', function (): void {
    expect(ErrorMessageSanitizer::sanitize('tarjeta FH1.k1.0199a1f0-0000-7000-8000-000000000000.c2ln rechazada'))
        ->toBe('tarjeta [secret] rechazada');
})->group('RF-PD-15', 'RL-19');

it('no deja que un caracter de uso privado del texto se haga pasar por un identificador', function (): void {
    // El paso 2 usa U+E000… como marcadores. Uno que ya viniera en el texto se
    // quita antes, o al restaurar se cambiaria por un identificador ajeno.
    expect(ErrorMessageSanitizer::sanitize("error \u{E000} trace a1b2c3d4e5f60718293a4b5c6d7e8f90"))
        ->toBe('error trace a1b2c3d4e5f60718293a4b5c6d7e8f90');
})->group('RF-PD-15');

it('conserva el dia de una fecha escrita y quita el año', function (): void {
    expect(ErrorMessageSanitizer::sanitize('nacio el 15 de marzo de 1985'))->toBe('… el 15 de marzo de [n]');
})->group('RF-PD-15', 'RL-19');

it('quita matriculas y codigos postales', function (string $texto, string $esperado): void {
    expect(ErrorMessageSanitizer::sanitize($texto))->toBe($esperado);
})->with([
    'matricula nueva' => ['vehiculo 1234 BCD', '… [n] …'],
    'matricula antigua' => ['vehiculo M-1234-AB', '… M-[n]-…'],
    'codigo postal' => ['cp 28013', '… [n]'],
])->group('RF-PD-15', 'RL-19');

it('sustituye por [uuid] todo UUID cuando se pide (H2)', function (): void {
    expect(ErrorMessageSanitizer::withoutUuids(
        'Employee 0199a1f0-0000-7000-8000-000000000000 and 0199A1F0-ABCD-7000-8000-00000000AB12, trace a1b2c3d4e5f60718293a4b5c6d7e8f90',
    ))->toBe('Employee [uuid] and [uuid], trace a1b2c3d4e5f60718293a4b5c6d7e8f90')
        ->and(ErrorMessageSanitizer::sanitize('[uuid]'))->toBe('[uuid]');
})->group('RF-PD-15', 'RL-19');

it('redactText filtra por palabra y redact solo por patrones', function (): void {
    $texto = "Employee Rosa Ficticiana (rosa@hotel-ejemplo.es)\nline 2";

    expect(ErrorMessageSanitizer::redactText($texto))->toBe("Employee … ([email])\nline 2")
        ->and(ErrorMessageSanitizer::redact($texto))->toBe("Employee Rosa Ficticiana ([email])\nline 2");
})->group('RF-PD-15', 'RL-19');

it('es idempotente con la lista blanca', function (string $texto): void {
    $una = ErrorMessageSanitizer::sanitize($texto);
    $texto1 = ErrorMessageSanitizer::redactText($texto);

    expect(ErrorMessageSanitizer::sanitize($una))->toBe($una)
        ->and(ErrorMessageSanitizer::redactText($texto1))->toBe($texto1);
})->with([
    'de todo' => ['Rosa Ficticiana E7K2M9QX4B 45.678.912-K ES91 2100 0418 4502 0005 1332 "Ana" 14-03-2026 22:15 a@b.es'],
    'tecnico' => ["TypeError: Cannot read properties of undefined (reading 'x') at app.js:1:123456"],
    'con uuid y traza' => ['employee 0199a1f0-0000-7000-8000-000000000000 trace a1b2c3d4e5f60718293a4b5c6d7e8f90'],
    'marcadores' => ['[email] [iban] [id] [code] [phone] [time] [ip] [secret] [uuid] [n] (sin mensaje)'],
])->group('RF-PD-15', 'RL-19');

it('falla cerrado tambien en redactText', function (): void {
    expect(ErrorMessageSanitizer::redactText("Rosa Ficticiana \xC3\x28"))->toBe('');
})->group('RF-PD-15', 'RL-19');

it('trunca exactamente en el techo y deja el indicador en el ultimo caracter', function (): void {
    // 16 caracteres de `SQLSTATE[23505] ` y 983 de `ok ok …`: el corte cae
    // justo despues de un `ok` completo y el indicador ocupa el caracter mil.
    expect(ErrorMessageSanitizer::sanitize('SQLSTATE[23505] '.str_repeat('ok ', 600)))
        ->toBe('SQLSTATE[23505] '.str_repeat('ok ', 327).'ok…');
})->group('RF-PD-15');

it('exige diez cifras para llamar IBAN a lo que tiene su forma', function (): void {
    expect(ErrorMessageSanitizer::redact('cuenta ab12 cd34 ef56 gh78 ij90 fin'))->toBe('cuenta [iban] fin')
        ->and(ErrorMessageSanitizer::redact('cuenta ab12 cd34 ef56 gh78 ijk9 fin'))->toBe('cuenta ab12 cd34 ef56 gh78 ijk9 fin');
})->group('RF-PD-15', 'RL-19');

/*
 * ---------------------------------------------------------------------------
 * Revision del bloque 19: IBAN con forma de hexadecimal, horas pegadas, el
 * estado HTTP de axios e idempotencia con fragmentos pegados.
 * ---------------------------------------------------------------------------
 */

it('no protege como hexadecimal un IBAN compacto en minusculas', function (string $iban): void {
    // `be71096123456769` son dieciseis caracteres hexadecimales con letra y
    // cifra: la forma de un span. Sin la exclusion salia intacto.
    expect(ErrorMessageSanitizer::sanitize('cuenta '.$iban.' rechazada'))->toBe('cuenta [iban] rechazada');
})->with([
    'belga compacto en minusculas' => ['be71096123456769'],
    'belga compacto en mayusculas' => ['BE71096123456769'],
    'belga con guiones' => ['be71-0961-2345-6769'],
    'frances en minusculas' => ['fr7630006000011234567890189'],
    'frances con espacios' => ['FR76 3000 6000 0112 3456 7890 189'],
    'italiano en minusculas' => ['it60x0542811101000000123456'],
    'italiano con espacios' => ['IT60 X054 2811 1010 0000 0123 456'],
    'portugues con guiones' => ['pt50-0002-0123-1234-5678-9015-4'],
    'britanico en minusculas' => ['gb29nwbk60161331926819'],
])->group('RF-PD-15', 'RL-19');

it('sustituye las horas pegadas a una letra, con punto o con am y pm', function (string $texto, string $esperado): void {
    $una = ErrorMessageSanitizer::sanitize($texto);

    expect($una)->toBe($esperado)
        ->and(ErrorMessageSanitizer::sanitize($una))->toBe($una);
})->with([
    'iso sin fecha' => ['marca T22:00:00Z rechazada', 'marca [time] rechazada'],
    'con am' => ['entrada 10:30am rechazada', 'entrada [time] rechazada'],
    'con pm y espacio' => ['entrada 10:30 PM rechazada', 'entrada [time] rechazada'],
    'con punto tras a las' => ['fichó a las 22.30 sin cierre', 'fichó a las [time] sin cierre'],
    'con punto tras at' => ['recorded at 22.30', 'recorded at [time]'],
    'con segundos y fraccion' => ['at 06:00:00.123Z', 'at [time]'],
])->group('RF-PD-15', 'RL-19');

it('no confunde con una hora una posicion de fichero ni una version', function (string $texto): void {
    expect(ErrorMessageSanitizer::sanitize($texto))->toBe($texto);
})->with([
    'columna' => ['app.js:1:12'],
    'linea y columna de php' => ['Collector.php:12:34'],
    'version con punto' => ['version 13.2 build 7.30'],
])->group('RF-PD-15');

it('conserva el estado HTTP de axios y sigue quitando un codigo detras de code', function (string $texto, string $esperado): void {
    expect(ErrorMessageSanitizer::redact($texto))->toBe($esperado);
})->with([
    'axios' => ['Request failed with status code 500', 'Request failed with status code 500'],
    'salida de un proceso' => ['exit code 137', 'exit code 137'],
    'codigo con letra y cifra' => ['code AB12C3', 'code [code]'],
    'codigo numerico largo' => ['code 739104', 'code [code]'],
])->group('RF-PD-15', 'RL-19');

it('es idempotente aunque los fragmentos lleguen pegados', function (string $texto): void {
    /*
     * Propiedad sobre un corpus generado con semilla fija: fragmentos con
     * forma de dato pegados sin espacio, con espacio o con puntuacion. Un
     * patron que dependa de `\b` cambia de opinion en la segunda pasada,
     * cuando lo de al lado ya es un marcador.
     */
    $una = ErrorMessageSanitizer::sanitize($texto);
    $texto1 = ErrorMessageSanitizer::redactText($texto);
    $patrones = ErrorMessageSanitizer::redact($texto);

    expect(ErrorMessageSanitizer::sanitize($una))->toBe($una, 'sanitize: '.$texto)
        ->and(ErrorMessageSanitizer::redactText($texto1))->toBe($texto1, 'redactText: '.$texto)
        ->and(ErrorMessageSanitizer::redact($patrones))->toBe($patrones, 'redact: '.$texto);
})->with(static function (): iterable {
    mt_srand(20261003);

    $fragmentos = [
        '::1', 'Ana', '10.0.0.5', 'Failing row contains (1, Ana)', '22:15', 'T22:00:00Z', 'E7K2M9QX4B',
        '45678912K', 'x-7654321-l', 'a@b.es', 'line 1234', 'SQLSTATE[23505]', "'Rosa'", '«Luz»', '`x`',
        '0199a1f0-0000-7000-8000-000000000000', 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'be71096123456769',
        'ES91 2100 0418 4502 0005 1332', '+34 612 345 678', '1985', 'x7k2m9', 'Bearer abc.def', 'password=x',
        'FH1.k1.tok.sig', 'code 739104', '#739104', 'Key (a)=(b) already exists', '2026-10-03', '14.03.2026',
        '192.168.1.1', 'fe80::1', 'getUserMedia', 'TypeError', '10:30am', 'a las 22.30', 'status code 500',
        'NAF 28/12345678/40', 'PAA654321', 'k98765432', '4111-1111-1111-1111', 'app.js:1:12', '…', '[n]',
    ];
    $separadores = ['', ' ', '', '-', '.', ':', ', ', '/'];

    for ($i = 0; $i < 120; $i++) {
        $texto = '';

        for ($j = 0, $n = mt_rand(2, 5); $j < $n; $j++) {
            $texto .= ($j === 0 ? '' : $separadores[mt_rand(0, \count($separadores) - 1)])
                .$fragmentos[mt_rand(0, \count($fragmentos) - 1)];
        }

        yield 'pegado '.$i => [$texto];
    }
})->group('RF-PD-15', 'RL-19');
