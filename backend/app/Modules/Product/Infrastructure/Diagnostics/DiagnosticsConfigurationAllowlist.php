<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

/**
 * Que claves del entorno entran en el paquete de diagnostico: **lista de
 * permitidos, no filtro de secretos por patron** (RF-PD-09, RS-08, ADR-020,
 * paso 5 de la ficha 5.9).
 *
 * ## Por que no se filtra por patron
 *
 * Un filtro que quitase lo que contiene `KEY`, `SECRET` o `PASSWORD` deja pasar
 * `DB_USERNAME`, `LOKI_URL`, `METRICS_ALLOW_CIDR` y `APP_URL` —que es el nombre
 * publico del hotel—, y sobre todo deja pasar **la variable que alguien añada
 * mañana**. La ficha lo dice sin margen: `.env` filtrado por lista de
 * permitidos, no por patron.
 *
 * ## Lo que NO entra, ni siquiera «redactado»
 *
 * Nada. Una clave fuera de la lista **no aparece**, en lugar de aparecer con el
 * valor tapado. Con `LICENSE_KEY: "***"` el paquete confirmaria que hay una
 * licencia instalada y su nombre; con la clave ausente, no dice nada. Y ademas
 * una lista de redactados es una lista de exclusiones con otro nombre: habria
 * que acordarse de añadir cada variable nueva.
 *
 * Fuera quedan, entre otras: `LICENSE_KEY`, `QR_SIGNING_KEY_*`,
 * `BACKUP_ENCRYPTION_KEY`, `REVERB_APP_*`, `DB_*`, `REDIS_PASSWORD`,
 * `MAIL_HOST`/`USERNAME`/`PASSWORD`/`FROM_*`, `APP_KEY`, `APP_URL`,
 * `IDENTITY_PIN_SEALING_SECRET_KEY`, `GRAFANA_*`, `*_CIDR`, `LOKI_URL` y
 * `OTEL_*`.
 *
 * ## Los prefijos son regla, no comodidad
 *
 * `ATTENDANCE_`, `COMPLIANCE_`, `PRODUCT_`, `WORKFORCE_IMPORT_` y `REALTIME_`
 * entran enteros porque **son familias de umbrales operativos**: numeros que
 * gobiernan el calculo y que son justo lo primero que hay que mirar cuando un
 * cliente dice «me salen las horas mal». Ninguna de esas familias puede alojar
 * un secreto sin que se note, porque un secreto no es un umbral.
 *
 * `BACKUP_` **no** es prefijo, y es la excepcion que explica la regla: esa
 * familia mezcla plazos con `BACKUP_ENCRYPTION_KEY` y con las credenciales de
 * la copia. Sus claves entran una a una.
 *
 * `IDENTITY_` tampoco: aloja `IDENTITY_PIN_SEALING_SECRET_KEY`. De esa familia
 * entra lo que termina en un sufijo de magnitud —`_SECONDS`, `_HOURS`, `_DAYS`,
 * `_ATTEMPTS`, `_LENGTH`, `_LIMIT`—, que son plazos y contadores.
 *
 * ## `TELEMETRY_ENABLED` entra y `TELEMETRY_ENDPOINT` **no**, a proposito
 *
 * Parecen la misma familia y no lo son. El primero es un booleano del producto:
 * dice si la telemetria esta activada, que es exactamente lo que soporte
 * necesita saber para entender un paquete —y ademas su valor de serie es
 * `false`, asi que verlo en `true` ya es la respuesta a una pregunta—.
 *
 * El segundo es **una URL del CLIENTE**. Puede llevar un identificador o un
 * token dentro de la ruta —es la forma normal de un colector—, dice a que
 * herramienta de supervision envia y, con ella, describe la infraestructura
 * interna del hotel. Nada de eso hace falta para diagnosticar nada, y el paquete
 * sale hacia el fabricante (ADR-020, regla dura 16). Minimizacion: si no hace
 * falta, no viaja.
 *
 * **No lo añadas por simetria.** Que dos variables compartan prefijo no las hace
 * igual de inocuas; es el mismo criterio por el que `BACKUP_` no es prefijo y
 * por el que viaja `BRANDING_LOGO_ROOT` y no la ruta del logotipo.
 */
final class DiagnosticsConfigurationAllowlist
{
    /**
     * Claves permitidas por su nombre exacto.
     *
     * @var list<string>
     */
    private const array EXACT = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_TIMEZONE',
        'APP_LOCALE',
        'APP_FALLBACK_LOCALE',
        'APP_SUPPORTED_LOCALES',
        'QUEUE_CONNECTION',
        'CACHE_STORE',
        'SESSION_DRIVER',
        'BROADCAST_CONNECTION',
        // Del correo entran el transporte y el puerto, que es lo que explica un
        // «no me llegan los avisos». El HOST no: es un nombre de servidor del
        // cliente, y con el usuario y la contraseña fuera, solo aporta a quien
        // quiera atacarlo.
        'MAIL_MAILER',
        'MAIL_PORT',
        'MAIL_SCHEME',
        'LOG_CHANNEL',
        'LOG_LEVEL',
        'KIOSK_BATCH_MAX_SIZE',
        'ERROR_HISTORY_RETENTION_DAYS',
        'TECHNICAL_LOG_RETENTION_DAYS',
        'BACKUP_DAILY_AT',
        'BACKUP_RETENTION_DAYS',
        'BACKUP_WAL_RETENTION_DAYS',
        'BACKUP_MIN_COPIES',
        'TELEMETRY_ENABLED',
        // Si el certificado es autofirmado y el producto lo tolera: la mitad del
        // diagnostico de «el quiosco no sincroniza».
        'TLS_ALLOW_SELF_SIGNED',
        // La RAIZ del directorio de marca, no la ruta del logotipo: la primera
        // es del despliegue y la segunda podria llevar el nombre del hotel.
        'BRANDING_LOGO_ROOT',
    ];

    /**
     * Familias enteras. Ver el docblock: solo las que no pueden alojar un
     * secreto.
     *
     * @var list<string>
     */
    private const array PREFIXES = [
        'ATTENDANCE_',
        'COMPLIANCE_',
        'PRODUCT_',
        'WORKFORCE_IMPORT_',
        'REALTIME_',
        'LOCALE_',
        'BACKUP_WEEKLY_',
    ];

    /**
     * Sufijos de magnitud de la familia `IDENTITY_`: plazos y contadores.
     *
     * @var list<string>
     */
    private const array IDENTITY_SUFFIXES = [
        '_SECONDS',
        '_HOURS',
        '_DAYS',
        '_ATTEMPTS',
        '_LENGTH',
        '_LIMIT',
    ];

    public static function allows(string $key): bool
    {
        if (in_array($key, self::EXACT, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        if (str_starts_with($key, 'IDENTITY_')) {
            foreach (self::IDENTITY_SUFFIXES as $suffix) {
                if (str_ends_with($key, $suffix)) {
                    return true;
                }
            }
        }

        // Los techos de peticiones del quiosco: `KIOSK_*RATE*`. No entra
        // `KIOSK_VLAN_CIDR`, que es topologia de la red del cliente.
        return str_starts_with($key, 'KIOSK_') && str_contains($key, 'RATE');
    }

    /**
     * Deja solo lo permitido, ordenado por nombre.
     *
     * Ordenado para que dos paquetes de la misma instalacion se puedan comparar
     * con `diff` sin que el orden de las variables del contenedor ensucie el
     * resultado.
     *
     * @param  array<string, mixed>  $environment
     * @return array<string, string>
     */
    public static function apply(array $environment): array
    {
        $allowed = [];

        foreach ($environment as $key => $value) {
            if (self::allows($key) && (is_scalar($value) || $value === null)) {
                $allowed[$key] = match (true) {
                    $value === null => '',
                    is_bool($value) => $value ? 'true' : 'false',
                    default => (string) $value,
                };
            }
        }

        ksort($allowed, SORT_STRING);

        return $allowed;
    }
}
