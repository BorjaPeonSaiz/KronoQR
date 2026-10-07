<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * **LOS CANDADOS DE FILA DEL PRODUCTO, Y LA PREMISA SOBRE `users`**
 * (ADR-046 §1.1 punto 4, §2 y §6 puntos 9 y 10).
 *
 * 1. **Nada toma `FOR UPDATE` sobre `employees`.** Choca con el `FOR KEY SHARE`
 *    de cada fichaje, ausencia y tarjeta de esa persona: los dejaria esperando a
 *    la baja y abriria un ciclo con el registro de ausencias. La ficha se toma
 *    con `FOR NO KEY UPDATE`. Se comprueba con un inventario cerrado de los
 *    candados de fila explicitos de `app/`, cada uno con su tabla: uno nuevo
 *    obliga a decidir donde va en el orden de ADR-046 §1.
 *
 * 2. **Nada hace `UPDATE` de `users.uuid` o `users.email`, ni `DELETE` sobre
 *    `users`.** Las dos columnas tienen indice unico completo: escribirlas toma
 *    `FOR UPDATE` sobre la cuenta, que choca con el `FOR KEY SHARE` de la
 *    entrega del PIN (`pin_delivered_by_user_id`) con la cadena tomada. Si
 *    algun dia hace falta «cambiar el correo de una cuenta», ese caso de uso
 *    tiene que tomar su fila antes de la cadena (§1.1 punto 3) y esta prueba se
 *    actualiza con el.
 *
 * Sobre el TEXTO del codigo, sin comentarios. El detector se prueba aparte con
 * fragmentos que tienen que saltar y otros que no, para que «no encuentra nada»
 * signifique algo.
 */

/**
 * Los candados de fila explicitos de `app/`, por fichero relativo a
 * `app/Modules/`, con la tabla que bloquean.
 *
 * @var array<string, string>
 */
const EMPLOYEE_LOCK_DISCIPLINE_ROW_LOCKS = [
    'Attendance/Infrastructure/Projection/DatabaseDailyTotalsProjection.php' => 'daily_totals (FOR UPDATE OF d)',
    'Identity/Infrastructure/Adapter/SanctumDeviceTokenIssuer.php' => 'devices, antes de la cadena (§1.1 punto 4)',
    'Kiosk/Infrastructure/Persistence/DbDeviceRegistry.php' => 'devices, al emparejar',
    'Product/Infrastructure/Persistence/DatabaseErrorEventRepository.php' => 'error_events (FOR UPDATE OF e), al fundir grupos en la migracion de ADR-048',
    'Reporting/Infrastructure/Persistence/DatabaseReportExportRepository.php' => 'report_exports (FOR UPDATE OF e)',
    // No es de fila: `LockProvider::lock()` de la cache, que el detector lexico
    // no distingue de `Builder::lock()`. Ninguna tabla (ADR-050).
    'Shared/Infrastructure/Cache/CacheMutex.php' => 'ninguna: candado de la cache (SET NX en Redis, flock en disco)',
    'Workforce/Infrastructure/Persistence/EloquentAbsenceRepository.php' => 'absences',
    // ADR-046 §1.1 punto 3: el renombrado toma su fila ANTES de la cadena,
    // porque `name` esta en un indice unico completo.
    'Workforce/Infrastructure/Persistence/EloquentDepartmentRepository.php' => 'departments, antes de la cadena (§1.1 punto 3)',
];

/**
 * El codigo sin comentarios y con el espacio en blanco normalizado: un
 * comentario que explica por que NO se usa `FOR UPDATE` no es un candado.
 */
function codigoSinComentariosDeLosCandados(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= \is_array($token) ? $token[1] : $token;
    }

    return (string) preg_replace('/\s+/', ' ', $code);
}

/**
 * Los candados de fila explicitos que toma el codigo: `lockForUpdate()`,
 * `sharedLock()`, `->lock(...)` con algo distinto de `for no key update` o
 * `for key share`, y `FOR UPDATE` / `FOR SHARE` escritos en SQL.
 *
 * @return list<string>
 */
function candadosDeFilaEn(string $code): array
{
    preg_match_all(
        '/->lockForUpdate\(|->sharedLock\(|->lock\(\s*(?![\'"]for (?:no key update|key share)[\'"])[^)]*\)|(?<!no key )\bfor update\b|(?<!key )\bfor share\b/i',
        $code,
        $matches,
    );

    return $matches[0];
}

/**
 * Las escrituras que la premisa sobre `users` prohibe.
 *
 * @return list<string>
 */
function escriturasProhibidasSobreUsers(string $code): array
{
    $patterns = [
        '/\bupdate "?users"? set\b[^;]*?\b(?:uuid|email)\b/i',
        '/\bdelete from "?users"?\b/i',
        '/\btruncate\b[^;]*\busers\b/i',
        '/table\( ?[\'"]users[\'"] ?\)[^;]*->(?:delete|forceDelete|truncate)\(/i',
        '/table\( ?[\'"]users[\'"] ?\)[^;]*->(?:update|upsert|updateOrInsert)\([^;]*[\'"](?:uuid|email)[\'"]/i',
        '/\bUser::destroy\(/',
        '/\bUser::[^;]*->(?:delete|forceDelete)\(/',
        '/\bUser::[^;]*->(?:update|upsert|updateOrInsert)\([^;]*[\'"](?:uuid|email)[\'"]/',
        '/\$(?:user|account)\w*->(?:email|uuid) ?=(?!=)/i',
        '/\$(?:user|account)\w*->(?:fill|forceFill|update)\([^;]*[\'"](?:uuid|email)[\'"]/i',
        '/\$(?:user|account)\w*->(?:delete|forceDelete)\(/i',
    ];

    $found = [];

    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $code, $matches);
        $found = [...$found, ...$matches[0]];
    }

    return $found;
}

/**
 * @return array<string, string> Codigo sin comentarios de cada fichero de
 *                               `app/Modules/`, por ruta relativa.
 */
function codigoDeLosModulosParaLosCandados(): array
{
    /** @var array<string, string>|null $cached */
    static $cached = null;

    if (\is_array($cached)) {
        return $cached;
    }

    return $cached = codigoSinComentariosBajo(ModuleTree::root());
}

/**
 * @return array<string, string> Codigo sin comentarios de cada fichero bajo la
 *                               ruta, por ruta relativa a ella.
 */
function codigoSinComentariosBajo(string $root): array
{
    $code = [];

    foreach (ModuleTree::phpFilesUnder($root) as $file) {
        $code[ModuleTree::relative($file, $root)] = codigoSinComentariosDeLosCandados((string) file_get_contents($file));
    }

    return $code;
}

it('solo toman candados de fila explicitos los ficheros del inventario, y ninguno sobre employees', function (): void {
    $conCandado = array_keys(array_filter(
        codigoDeLosModulosParaLosCandados(),
        static fn (string $code): bool => candadosDeFilaEn($code) !== [],
    ));

    expect($conCandado)->toBe(array_keys(EMPLOYEE_LOCK_DISCIPLINE_ROW_LOCKS))
        ->and(array_filter(EMPLOYEE_LOCK_DISCIPLINE_ROW_LOCKS, static fn (string $tabla): bool => str_contains($tabla, 'employees')))
        ->toBe([]);
})->group('RN-14', 'RF-GP-03');

it('la ficha del empleado se lee para escribir con FOR NO KEY UPDATE', function (): void {
    $repositorio = codigoDeLosModulosParaLosCandados()['Workforce/Infrastructure/Persistence/EloquentEmployeeRepository.php'];

    expect($repositorio)->toContain("->lock('for no key update')")
        ->and(candadosDeFilaEn($repositorio))->toBe([]);
})->group('RN-14', 'RF-GP-03');

it('ningun fichero escribe users.uuid ni users.email ni borra cuentas de gestion', function (): void {
    // Todo `app/`, no solo los modulos: un comando de consola tambien cuenta.
    $escrituras = array_filter(array_map(
        escriturasProhibidasSobreUsers(...),
        codigoSinComentariosBajo(dirname(ModuleTree::root())),
    ));

    expect($escrituras)->toBe([]);
})->group('RF-ID-01', 'RN-14');

it('el detector de candados ve los prohibidos y deja pasar los de ADR-046', function (string $fragmento, int $candados): void {
    expect(candadosDeFilaEn(codigoSinComentariosDeLosCandados('<?php '.$fragmento)))->toHaveCount($candados);
})->with([
    'lockForUpdate' => ["Employee::query()->where('uuid', \$u)->lockForUpdate()->first();", 1],
    'sharedLock' => ["DB::table('employees')->sharedLock()->get();", 1],
    'lock con for update' => ["Employee::query()->lock('for update')->first();", 1],
    'FOR UPDATE en SQL' => ["DB::select('SELECT id FROM employees WHERE uuid = ? FOR UPDATE', [\$u]);", 1],
    'FOR NO KEY UPDATE' => ["Employee::query()->lock('for no key update')->first();", 0],
    'FOR NO KEY UPDATE en SQL' => ["DB::select('SELECT id FROM employees WHERE id = ? FOR NO KEY UPDATE SKIP LOCKED');", 0],
    'FOR KEY SHARE en SQL' => ["DB::select('SELECT id FROM sites ORDER BY id FOR KEY SHARE');", 0],
    'en un comentario' => ["// nunca lockForUpdate() ni FOR UPDATE aqui\n\$x = 1;", 0],
])->group('RN-14');

it('el detector de escrituras sobre users ve las prohibidas y deja pasar las de hoy', function (string $fragmento, int $escrituras): void {
    expect(escriturasProhibidasSobreUsers(codigoSinComentariosDeLosCandados('<?php '.$fragmento)))->toHaveCount($escrituras);
})->with([
    'cambiar el correo por el modelo' => ["User::query()->where('id', \$id)->update(['email' => \$nuevo]);", 1],
    'cambiar el correo en la instancia' => ["\$user->email = \$nuevo;\n\$user->save();", 1],
    'cambiar el uuid con fill' => ["\$user->fill(['uuid' => \$otro]);", 1],
    'borrar la cuenta' => ['$user->delete();', 1],
    'destroy' => ['User::destroy($id);', 1],
    'borrar por la tabla' => ["DB::table('users')->where('id', \$id)->delete();", 1],
    'UPDATE en SQL' => ["DB::update('UPDATE users SET email = ? WHERE id = ?', [\$e, \$id]);", 1],
    'DELETE en SQL' => ["DB::delete('DELETE FROM users WHERE id = ?', [\$id]);", 1],
    'desactivar la cuenta' => ["User::query()->where('uuid', \$uuid)->update(['is_active' => false]);", 0],
    'guardar la contrasena' => ["\$user->password = \$hash;\n\$user->save();", 0],
    'ultimo acceso' => ["User::query()->whereKey(\$id)->update(['last_login_at' => \$at]);", 0],
    'borrar un token' => ['$token->delete();', 0],
    'leer por correo' => ["User::query()->where('email', \$email)->first();", 0],
])->group('RF-ID-01');
