<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Console;

use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\Exception\InvalidResponsibleReference;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Domain\ValueObject\ResponsibleReference;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Console\Command;

/**
 * La firma de una orden de consola con valor legal: quien es `--responsible`,
 * si puede firmar y si quien teclea confirma que es esa cuenta (doc 01 §7.2, «Firmas por consola»).
 *
 * La usan todas las ordenes que firman un asiento, para que las reglas y los
 * mensajes sean los mismos en todas:
 *
 * 1. {@see self::resolve()}: `--responsible` es un `uuid` o un correo (un
 *    numero se rechaza explicando el cambio), la cuenta existe, esta activa y
 *    tiene alguno de los roles de la accion. Cualquier negativa es `2`.
 * 2. {@see self::confirm()}: enseña `Firma: nombre <correo> · rol · uuid` y
 *    pregunta. Sin terminal o con `--force` no hay a quien preguntar y la
 *    confirmacion es `--responsible-confirm=<correo>`, que tiene que ser el de
 *    la cuenta resuelta: un dedo que resbala en `--responsible` no firma con
 *    otra persona. Rechazar la pregunta es `1`.
 *
 * **El nombre se enseña en la terminal y en ningun otro sitio**: es el de la
 * cuenta de gestion de quien firma, a quien lo esta tecleando. Al log tecnico
 * va el `uuid` (regla dura 21).
 *
 * **Lo leido aqui sirve para preguntar.** El caso de uso vuelve a comprobar la
 * cuenta por su id con la cadena de `audit_log` tomada antes de escribir el
 * asiento: una baja entre la pregunta y la firma no firma.
 */
final class ConsoleResponsible
{
    /** La opcion con la que se confirma sin terminal o con `--force`. */
    public const string CONFIRM_OPTION = 'responsible-confirm';

    /**
     * La cuenta que firma, o `Command::INVALID` con el motivo ya escrito.
     *
     * @param  list<UserRole>  $roles  los que pueden firmar la accion; vacia: cualquier rol de gestion
     */
    public static function resolve(Command $command, ResponsibleAccounts $accounts, string $value, array $roles): ResponsibleAccount|int
    {
        try {
            $reference = ResponsibleReference::parse($value);
        } catch (InvalidResponsibleReference $invalid) {
            $command->error(self::text($invalid->translationKey, ['value' => trim($value)]));

            return Command::INVALID;
        }

        $account = $accounts->find($reference);

        if (! $account instanceof ResponsibleAccount) {
            $command->error(self::text('responsible.not_found', ['value' => $reference->value()]));

            return Command::INVALID;
        }

        if (! $account->isActive) {
            $command->error(self::text('responsible.inactive', ['email' => $account->email]));

            return Command::INVALID;
        }

        if (! $account->canSignAs($roles)) {
            $command->error(self::text('responsible.wrong_role', [
                'email' => $account->email,
                'roles' => self::roleList($roles === [] ? UserRole::managementRoles() : $roles),
            ]));

            return Command::INVALID;
        }

        return $account;
    }

    /**
     * `null` si se puede firmar; si no, el codigo de salida con el motivo ya
     * escrito: `INVALID` si falta o no cuadra `--responsible-confirm`,
     * `FAILURE` si la persona responde que no.
     *
     * @param  bool  $unattended  con `--force` o sin terminal
     */
    public static function confirm(Command $command, ResponsibleAccount $account, string $question, bool $unattended): ?int
    {
        $command->line(self::text('responsible.signature', [
            'name' => $account->name,
            'email' => $account->email,
            'roles' => self::roleList($account->roles),
            'uuid' => $account->uuid,
        ]));

        if ($unattended) {
            $confirmation = $command->option(self::CONFIRM_OPTION);
            $confirmation = \is_string($confirmation) ? trim($confirmation) : '';

            if ($confirmation === '') {
                $command->error(self::text('responsible.confirm_missing'));

                return Command::INVALID;
            }

            if (! $account->isConfirmedBy($confirmation)) {
                $command->error(self::text('responsible.confirm_mismatch', ['email' => $account->email]));

                return Command::INVALID;
            }

            return null;
        }

        if ($command->confirm($question, false)) {
            return null;
        }

        $command->line(self::text('responsible.cancelled'));

        return Command::FAILURE;
    }

    /** El aviso de que falta `--responsible`, igual en todas las ordenes. */
    public static function missing(Command $command): int
    {
        $command->error(self::text('responsible.missing'));

        return Command::INVALID;
    }

    /**
     * La cuenta paso la pregunta pero ya no puede firmar cuando el caso de uso
     * la vuelve a comprobar con la cadena tomada: se dio de baja o perdio el
     * rol entre medias. Igual en todas las ordenes.
     */
    public static function noLongerAllowed(Command $command): int
    {
        $command->error(self::text('responsible.no_longer_allowed'));

        return Command::INVALID;
    }

    /**
     * @param  list<UserRole>  $roles
     */
    private static function roleList(array $roles): string
    {
        return $roles === []
            ? '—'
            : implode(', ', array_map(static fn (UserRole $role): string => $role->value, $roles));
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return \is_string($text) ? $text : $key;
    }
}
