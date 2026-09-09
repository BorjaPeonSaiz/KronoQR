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
