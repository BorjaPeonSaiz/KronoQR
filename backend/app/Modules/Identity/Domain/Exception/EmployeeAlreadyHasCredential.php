<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

/**
 * Se ha intentado emitir una segunda credencial activa para el mismo empleado
 * (doc 01 §5.2).
 *
 * **La invariante la declaran dos indices parciales** —
 * `one_pending_credential_per_employee` y
 * `one_active_credential_per_key_and_employee` (tarea 2.12)—, y esta excepcion es
 * el eco en PHP de cualquiera de los dos: el repositorio lo intenta y traduce el
 * choque, en lugar de comprobar antes con un `SELECT` que seria una condicion de
 * carrera con aspecto de comprobacion —dos peticiones simultaneas la pasarian las
 * dos—.
 *
 * **La regla no es «una tarjeta por persona» sino «una por persona y clave»**: lo
 * que no puede existir son dos tarjetas vivas indistinguibles. Durante una
 * rotacion con solape conviven la que se lleva encima y su relevo pendiente de
 * imprimir, y eso es correcto (RF-QR-07, §5.3).
 *
 * **Por que esta en `Domain/Exception` si el agregado no puede comprobarla.**
 * Por lo mismo que `EmployeeCodeAlreadyTaken` en `Workforce`: es una **regla de
 * negocio** —una persona, una tarjeta— aunque quien la haga cumplir sea la base
 * de datos, y el puerto `CredentialRepository` tiene que poder nombrarla en su
 * `@throws`. Un puerto de `Application` no puede depender de `Application`
 * (Deptrac), asi que ponerla ahi habria dejado el contrato sin poder declarar lo
 * que lanza.
 *
 * Se traduce a un 409 en la API. La salida correcta es **reemitir** —revocar la
 * anterior y emitir otra en el mismo acto—, que es lo que hace el `reissue` del
 * caso de uso.
 */
final class EmployeeAlreadyHasCredential extends IdentityDomainException
{
    public static function make(): self
    {
        return new self('El empleado ya tiene una credencial activa. Reemite en lugar de emitir una segunda.');
    }
}
