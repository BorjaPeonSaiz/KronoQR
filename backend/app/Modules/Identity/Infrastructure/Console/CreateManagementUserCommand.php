<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * `php artisan identity:create-user` — crea una cuenta de gestion.
 *
 * **Por que existe.** En la instalacion de un cliente no se ejecuta ningun
 * seeder, asi que sin esto no habria forma de crear la primera cuenta y el panel
 * no tendria puerta de entrada. El asistente de puesta en marcha (RF-PD-03,
 * Fase 5) hara lo mismo desde la interfaz y llamara aqui.
 *
 * **La contrasena no se pasa como argumento.** Se pide por consola con eco
 * apagado: un argumento queda en el historial del shell y en la lista de
 * procesos del servidor del cliente.
 *
 * **La politica de robustez de RF-ID-01 se aplica aqui**, que es donde se FIJA
 * una contrasena, y no al usarla. Sin `uncompromised()`: esa regla consulta un
 * servicio externo por HTTP y este producto se instala en servidores sin salida
 * a internet (ADR-016), donde la comprobacion fallaria o —peor— colgaria la
 * creacion del primer administrador.
 */
final class CreateManagementUserCommand extends Command
{
    protected $signature = 'identity:create-user
        {--name= : Nombre visible de la persona}
        {--email= : Correo, que es su identificador de acceso}
        {--role= : Rol del catalogo de RF-ID-02 (admin, rrhh, responsable_departamento, auditor)}
        {--locale=es : Idioma del panel para esta cuenta}';

    protected $description = 'Crea una cuenta de gestion con su rol (RF-ID-01, RF-ID-02).';

    public function handle(): int
    {
        $name = $this->stringOption('name') ?? $this->asked('Nombre');
        $email = $this->stringOption('email') ?? $this->asked('Correo');
        $role = $this->stringOption('role') ?? $this->chosenRole();
        $password = $this->secretly('Contrasena');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'role' => $role, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
                'role' => ['required', 'string', 'in:'.implode(',', array_map(
                    static fn (UserRole $case): string => $case->value,
                    UserRole::managementRoles(),
                ))],
                'password' => ['required', 'string', $this->passwordPolicy()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error((string) $error);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'uuid' => Str::uuid7()->toString(),
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'locale' => $this->stringOption('locale') ?? 'es',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        // Sin el correo ni el nombre en la salida: este comando se ejecuta a
        // menudo con la salida redirigida a un fichero de instalacion.
        $this->components->info('Cuenta de gestion creada con el rol '.$role.' y UUID '.$user->uuid.'.');

        return self::SUCCESS;
    }

    /**
     * Politica de robustez de RF-ID-01, con la longitud minima configurable
     * (regla dura 13: los umbrales son configuracion, no constantes).
     */
    private function passwordPolicy(): Password
    {
        return Password::min(max(8, config()->integer('identity.password.min_length')))
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols();
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Las tres envolturas de abajo existen solo para tipar: los ayudantes de
     * consola de Laravel devuelven `mixed`, y con PHPStan 9 eso obliga a
     * estrechar el tipo en algun sitio. Mejor aqui, una vez, que en cada uso.
     */
    private function asked(string $question): string
    {
        $answer = $this->ask($question);

        return \is_string($answer) ? trim($answer) : '';
    }

    private function secretly(string $question): string
    {
        $answer = $this->secret($question);

        return \is_string($answer) ? $answer : '';
    }

    private function chosenRole(): string
    {
        $answer = $this->choice(
            'Rol',
            array_map(static fn (UserRole $case): string => $case->value, UserRole::managementRoles()),
            UserRole::RRHH->value,
        );

        return \is_string($answer) ? $answer : UserRole::RRHH->value;
    }
}
