<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Situacion laboral del empleado (`employees.status`, doc 01 §5.5).
 *
 * En Shared y no en Workforce porque viaja dentro de {@see EmployeeSnapshot},
 * que cruza la frontera entre Workforce y Attendance (ADR-025, restriccion 2).
 * Un `enum` y no una cadena: el nucleo decide si acepta un fichaje mirando
 * este valor (RN-14), y un estado escrito mal en un adaptador no puede llegar
 * a esa decision como si fuera valido.
 */
enum EmploymentStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case TERMINATED = 'terminated';

    /**
     * RN-14: solo el empleado activo ficha. El de baja conserva su historial,
     * su credencial queda revocada y sus escaneos se rechazan.
     */
    public function canClock(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * RN-25 y RL-05: entra al portal personal quien sigue en plantilla, activa
     * **o suspendida**. La suspension impide fichar, no consultar el propio
     * registro (decision del propietario del 27-10-2026). La baja (RN-14) no
     * entra: su derecho de acceso se atiende por la solicitud del RGPD.
     */
    public function canSignInToPortal(): bool
    {
        return $this !== self::TERMINATED;
    }

    /**
     * Si un PIN correcto vale para esta persona en esa puerta: en el quiosco,
     * {@see canClock()}; en el portal, {@see canSignInToPortal()}. Un solo
     * verificador sirve a las dos puertas (RS-12), y la diferencia entre ellas
     * es solo esta.
     */
    public function admitsPinFrom(PinOrigin $origin): bool
    {
        return match ($origin) {
            PinOrigin::KIOSK => $this->canClock(),
            PinOrigin::PORTAL => $this->canSignInToPortal(),
        };
    }
}
