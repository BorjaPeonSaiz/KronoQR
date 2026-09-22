<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use App\Modules\Identity\Application\UseCase\DeactivateManagementAccountHandler;
use App\Modules\Identity\Application\UseCase\ResetManagementPasswordHandler;
use SensitiveParameter;

/**
 * El padron de cuentas de gestion visto por el puerto del ciclo de vida, en
 * memoria y sin base de datos (RS-05, RS-06; hallazgo H-03 de la revision
 * interna ASVS de 2026-09).
 *
 * **Guarda una sola cuenta a proposito.** Los dos casos de uso que usan el
 * puerto —{@see DeactivateManagementAccountHandler}
 * y {@see ResetManagementPasswordHandler}—
 * buscan por correo y actuan sobre lo que encuentran: lo que decide el desenlace
 * no es cuantas cuentas hay, sino en cual de los tres estados esta esa —activa,
 * dada de baja o inexistente—. Un padron con varias filas obligaria a la prueba
 * a explicar cual es la que importa.
 *
 * Los tres constructores con nombre dicen el caso que se esta probando sin
 * comentarios; `deactivated` y `passwords` dejan ver **lo que se escribio**, que
 * es lo que separa «no hizo nada» de «hizo algo que no se ve».
 */
final class InMemoryManagementAccounts implements ManagementAccountLifecycle
{
    /**
     * El uuid publico de la cuenta del escenario.
     *
     * Es un UUID v7 escrito a mano y no generado: un valor fijo se puede afirmar
     * tal cual en la prueba y aparece igual en el evento, que es donde se
     * comprueba que viaja el uuid y no el correo (regla dura 21).
     */
    public const string UUID = '0199c4a1-6f2d-7b10-9e3a-4c81d5f20b77';

    /**
     * Los uuid que se han dado de baja, en orden de llamada.
     *
     * @var list<string>
     */
    public array $deactivated = [];

    /**
     * La contrasena fijada para cada uuid, **tal cual llego al puerto**: el
     * adaptador real la hashea, y aqui se conserva en claro justamente para
     * poder afirmar que el caso de uso no la recorta ni la normaliza por el
     * camino.
     *
     * @var array<string, string>
     */
    public array $passwords = [];

    private function __construct(
        private readonly ?string $email,
        private readonly string $uuid,
        private bool $active,
    ) {}

    public static function withActiveAccount(string $email, string $uuid = self::UUID): self
    {
        return new self($email, $uuid, true);
    }

    public static function withDeactivatedAccount(string $email, string $uuid = self::UUID): self
    {
        return new self($email, $uuid, false);
    }

    public static function withoutAnyAccount(): self
    {
        return new self(null, self::UUID, false);
    }

    public function uuidOfActiveAccount(string $email): ?string
    {
        return $this->accountExists($email) && $this->active ? $this->uuid : null;
    }

    public function accountExists(string $email): bool
    {
        // `null === string` es siempre falso, asi que el padron vacio no
        // reconoce ningun correo sin necesidad de un caso aparte.
        return $this->email === $email;
    }

    public function deactivate(string $uuid): void
    {
        $this->deactivated[] = $uuid;

        // La cuenta queda de baja de verdad: repetir el comando tiene que
        // encontrarse lo mismo que se encontraria en PostgreSQL.
        $this->active = false;
    }

    public function replacePassword(string $uuid, #[SensitiveParameter] string $password): void
    {
        $this->passwords[$uuid] = $password;
    }
}
