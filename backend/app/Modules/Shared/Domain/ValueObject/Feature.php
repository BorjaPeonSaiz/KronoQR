<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Las funcionalidades **accesorias** del producto: las unicas que una licencia
 * puede apagar (**ADR-023**, RF-PD-05, regla dura 15).
 *
 * ## Este enum ES la frontera, no una copia de ella
 *
 * ADR-023 divide el producto en dos conjuntos y advierte de que el riesgo no es
 * no tener la lista, sino que cada quien la decida en el sitio con un
 * `if (license.expired)`. Aqui esa lista deja de ser prosa: **el conjunto
 * degradable es exactamente el de estos casos**, y el conjunto legal
 * —fichaje, cola offline, consulta de jornadas, portal, exportacion para la
 * Inspeccion, auditoria, correcciones, copias y sondas— **no tiene caso**.
 *
 * Eso es mas fuerte que confiar en que nadie lo desactive: no existe forma de
 * expresar su desactivacion. `Shared/Application/Port/FeatureGate`
 * solo acepta este tipo, asi que no hay ninguna cadena que alguien pueda pasar
 * para preguntar si el fichaje esta habilitado. La pregunta no se puede ni
 * formular.
 *
 * ## Lo no clasificado es NO degradable
 *
 * La segunda regla de ADR-023, y sale gratis con este diseño: una funcionalidad
 * nueva que nadie añada aqui no tiene forma de apagarse. El valor por defecto de
 * lo no clasificado es «el registro gana», que es lo que el ADR pide.
 *
 * ## Los valores viajan dentro de la clave firmada
 *
 * El campo `features` de la licencia (doc 01 §5) enumera **estas** cadenas. Un
 * valor desconocido en una clave emitida por una version posterior del producto
 * se ignora al leerla —no se rechaza la licencia entera— y esa tolerancia esta
 * documentada en `Product/Domain/ValueObject/License`.
 *
 * `tests/Architecture/LicenseBoundaryTest.php` ata estos casos con la tabla
 * «Degradable — accesorio» de `docs/adr/ADR-023-*.md`: si alguien añade un caso
 * que el ADR no lista, o retira uno que si, la prueba falla. La lista es
 * contractual antes que tecnica y por eso la manda el documento.
 */
enum Feature: string
{
    /**
     * Informes avanzados y comparacion entre periodos (RF-IN-01..03, tarea 2.8).
     *
     * Es `GET /api/v1/reports/period` y su exportacion. **No es la consulta del
     * registro**: las jornadas de una persona (`GET /employees/{uuid}/workdays`),
     * el portal (`GET /me/workdays`) y la exportacion para la Inspeccion
     * (`GET /reports/legal-export`) son registro legal y no se tocan.
     */
    case AdvancedReports = 'advanced_reports';

    /** Cuadro de impacto y adopcion (RF-IN-08). Material de gestion. Llega en la Fase 3. */
    case ImpactDashboard = 'impact_dashboard';

    /** Exportacion configurable para nomina (RF-IN-07). Llega en la Fase 3. */
    case PayrollExport = 'payroll_export';

    /** Resumen semanal por correo (RF-PR-05). Llega en la Fase 3. */
    case WeeklyEmailSummary = 'weekly_email_summary';

    /**
     * Presencia en **tiempo real** por WebSocket (RF-PA-01, tarea 2.4).
     *
     * **La unica degradacion parcial de ADR-023**: sin ella la vista no se
     * apaga, pasa a sondear cada `poll_interval_seconds` (ADR-011). Apagarla del
     * todo se percibiria como una averia y no como una licencia vencida, y
     * generaria una llamada de soporte en lugar de una renovacion.
     */
    case RealtimePresence = 'realtime_presence';

    /** Marca blanca (RF-PD-08). Al caducar se vuelve a la marca del fabricante. Llega en la 5.8. */
    case WhiteLabel = 'white_label';

    /** Telemetria opcional (RF-PD-12). Ya viene desactivada de serie. Llega en la 5.10. */
    case Telemetry = 'telemetry';

    /**
     * Las que ya tienen consumidor en el codigo de hoy.
     *
     * Existe para que la documentacion, la pantalla de licencia y `license:show`
     * puedan distinguir «esto se apagara» de «esto se apagara cuando exista», en
     * lugar de prometerle al cliente una degradacion de algo que todavia no ha
     * comprado. Las cuatro restantes entran con su tarea (3.x, 5.8 y 5.10).
     *
     * @return list<self>
     */
    public static function implemented(): array
    {
        return [self::AdvancedReports, self::RealtimePresence];
    }

    public function isImplemented(): bool
    {
        return \in_array($this, self::implemented(), true);
    }
}
