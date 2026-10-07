<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use DateTimeImmutable;

/**
 * Alta de cuentas de gestion (RF-ID-01, RF-ID-02, RF-ID-10).
 *
 * **Puerto propio y no metodos mas en {@see UserAccounts}.** Aquel es «las
 * cuentas vistas por quien autentica» y no tiene ninguna escritura: darle un
 * `create()` significaria que el caso de uso que comprueba una contrasena
 * tambien puede crear cuentas, y eso es exactamente la clase de poder que no se
 * concede por comodidad.
 */
interface ManagementAccountRegistry
{
    /**
     * ¿Existe ya **alguna** cuenta de gestion, activa o no?
     *
     * Es la unica guarda de `POST /api/v1/setup/administrator`, que es publico.
     * Cuenta tambien las desactivadas a proposito: si no lo hiciera, desactivar
     * la unica cuenta de la instalacion reabriria la creacion publica de un
     * administrador, y eso convierte una tarea rutinaria de RRHH en una via de
     * escalada.
     */
    public function anyManagementAccountExists(): bool;

    /**
     * ¿Hay alguna cuenta —activa o dada de baja— con ese correo?
     *
     * Las bajas conservan su correo: el historico no se reasigna a otra persona.
     */
    public function emailTaken(string $email): bool;

    /**
     * Crea la cuenta con su rol y devuelve como la vera el resto del sistema.
     *
     * **Recibe el hash, no la contrasena**: se calcula antes, fuera del candado
     * de la cadena de auditoria ({@see PasswordHasher}). `$temporaryExpiresAt`
     * no nulo crea la cuenta con contrasena **temporal** que caduca en ese
     * instante (RF-ID-10); `null`, con la contrasena que eligio su titular —el
     * primer administrador del asistente—.
     *
     * Si el `UNIQUE (users.email)` salta en carrera con otra alta del mismo
     * correo, el adaptador lanza `ManagementAccountEmailTaken` (de la capa de
     * aplicacion; nombrada en texto porque un puerto no importa esa capa).
     */
    public function create(
        string $name,
        string $email,
        string $passwordHash,
        string $locale,
        UserRole $role,
        ?DateTimeImmutable $temporaryExpiresAt,
    ): AuthenticatedUser;
}
