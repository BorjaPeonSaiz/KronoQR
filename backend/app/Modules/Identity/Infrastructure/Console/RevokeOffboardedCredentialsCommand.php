<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\UseCase\RevokeLeftoverCredentialsOfOffboardedEmployees;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * `credentials:revoke-offboarded`: revoca las tarjetas que siguen activas de
 * personas ya dadas de baja.
 *
 * La lanza `update.sh` despues de las migraciones; se puede repetir a mano sin
 * efecto. **La salida solo lleva recuentos** (regla dura 21), en una linea que
 * `update.sh` copia a su registro:
 *
 *     revoked_credentials=<n> employees=<n> failed=<n>
 *
 * Una persona cuya revocacion falla no detiene a las demas: su transaccion se
 * revierte entera, el fallo se notifica con `report()` —pasa por el saneado de
 * `error_events`— y se imprime una linea por fallo con la clase de la excepcion
 * y, si es de base de datos, su SQLSTATE. **Nunca el mensaje**: el de una
 * excepcion de base de datos puede llevar valores de la fila.
 *
 * Codigos de salida: `0` sin fallos (tambien si no habia nada), `1` alguna
 * persona fallo. Repetirla vuelve a intentar solo lo que quedo activo.
 */
final class RevokeOffboardedCredentialsCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`, compartida por las ordenes `credentials:*`. */
    private const string TEXTS = 'console.credentials';

    protected $signature = 'credentials:revoke-offboarded';

    protected $description = 'Revoca, con su asiento, las tarjetas que siguen activas de personas dadas de baja (RN-14).';

    public function handle(RevokeLeftoverCredentialsOfOffboardedEmployees $sweep): int
    {
        try {
            $result = $sweep->handle();
        } catch (Throwable $exception) {
            // La seleccion misma fallo: no se ha revocado nada.
            report($exception);
            $this->error($this->text('revoke_offboarded.failed', ['error' => $this->describe($exception)]));

            return self::FAILURE;
        }

        foreach ($result['failures'] as $failure) {
            report($failure);
            $this->error($this->text('revoke_offboarded.one_failed', ['error' => $this->describe($failure)]));
        }

        $this->line(\sprintf(
            'revoked_credentials=%d employees=%d failed=%d',
            $result['credentials'],
            $result['employees'],
            $result['failed'],
        ));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function describe(Throwable $failure): string
    {
        $sqlState = $failure instanceof QueryException ? (string) $failure->getCode() : '';

        return $failure::class.($sqlState !== '' ? \sprintf(' (SQLSTATE %s)', $sqlState) : '');
    }
}
