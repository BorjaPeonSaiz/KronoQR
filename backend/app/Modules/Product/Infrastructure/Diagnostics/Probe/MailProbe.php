<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;

/**
 * Sondas `mail.*` de `product:doctor` (RF-PD-13).
 *
 * ## El correo no es critico en este producto, y por eso ninguna sonda falla
 *
 * Aqui no hay invitaciones por correo ni credenciales por correo (ADR-014,
 * ADR-015, regla dura 12): la tarjeta es fisica y el portal entra con codigo y
 * PIN. El correo solo lleva avisos accesorios. Un `failure` por el correo
 * pondria en rojo el codigo de salida de `install.sh` por algo que no impide
 * fichar ni consultar el registro, y un rojo que no importa entrena a la gente
 * a ignorar los rojos.
 *
 * ## `mail.transport` avisa cuando en produccion el correo va al log
 *
 * Es el fallo mas silencioso posible: `MAIL_MAILER=log` deja la instalacion
 * enviando correos a un fichero. Nadie recibe nada y nada falla.
 *
 * ## `mail.reachable` es un TCP, no un envio
 *
 * Se abre el socket al host y puerto configurados con tres segundos de tope y se
 * cierra. Mandar un correo de prueba exigiria una direccion de destino que este
 * comando no tiene y dejaria un correo real en la bandeja de alguien cada vez
 * que se ejecuta.
 */
final readonly class MailProbe implements DoctorProbe
{
    /** Tope del intento de conexion. `doctor` corre dentro de una instalacion. */
    private const int TIMEOUT_SECONDS = 3;

    public function __construct(
        private string $mailer,
        private ?string $host,
        private ?int $port,
        private string $environment,
    ) {}

    public function family(): string
    {
        return 'mail';
    }

    public function run(): array
    {
        return [$this->transport(), $this->reachable()];
    }

    private function transport(): DoctorFinding
    {
        $isProduction = $this->environment === 'production';
        $isPlaceholder = in_array($this->mailer, ['log', 'array', 'null'], true);

        if ($isProduction && $isPlaceholder) {
            return DoctorFinding::warning(
                'mail.transport',
                params: ['mailer' => $this->mailer],
                details: ['mailer' => $this->mailer, 'app_env' => $this->environment],
            );
        }

        return DoctorFinding::ok(
            'mail.transport',
            ['mailer' => $this->mailer, 'app_env' => $this->environment],
            ['mailer' => $this->mailer],
        );
    }

    private function reachable(): DoctorFinding
    {
        if ($this->host === null || $this->host === '' || $this->port === null) {
            return DoctorFinding::warning('mail.reachable', 'not_configured', details: ['mailer' => $this->mailer]);
        }

        $socket = @fsockopen($this->host, $this->port, $code, $message, self::TIMEOUT_SECONDS);

        if ($socket === false) {
            return DoctorFinding::warning(
                'mail.reachable',
                params: ['port' => $this->port],
                // El host NO va en los detalles: es un nombre de servidor del
                // cliente y este informe viaja dentro del paquete (ADR-020).
                // El codigo de error dice si fue DNS, red o rechazo.
                details: ['port' => $this->port, 'error_code' => $code],
            );
        }

        fclose($socket);

        return DoctorFinding::ok('mail.reachable', ['port' => $this->port], ['port' => $this->port]);
    }
}
