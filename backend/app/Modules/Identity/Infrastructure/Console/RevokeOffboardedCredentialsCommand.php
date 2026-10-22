<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\UseCase\RevokeLeftoverCredentialsOfOffboardedEmployees;
use Illuminate\Console\Command;
use Throwable;

/**
 * `credentials:revoke-offboarded`: revoca las tarjetas que siguen activas de
 * personas ya dadas de baja (R3-AR-07, R4-SC-11; tarea 12.4 de la 2.3.0).
 *
 * La lanza `update.sh` despues de las migraciones; se puede repetir a mano sin
 * efecto. **La salida solo lleva recuentos** (regla dura 21): una linea
 * `revoked_credentials=<n> employees=<n>` que `update.sh` puede copiar a su
 * registro.
 *
 * Codigos de salida: `0` hecho (tambien si no habia nada), `1` fallo — la
 * revocacion de la persona en curso se revierte entera y las anteriores quedan
 * hechas, con sus asientos; repetirla continua donde lo dejo.
 */
final class RevokeOffboardedCredentialsCommand extends Command
{
    protected $signature = 'credentials:revoke-offboarded';

    protected $description = 'Revoca, con su asiento, las tarjetas que siguen activas de personas dadas de baja (R3-AR-07).';

    public function handle(RevokeLeftoverCredentialsOfOffboardedEmployees $sweep): int
    {
        try {
            $result = $sweep->handle();
        } catch (Throwable $exception) {
            // Solo la clase: el mensaje de una excepcion de base de datos puede
            // llevar valores de la fila (regla dura 21).
            $this->error('No se han podido revocar las tarjetas de las bajas: '.$exception::class);

            return self::FAILURE;
        }

        $this->line(\sprintf('revoked_credentials=%d employees=%d', $result['credentials'], $result['employees']));

        return self::SUCCESS;
    }
}
