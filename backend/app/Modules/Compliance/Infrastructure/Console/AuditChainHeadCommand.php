<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\Port\AuditChainHead;
use Illuminate\Console\Command;

/**
 * `php artisan compliance:audit-chain-head` — imprime la punta de la cadena de
 * auditoria (RS-07, RF-PD-10, tarea 5.7).
 *
 * ## Por que hace falta un comando de lectura
 *
 * `compliance:verify-audit-chain` dice **si** la cadena verifica; no dice **por
 * donde va**. El asiento que deja el instalador necesita las dos huellas: la que
 * verifico antes de tocar nada y la de despues de migrar. Y sobre todo, en la
 * vuelta atras necesita leer la punta **justo antes de restaurar**, porque
 * despues ya no existe: esa huella es la del ultimo hecho del intervalo que se
 * descarta, y es lo unico que permite demostrar, dentro del propio registro, que
 * hubo un intervalo descartado. Sin ella, el asiento diria «restaure una copia»
 * sin poder acotar que se llevo por delante.
 *
 * ## Solo lee
 *
 * No escribe, no bloquea y no verifica. Es seguro llamarlo en cualquier momento,
 * incluido el peor de todos —una actualizacion que acaba de fallar—, que es
 * justo cuando el instalador lo llama.
 *
 * Sale `0` siempre que pueda leer la punta: una instalacion recien montada, sin
 * ni un asiento, tiene punta igual (la genesis).
 */
final class AuditChainHeadCommand extends Command
{
    protected $signature = 'compliance:audit-chain-head';

    protected $description = 'Imprime en JSON la huella del ultimo eslabon de audit_log (RS-07)';

    public function handle(AuditChainHead $head): int
    {
        $snapshot = $head->snapshot();

        $this->line((string) json_encode([
            'hash' => $snapshot->hash,
            'last_entry_id' => $snapshot->lastEntryId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
