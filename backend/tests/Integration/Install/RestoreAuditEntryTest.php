<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * El asiento de auditoria de `restore.sh` (PR1, regla dura 6, RL-04, AUD-1).
 *
 * Se ejecuta el script de verdad —cargado con `source`, sin restaurar nada— con
 * un `php` falso en el PATH que registra como se le llama. Lo que se prueba:
 * que el asiento se escribe con el rol de migracion que el servicio ya tiene
 * (nunca uno nuevo), que lleva la punta de la cadena descartada, que un fallo
 * NO deshace nada y deja el asiento listo para escribir a mano, y que solo se
 * escribe cuando la base de destino es la de la instalacion.
 */

const RESTORE_AUDIT_ENTRY_PHP_FALSO = <<<'BASH'
#!/usr/bin/env bash
{
  echo "ARGS: $*"
  echo "CONN=${DB_CONNECTION:-} DB=${DB_DATABASE:-} USER=${DB_MIGRATION_USERNAME:-}"
} >>"${STUB_DIR}/php.log"
case "$2" in
compliance:audit-chain-head)
  printf '{"hash":"%s","last_entry_id":7}\n' "$(printf 'b%.0s' $(seq 64))"
  ;;
compliance:record-system-event)
  cat >"${STUB_DIR}/payload.json"
  if [ "${STUB_FAIL:-0}" = 1 ]; then
    echo "No se pudo escribir el asiento: la base no responde"
    exit 2
  fi
  echo '{"id":9}'
  ;;
esac
BASH;

/**
 * @param  array<string, string>  $env
 * @return array{0: string, 1: Process}
 */
function restoreAuditScenario(string $body, array $env = []): array
{
    $dir = sys_get_temp_dir().'/kq-restore-audit-'.bin2hex(random_bytes(4));
    mkdir($dir.'/bin', 0777, true);
    mkdir($dir.'/app', 0777, true);
    file_put_contents($dir.'/bin/php', RESTORE_AUDIT_ENTRY_PHP_FALSO);
    chmod($dir.'/bin/php', 0755);
    touch($dir.'/app/artisan');
    file_put_contents($dir.'/app/VERSION', "2.2.0\n");
    file_put_contents($dir.'/restore-20260930T020000Z.log', '');

    $script = 'set -euo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/restore.sh')).'; '
        .'ARTISAN_DIR='.escapeshellarg($dir.'/app').'; PGHOST=db; PGPORT=5432; PGUSER=fichaje_migrator; PGPASSWORD=secreto-de-prueba; '
        .'FICHERO='.escapeshellarg($dir.'/kronoqr-20260930T010203Z.dump.enc').'; BASE_DESTINO=fichaje; PGDATABASE=fichaje; '
        .'INFORME='.escapeshellarg($dir.'/restore-20260930T020000Z.log').'; '.$body;

    $process = new Process(['bash', '-c', $script], env: array_merge([
        'PATH' => $dir.'/bin:'.getenv('PATH'),
        'STUB_DIR' => $dir,
        'KRONOQR_LANG' => 'es',
    ], $env), timeout: 60.0);
    $process->run();

    return [$dir, $process];
}

it('escribe system.restored_from_backup con el rol de migracion del servicio y la punta descartada', function (): void {
    [$dir, $process] = restoreAuditScenario('CADENA_DESCARTADA="$(punta_cadena_descartada)"; escribir_asiento; echo "pendiente=${ASIENTO_PENDIENTE}"');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('pendiente=0');

    $llamadas = (string) file_get_contents($dir.'/php.log');
    expect($llamadas)
        ->toContain('ARGS: artisan compliance:record-system-event system.restored_from_backup --data=-')
        ->toContain('CONN=pgsql_migrator DB=fichaje USER=fichaje_migrator');

    /** @var array<string, string> $payload */
    $payload = json_decode((string) file_get_contents($dir.'/payload.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'backup_file' => 'kronoqr-20260930T010203Z.dump.enc',
        'backup_taken_at' => '2026-09-30T01:02:03Z',
        'from_version' => '2.2.0',
        'to_version' => '2.2.0',
        'chain_before' => str_repeat('b', 64),
        'report_id' => 'restore-20260930T020000Z',
    ]);

    // Ni la contraseña ni una ruta absoluta entran en el asiento ni en el informe (reglas 16 y 21).
    $serialized = (string) json_encode($payload);

    expect($serialized)->not->toContain('secreto-de-prueba')
        ->and($serialized)->not->toContain('/tmp');
    expect((string) file_get_contents($dir.'/restore-20260930T020000Z.log'))->not->toContain('secreto-de-prueba');
})->group('RL-04', 'RS-07', 'RF-PR-04');

it('un asiento que falla no deshace nada: queda pendiente con el JSON y la orden para escribirlo', function (): void {
    [$dir, $process] = restoreAuditScenario('escribir_asiento; echo "pendiente=${ASIENTO_PENDIENTE}"', ['STUB_FAIL' => '1']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('pendiente=1');

    $informe = (string) file_get_contents($dir.'/restore-20260930T020000Z.log');

    expect($informe)
        ->toContain('ASIENTO PENDIENTE')
        ->toContain('"reason":"manual_restore"')
        ->toContain('-e DB_CONNECTION=pgsql_migrator migrate php artisan compliance:record-system-event system.restored_from_backup');
})->group('RL-04', 'RS-07', 'RF-PR-04');

it('solo deja asiento si la base de destino es la de la instalacion y nadie mas se encarga', function (): void {
    [, $misma] = restoreAuditScenario('decidir_asiento; echo "auditar=${AUDITAR}"');
    [, $otra] = restoreAuditScenario('BASE_DESTINO=fichaje_destino; decidir_asiento; echo "auditar=${AUDITAR}"');
    [, $llamador] = restoreAuditScenario('ASIENTO_POR_LLAMADOR=1; decidir_asiento; echo "auditar=${AUDITAR}"');

    expect($misma->getOutput())->toContain('auditar=1')
        ->and($otra->getOutput())->toContain('auditar=0')
        ->and($llamador->getOutput())->toContain('auditar=0');
})->group('RL-04', 'RF-PR-04');

it('se niega a empezar, sin tocar nada, si no puede escribir el asiento (salida 2)', function (): void {
    [, $process] = restoreAuditScenario('AUDITAR=1; ARTISAN_DIR=/no/existe; comprobar_asiento_posible');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('servicio');
})->group('RL-04', 'RF-PR-04');

it('el mensaje de asiento pendiente sale en ingles con KRONOQR_LANG/KQ_LANG=en', function (): void {
    [, $process] = restoreAuditScenario('AUDITAR=1; ARTISAN_DIR=/no/existe; comprobar_asiento_posible', ['KQ_LANG' => 'en']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('audit entry cannot be written');
})->group('RL-04', 'RF-PR-04');

it('el cableado: el actualizador no duplica el asiento, el servicio restore arranca artisan y las guias lo explican', function (): void {
    $actualizador = Repo::contents('infra/scripts/update.sh');
    $restore = Repo::contents('infra/scripts/restore.sh');

    expect($actualizador)->toContain('restore.sh" --file "${BACKUP_FILE}" --yes --audit-by-caller');

    // El asiento se escribe con la credencial que restore ya tiene, nunca con la de la aplicacion.
    expect($restore)->toContain('DB_CONNECTION=pgsql_migrator')
        ->and($restore)->not->toContain('DB_PASSWORD=')
        ->and($restore)->toContain('KQ_EXIT_VERIFY_FAILED');

    // El intercambio de bases va ANTES del asiento, y el asiento antes de purgar.
    $intercambio = strpos($restore, 'ALTER DATABASE \"${base_nueva}\" RENAME TO');
    $asiento = strpos($restore, "    escribir_asiento\n");
    $purga = strrpos($restore, "  purgar_bases_anteriores\n}");
    expect($intercambio)->not->toBeFalse()->and($asiento)->not->toBeFalse()->and($purga)->not->toBeFalse();
    expect((int) $intercambio)->toBeLessThan((int) $asiento)->and((int) $asiento)->toBeLessThan((int) $purga);

    expect(Repo::contents('infra/scripts/lib/exit-codes.sh'))->toContain('ASIENTO PENDIENTE');
    expect(Repo::contents('docs/runbooks/restaurar-backup.md'))->toContain('### 6.7')->toContain('system.restored_from_backup');
    expect(Repo::contents('docs/cliente/operacion.md'))->toContain('system.restored_from_backup');
})->group('RL-04', 'RS-07', 'RF-PR-04');
