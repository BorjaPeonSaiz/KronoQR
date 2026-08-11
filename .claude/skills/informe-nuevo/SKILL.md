---
name: informe-nuevo
description: Añade un informe o exportación al módulo Reporting — consulta de lectura, proyección si hace falta, endpoint, generación asíncrona, formatos CSV/XLSX/PDF y prueba de rendimiento con volumen real. Úsalo para informes de horas, cumplimiento, absentismo o exportaciones para nómina e Inspección de Trabajo.
---

# Añadir un informe

Los informes de este sistema acaban en decisiones de nómina y en manos de una inspección. Precisión y reproducibilidad antes que velocidad.

## Paso 1 — Definir la pregunta exacta

Antes de escribir SQL, escribe en una frase qué pregunta responde el informe y para quién. Después concreta:

- **Granularidad:** ¿por empleado, departamento, centro? ¿por día, semana, mes, rango libre?
- **Zona horaria:** los datos están en UTC; el informe se presenta en la zona del centro. Si cruza centros con zonas distintas, **decide y documenta** cómo se agrega.
- **Qué cuenta y qué no:** ¿los turnos abiertos se incluyen? ¿los tramos anulados? ¿los que tienen incidencia sin resolver? Estas decisiones cambian el resultado y deben quedar escritas en el propio informe, visibles para quien lo lee.
- **Días sin actividad:** ¿aparecen con cero o se omiten? Para un informe de absentismo, omitirlos es un error.

## Paso 2 — Elegir la fuente

| Situación | Fuente |
|---|---|
| Agregados por empleado y día | `daily_totals` (proyección) |
| Detalle de tramos | `shift_entries` |
| Análisis de escaneos, rechazos, sincronización | `scan_events` |
| Trazabilidad de correcciones | `shift_corrections` + `audit_log` |

**Nunca recalcules desde `scan_events` lo que ya está en `daily_totals`.** Si los números no cuadran, el problema es la proyección: ejecuta `attendance:reconcile` y averigua por qué divergió, no lo parchees en el informe.

## Paso 3 — Consulta

En `Modules/Reporting/Application/Query/`. Aprovecha PostgreSQL:

- `generate_series` para incluir los días sin actividad
- Funciones de ventana para acumulados y comparativas con el periodo anterior
- `AT TIME ZONE` para agrupar por día en la zona del centro, no en UTC
- Filtro por ámbito del rol **dentro de la consulta**, no después en PHP

**Cuidado con la agrupación por fecha:** agrupar por `date_trunc('day', clocked_in_at)` en UTC produce resultados incorrectos para turnos nocturnos. Usa `work_date`, que ya está calculado según RN-05.

## Paso 4 — Índices

Ejecuta `EXPLAIN ANALYZE` con volumen realista (500 empleados × 2 años ≈ 400.000 filas en `daily_totals`). Si hay un *sequential scan* sobre una tabla grande, falta un índice. Añádelo con `CREATE INDEX CONCURRENTLY` mediante la skill `migracion-segura`.

## Paso 5 — Síncrono o asíncrono

- **< 5 s:** respuesta directa
- **≥ 5 s o más de 3 meses de datos:** job en cola, notificación al terminar y enlace de descarga con caducidad

El enlace de descarga lleva token de un solo uso y expiración: contiene datos personales de la plantilla.

## Paso 6 — Formatos

| Formato | Cómo |
|---|---|
| CSV | `spatie/simple-excel` en streaming. UTF-8 con BOM para que Excel no rompa los acentos. Separador según *locale* |
| XLSX | Streaming. Cabeceras congeladas, columnas con ancho, horas como texto `HH:MM` (no como decimal: nadie interpreta bien 7,75) |
| PDF | `spatie/laravel-pdf`. Con pie de página: fecha de generación, usuario emisor, periodo, y **hash del contenido** |

**Exportación legal para Inspección:** formato tabular legible y tratable, no propietario. Debe incluir por trabajador y día: hora de inicio y fin de cada tramo, total, y **las correcciones con su autor, fecha y motivo**. Un informe que oculte las correcciones no cumple.

## Paso 7 — Autorización

El ámbito se aplica en la consulta: un responsable de Cocina no puede obtener datos de Recepción ni siquiera agregados. Toda generación de informe con datos de terceros **se registra en `audit_log`**: quién, qué periodo, qué empleados.

## Paso 8 — Pruebas

- [ ] Corrección del cálculo con un conjunto de datos conocido y resultado verificado a mano
- [ ] Turnos nocturnos agrupados en la jornada correcta
- [ ] Semana con cambio de hora: el total refleja las 23 o 25 horas reales
- [ ] Empleado dado de baja a mitad de periodo
- [ ] Días sin actividad tratados según lo decidido
- [ ] Autorización: un rol sin ámbito no obtiene datos ajenos
- [ ] Rendimiento: `EXPLAIN ANALYZE` con volumen real, dentro del presupuesto
- [ ] Exportación: el fichero abre correctamente en Excel y LibreOffice, con acentos

## Lista de comprobación de entrega

- [ ] Pregunta, granularidad y criterios de inclusión documentados **y visibles en el informe**
- [ ] Zona horaria resuelta correctamente; agrupación por `work_date`
- [ ] Fuente correcta; sin recálculos paralelos a la proyección
- [ ] `EXPLAIN ANALYZE` limpio con volumen realista
- [ ] Asíncrono si supera el umbral, con descarga caducable
- [ ] Formatos correctos; horas como `HH:MM`
- [ ] Ámbito aplicado en consulta y generación auditada
- [ ] Las ocho pruebas en verde
