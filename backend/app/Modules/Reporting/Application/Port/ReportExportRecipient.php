<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * La cuenta de gestion a la que se avisa (**RF-IN-06**).
 *
 * ## Tres datos y ninguno sobra
 *
 * - `name` — con quien se saluda. Un correo que empieza por «Hola» a secas parece
 *   automatico y se ignora.
 * - `email` — a donde va. Nulo o vacio significa «no hay a donde»: el aviso se
 *   queda en la pantalla.
 * - `locale` — `users.locale`. El correo va en el idioma de **la cuenta**, al
 *   contrario que el fichero, que va en el de la instalacion (regla dura 13):
 *   aquel lo lee una persona concreta y este lo abre un programa.
 *
 * **Aqui si va un nombre, y no contradice la regla dura 21.** Aquella prohibe
 * nombres de empleado en logs tecnicos y en `error_events`, que viajan al
 * fabricante. Esto es un objeto en memoria para componer un correo dirigido a esa
 * misma persona.
 */
final readonly class ReportExportRecipient
{
    public function __construct(
        public string $name,
        public ?string $email,
        public string $locale,
    ) {}

    /** Hay una direccion a la que escribir. */
    public function reachableByMail(): bool
    {
        return $this->email !== null && $this->email !== '';
    }
}
