<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
 * `php artisan backup:wal-metrics`: el puente delgado entre el planificador y
 * `infra/scripts/wal-metrics.sh` (2.2.0, bloque 20, R5-DV-01; RNF-D-02).
 *
 * Lo que se prueba es lo unico que aporta el comando: que ejecuta ESE script,
 * sin argumentos, con BACKUP_PATH de la configuracion, y que devuelve su codigo
 * de salida sin traducirlo. El calculo lo prueba `WalMetricsScriptTest`.
 */

/**
 * Un directorio de scripts falso con un `wal-metrics.sh` que deja constancia de
 * como se le llamo y sale con `$exitCode`.
 */
function walMetricsCommandScripts(int $exitCode): string
{
    $dir = sys_get_temp_dir().'/kq-wal-metrics-command-'.bin2hex(random_bytes(4));
    mkdir($dir, 0o700, true);
    file_put_contents($dir.'/wal-metrics.sh', <<<BASH
        #!/usr/bin/env bash
        printf 'args=%s|backup_path=%s\\n' "\$*" "\${BACKUP_PATH:-}" >"{$dir}/llamada.log"
        echo "medida del WAL"
        exit {$exitCode}
        BASH);

    return $dir;
}

it('ejecuta wal-metrics.sh sin argumentos y con el BACKUP_PATH de la configuracion', function (): void {
    $scripts = walMetricsCommandScripts(0);
    config(['backup.script_path' => $scripts, 'backup.path' => '/var/backups/fichaje-de-prueba']);

    $code = Artisan::call('backup:wal-metrics');

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('medida del WAL')
        ->and((string) file_get_contents($scripts.'/llamada.log'))->toBe("args=|backup_path=/var/backups/fichaje-de-prueba\n");
})->group('RNF-D-02');

it('devuelve el codigo del script tal cual: un fallo lo avisa la alerta, no el planificador', function (int $exitCode): void {
    config(['backup.script_path' => walMetricsCommandScripts($exitCode)]);

    expect(Artisan::call('backup:wal-metrics'))->toBe($exitCode);
})->with([2, 7])->group('RNF-D-02');

it('sin el script dice donde buscarlo y falla', function (): void {
    config(['backup.script_path' => sys_get_temp_dir().'/kq-no-existe-'.bin2hex(random_bytes(4))]);

    expect(Artisan::call('backup:wal-metrics'))->toBe(1)
        ->and(Artisan::output())->toContain('BACKUP_SCRIPT_PATH');
})->group('RNF-D-02');
