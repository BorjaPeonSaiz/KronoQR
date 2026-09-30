<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * El runtime no tiene ninguna credencial que pueda alterar el registro
 * (ADR-042, hallazgo AUD-1 de la 2.1.0, regla dura 6).
 *
 * DE DONDE SALE. Hasta la 2.1.0, `ComplianceServiceProvider` resolvia la
 * conexion del rol de migracion —SUPERUSER— para crear la particion anual de
 * `audit_log`. Con esa credencial en el runtime, quien ejecutara codigo PHP
 * podia reescribir el registro y recalcular la cadena. Desde ADR-042 la
 * particion la crea una funcion `SECURITY DEFINER` invocada con el rol de la
 * aplicacion, y la conexion del migrador solo la usan las migraciones y las
 * pruebas.
 *
 * Lo que garantiza que nadie la vuelva a nombrar desde `app/` no es el docblock
 * de `config/database.php`: es esta prueba. Mira tambien los comentarios, a
 * proposito: un comentario que nombra la conexion es la mitad de una llamada, y
 * el coste de reformularlo es nulo.
 *
 * La otra mitad —que ningun servicio de runtime de `compose.prod.yaml` reciba
 * `DB_MIGRATION_*`— la cubre la parte de Compose de ADR-042 (`qa-testing` con
 * `devops-observabilidad`). La comprobacion de ejecucion —que el comando de
 * particiones funciona con la conexion del migrador inutilizada y sin abrirla—
 * esta en `tests/Integration/Compliance/EnsureAuditPartitionsCommandTest.php`.
 *
 * Se recorre con `ModuleTree::phpFilesUnder()`, que usa `scandir`: el iterador
 * recursivo pierde ficheros sobre el bind mount de Docker Desktop y daria verde
 * sin haber mirado.
 */

/** Lo que ningun fichero de `backend/app` puede contener (ADR-042 §5). */
const RUNTIME_DATABASE_CREDENTIALS_PROHIBIDOS = [
    'pgsql_migrator',
    'database.migrations.connection',
    'DB_MIGRATION',
];

it('ningun fichero de backend/app nombra la conexion del migrador', function (): void {
    $app = \dirname(ModuleTree::root());
    $files = ModuleTree::phpFilesUnder($app);

    // Sin esto, la prueba pasaria en verde recorriendo un directorio vacio. El
    // suelo no mide nada: solo detecta que el recorrido se ha roto.
    expect(\count($files))->toBeGreaterThan(500);

    $offenders = [];

    foreach ($files as $file) {
        $contents = (string) file_get_contents($file);

        foreach (RUNTIME_DATABASE_CREDENTIALS_PROHIBIDOS as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = ModuleTree::relative($file, $app).' nombra «'.$needle.'»';
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'El codigo de la aplicacion nombra la conexion o la credencial del rol de migracion, que es '
        .'SUPERUSER. ADR-042: ningun proceso del runtime puede tener una credencial capaz de alterar el '
        .'registro. Si hace falta DDL en runtime, se delega en una funcion SECURITY DEFINER estrecha, como '
        .'audit_log_create_partition. Ficheros: '.implode(', ', $offenders)
    );
})->group('RS-07', 'RS-08');
