<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\BackupOffsiteProbe;
use App\Modules\Product\Infrastructure\Diagnostics\TextfileMetricsReader;
use Tests\Architecture\Support\Repo;
use Tests\Support\Shared\GeneratedFilesSandbox;

/*
 * Sonda `backup.offsite` de `product:doctor` (V5-DV-05; RF-PD-13, RF-PD-15,
 * RL-12, RL-14).
 *
 * La promesa de perder como mucho 15 minutos vale «siempre que las copias y el
 * registro continuo de cambios salgan del servidor» (doc 05). `backup.sh` mide si
 * BACKUP_PATH y su `wal/` comparten dispositivo con los datos (o son de red) y lo
 * publica en `kronoqr_backup_run.prom`; la sonda lo lee. Aqui se simulan esas
 * metricas con ficheros `.prom` de verdad, con el formato exacto que escribe
 * `emit_offsite_metrics` de `lib/backup-common.sh`.
 *
 * Es un AVISO, nunca un fallo: el cliente puede sacar las copias por su cuenta.
 */

afterEach(function (): void {
    GeneratedFilesSandbox::cleanUp();
});

/** El fichero de resultado de la copia, con las dos series de lib/backup-common.sh. */
function copiasFueraDelServidorFichero(string $destino, string $wal): string
{
    return "# HELP kronoqr_backup_last_result Resultado de la ultima copia: 1 correcta, 0 fallida.\n"
        ."# TYPE kronoqr_backup_last_result gauge\n"
        ."kronoqr_backup_last_result{type=\"dump\"} 1\n"
        ."# HELP kronoqr_backup_destination_offsite Las copias salen del servidor.\n"
        ."# TYPE kronoqr_backup_destination_offsite gauge\n"
        ."kronoqr_backup_destination_offsite {$destino}\n"
        ."# HELP kronoqr_backup_wal_offsite El archivo del WAL sale del servidor.\n"
        ."# TYPE kronoqr_backup_wal_offsite gauge\n"
        ."kronoqr_backup_wal_offsite {$wal}\n";
}

/** @param  array<string, string>  $ficheros */
function copiasFueraDelServidorSonda(array $ficheros): DoctorFinding
{
    $dir = GeneratedFilesSandbox::directory('metrics');

    foreach ($ficheros as $nombre => $contenido) {
        GeneratedFilesSandbox::file($dir.'/'.$nombre, $contenido);
    }

    $findings = new BackupOffsiteProbe(new TextfileMetricsReader($dir), '03:15')->run();

    expect($findings)->toHaveCount(1);

    return $findings[0];
}

function copiasFueraDelServidorTexto(DoctorFinding $finding, string $locale = 'es'): string
{
    $text = __($finding->messageKey(), $finding->params, $locale);

    expect($text)->toBeString()->and($text)->not->toStartWith('doctor.checks.');

    return (string) $text;
}

function copiasFueraDelServidorArreglo(DoctorFinding $finding, string $locale = 'es'): string
{
    $key = $finding->fixKey();

    expect($key)->not->toBeNull();

    $text = __((string) $key, $finding->params, $locale);

    expect($text)->toBeString()->and($text)->not->toStartWith('doctor.fixes.');

    return (string) $text;
}

it('da por buenas las copias que salen del servidor', function (): void {
    $finding = copiasFueraDelServidorSonda([
        BackupOffsiteProbe::RUN_FILE => copiasFueraDelServidorFichero('1', '1'),
    ]);

    expect($finding->id)->toBe('backup.offsite')
        ->and($finding->status)->toBe(DoctorStatus::Ok)
        ->and($finding->fixKey())->toBeNull();
})->group('RF-PD-13', 'RF-PD-15', 'RL-14');

it('avisa, sin fallar, cuando las copias estan en el mismo disco que los datos', function (): void {
    $finding = copiasFueraDelServidorSonda([
        BackupOffsiteProbe::RUN_FILE => copiasFueraDelServidorFichero('0', '0'),
    ]);

    expect($finding->status)->toBe(DoctorStatus::Warning)
        ->and($finding->variant)->toBe('same_disk')
        ->and(copiasFueraDelServidorTexto($finding))->toContain('mismo disco que los datos')
        ->and(copiasFueraDelServidorArreglo($finding))->toContain('otro disco o en un recurso de red')
        ->and(copiasFueraDelServidorArreglo($finding))->toContain('Las copias tienen que salir del servidor');
})->group('RF-PD-13', 'RF-PD-15', 'RL-12', 'RL-14');

it('avisa del WAL aunque las copias diarias si salgan', function (): void {
    $finding = copiasFueraDelServidorSonda([
        BackupOffsiteProbe::RUN_FILE => copiasFueraDelServidorFichero('1', '0'),
    ]);

    expect($finding->status)->toBe(DoctorStatus::Warning)
        ->and($finding->variant)->toBe('wal_same_disk')
        ->and(copiasFueraDelServidorTexto($finding))->toContain('WAL')->toContain('15 minutos');
})->group('RF-PD-13', 'RL-12', 'RL-14');

it('nunca es un fallo: ninguna combinacion de medidas sale en rojo', function (string $destino, string $wal): void {
    $finding = copiasFueraDelServidorSonda([
        BackupOffsiteProbe::RUN_FILE => copiasFueraDelServidorFichero($destino, $wal),
    ]);

    expect($finding->status)->not->toBe(DoctorStatus::Failure);
})->with([
    'ambas fuera' => ['1', '1'],
    'ambas en el mismo disco' => ['0', '0'],
    'solo el WAL en el mismo disco' => ['1', '0'],
    'ambas sin medir' => ['-1', '-1'],
    'una sin medir' => ['1', '-1'],
])->group('RF-PD-13', 'RF-PD-15');

it('dice que no consta cuando la medida no se pudo hacer o aun no existe', function (array $ficheros): void {
    $finding = copiasFueraDelServidorSonda($ficheros);

    expect($finding->status)->toBe(DoctorStatus::Warning)
        ->and($finding->variant)->toBe('unknown')
        ->and(copiasFueraDelServidorTexto($finding))->toContain('03:15 UTC')
        ->and(copiasFueraDelServidorArreglo($finding))->toContain('sudo ./doctor.sh');
})->with([
    'sin fichero de resultado' => [[]],
    'copia anterior a la 2.2.1, sin la serie' => [[BackupOffsiteProbe::RUN_FILE => "kronoqr_backup_last_result{type=\"dump\"} 1\n"]],
    'no se pudo medir' => [[BackupOffsiteProbe::RUN_FILE => copiasFueraDelServidorFichero('-1', '-1')]],
])->group('RF-PD-13', 'RF-PD-15');

it('tiene texto en espanol y en ingles para cada resultado', function (): void {
    $casos = ['same_disk' => ['0', '0'], 'wal_same_disk' => ['1', '0'], 'unknown' => ['-1', '-1']];

    foreach ($casos as $variant => [$destino, $wal]) {
        $finding = copiasFueraDelServidorSonda([
            BackupOffsiteProbe::RUN_FILE => copiasFueraDelServidorFichero($destino, $wal),
        ]);

        expect($finding->variant)->toBe($variant)
            ->and(copiasFueraDelServidorTexto($finding, 'en'))->not->toBe(copiasFueraDelServidorTexto($finding, 'es'))
            ->and(copiasFueraDelServidorArreglo($finding, 'en'))->toContain('operation.md');
    }

    $ok = copiasFueraDelServidorSonda([BackupOffsiteProbe::RUN_FILE => copiasFueraDelServidorFichero('1', '1')]);

    expect(copiasFueraDelServidorTexto($ok, 'en'))->not->toBe(copiasFueraDelServidorTexto($ok, 'es'));
})->group('RF-PD-13', 'RF-PD-15');

it('lee las mismas series que publica backup.sh, en el mismo fichero que el resultado de la copia', function (): void {
    $lib = (string) file_get_contents(Repo::file('infra/scripts/lib/backup-common.sh'));
    $backup = (string) file_get_contents(Repo::file('infra/scripts/backup.sh'));

    expect($lib)->toContain(BackupOffsiteProbe::DESTINATION_METRIC.' ${dest}')
        ->and($lib)->toContain(BackupOffsiteProbe::WAL_METRIC.' ${wal}')
        ->and($backup)->toMatch('/emit_offsite_metrics\n\s*\} \| write_metrics "\$\{BACKUP_DIR_METRICS\}\/'.preg_quote(BackupOffsiteProbe::RUN_FILE, '/').'"/');
})->group('RF-PD-13', 'RL-14');
