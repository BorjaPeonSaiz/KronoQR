# reports

Informes del panel. Tareas 1.17 (exportación para la Inspección), 2.8 (horas
por periodo) y 3.13 (cuadro de impacto y adopción).

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

## Qué hay aquí

| Fichero                     | Qué hace                                                                                                                                 |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `legalExport.api.ts`        | Cliente de `GET /reports/legal-export` (RF-IN-05, RL-06). Descarga un CSV y lo suelta; no parsea nada.                                   |
| `LegalExportView.vue`       | La pantalla del requerimiento de Inspección: periodo, alcance opcional por persona y las cifras que devolvió el servidor.                |
| `periodReport.api.ts`       | Cliente de `GET /reports/period` (RF-IN-01..03), con los parámetros en camelCase que usa el panel.                                       |
| `PeriodReportView.vue`      | Formulario de periodo, granularidad y agrupación; tabla de resultados; aviso de cobertura de contrato; criterios de inclusión visibles.  |
| `PeriodReportTable.vue`     | Las filas: sujeto, periodo recortado, trabajadas, contratadas, desviación, exceso y los cuatro contadores de días.                       |
| `adoptionReport.api.ts`     | Cliente de `GET /reports/adoption` y `.../export` (RF-IN-08, RNF-D-01). Ver «El cuadro de impacto y adopción» más abajo.                 |
| `AdoptionDashboardView.vue` | «Impacto y adopción»: una tarjeta por indicador con objetivo del §1.3, rosco de origen y barras de comparación, criterios y exportación. |

## Aquí no se calcula ninguna hora

Regla dura 7. `worked_minutes` y su `HH:MM` vienen **los dos** del servidor, que
es el único que lee la proyección de jornadas (`daily_totals`). Sumar o formatear
horas en el navegador sería una segunda forma de calcular lo mismo, y el día que
discreparan la pantalla enseñaría una cosa y el CSV de la tarea 2.9 otra.

Lo mismo con la desviación y el exceso: son campos de la respuesta, no una resta
hecha aquí.

## Las horas se leen en `HH:MM`, nunca en decimal

«7,75 h» obliga a interpretar y además cambia de sentido según el separador
decimal de quien lo lea. Los minutos enteros van en el `title` de cada celda,
para quien necesite el número exacto sin que la tabla lo grite.

## Los criterios de inclusión son parte del informe

`meta.criteria` llega **ya traducido** al idioma de la petición y se pinta tal
cual, sin reordenar ni resumir. Sin esa lista, la tabla es un conjunto de números
que cada persona interpreta a su manera —¿cuenta el turno que sigue abierto? ¿y
el tramo que se anuló?— y esa interpretación acaba discutiéndose en una reunión
de nómina.

## El informe no se pide al abrir la pantalla

Es una consulta cara: cruza la plantilla con el calendario. Generarla con un
rango inventado gastaría la misma base de datos que atiende el fichaje
(RNF-P-02) para dar una cifra que nadie ha pedido. El botón está deshabilitado
hasta que hay las dos fechas, que además son obligatorias en el contrato.

## Un error retira el informe anterior

Si el periodo pedido no cabe en una respuesta síncrona (`422`, RNF-P-05), la
tabla anterior desaparece. Dejarla en pantalla junto al mensaje de error haría
creer que esas cifras valen para el periodo que se acaba de pedir, y no valen
para ninguna.

## El aviso de cobertura va antes de la tabla

`meta.contract_coverage.complete` a `false` significa que hay días del periodo
sin contrato registrado. Esos días no suman horas contratadas, así que la
desviación de esas filas sale enorme y **con aspecto de dato bueno**. El aviso va
delante y no en una nota al pie.

## El cuadro de impacto y adopción (tarea 3.13, RF-IN-08)

Solo `admin` y `rrhh` (Anexo B del doc 01): ni `responsable_departamento` ni
`auditor` ven la entrada del menú ni pueden abrir la pantalla por URL
(`REPORTS_MANAGE`, el mismo ámbito que «Informes» y «Nómina»).

### Tipos locales provisionales, calcados del contrato real

`backend-laravel` cerró `GET /api/v1/reports/adoption` en
`docs/api/openapi.yaml` (esquemas `AdoptionReport`, `AdoptionIndicator`,
`AdoptionTarget`…) mientras se escribía esta pantalla, pero
`shared/api/schema.d.ts` todavía no se ha regenerado desde ese contrato. Los
tipos de `adoptionReport.api.ts` son una copia literal de esos esquemas: en
cuanto `schema.d.ts` incluya `Schemas['AdoptionReport']`, sustituirlos es un
cambio de importación, no de forma. `tests/e2e/support/admin.ts` hace lo mismo
con su propia copia local, y por el mismo motivo: importar el tipo desde
`adoptionReport.api.ts` arrastraría el `import.meta.env` de
`@kronoqr/web-kit/http` a un proyecto de TypeScript (`tsconfig.e2e.json`) que
no carga los tipos de Vite.

### Doce indicadores, seis con objetivo

El contrato siempre trae los doce indicadores del §1.3 y del bloque RF-IN-08,
en el mismo orden y con `current: null` cuando no hay dato (nunca ausente).
Esta pantalla les da dos tratamientos distintos:

- **Seis tarjetas propias** (`PRIMARY_INDICATOR_KEYS`), una por cada
  indicador que lleva un objetivo del §1.3: jornadas completas, fichajes por
  QR, correcciones, disponibilidad, tiempo de resolución y la línea base de
  horas/mes. Cada una enseña el valor, el objetivo, el estado («dentro»/«fuera»
  con texto e icono, nunca solo color) y la variación contra el periodo
  anterior — vacía, nunca `0`, cuando no hay con qué comparar.
- **Seis datos secundarios**, sin tarjeta propia: `offline_resolved_ratio`
  (junto a la disponibilidad), `incident_resolution_median_minutes` (junto a
  la media), `open_incidents` y `employees_without_credential` (fotos de hoy)
  y `worked_minutes`/`contracted_minutes` (horas trabajadas frente a
  contratadas).

El objetivo `reduction` (la línea base de horas/mes) es un caso especial: el
producto no puede medir si se consiguió, así que se enseña como referencia,
sin badge de «dentro/fuera».

### Nada se calcula aquí

Regla dura 7. Todos los porcentajes, deltas y minutos vienen resueltos del
servidor; esta pantalla solo convierte minutos a horas y minutos enteros
(`formatMinutes`, nunca decimales ambiguos) y arma las tarjetas.

### Gráficos con tabla de datos alternativa

`ChartWithTable.vue` (`shared/ui/`) es el primer uso de ECharts del proyecto:
un rosco del reparto por origen (periodo actual) y unas barras con los cuatro
indicadores porcentuales actual frente a anterior. Ver la documentación del
propio componente para el porqué de cada decisión (carga diferida, colores de
`--kq-*`, degradación si el lienzo no llega a montarse).

### Lo que no está aquí

Desglose por departamento o por quiosco, tendencia de más de dos periodos,
envío programado y comparación contra el mismo periodo del año anterior:
fuera de alcance de la tarea 3.13 (regla dura 21, un agregado de la
instalación entera y no una herramienta de vigilancia por persona).

## Lo que no está aquí, y de quién es

Las exportaciones CSV/XLSX/PDF del informe por periodo son la tarea 2.9, y se
generan **desde el mismo objeto de resultado del servidor**: el fichero que
alguien adjunta a un correo y la tabla que ve en pantalla se calculan una sola
vez para que no puedan discrepar.
