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

    /**
     * Cuadro de impacto y adopcion (RF-IN-08, tarea 3.13). Material de gestion.
     *
     * Con consumidor desde esa tarea: `AdoptionReportController` y
     * `AdoptionReportExportController` preguntan por ella y responden `402`.
     */
    case ImpactDashboard = 'impact_dashboard';

    /**
     * Exportacion configurable para nomina (RF-IN-07, tarea 3.9).
     *
     * Con consumidor desde esa tarea: `PayrollExportController` y la rama
     * `payroll` de `ReportExportController` preguntan por ella y responden `402`.
     */
    case PayrollExport = 'payroll_export';

    /**
     * Resumen semanal por correo (RF-PR-05, tarea 3.12).
     *
     * Con consumidor desde esa tarea: `SendWeeklySummaries` pregunta por ella
     * antes de componer nada y registra `not_in_plan` cuando el plan no la trae.
     */
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

    /**
     * Marca blanca (RF-PD-08, tarea 5.8).
     *
     * Sin ella se vuelve a la marca del **fabricante** —`KronoQR`, sin logotipo
     * y con el acento de serie— en las tres aplicaciones, en la tarjeta impresa,
     * en el informe sellado y en la cabecera de la exportacion legal. La decision
     * la aplica un unico decorador del puerto `BrandingProvider`; ningun
     * consumidor la conoce.
     *
     * **Lo guardado no se pierde.** Las tres claves `BRANDING_*` se siguen
     * pudiendo ver y editar —la licencia jamas cierra la configuracion (regla
     * dura 15)—, las filas no se tocan y vuelven a aplicarse solas al renovar.
     */
    case WhiteLabel = 'white_label';

    /**
     * Telemetria opcional (RF-PD-12, tarea 5.10).
     *
     * **Ya viene desactivada de serie**, y hacen falta tres cosas a la vez para
     * que envie algo: `TELEMETRY_ENABLED`, un `TELEMETRY_ENDPOINT` y esta
     * funcionalidad en el plan. Que este aqui significa que una licencia
     * caducada la apaga -es accesoria, ADR-023-, y apagarla no degrada nada:
     * el producto funciona identicamente sin ella (RF-PD-12).
     */
    case Telemetry = 'telemetry';

    /**
     * Las que ya tienen consumidor en el codigo de hoy: **desde el cierre de la
     * Fase 3, las siete**.
     *
     * Nacio para que la documentacion, la pantalla de licencia y `license:show`
     * pudieran distinguir «esto se apagara» de «esto se apagara cuando exista»,
     * en lugar de prometerle al cliente la degradacion de algo que todavia no
     * habia comprado. Eran cuatro porque tres de los siete casos no tenian aun
     * quien preguntara por ellos.
     *
     * Ya no queda ninguno. Las tres que faltaban estrenaron consumidor en la
     * Fase 3 y las tres responden `402` con la licencia caducada:
     *
     * | Caso | Quien pregunta | Tarea |
     * |---|---|---|
     * | `PayrollExport` | `PayrollExportController`, `ReportExportController` | 3.9 |
     * | `WeeklyEmailSummary` | `SendWeeklySummaries` | 3.12 |
     * | `ImpactDashboard` | `AdoptionReportController`, `AdoptionReportExportController` | 3.13 |
     *
     * **Mantenerlas fuera era peor que no tener la lista**: los cuatro
     * consumidores de `isImplemented()` —`LicenseShowCommand`, `LicenseResource`,
     * `LicenseCollector` y la pantalla de licencia del panel— filtran por ella, y
     * con la licencia vencida el cliente recibia un `402` del endpoint y, en la
     * misma pantalla, un texto diciendo que esa funcionalidad *«todavia no
     * existe, asi que no se pierde nada»*. Es exactamente la promesa incumplida
     * que esta lista existia para evitar, con el signo invertido (ADR-019,
     * ADR-023, RF-PD-05).
     *
     * ## Por que el metodo sigue existiendo aunque hoy no separe nada
     *
     * Porque separara otra vez: el catalogo lo manda ADR-023 —la tabla
     * «Degradable» del documento, atada por `LicenseBoundaryTest`— y un caso
     * nuevo se declara ahi **antes** de que exista quien pregunte por el. Ese dia
     * hay que poder decirlo, y retirar el concepto obligaria a reintroducirlo, a
     * cambiar el contrato y a tocar los cuatro consumidores por segunda vez.
     *
     * Lo que si cambia es que ahora hay una prueba que lo vigila:
     * `LicenseBoundaryTest` recorre los modulos buscando quien consulta cada caso
     * por el puerto `FeatureGate` y exige que todo caso consultado este aqui. Un
     * consumidor nuevo sobre un caso ausente de esta lista deja de ser una
     * contradiccion silenciosa en la pantalla del cliente y pasa a ser una
     * prueba roja.
     *
     * @return list<self>
     */
    public static function implemented(): array
    {
        return self::cases();
    }

    public function isImplemented(): bool
    {
        return \in_array($this, self::implemented(), true);
    }
}
