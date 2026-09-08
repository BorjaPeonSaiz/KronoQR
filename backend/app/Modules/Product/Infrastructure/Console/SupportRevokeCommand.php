<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Command\RevokeSupportAccessCommand;
use App\Modules\Product\Application\Port\SupportGrantRepository;
use App\Modules\Product\Application\UseCase\RevokeSupportAccessHandler;
use App\Modules\Product\Domain\ValueObject\SupportRevocationOutcome;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Console\Command;

/**
 * `php artisan support:revoke [uuid] [--all]` (Anexo C del doc 01, **RF-PD-11**,
 * ADR-020).
 *
 * ## Es el boton de panico, y por eso `--all` existe
 *
 * La pregunta que se hace a las tres de la mañana no es «¿cual de las
 * concesiones revoco?», es **«corta todo acceso del fabricante ahora mismo»**. Si
 * la unica forma de hacerlo fuera revocar una por una leyendo UUID de una lista,
 * la respuesta rapida seria borrar filas a mano con `psql`, que destruye
 * exactamente la evidencia que hace falta despues.
 *
 * ## Sin `--as=`, al contrario que `support:grant`
 *
 * Y no es una asimetria descuidada: `revoked_by_user_id` **si admite nulos**
 * porque retirar un acceso no requiere firma de nadie —cualquiera que pueda
 * hacerlo, debe poder hacerlo, y sin preguntas—. Lo que consta entonces es
 * «lo revoco la consola», que es la verdad. Conceder es lo contrario: ahi la
 * firma es el requisito (RL-18).
 *
 * ## Codigos de salida
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | Revocado, o no habia nada vivo que revocar. |
 * | `1` | El UUID indicado no existe, o no se indico ninguno y tampoco `--all`. |
 *
 * «No habia nada que revocar» es `0` a proposito: quien ejecuta esto quiere que
 * no haya accesos abiertos, y ese es el resultado. Devolver error por conseguir
 * lo que se pedia complicaria cualquier script que lo llamara.
 */
final class SupportRevokeCommand extends Command
{
    protected $signature = 'support:revoke
        {uuid? : La concesion a revocar. Sin argumento, usa --all}
        {--all : Revoca TODAS las concesiones activas}';

    protected $description = 'Retira uno o todos los accesos de soporte al fabricante, en el acto';

    public function handle(
        RevokeSupportAccessHandler $revoke,
        SupportGrantRepository $grants,
        Clock $clock,
    ): int {
        $uuid = $this->uuidArgument();

        if ($uuid === '' && $this->option('all') !== true) {
            $this->error('Indica la concesion que quieres revocar, o usa --all para revocarlas todas.');
            $this->line('Uso:  php artisan support:revoke 0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f60');
            $this->line('      php artisan support:revoke --all');

            return self::FAILURE;
        }

        return $uuid === ''
            ? $this->revokeAll($revoke, $grants, $clock)
            : $this->revokeOne($revoke, $uuid);
    }

    private function revokeOne(RevokeSupportAccessHandler $revoke, string $uuid): int
    {
        // Sin actor: por consola no hay sesion detras y no se inventa una. El
        // asiento lo refleja tal cual.
        $outcome = $revoke->handle(new RevokeSupportAccessCommand($uuid, null));

        return match ($outcome) {
            SupportRevocationOutcome::Revoked => $this->reportRevoked(),
            SupportRevocationOutcome::AlreadyRevoked => $this->reportAlreadyRevoked(),
            SupportRevocationOutcome::NotFound => $this->reportNotFound($uuid),
        };
    }

    private function revokeAll(
        RevokeSupportAccessHandler $revoke,
        SupportGrantRepository $grants,
        Clock $clock,
    ): int {
        $active = $grants->active($clock->now());

        if ($active === []) {
            $this->info('No hay ningun acceso de soporte activo. Nada que revocar.');

            return self::SUCCESS;
        }

        foreach ($active as $grant) {
            $revoke->handle(new RevokeSupportAccessCommand($grant->uuid, null));
            $this->line('Revocado: '.$grant->uuid.'  ('.$grant->scope->value.')');
        }

        $this->info(\count($active).' acceso(s) de soporte revocado(s). Sus tokens dejan de valer ya.');
        $this->line('Las concesiones se conservan con su fecha de revocacion, y consta en auditoria.');

        return self::SUCCESS;
    }

    private function reportRevoked(): int
    {
        $this->info('Acceso revocado. Su token deja de valer en la peticion siguiente.');
        $this->line('La concesion se conserva con su fecha de revocacion (nada se borra).');

        return self::SUCCESS;
    }

    private function reportAlreadyRevoked(): int
    {
        $this->info('Esa concesion ya estaba revocada. No habia nada que hacer.');

        return self::SUCCESS;
    }

    private function reportNotFound(string $uuid): int
    {
        $this->error('No hay ninguna concesion con el identificador «'.$uuid.'».');
        $this->line('Los identificadores de las concesiones activas los da el panel, en Soporte.');

        return self::FAILURE;
    }

    private function uuidArgument(): string
    {
        $value = $this->argument('uuid');

        return \is_string($value) ? trim($value) : '';
    }
}
