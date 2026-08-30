# reports

Informes del panel. Tareas 1.17 (exportación para la Inspección) y 2.8 (horas por
periodo).

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

## Qué hay aquí

| Fichero                 | Qué hace                                                                                                                                |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| `legalExport.api.ts`    | Cliente de `GET /reports/legal-export` (RF-IN-05, RL-06). Descarga un CSV y lo suelta; no parsea nada.                                  |
| `LegalExportView.vue`   | La pantalla del requerimiento de Inspección: periodo, alcance opcional por persona y las cifras que devolvió el servidor.               |
| `periodReport.api.ts`   | Cliente de `GET /reports/period` (RF-IN-01..03), con los parámetros en camelCase que usa el panel.                                      |
| `PeriodReportView.vue`  | Formulario de periodo, granularidad y agrupación; tabla de resultados; aviso de cobertura de contrato; criterios de inclusión visibles. |
| `PeriodReportTable.vue` | Las filas: sujeto, periodo recortado, trabajadas, contratadas, desviación, exceso y los cuatro contadores de días.                      |

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
para ninguno.

## El aviso de cobertura va antes de la tabla

`meta.contract_coverage.complete` a `false` significa que hay días del periodo
sin contrato registrado. Esos días no suman horas contratadas, así que la
desviación de esas filas sale enorme y **con aspecto de dato bueno**. El aviso va
delante y no en una nota al pie.

## Lo que no está aquí, y de quién es

El cuadro de impacto y las comparaciones visuales avanzadas son de la tarea
3.13, con agente propio y dependiendo de indicadores que la 2.8 todavía no
calcula. Las exportaciones CSV/XLSX/PDF de este mismo informe son la 2.9, y se
generan **desde el mismo objeto de resultado del servidor**: el fichero que
alguien adjunta a un correo y la tabla que ve en pantalla se calculan una sola
vez para que no puedan discrepar.
