<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Los seis roles de RF-ID-02.
 *
 * **En Shared y no en Identity por la misma razon que EmploymentStatus**: el
 * rol cruza la frontera entre modulos. Lo emite `Identity`, que es quien tiene
 * `roles` y las cuentas, y lo consume la policy de cada recurso, que vive en el
 * modulo que posee ese recurso —`Workforce` para empleados, centros y
 * departamentos—. Deptrac prohibe que `Workforce` importe nada de `Identity`
 * (doc 02 §1.6), asi que el catalogo tiene que estar en el unico sitio que los
 * dos alcanzan. La alternativa era repetir las cadenas 'admin' y 'rrhh' en cada
 * policy, y dos listas de roles divergen el dia que aparece la septima.
 *
 * **El catalogo es identico para todos los clientes** (regla dura 13,
 * ADR-017). Lo que cambia de una instalacion a otra es quien tiene cada rol,
 * nunca la lista: una lista por cliente obligaria a tocar el repositorio para
 * vender, que es exactamente lo que ADR-017 prohibe.
 *
 * **Que hay hoy y que no.** En la Fase 1 se usan `ADMIN` y `RRHH`. Los otros
 * cuatro existen en el catalogo desde el principio —RF-ID-02 los enumera— pero
 * no tienen todavia su ambito: `RESPONSABLE_DEPARTAMENTO` no adquiere el
 * alcance por departamento hasta la tarea 2.1 (RF-ID-03), `EMPLEADO` entra por
 * el portal con codigo y PIN (ADR-015) y `KIOSK` es el token del dispositivo
 * (RF-ID-04), no una persona con contrasena.
 *
 * Los nombres van **en espanol** y es la unica excepcion consciente a «el
 * codigo se escribe en ingles» (doc 02 §3.5): estos valores no son
 * identificadores del programador, son datos que ya estan escritos en la
 * columna `roles.name` de cualquier instalacion y en RF-ID-02. Traducirlos aqui
 * crearia dos nombres para el mismo rol.
 */
enum UserRole: string
{
    case ADMIN = 'admin';
    case RRHH = 'rrhh';
    case RESPONSABLE_DEPARTAMENTO = 'responsable_departamento';
    case AUDITOR = 'auditor';
    case EMPLEADO = 'empleado';
    case KIOSK = 'kiosk';

    /**
     * Roles que abren sesion en el panel con correo y contrasena.
     *
     * `empleado` y `kiosk` quedan fuera y no es un detalle: el empleado entra a
     * su portal con codigo y PIN porque el producto no puede exigirle correo
     * (regla dura 12), y el quiosco se autentica con un token de dispositivo de
     * ambito restringido (RF-ID-04). Una cuenta de gestion para cualquiera de
     * los dos seria una puerta que nadie ha pedido.
     *
     * @return list<self>
     */
    public static function managementRoles(): array
    {
        return [
            self::ADMIN,
            self::RRHH,
            self::RESPONSABLE_DEPARTAMENTO,
            self::AUDITOR,
        ];
    }

    public function isManagementRole(): bool
    {
        return \in_array($this, self::managementRoles(), true);
    }
}
