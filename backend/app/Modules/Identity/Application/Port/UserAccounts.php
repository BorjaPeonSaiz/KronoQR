<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use DateTimeImmutable;

/**
 * Las cuentas de gestion, vistas por el caso de uso que autentica.
 *
 * Lo implementa `Identity/Infrastructure/Persistence`, que es donde estan la
 * tabla `users` y el hash de la contrasena. El puerto existe para que el caso de
 * uso no importe Eloquent ni facades (doc 02 §3.5, verificado por Deptrac) y
 * para que la comprobacion de credenciales se pueda sustituir en una prueba sin
 * base de datos.
 */
interface UserAccounts
{
    /**
     * Comprueba correo y contrasena y devuelve la cuenta, o `null`.
     *
     * **Un solo `null` para todas las causas** —el correo no existe, la
     * contrasena no coincide, la cuenta esta desactivada— porque el endpoint
     * responde lo mismo en los tres casos: distinguirlos convertiria el acceso
     * al panel en un comprobador de cuentas de la empresa.
     *
     * La implementacion **compara el hash aunque la cuenta no exista**, para que
     * el tiempo de respuesta no delate que correos estan dados de alta.
     */
    public function verifyCredentials(string $email, string $password): ?AuthenticatedUser;

    /**
     * La cuenta con ese UUID publico, o `null` si no existe o esta desactivada.
     *
     * La usa `GET /api/v1/auth/me`, que necesita el rol y el ambito vigentes
     * **ahora** y no los del momento en que se emitio el token: revocar un rol
     * tiene que notarse sin esperar a que caduque la sesion.
     */
    public function findByUuid(string $uuid): ?AuthenticatedUser;

    /**
     * Anota el ultimo acceso (`users.last_login_at`).
     *
     * Recibe el instante ya resuelto por el puerto `Clock` (ADR-021): ni el caso
     * de uso ni el adaptador preguntan la hora al sistema.
     */
    public function recordSuccessfulLogin(string $uuid, DateTimeImmutable $at): void;
}
