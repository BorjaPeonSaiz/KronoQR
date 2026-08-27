<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Port\AuditChainReader;
use App\Modules\Compliance\Application\Port\AuditMetrics;
use App\Modules\Compliance\Domain\AuditChain;
use App\Modules\Compliance\Domain\ValueObject\AuditChainBreak;
use App\Modules\Compliance\Domain\ValueObject\AuditChainBreakKind;
use App\Modules\Compliance\Domain\ValueObject\AuditChainVerification;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Recorre `audit_log` de principio a fin y comprueba que cada eslabon encaja
 * (RS-07, ADR-010, ADR-027).
 *
 * **Dos comprobaciones por fila, y hacen falta las dos.**
 *
 * 1. **El contenido produce su hash.** Se recalcula `hash_n` con la formula del
 *    §7.4 sobre los campos leidos y se compara con el `hash` escrito. Es lo que
 *    detecta que alguien cambio una hora, un actor o un payload por SQL directo.
 * 2. **El eslabon apunta a la fila anterior.** El `prev_hash` de cada fila tiene
 *    que ser el `hash` de la anterior. Es lo que detecta que se borro, se
 *    inserto o se reordeno algo.
 *
 * **El arranque de la cadena tiene tres desenlaces** (ADR-027). Si el
 * `prev_hash` de la primera fila es la genesis, todo normal. Si no lo es pero
 * coincide con el `last_hash` de un ancla, es una **purga sellada**: se informa
 * y la cadena continua. Si no es ninguna de las dos cosas, **es manipulacion** y
 * salta la alerta. Sin el segundo caso, la purga de retencion de RL-02 haria
 * sonar la alerta critica todos los dias de forma permanente, y una alerta que
 * suena siempre se silencia.
 *
 * **No para en la primera rotura.** Recorre hasta el final y devuelve todas: al
 * responder un incidente, saber si son tres filas seguidas o trescientas
 * repartidas es la mitad del diagnostico (RL-15).
 */
final readonly class VerifyAuditChain
{
    public function __construct(
        private AuditChainReader $reader,
        private AuditMetrics $metrics,
        private Clock $clock,
    ) {}

    public function handle(int $chunkSize = 1000): AuditChainVerification
    {
        $expectedPrevious = null;
        $sealedPurgeYears = [];
        $breaks = [];
        $rows = 0;

        foreach ($this->reader->inChainOrder($chunkSize) as $entry) {
            $rows++;

            if ($expectedPrevious === null) {
                // Primera fila viva de la tabla. Su `prev_hash` decide si
                // delante hubo una purga sellada, no hubo nada, o falta algo.
                $expectedPrevious = $this->resolveChainStart(
                    $entry->previousHash,
                    $entry->id ?? 0,
                    $sealedPurgeYears,
                    $breaks,
                );
            }

            if (! AuditChain::matches($expectedPrevious, $entry->previousHash)) {
                $breaks[] = new AuditChainBreak(
                    $entry->id ?? 0,
                    AuditChainBreakKind::BrokenLink,
                    $expectedPrevious,
                    $entry->previousHash,
                );
            }

            $recomputed = AuditChain::hashFor($entry->draft, $entry->previousHash);

            if (! AuditChain::matches($recomputed, $entry->hash)) {
                $breaks[] = new AuditChainBreak(
                    $entry->id ?? 0,
                    AuditChainBreakKind::ContentAltered,
                    $recomputed,
                    $entry->hash,
                );
            }

            // Se sigue con el hash ESCRITO y no con el recalculado: si la fila
            // esta alterada, continuar con el recalculado marcaria tambien como
            // rota la siguiente, que esta intacta. Una rotura, un hallazgo.
            $expectedPrevious = $entry->hash;
        }

        $result = new AuditChainVerification($rows, $breaks, $sealedPurgeYears);

        $this->metrics->recordVerification($result, $this->clock->now());

        return $result;
    }

    /**
     * @param  list<int>  $sealedPurgeYears
     * @param  list<AuditChainBreak>  $breaks
     */
    private function resolveChainStart(
        string $previousHash,
        int $entryId,
        array &$sealedPurgeYears,
        array &$breaks,
    ): string {
        $genesis = AuditChain::genesisHash();

        if (AuditChain::matches($genesis, $previousHash)) {
            return $genesis;
        }

        $anchor = $this->reader->anchorSealedWith($previousHash);

        if ($anchor !== null) {
            $sealedPurgeYears[] = $anchor->partitionYear;

            return $anchor->lastHash;
        }

        $breaks[] = new AuditChainBreak(
            $entryId,
            AuditChainBreakKind::OrphanStart,
            $genesis,
            $previousHash,
        );

        // Se devuelve el valor encontrado para no encadenar un segundo hallazgo
        // sobre la misma fila: ya se ha registrado uno.
        return $previousHash;
    }
}
