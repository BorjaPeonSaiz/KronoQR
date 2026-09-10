<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Metrics\Exposition;

/**
 * **El catalogo unico de lo que sale por `GET /metrics`** (doc 02 §8.2,
 * decision 1 de la ficha 3.1).
 *
 * ## Por que existe una lista y no un `SCAN kronoqr:metrics:*`
 *
 * Por lo mismo que `Product\…\Diagnostics\Collector\MetricsCollector` tiene la
 * suya: **lo que no esta, no sale**. Un recorrido ciego del prefijo publicaria
 * automaticamente la serie que alguien añada mañana con una etiqueta que
 * identifique a una persona (regla dura 21) y, ademas, la publicaria sin
 * `# HELP` ni `# TYPE` —con lo que Prometheus la trataria como `untyped` y
 * `histogram_quantile()` devolveria `NaN` sobre cualquier duracion—.
 *
 * La lista tambien es lo que hace posible la prueba de arquitectura
 * `MetricsCatalogueTest`, que compara este catalogo, serie a serie, con el
 * **bloque literal del §8.2 del doc 02** y falla en las dos direcciones: una
 * serie catalogada que el documento no declara, y una serie del documento que
 * el catalogo no expone.
 *
 * ## Que NO esta aqui, y por que no es un olvido
 *
 * - **Las series por fichero `.prom`** —`open_shifts_current`,
 *   `websocket_connections_active`, `incidents_open`,
 *   `incidents_metrics_timestamp_seconds`, `projection_divergence_total`,
 *   `projection_reconciliation_last_run_timestamp_seconds`, `audit_chain_*`,
 *   `audit_log_partition_*`, las cuatro de credenciales y
 *   `workdays_complete_ratio`—. Las publica el colector
 *   *textfile* de `node-exporter` y **no se duplican aqui**: la razon del
 *   textfile —seguir publicandose aunque la aplicacion no arranque— sigue
 *   vigente, y una misma serie por dos objetivos de *scrape* daria dos series
 *   con distinto `job`. La verificacion «las series del §8.2 estan» se hace
 *   sobre los dos objetivos juntos.
 * - **`kronoqr_backup_*`**. Las escriben `infra/scripts/backup.sh` y
 *   `restore-drill.sh`, por el mismo motivo elevado al cuadrado: una metrica de
 *   respaldo servida por el proceso que hay que restaurar no vale nada.
 * - **`scan_batch_size` y `pin_resets_total`**, que si estan en Redis pero
 *   **no** en el listado del §8.2. Se quedan fuera para que la comparacion con
 *   el documento pueda ser bidireccional y estricta: el dia que el §8.2 las
 *   declare, se añaden aqui y la prueba pasa a exigirlas. Siguen viajando en el
 *   paquete de diagnostico.
 *
 * ## Los `# HELP` van en castellano
 *
 * Los lee quien opera la instalacion —IT del hotel, con Grafana delante— y el
 * producto se vende en España. El codigo sigue en ingles (CLAUDE.md); lo que
 * cambia de lengua es el texto que ve una persona, igual que en los siete
 * adaptadores `Textfile*Metrics` que ya escriben su `# HELP` asi.
 */
final class MetricCatalogue
{
    /**
     * El prefijo comun de todas las series en Redis. Es el mismo que declaran
     * los catorce adaptadores `Redis*Metrics` en su `KEY_PREFIX`, y una prueba
     * de arquitectura comprueba que ninguno se separa.
     */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    /**
     * No se instancia: es una tabla, no un colaborador.
     */
    private function __construct() {}

    /**
     * @return list<MetricDefinition>
     */
    public static function all(): array
    {
        return [
            // ---------------------------------------------------------------
            // Tecnicas (RED).
            // ---------------------------------------------------------------
            new MetricDefinition(
                'http_request_duration_seconds',
                MetricType::Histogram,
                MetricStorage::Histogram,
                'Duracion de cada peticion HTTP. La etiqueta es el NOMBRE de la ruta, nunca su URI: con la URI habria una serie por empleado.',
                ['route', 'method', 'status'],
            ),
            new MetricDefinition(
                'http_requests_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Peticiones HTTP atendidas, por ruta, metodo y codigo de estado.',
                ['route', 'method', 'status'],
            ),
            new MetricDefinition(
                'queue_jobs_pending',
                MetricType::Gauge,
                MetricStorage::Runtime,
                'Trabajos esperando en cada cola en este momento. Se calcula en el scrape: es un estado, no un acontecimiento.',
                ['queue'],
            ),
            new MetricDefinition(
                'queue_job_duration_seconds',
                MetricType::Histogram,
                MetricStorage::Histogram,
                'Duracion de cada trabajo en cola, por clase corta del trabajo.',
                ['job'],
            ),
            new MetricDefinition(
                'queue_jobs_failed_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Trabajos que agotaron sus intentos, por clase corta del trabajo.',
                ['job'],
            ),
            new MetricDefinition(
                'db_query_duration_seconds',
                MetricType::Histogram,
                MetricStorage::Histogram,
                'Duracion de las consultas a PostgreSQL, agrupadas por operacion. Nunca la sentencia: un valor ligado puede ser un nombre.',
                ['operation'],
            ),

            // ---------------------------------------------------------------
            // Negocio.
            // ---------------------------------------------------------------
            new MetricDefinition(
                'scans_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Escaneos procesados, por quiosco y desenlace. El desenlace detallado solo sale por aqui, nunca al cliente (RS-03).',
                ['device', 'result'],
            ),
            new MetricDefinition(
                'scan_processing_duration_seconds',
                MetricType::Histogram,
                MetricStorage::Histogram,
                'Tiempo de proceso de un fichaje en el servidor (RNF-P-01).',
            ),
            new MetricDefinition(
                'kiosk_last_seen_seconds',
                MetricType::Gauge,
                MetricStorage::LabelledHash,
                'Momento del ultimo latido de cada quiosco, en segundos desde epoch.',
                ['device'],
            ),
            new MetricDefinition(
                'kiosk_offline_queue_size',
                MetricType::Gauge,
                MetricStorage::LabelledHash,
                'Fichajes pendientes de sincronizar que declara cada quiosco en su latido.',
                ['device'],
            ),
            new MetricDefinition(
                'kiosk_pairing_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Emparejamientos de quiosco por desenlace; los rechazos llevan ademas su motivo.',
                ['result', 'reason'],
            ),
            new MetricDefinition(
                'sync_delay_seconds',
                MetricType::Histogram,
                MetricStorage::Histogram,
                'Tiempo que espero en la tablet el fichaje mas antiguo de cada lote sincronizado. Dice si una cola drena o lleva media jornada atascada.',
                ['device'],
            ),
            new MetricDefinition(
                'manual_corrections_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Correcciones manuales aplicadas, por motivo del Anexo C. Sin etiqueta que identifique a nadie.',
                ['reason_code'],
            ),
            new MetricDefinition(
                'anomalous_patterns_detected_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Hallazgos de la revision automatica nocturna, por tipo de anomalia (RF-PR-01).',
                ['pattern'],
            ),

            // ---------------------------------------------------------------
            // Impacto y adopcion (RF-IN-08).
            // ---------------------------------------------------------------
            new MetricDefinition(
                'scans_by_origin_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Fichajes registrados por origen: tarjeta QR, PIN o alta manual. Es el reparto que responde a si la tarjeta esta funcionando.',
                ['origin'],
            ),
            new MetricDefinition(
                'incident_resolution_seconds',
                MetricType::Histogram,
                MetricStorage::Histogram,
                'Tiempo desde que se abre una incidencia hasta que se resuelve, por tipo.',
                ['type'],
            ),
            new MetricDefinition(
                'application_errors_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Ocurrencias del historico de errores (RF-PD-15). Sube con cada repeticion de un fallo ya conocido.',
                ['source', 'level'],
            ),
            new MetricDefinition(
                'application_error_groups_opened_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Grupos de error creados o reabiertos (RF-PD-15). Sube solo con un problema NUEVO: es la que sostiene la alerta.',
                ['source', 'level'],
            ),
            new MetricDefinition(
                'worked_minutes_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Minutos trabajados acumulados por centro y departamento.',
                ['site', 'department'],
            ),
            new MetricDefinition(
                'report_exports_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Exportaciones de informe generadas, por formato.',
                ['format'],
            ),
            new MetricDefinition(
                'installation_setting_changes_total',
                MetricType::Counter,
                MetricStorage::ScalarKeys,
                'Cambios de configuracion de la instalacion, separando los que alteran el calculo de horas.',
                ['affects_worked_hours'],
            ),
            new MetricDefinition(
                'compliance_profile_changes_total',
                MetricType::Counter,
                MetricStorage::ScalarKeys,
                'Cambios del perfil de cumplimiento, por efecto: cualquiera, deteccion de incidencias o retencion.',
                ['effect'],
            ),
            new MetricDefinition(
                'license_limit_exceeded_total',
                MetricType::Counter,
                MetricStorage::ScalarKeys,
                'Veces que una operacion topo con un limite del plan. Nunca bloquea el fichaje (ADR-019).',
                ['limit'],
            ),

            // ---------------------------------------------------------------
            // Autenticacion (OWASP A09).
            // ---------------------------------------------------------------
            new MetricDefinition(
                'kronoqr_auth_attempts_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Intentos de autenticacion por canal y desenlace. Ninguna etiqueta identifica a nadie (regla dura 21).',
                ['channel', 'outcome'],
            ),

            // ---------------------------------------------------------------
            // Credenciales.
            // ---------------------------------------------------------------
            new MetricDefinition(
                'pin_fallback_scans_total',
                MetricType::Counter,
                MetricStorage::LabelledHash,
                'Fichajes con PIN por centro. Una subida delata tarjetas rotas o una remesa sin entregar.',
                ['site'],
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (MetricDefinition $definition): string => $definition->name, self::all());
    }
}
