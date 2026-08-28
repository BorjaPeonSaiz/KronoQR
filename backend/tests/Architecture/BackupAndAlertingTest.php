<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\Repo;

/*
 * Copias verificadas y sus alertas, comprobadas como configuracion
 * (RF-PR-04, RNF-D-02, RNF-D-05, RQ-09, RL-12).
 *
 * Que comprueba y que NO. Que la copia se hace, se cifra y se restaura lo
 * ejecuta de verdad `.github/workflows/backup-drill.yml`, con PostgreSQL 17 y
 * un contenedor limpio: eso necesita un servidor de base de datos y no cabe en
 * la etapa de pruebas unitarias. Lo que se comprueba AQUI es lo que se erosiona
 * sin que nadie se entere y deja el simulacro sin valor:
 *
 *   · que el archivado de WAL sigue configurado con el `archive_timeout` del
 *     que depende el RPO de 15 minutos —basta que alguien lo quite en un
 *     ajuste de rendimiento para que la promesa deje de ser cierta, y nada
 *     fallaria hasta el dia de la restauracion—;
 *   · que cada alerta sigue teniendo umbral, severidad, destinatario y un
 *     runbook QUE EXISTE (doc 02 §8.4: una alerta sin procedimiento es ruido);
 *   · que el simulacro sigue estando automatizado con cadencia trimestral
 *     (RNF-D-05), y no convertido en algo que alguien deberia lanzar a mano.
 */

function backupFile(string $relative): string
{
    expect(is_file(Repo::file($relative)))->toBeTrue($relative.' no existe en el repositorio.');

    return Repo::contents($relative);
}

/**
 * Las reglas de alerta, ya en la forma que espera Prometheus.
 *
 * @return list<array{alert?: string, expr?: string, for?: string, labels?: array<string, string>, annotations?: array<string, string>}>
 */
function reglasDeAlerta(string $relative): array
{
    /** @var array{groups: list<array{rules?: list<array<string, mixed>>}>} $documento */
    $documento = Yaml::parse(backupFile($relative));
    expect($documento)->toBeArray()->toHaveKey('groups');

    $reglas = [];
    foreach ($documento['groups'] as $grupo) {
        foreach ($grupo['rules'] ?? [] as $regla) {
            /** @var array{alert?: string, expr?: string, for?: string, labels?: array<string, string>, annotations?: array<string, string>} $regla */
            $reglas[] = $regla;
        }
    }

    return $reglas;
}

/**
 * La norma del doc 02 §8.4 aplicada a un fichero de reglas: **cada** alerta
 * lleva umbral, severidad, destinatario y enlace a un runbook QUE EXISTE.
 *
 * Esta comun a los dos ficheros de reglas —copias y auditoria— y no duplicada en
 * cada prueba: la norma es una, y una comprobacion copiada es una comprobacion
 * que se corrige en un sitio y se queda vieja en el otro.
 *
 * @param  list<array{alert?: string, expr?: string, for?: string, labels?: array<string, string>, annotations?: array<string, string>}>  $reglas
 */
function exigeProcedimientoEnCadaAlerta(array $reglas): void
{
    foreach ($reglas as $regla) {
        $nombre = $regla['alert'] ?? '(sin nombre)';

        expect($regla)->toHaveKeys(['expr', 'for', 'labels', 'annotations'], $nombre.' esta incompleta.');
        expect($regla['labels'] ?? [])->toHaveKeys(['severity', 'destinatario'], $nombre.' no dice a quien avisa.');
        expect($regla['annotations'] ?? [])->toHaveKeys(['summary', 'description', 'runbook_url'], $nombre.' no explica que hacer.');

        $runbook = $regla['annotations']['runbook_url'] ?? '';
        expect(is_file(Repo::file($runbook)))->toBeTrue(
            $nombre.' apunta al runbook "'.$runbook.'", que no existe. Escribelo antes de crear la alerta.'
        );
    }
}

it('archiva el WAL con el intervalo del que depende el RPO de 15 minutos', function (): void {
    // RNF-D-02. `archive_timeout=900` obliga a cerrar y archivar un segmento
    // cada 15 min aunque no haya trafico. Sin el, un hotel tranquilo de
    // madrugada tendria horas de escrituras sin archivar y el RPO real seria
    // "lo que tarde en llenarse un segmento de 16 MiB".
    $prod = backupFile('infra/compose.prod.yaml');

    expect($prod)->toContain('archive_mode=on');
    expect($prod)->toContain('archive_timeout=900');
    expect($prod)->toContain('archive_command=/usr/local/bin/kronoqr-archive-wal %p %f');
    expect($prod)->toContain('wal_level=replica');
})->group('RNF-D-02');

it('admite conexiones de replicacion, sin las que no hay copia fisica', function (): void {
    // RNF-D-02, la otra mitad. El WAL archivado solo reconstruye algo sobre una
    // copia FISICA (pg_basebackup), y pg_basebackup necesita una conexion de
    // replicacion. El pg_hba que genera initdb solo la admite desde el propio
    // equipo: sin este fichero declarativo, la copia fisica falla el dia de la
    // instalacion y nadie lo nota hasta que hace falta.
    $hba = backupFile('infra/docker/postgres/conf/pg_hba.conf');

    expect($hba)->toMatch('/^host\s+replication\s+all\s+all\s+scram-sha-256\s*$/m');
    // Ni una linea `trust` por TCP: dentro de la red de contenedores tambien se
    // exige contraseña.
    expect($hba)->not->toMatch('/^host\s+\S+\s+\S+\s+\S+\s+trust\s*$/m');

    expect(backupFile('infra/compose.prod.yaml'))->toContain('hba_file=/etc/kronoqr/pg_hba.conf');
})->group('RNF-D-02');

it('cifra las copias y no guarda la clave en el repositorio', function (): void {
    // RL-12. El cifrado es de la biblioteca comun, y la clave llega por el
    // entorno: ni en argv (la veria `ps`), ni en un fichero temporal, ni
    // escrita en ningun sitio versionado.
    $lib = backupFile('infra/scripts/lib/backup-common.sh');

    expect($lib)->toContain('BACKUP_CIPHER="aes-256-cbc"');
    expect($lib)->toContain('pbkdf2');
    expect($lib)->toContain('BACKUP_PBKDF2_ITER=600000');
    // La clave nunca como argumento de openssl.
    expect($lib)->not->toContain('-pass pass:');

    // El .env.example declara el parametro y lo deja VACIO: install.sh lo
    // genera en el servidor del cliente y el fabricante no lo conoce (§7.7).
    expect(backupFile('.env.example'))->toMatch('/^BACKUP_ENCRYPTION_KEY=\s*$/m');
})->group('RL-12');

it('deja cada alerta con umbral, destinatario y un runbook que existe', function (): void {
    // Doc 02 §8.4 y doc 01 §9.3: cada alerta lleva destinatario, umbral y
    // enlace a su runbook. Una alerta sin procedimiento asociado es ruido y se
    // elimina. Esta prueba es la que impide que se cuele la siguiente.
    $reglas = reglasDeAlerta('infra/observability/prometheus/rules/backup.yml');

    exigeProcedimientoEnCadaAlerta($reglas);

    // La fila del catalogo del doc 01 §9.3 —«Copia de seguridad fallida o no
    // verificada»— necesita las dos mitades: la que falla y la que no se
    // verifica. Y la del silencio, que es la que descubre que el trabajo
    // programado no llego a ejecutarse.
    $nombres = array_column($reglas, 'alert');
    expect($nombres)->toContain('CopiaDeSeguridadFallida');
    expect($nombres)->toContain('CopiaDeSeguridadSinVerificar');
    expect($nombres)->toContain('CopiaDeSeguridadAusente');
    expect($nombres)->toContain('ArchivadoDeWalDetenido');
    expect(count($reglas))->toBeGreaterThanOrEqual(4);
})->group('RF-PR-04');

it('publica el resultado de la copia como metrica, no solo en un log', function (): void {
    // Doc 02 §8.2, seccion de respaldo. Sin metrica, un cron que falla en
    // silencio se descubre a fin de mes; con ella, salta una alerta. Las
    // escriben los scripts y las recoge Prometheus por el colector textfile.
    $backup = backupFile('infra/scripts/backup.sh');

    foreach ([
        'kronoqr_backup_last_result',
        'kronoqr_backup_last_success_timestamp_seconds',
        'kronoqr_backup_last_verify_result',
        'kronoqr_backup_last_verified_timestamp_seconds',
        'kronoqr_backup_wal_last_archived_age_seconds',
    ] as $metrica) {
        expect($backup)->toContain($metrica);
    }

    expect(backupFile('infra/scripts/restore-drill.sh'))
        ->toContain('kronoqr_backup_restore_drill_last_success_timestamp_seconds');

    expect(backupFile('infra/observability/prometheus/prometheus.yml'))
        ->toContain('job_name: kronoqr-backup')
        ->toContain('alertmanagers');
})->group('RF-PR-04');

it('deja la alerta de rotura de cadena sin umbral, dirigida a seguridad y con runbook', function (): void {
    // Doc 01 §9.3, fila «Rotura de la cadena de hash de auditoria | cualquiera |
    // Critica (seguridad)». Las dos mitades importan: sin umbral —«cualquiera»,
    // no una tendencia— y al RESPONSABLE DE SEGURIDAD, no al IT (ADR-010): la
    // respuesta no es reiniciar nada, es acotar el alcance de un acceso
    // indebido (RL-15).
    $reglas = reglasDeAlerta('infra/observability/prometheus/rules/audit.yml');

    exigeProcedimientoEnCadaAlerta($reglas);

    $porNombre = [];
    foreach ($reglas as $regla) {
        $porNombre[$regla['alert'] ?? ''] = $regla;
    }

    expect($porNombre)->toHaveKey('RoturaDeCadenaDeAuditoria');
    // El silencio: sin esta, apagar el comando diario seria la forma mas comoda
    // de que la alerta de rotura no volviera a sonar nunca.
    expect($porNombre)->toHaveKey('VerificacionDeAuditoriaAusente');
    // Sin particion, TODA accion auditable falla y con ella el fichaje.
    expect($porNombre)->toHaveKey('ParticionDeAuditoriaAusente');

    $rotura = $porNombre['RoturaDeCadenaDeAuditoria'];
    expect($rotura['expr'] ?? '')->toContain('audit_chain_verification_failures_total > 0');
    expect($rotura['labels']['severity'] ?? '')->toBe('critical');
    expect($rotura['labels']['destinatario'] ?? '')->toBe('seguridad');
})->group('RS-07');

it('dirige las alertas de ataque a credenciales a seguridad, con runbook y tecnica ATT&CK', function (): void {
    // OWASP A09 y doc 07 §5: un ataque de fuerza bruta contra el panel, el
    // portal o el PIN del quiosco tiene que sonar en el responsable de
    // seguridad, no en el IT, y cada alerta tiene que decir que tecnica del
    // adversario esta viendo (T1110) y donde esta el procedimiento. Sin esta
    // prueba, la puerta «cada alerta con runbook que existe» no cubria
    // `auth.yml`: solo escaneaba `backup.yml` y `audit.yml`.
    $reglas = reglasDeAlerta('infra/observability/prometheus/rules/auth.yml');

    exigeProcedimientoEnCadaAlerta($reglas);

    $porNombre = [];
    foreach ($reglas as $regla) {
        $porNombre[$regla['alert'] ?? ''] = $regla;
    }

    expect($porNombre)->toHaveKeys(['KronoqrAuthFailureBurst', 'KronoqrAuthLockouts', 'KronoqrAuthFailureSpike']);

    foreach ($porNombre as $nombre => $regla) {
        expect($regla['expr'] ?? '')->toContain('kronoqr_auth_attempts_total');
        expect($regla['labels']['destinatario'] ?? '')->toBe('seguridad');
        expect(implode(' ', $regla['annotations'] ?? []))->toMatch('/T1110/');
    }

    expect($porNombre['KronoqrAuthFailureSpike']['labels']['severity'] ?? '')->toBe('critical');
    expect($porNombre['KronoqrAuthLockouts']['expr'] ?? '')->toContain('outcome="lockout"');
})->group('RS-12');

it('verifica la cadena de auditoria a diario, que es lo que RS-07 exige', function (): void {
    // «La cadena se verifica a diario y cualquier rotura dispara alerta critica
    // en menos de 24 h» (doc 01 §9.3). Sin la programacion, la alerta existe y
    // no la dispara nadie: la cadencia ES el requisito.
    $scheduler = backupFile('backend/routes/console.php');

    expect($scheduler)
        ->toContain("Schedule::command('compliance:verify-audit-chain')")
        ->toContain("Schedule::command('compliance:ensure-audit-partitions')")
        ->toMatch('/compliance:verify-audit-chain\'\)\s*\n\s*->dailyAt\(/');
})->group('RS-07');

it('mantiene el simulacro de restauracion automatizado y trimestral', function (): void {
    // RNF-D-05 y RQ-09: prueba de restauracion automatizada y ejecutada al
    // menos trimestralmente. Que exista el script no basta; lo que se degrada
    // es la ejecucion, asi que se comprueba la automatizacion.
    $workflow = backupFile('.github/workflows/backup-drill.yml');

    // Dia 1 de enero, abril, julio y octubre: un trimestre.
    expect($workflow)->toContain('cron: "0 4 1 1,4,7,10 *"');
    expect($workflow)->toContain('restore-drill.sh');

    $drill = backupFile('infra/scripts/restore-drill.sh');
    // Las dos comprobaciones que dan sentido al simulacro.
    expect($drill)->toContain('comprobar_integridad_referencial');
    expect($drill)->toContain('comprobar_conteos');

    // Y el procedimiento para el servidor del cliente, que es donde estan las
    // copias que de verdad importan: el fabricante no accede a ellas (ADR-020).
    expect(backupFile('docs/runbooks/restaurar-backup.md'))
        ->toContain('restore-drill.sh')
        ->toContain('1,4,7,10');
})->group('RNF-D-05', 'RQ-09');
