# Simulacro de alertas (`make alerts-drill`)

**Solo del fabricante y de la CI.** No viaja en el paquete de entrega, no se
copia a ninguna imagen y se niega a ejecutarse si `APP_ENV` no es `local` o
`testing` (misma guarda que las semillas de desarrollo, SC7-03). Nunca se lanza
contra la instalación de un cliente.

Cierra V4-DV-3 de la verificación 2.2.0: 34 de las 61 alertas no habían saltado
nunca de verdad y no había forma repetible de dispararlas. Una alerta que nunca
ha saltado es una alerta de la que no se sabe si salta.

## Qué hace

1. Levanta una pila **aparte** (proyecto `kq-drill`, sin puertos publicados, sin
   volúmenes con nombre): `node-exporter` con su colector `textfile`, el
   Prometheus de la imagen de producción con las **reglas reales** de
   `infra/observability/prometheus/rules/`, y el **Alertmanager real** (su
   plantilla y su `render-config.sh`, con destinatarios que responden 404 para
   que la entrega falle de verdad).
2. Publica un estado sano (`baseline.prom`) y comprueba que ninguna de las
   alertas del simulacro está activa.
3. Para cada alerta aplica su falla (`faults.tsv`) y espera a que aparezca en la
   API de Alertmanager (`/api/v2/alerts`), mostrando el estado y el receptor al
   que se enruta. Después restaura el estado sano.
4. Lista las alertas que no se pueden fabricar (`not-fabricable.tsv`), cubiertas
   por `promtool test rules`.
5. Destruye la pila (salvo `KQ_DRILL_KEEP=1`).

```bash
make alerts-drill                                   # las 33, unos 8 minutos
make alerts-drill ALERT=RoturaDeCadenaDeAuditoria   # una sola, medio minuto
infra/drill/alerts-drill.sh --list                  # inventario, sin Docker
```

`KQ_DRILL_TIMEOUT` (90 s por omisión) fija cuánto se espera a cada alerta. En la
CI lo ejecuta `.github/workflows/alerts-drill.yml`: en cada cambio de
`infra/observability/` o de esta carpeta, y cada lunes.

## Qué demuestra y qué no

**Demuestra** el camino serie → regla → Prometheus → Alertmanager → ruta: que la
expresión de la regla puede ser cierta con datos plausibles y que la alerta llega
con la etiqueta de destinatario que enruta a quien toca. Encontró ya una alerta
muerta: `ParticionDeAuditoriaDelProximoAnoSinPreparar` llevaba un `and` sin
`on()` y no podía saltar nunca.

**No demuestra** que el producto emita esas series: eso lo prueban las pruebas
PHP de cada métrica. Tampoco el `for:` (el simulacro lo pone a 0 s y el
`interval:` de los grupos a 5 s, porque esperar 24 h no es repetible): la
persistencia la prueban los casos de `promtool test rules` de
`infra/observability/prometheus/tests/`. Tampoco la entrega real al buzón de un
cliente: eso es la prueba de entrega de `docs/runbooks/entrega-de-alertas.md` §4.2.

## Las 34 alertas

La columna «Falla» es lo que cambia en `baseline.prom` (ver `faults.tsv`). La
columna «Real» es cómo se produciría la misma condición en una instalación; en
ninguna se fabrica a propósito, porque romper la instalación es lo que el
simulacro evita.

| Alerta | Falla | Receptor | Real |
| --- | --- | --- | --- |
| `AlmacenDeMetricasAusente` | retira `kronoqr_metrics_store_up` | it-cliente | Redis parado o inalcanzable desde la app |
| `ArchivadoDeWalFallando` | `kronoqr_wal_archive_failing 1` | it-cliente | destino de copias sin montar o sin clave del WAL |
| `CertificadoTlsCaducado` | caducidad un día en el pasado | it-cliente | certificado vencido sin renovar |
| `CertificadoTlsNoVerificable` | sonda verificada a 0 con la sonda básica a 1 | it-cliente | cadena incompleta o nombre que no coincide |
| `CertificadoTlsProximoACaducar` | caducidad a 10 días | it-cliente | certificado a punto de vencer |
| `ColaOfflineAtascada` | `kiosk_offline_queue_size` 80 | it-cliente | quiosco con red caída varias horas |
| `ConciliacionCompletaDelRegistroAusente` | última conciliación completa hace 9 días | seguridad | `scheduler` parado más de una semana |
| `ConciliacionDelRegistroAusente` | retira la serie de la conciliación reciente | seguridad | `scheduler` parado o tarea sin ejecutarse |
| `CopiaDeSeguridadAusente` | retira `kronoqr_backup_last_success_timestamp_seconds` | it-cliente | cron de copias que nunca corrió o carpeta de métricas sin montar |
| `DescansoEntreJornadasInsuficiente` | `incidents_open{type="insufficient_rest"}` 2 | rrhh | dos jornadas con menos descanso del perfil |
| `DeteccionDeIncidenciasConFallos` | `incident_detection_last_failures` 1 | it-cliente | excepción en la detección nocturna |
| `DeteccionDePatronesConFallos` | `pattern_detection_last_failures` 1 | it-cliente | excepción en la detección de patrones |
| `DiscoDeCopiasCasiLleno` | `kronoqr_backup_volume_free_ratio` 0,10 | it-cliente | volumen de copias al 90 % |
| `EntregaDeAlertasFallando` | ninguna: las entregas de las demás fallan de verdad | it-cliente | SMTP rechaza, contraseña rotada, webhook caído |
| `EspacioEnDiscoBajo` | `node_filesystem_avail_bytes` al 10 % | it-cliente | disco del servidor lleno |
| `FicheroGeneradoSinRetirarPasadoSuPlazo` | `generated_files_overdue` 3 | it-cliente | purga programada que no retira |
| `KioskDiscardedScansAttributed` | `kiosk_discarded_scans_total{attributed="true"}` +5 | it-cliente | quiosco descartó fichajes atribuibles a una tarjeta |
| `KioskQueueStorageDegraded` | `kiosk_queue_storage_degraded` 1 | it-cliente | IndexedDB del quiosco degradado |
| `KioskUnreportedDiscards` | `kiosk_unreported_discards` 4 | it-cliente | descartes que el quiosco no pudo notificar |
| `KronoqrManagementAdminAccountCreated` | `..._changes_total{action="created",role="admin"}` +3 | seguridad | alta de una cuenta administradora |
| `KronoqrManagementTwoFactorReset` | `..._changes_total{action="two_factor_reset"}` +3 | seguridad | restablecimiento de doble factor |
| `MedicionDeWalAusente` | último latido de `wal-metrics.sh` hace 1 h | it-cliente | `scheduler` parado |
| `MetricaDeIncidenciasAusente` | retira `incidents_metrics_timestamp_seconds` | it-cliente | tarea de incidencias sin ejecutarse |
| `ParticionDeAuditoriaAusente` | `audit_log_partition_ready{horizon="current"}` 0 | it-cliente | falta la partición del año |
| `ParticionDeAuditoriaDelProximoAnoSinPreparar` | **no fabricable** (depende de `month() >= 11`) | it-cliente | noviembre sin partición del año siguiente |
| `PurgaDeFicherosGeneradosNoPuedeBorrar` | `generated_files_remove_failed_total` +3 | it-cliente | permisos del disco de ficheros |
| `RechazoDeFirmaQr` | `scans_total{result="rejected_signature"}` +60 | seguridad | ráfaga de QR con firma falsa |
| `ReconciliacionConFallos` | `projection_reconciliation_last_failures` 1 | it-cliente | excepción al reconciliar proyecciones |
| `RoturaDeCadenaDeAuditoria` | `audit_chain_verification_failures_total` 1 | seguridad | `audit_log` alterado (no se fabrica: sería romper la cadena) |
| `SaturacionDelBordeEnElFichaje` | `http_requests_total{route="attendance.scan",status="429"}` +60 | it-cliente | cuota del borde agotada en el fichaje |
| `ScanBatchItemNotProcessed` | `scan_batch_items_not_processed_total` +12 | it-cliente | lote del quiosco con elementos sin procesar |
| `SimulacroDeRestauracionCaducado` | `kronoqr_backup_restore_drill_last_result` 0 | it-cliente | último simulacro de restauración fallido |
| `SimulacroDeRestauracionNuncaEjecutado` | retira `..._restore_drill_last_success_timestamp_seconds` | it-cliente | instalación sin simulacro trimestral |
| `SlotDeReplicacionParado` | `kronoqr_wal_replication_slots_inactive` 1 | it-cliente | slot creado a mano que retiene WAL |

Las que **no se pueden fabricar** van en `not-fabricable.tsv` con su motivo, y
`AlertsDrillTest` exige que `promtool` tenga un caso que las dispare:
`ParticionDeAuditoriaDelProximoAnoSinPreparar` (el reloj de la pila es el real y
hasta el 1 de noviembre `month()` no llega a 11) la cubre
`prometheus/tests/audit-noviembre.test.yml`, que sitúa la evaluación en noviembre.

## Cómo añadir una receta

1. La serie que lee la regla tiene que estar en `baseline.prom` con un valor sano.
2. Una línea en `faults.tsv` (`ALERTA<TAB>set|del|none<TAB>argumento`).
3. Una fila en la tabla de arriba (`AlertsDrillTest` lo exige para las 34).
4. `make alerts-drill ALERT=NombreDeLaAlerta`.

Si `alerts-drill.sh` dice «ESTADO SANO NO SILENCIOSO», la regla lee una serie que
el estado sano no declara (o `absent()` dispara con todo presente).
