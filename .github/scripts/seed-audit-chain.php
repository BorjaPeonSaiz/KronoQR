<?php

declare(strict_types=1);

/*
 * KronoQR — siembra N asientos de audit_log con una cadena de hash VALIDA (job `volumen` de
 * backup-drill.yml; V21-5-DV-3).
 *
 * NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI, dentro de la imagen de la
 * aplicacion (kronoqr/app:ci), con el esquema real ya migrado.
 *
 * POR QUE NO SQL. La formula del hash (doc 02 §7.4) y su forma canonica viven en el dominio
 * (`AuditChain`); duplicarla en SQL seria una segunda copia que podria divergir sin que nadie lo
 * notara. Aqui se usa la MISMA clase que escribe y que verifica la cadena, y se inserta por lotes
 * (la aplicacion escribe de uno en uno, bajo el candado global de ADR-010: 1 M asi tardaria horas).
 *
 * Uso (dentro del contenedor, con DB_HOST, DB_PORT, DB_DATABASE, DB_MIGRATION_USERNAME y
 * DB_MIGRATION_PASSWORD en el entorno):
 *
 *   php seed-audit-chain.php 1000000
 *
 * Continua desde la punta actual de la cadena (o desde la genesis si la tabla esta vacia). Los
 * instantes caen en el ano en curso, cuya particion crea la migracion.
 */

use App\Modules\Compliance\Domain\AuditChain;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;

require '/var/www/html/vendor/autoload.php';

$total = (int) ($argv[1] ?? 0);
if ($total < 1) {
    fwrite(STDERR, "uso: php seed-audit-chain.php <numero de asientos>\n");
    exit(2);
}

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_PORT') ?: '5432',
    getenv('DB_DATABASE') ?: 'fichaje',
);
$pdo = new PDO($dsn, (string) getenv('DB_MIGRATION_USERNAME'), (string) getenv('DB_MIGRATION_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$head = $pdo->query('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn();
$previous = is_string($head) ? $head : AuditChain::genesisHash();

// Hasta ~17 minutos de milisegundos para 1 M de asientos, acabando "ahora": dentro del ano en curso.
$end = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$batchSize = 1000;
$columns = '(occurred_at, actor_type, actor_id, action, subject_type, subject_id, payload, prev_hash, hash)';

for ($written = 0; $written < $total;) {
    $rows = [];
    $params = [];
    $count = min($batchSize, $total - $written);

    for ($i = 0; $i < $count; $i++) {
        $n = $written + $i;
        $instant = $end->modify('-'.($total - $n).' milliseconds');
        $draft = new AuditEntryDraft(
            $instant,
            AuditActor::device(1),
            AuditAction::ShiftEntryCreated,
            AuditSubject::of('shift_entry', $n + 1),
            AuditPayload::of(['seed' => $n]),
        );
        $entry = AuditChain::link($draft, $previous);
        $previous = $entry->hash;

        $rows[] = '(?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?)';
        array_push(
            $params,
            $instant->format('Y-m-d H:i:s.uP'),
            $draft->actor->type->value,
            $draft->actor->id,
            $draft->action->value,
            $draft->subject->type,
            $draft->subject->id,
            $draft->payload->encode(),
            $entry->previousHash,
            $entry->hash,
        );
    }

    $pdo->prepare('INSERT INTO audit_log '.$columns.' VALUES '.implode(', ', $rows))->execute($params);
    $written += $count;

    if ($written % 100000 === 0) {
        fwrite(STDOUT, "  {$written} / {$total}\n");
    }
}

fwrite(STDOUT, "{$total} asientos de audit_log sembrados con la cadena encadenada.\n");
