<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Compliance\Domain\ValueObject\AuditChainBreak;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan compliance:verify-audit-chain` — recorre `audit_log` y comprueba
 * que la cadena de hash sigue encajando (RS-07, ADR-010, doc 02 Anexo C).
 *
 * **Se ejecuta a diario** (`routes/console.php`). RS-07 pide deteccion en menos
 * de 24 h: la cadencia no es una preferencia de operacion, es el requisito.
 *
 * **Codigo de salida.** `0` si la cadena esta integra, `1` si hay cualquier
 * rotura. Sin gradacion y sin umbral: el catalogo del doc 01 §9.3 dice
 * «cualquiera». Una sola fila alterada invalida la afirmacion de que el registro
 * es detectablemente inalterable, que es lo unico que esta tabla aporta.
 *
 * **Un aviso que no es un fallo.** Si alguna fila lleva una accion que este
 * binario no tiene en su catalogo, se nombra como «accion desconocida» y el
 * codigo de salida **no cambia**: la cadena se verifica por hash, no por
 * catalogo. Pasa cuando una actualizacion se deshace y la version anterior
 * queda corriendo sobre una base en la que la siguiente ya escribio.
 *
 * **Que se publica y donde.** El detalle —que fila, que tipo de rotura, que
 * hash— va al log tecnico y a la salida del comando. La alerta la dispara la
 * metrica. Ni una cosa ni otra llevan nombres: identificadores y hashes (regla
 * dura 21). Lo que hay que hacer al verla esta en
 * `docs/runbooks/rotura-cadena-auditoria.md`.
 */
final class VerifyAuditChainCommand extends Command
{
    protected $signature = 'compliance:verify-audit-chain
        {--chunk=1000 : Filas por lote. Solo afecta a la memoria, no al resultado}';

    protected $description = 'Verifica la cadena de hash de audit_log y alerta ante cualquier rotura (RS-07)';

    public function handle(VerifyAuditChain $verify): int
    {
        $chunk = (int) $this->option('chunk');

        $result = $verify->handle($chunk > 0 ? $chunk : 1000);

        foreach ($result->sealedPurgeYears as $year) {
            // No es una rotura: es una purga de retencion que dejo su ancla
            // (ADR-027). Se informa para que quede en el log del dia, no para
            // que nadie haga nada.
            $this->line('Purga sellada reconocida: particion '.$year.' (ADR-027).');
        }

        foreach ($result->unknownActions as $action) {
            // Aviso, no hallazgo. Que el catalogo de esta version no reconozca
            // un nombre no dice nada de la integridad de la fila: el hash se
            // recalcula con la cadena literal y cuadra. Lo que si dice es que
            // esta base la escribio una version posterior a la que esta
            // corriendo -tipicamente tras una vuelta atras de `update.sh`-, y
            // eso conviene verlo en el log del dia.
            $this->warn(
                'Accion desconocida para esta version: '.$action
                .' — no es una rotura (el hash cuadra). Escrita por una version posterior; '
                .'ver docs/runbooks/rotura-cadena-auditoria.md §3.'
            );
        }

        if ($result->isIntact()) {
            $this->info('Cadena integra: '.$result->rowsVerified.' entradas verificadas.');

            return self::SUCCESS;
        }

        Log::critical('audit_chain_broken', [
            'rows_verified' => $result->rowsVerified,
            'failures' => $result->failureCount(),
            'breaks' => array_map(
                static fn (AuditChainBreak $break): array => [
                    'entry_id' => $break->entryId,
                    'kind' => $break->kind->value,
                    'expected_hash' => $break->expectedHash,
                    'actual_hash' => $break->actualHash,
                ],
                $result->breaks,
            ),
        ]);

        $this->error(
            'ROTURA DE LA CADENA DE AUDITORIA: '.$result->failureCount().' hallazgo(s) sobre '
            .$result->rowsVerified.' entradas.'
        );

        foreach ($result->breaks as $break) {
            $this->line('  '.$break->describe());
        }

        $this->error('Incidente de seguridad. Procedimiento: docs/runbooks/rotura-cadena-auditoria.md');

        return self::FAILURE;
    }
}
