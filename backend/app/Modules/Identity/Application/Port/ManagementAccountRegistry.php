<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use SensitiveParameter;

/**
 * Alta de cuentas de gestion (RF-ID-01, RF-ID-02).
 *
 * **Puerto propio y no dos metodos mas en {@see UserAccounts}.** Aquel es «las
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
     * Crea la cuenta con su rol y devuelve como la vera el resto del sistema.
     *
     * La contrasena llega **en claro** y se hashea en el adaptador, que es quien
     * conoce el algoritmo configurado; nunca se almacena tal cual y nunca vuelve
     * a salir.
     */
    public function create(
        string $name,
        string $email,
        #[SensitiveParameter] string $password,
        string $locale,
        UserRole $role,
    ): AuthenticatedUser;
}
