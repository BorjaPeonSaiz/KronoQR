<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\FieldAllowlist;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use Illuminate\Database\ConnectionInterface;

/**
 * Seccion `kiosks`: la flota de tablets, **sin el nombre de ninguna**
 * (RF-PD-09, contrato `DiagnosticsBundle.kiosks`).
 *
 * ## Por que el nombre del quiosco no sale
 *
 * Porque `devices.name` lo escribe el cliente y lo escribe como le viene: la
 * mitad de las instalaciones lo llaman «Recepcion» y la otra mitad «Tablet de
 * Marta». Un nombre libre es un campo de texto que **puede llevar un nombre de
 * persona**, y la regla dura 21 no admite ese «puede». El `uuid` identifica el
 * dispositivo en el resto del paquete —las metricas `kiosk_*` van etiquetadas
 * por el— y es lo unico que soporte necesita para seguirle la pista.
 *
 * ## Lo que hace falta para «el quiosco no sincroniza» (PR13)
 *
 * La causa mas probable de esa incidencia es un token caducado o a punto de
 * caducar (F1-1), y sin estos campos no se ve:
 *
 * - `token_expires_on`: **el dia** en que caduca el token vigente, en UTC. Solo
 *   la fecha: la hora exacta no cambia el diagnostico, y ni el token ni su hash
 *   salen nunca. Nulo si el dispositivo no tiene token (sin emparejar o
 *   desvinculado). Si hay dos en solape tras una rotacion (ADR-044), el que
 *   caduca mas tarde, que es el que la tablet deberia estar usando.
 * - `paired_at`: cuando se emparejo.
 * - `oldest_pending_at`: el instante del fichaje mas antiguo que la tablet aun
 *   no ha podido enviar, tal como lo informa su latido. **Solo el instante**:
 *   ni el `scan_id` ni a quien pertenece, que la tabla no guarda. Convierte
 *   «hay 37 pendientes» en «hay 37 pendientes desde el martes».
 * - `battery_level` y `battery_charging`: una tablet que se apaga sola tambien
 *   «no sincroniza». Nulos si el navegador no informa.
 *
 * Todos salen del ultimo latido (`devices`) salvo la caducidad, que es de
 * `personal_access_tokens`. Cuando no hay dato el campo es `null`: nunca se
 * inventa un valor ni se omite la clave, para que el elemento tenga la misma
 * forma en todas las tablets.
 *
 * ## La lista de permitidos esta escrita aqui y la impone `FieldAllowlist`
 *
 * Columnas nombradas, no «todas menos el nombre». El dia que `devices` gane una
 * columna nueva —un campo de notas, una direccion MAC—, no aparecera en el
 * paquete hasta que alguien la añada a esta lista mirandola.
 */
final readonly class KioskCollector implements DiagnosticsCollector
{
    /** Tope de filas. Un hotel tiene unidades de quioscos; mil serian un fallo. */
    private const int MAX_ROWS = 200;

    /**
     * Las claves de cada elemento, en el orden del contrato.
     *
     * @var list<string>
     */
    public const array FIELDS = [
        'uuid',
        'status',
        'app_version',
        'last_seen_at',
        'pending_queue_size',
        'queue_storage',
        'unreported_discards',
        'oldest_pending_at',
        'battery_level',
        'battery_charging',
        'paired_at',
        'token_expires_on',
    ];

    public function __construct(private ConnectionInterface $database) {}

    public function section(): string
    {
        return 'kiosks';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        // La caducidad se busca por el dispositivo Y por el nombre con el que
        // `SanctumDeviceTokenIssuer` crea el token (`kiosk:<uuid>`). Las dos
        // condiciones juntas porque `Product` no puede importar el modelo de
        // `Identity` para leer su `tokenable_type` (Deptrac), y un `tokenable_id`
        // a secas casaria tambien con el token de una cuenta de gestion que
        // tuviera el mismo `id`. La prueba de integracion empareja por el emisor
        // real: si el nombre cambia alli, falla aqui.
        $rows = $this->database->table('devices as d')
            ->select([
                'd.uuid',
                'd.status',
                'd.app_version',
                'd.last_seen_at',
                'd.pending_queue_size',
                'd.queue_storage',
                'd.unreported_discards',
                'd.oldest_pending_at',
                'd.battery_level',
                'd.battery_charging',
                'd.paired_at',
            ])
            ->selectRaw(
                "(SELECT MAX(t.expires_at) FROM personal_access_tokens t
                   WHERE t.tokenable_id = d.id AND t.name = 'kiosk:' || d.uuid::text) AS token_expires_at",
            )
            ->orderBy('d.uuid')
            ->limit(self::MAX_ROWS)
            ->get();

        $kiosks = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $kiosks[] = self::present($values);
        }

        return $kiosks;
    }

    /**
     * Una fila de `devices` (mas `token_expires_at`) en la forma del contrato.
     *
     * Publica y estatica para probarla sin base de datos: aqui vive todo lo que
     * decide que sale y con que forma.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        // `FieldAllowlist` deja TODAS las claves de la lista, con null si la
        // fila no la traia: por eso aqui no hace falta `?? null`.
        $allowed = (new FieldAllowlist(...[...self::FIELDS, 'token_expires_at']))->apply($row);

        // Postgres devuelve `2026-09-08 09:07:33.123456+00` y el esquema
        // `UtcTimestamp` del contrato exige la forma con `T` y `Z`. Sin esta
        // conversion la respuesta no valida y el cliente TypeScript generado
        // recibe algo que su tipo no describe.
        return [
            'uuid' => $allowed['uuid'],
            'status' => $allowed['status'],
            'app_version' => $allowed['app_version'],
            'last_seen_at' => UtcInstant::fromDatabase($allowed['last_seen_at']),
            // ADR-047: `null` = desconocido —la cola de la tablet cayo a
            // memoria— y NUNCA se convierte en cero en el paquete.
            'pending_queue_size' => self::boundedInteger($allowed['pending_queue_size'], PHP_INT_MAX),
            'queue_storage' => \in_array($allowed['queue_storage'], ['durable', 'memory', 'unavailable'], true)
                ? $allowed['queue_storage']
                : 'durable',
            // Solo el recuento de descartes sin avisar (RN-22): ni `scan_id` ni
            // persona.
            'unreported_discards' => self::boundedInteger($allowed['unreported_discards'], PHP_INT_MAX) ?? 0,
            'oldest_pending_at' => UtcInstant::fromDatabase($allowed['oldest_pending_at']),
            'battery_level' => self::boundedInteger($allowed['battery_level'], 100),
            'battery_charging' => self::nullableBoolean($allowed['battery_charging']),
            'paired_at' => UtcInstant::fromDatabase($allowed['paired_at']),
            'token_expires_on' => self::utcDate($allowed['token_expires_at']),
        ];
    }

    /** Un entero entre 0 y `$maximum`, o null si no es un numero. */
    private static function boundedInteger(mixed $value, int $maximum): ?int
    {
        return is_numeric($value) ? min($maximum, max(0, (int) $value)) : null;
    }

    /** Solo el dia (`2026-12-01`) del instante, ya llevado a UTC por `UtcInstant`. */
    private static function utcDate(mixed $value): ?string
    {
        $instant = UtcInstant::fromDatabase($value);

        return $instant === null ? null : substr($instant, 0, 10);
    }

    /** PDO devuelve `true`/`false`, pero un driver en modo texto devuelve `t`/`f`. */
    private static function nullableBoolean(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 't', $value === 1, $value === '1' => true,
            $value === 'f', $value === 0, $value === '0' => false,
            default => null,
        };
    }
}
