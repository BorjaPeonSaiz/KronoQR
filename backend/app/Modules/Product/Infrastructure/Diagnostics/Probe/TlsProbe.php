<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Shared\Application\Port\Clock;
use OpenSSLCertificate;
use Throwable;

/**
 * Sonda `tls.certificate` de `product:doctor` (RF-PD-13).
 *
 * ## Es la averia numero uno de un producto instalado en casa del cliente
 *
 * Un certificado caducado deja **veinte tablets sin sincronizar a la vez** y sin
 * ningun mensaje que lo explique: la PWA encola y el empleado sigue fichando
 * (regla dura 19), asi que nadie se entera hasta que alguien mira la cola. Un
 * aviso treinta dias antes convierte una madrugada de panico en una tarea de
 * mantenimiento.
 *
 * ## Se lee el certificado que sirve el borde, no el fichero del disco
 *
 * Porque son dos cosas distintas: se puede haber renovado el fichero y no haber
 * recargado Nginx, que es justo el error que deja al cliente creyendo que ya lo
 * arreglo. Se hace un handshake contra el host de `APP_URL` con
 * `verify_peer=false` — el objetivo es **leer** `notAfter`, no validar la
 * cadena: en una instalacion interna el certificado es autofirmado a proposito y
 * validarlo fallaria siempre.
 *
 * ## Inalcanzable es `warning`, nunca `failure`
 *
 * `doctor` se ejecuta tambien desde dentro del contenedor durante una
 * instalacion, cuando el borde puede no estar levantado todavia. Un `failure`
 * ahi devolveria `2` y `install.sh` abortaria una instalacion correcta.
 */
final readonly class TlsProbe implements DoctorProbe
{
    /** Dias de margen antes de avisar. Renovar un certificado lleva mas de una tarde. */
    private const int WARNING_DAYS = 30;

    /** Tope del handshake. */
    private const int TIMEOUT_SECONDS = 3;

    public function __construct(
        private string $applicationUrl,
        private bool $allowSelfSigned,
        private Clock $clock,
    ) {}

    public function family(): string
    {
        return 'tls';
    }

    public function run(): array
    {
        $parts = parse_url($this->applicationUrl);
        $parts = is_array($parts) ? $parts : [];

        $host = self::text($parts, 'host');
        $scheme = self::text($parts, 'scheme') ?? 'https';
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : 443;

        if ($host === null) {
            return [DoctorFinding::warning('tls.certificate', 'no_url', details: ['app_url' => 'unparseable'])];
        }

        if ($scheme !== 'https') {
            // `APP_URL` en `http://` es lo normal en desarrollo y un problema en
            // produccion, pero eso lo dice `app.*`: aqui no hay certificado que
            // mirar y no se inventa un veredicto.
            return [DoctorFinding::warning('tls.certificate', 'not_https', details: ['scheme' => $scheme])];
        }

        return [$this->certificate($host, $port)];
    }

    private function certificate(string $host, int $port): DoctorFinding
    {
        $certificate = $this->peerCertificate($host, $port);

        if ($certificate === null) {
            return DoctorFinding::warning(
                'tls.certificate',
                'unreachable',
                params: ['port' => $port],
                details: ['port' => $port],
            );
        }

        $expiresAt = is_int($certificate['validTo_time_t'] ?? null) ? $certificate['validTo_time_t'] : null;

        if ($expiresAt === null) {
            return DoctorFinding::warning('tls.certificate', 'unreadable', details: ['port' => $port]);
        }

        $days = (int) floor(($expiresAt - $this->clock->now()->getTimestamp()) / 86400);

        $selfSigned = ($certificate['issuer'] ?? null) === ($certificate['subject'] ?? null);

        return $this->verdict($days, $selfSigned, [
            'expires_at' => gmdate(DATE_ATOM, $expiresAt),
            'days_until_expiry' => $days,
            'self_signed' => $selfSigned,
            'port' => $port,
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function verdict(int $days, bool $selfSigned, array $details): DoctorFinding
    {
        return match (true) {
            $days < 0 => DoctorFinding::failure(
                'tls.certificate',
                params: ['days' => abs($days)],
                details: $details,
            ),
            $days <= self::WARNING_DAYS => DoctorFinding::warning(
                'tls.certificate',
                params: ['days' => $days],
                details: $details,
            ),
            $selfSigned && ! $this->allowSelfSigned => DoctorFinding::warning(
                'tls.certificate',
                'self_signed',
                details: $details,
            ),
            default => DoctorFinding::ok('tls.certificate', $details, ['days' => $days]),
        };
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private static function text(array $parts, string $key): ?string
    {
        $value = $parts[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function peerCertificate(string $host, int $port): ?array
    {
        try {
            $context = stream_context_create(['ssl' => [
                'capture_peer_cert' => true,
                // A proposito: se quiere LEER la fecha, no validar la cadena. En
                // una instalacion interna el certificado es autofirmado por
                // decision del cliente y validarlo fallaria siempre.
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ]]);

            $stream = @stream_socket_client(
                'ssl://'.$host.':'.$port,
                $code,
                $message,
                self::TIMEOUT_SECONDS,
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if ($stream === false) {
                return null;
            }

            $params = stream_context_get_params($stream);
            fclose($stream);

            $ssl = $params['options']['ssl'] ?? null;
            $resource = is_array($ssl) ? ($ssl['peer_certificate'] ?? null) : null;

            if (! $resource instanceof OpenSSLCertificate) {
                return null;
            }

            $parsed = openssl_x509_parse($resource);

            return $parsed === false ? null : $parsed;
        } catch (Throwable) {
            return null;
        }
    }
}
