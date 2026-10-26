<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Http\Rules\ActiveLocale;
use App\Modules\Identity\Application\Command\CreateManagementAccountCommand;
use App\Modules\Identity\Application\Exception\ManagementAccountEmailTaken;
use App\Modules\Identity\Application\UseCase\CreateManagementAccountHandler;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Validator;

/**
 * `php artisan identity:create-user` — crea una cuenta de gestion con
 * **contrasena temporal** (RF-ID-01, RF-ID-02, RF-ID-10).
 *
 * **Por que existe, si ya esta el panel.** Para cuando el panel no esta: una
 * instalacion cuya unica `admin` se fue sin dejar a nadie, o una operacion de
 * soporte desde el servidor. Pasa por el mismo caso de uso que
 * `POST /api/v1/management-accounts` y entrega lo mismo.
 *
 * **No pide la contrasena.** La genera el servidor, se enseña **una vez** y
 * caduca (`IDENTITY_TEMPORARY_PASSWORD_TTL_HOURS`); su titular entra con ella,
 * activa su segundo factor si su rol lo exige y fija la suya antes de poder
 * hacer nada mas. Una contrasena elegida por quien no la va a usar acaba siendo
 * la misma en todas las instalaciones que atiende ese tecnico, y una que no
 * caduca es una credencial compartida.
 *
 * **Sin el nombre ni el correo en la salida**: este comando se ejecuta a menudo
 * con la salida redirigida a un fichero de instalacion. **Sin actor**: un comando
 * de consola no tiene sesion detras, y los asientos (`user.created` y
 * `role_assignment.changed`) salen a nombre del sistema.
 */
final class CreateManagementUserCommand extends AbstractManagementAccountCommand
{
    protected $signature = 'identity:create-user
        {--name= : Nombre visible de la persona}
        {--email= : Correo, que es su identificador de acceso}
        {--role= : Rol del catalogo de RF-ID-02 (admin, rrhh, responsable_departamento, auditor)}
        {--locale= : Idioma del panel para esta cuenta, uno de los activos; sin el, el de la instalacion}';

    protected $description = 'Crea una cuenta de gestion con su rol y una contrasena temporal (RF-ID-02, RF-ID-10).';

    public function handle(CreateManagementAccountHandler $handler): int
    {
        $name = $this->stringOption('name') ?? $this->asked($this->text('create.ask_name'));
        $email = $this->stringOption('email') ?? $this->asked($this->text('create.ask_email'));
        $role = $this->stringOption('role') ?? $this->chosenRole();
        // Sin `--locale`, el de la instalacion (`LOCALE_DEFAULT`), y uno fuera de
        // los activos se rechaza: el mismo criterio que el alta desde el panel.
        $locale = $this->stringOption('locale') ?? ActiveLocale::defaultLocale();

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'role' => $role, 'locale' => $locale],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'string', 'email:rfc', 'max:190'],
                'role' => ['required', 'string', 'in:'.implode(',', array_map(
                    static fn (UserRole $case): string => $case->value,
                    UserRole::managementRoles(),
                ))],
                'locale' => ['required', 'string', ActiveLocale::rule()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error((string) $error);
            }

            return self::FAILURE;
        }

        try {
            $provisioned = $handler->handle(new CreateManagementAccountCommand(
                name: $name,
                email: $email,
                role: UserRole::from($role),
                locale: ActiveLocale::canonical($locale),
            ));
        } catch (ManagementAccountEmailTaken) {
            $this->components->error($this->text('create.email_taken'));

            return self::FAILURE;
        }

        $this->components->info($this->text('create.created', ['role' => $role, 'uuid' => $provisioned->account->uuid]));

        $this->showTemporaryPassword($provisioned->password->plain, $provisioned->expiresAt);

        return self::SUCCESS;
    }

    private function chosenRole(): string
    {
        $answer = $this->choice(
            $this->text('create.ask_role'),
            array_map(static fn (UserRole $case): string => $case->value, UserRole::managementRoles()),
            UserRole::RRHH->value,
        );

        return \is_string($answer) ? $answer : UserRole::RRHH->value;
    }
}
