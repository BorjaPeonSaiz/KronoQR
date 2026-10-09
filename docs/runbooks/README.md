# Runbooks de KronoQR

Procedimientos de operación interna: uno por cada modo de fallo que tiene una
alerta asociada, y uno por cada situación que el cliente o el fabricante
afrontan sin alerta (una persona que entra, una norma que cambia, un servidor
que se pierde). **Los 33 viajan en el paquete de entrega** (`docs/runbooks/`):
los ejecuta el IT del cliente, con el sistema delante y a veces a las 06:30.

**La norma que gobierna esta carpeta** (doc 02 §8.4): *cada alerta lleva
destinatario, umbral y enlace a su runbook. Una alerta sin procedimiento
asociado es ruido y se elimina.* Y por el otro lado, la Definición de Terminado
del §10.3: *runbook o documentación de cliente actualizada si añade un modo de
fallo o un parámetro.* La regla de autoría es la misma desde la Fase 0: **el
runbook se escribe en el cambio que crea la alerta o el procedimiento**, con el
sistema delante; quien introduce el modo de fallo es quien sabe qué hay que
hacer cuando ocurra.

Lo hacen cumplir las pruebas de `backend/tests/Architecture/`
(`AlertCatalogueTest`, `BackupAndAlertingTest`, `ClientDocumentationTest`,
`GrafanaDashboardsTest`): una regla de Prometheus sin `runbook_url`, o cuyo
`runbook_url` no existe, rompe la CI; y `php artisan docs:consistency --check`
vigila que los documentos no se contradigan.

---

## Antes de pegar una orden: cómo se leen los comandos de estos runbooks

- **Directorio.** Todo `docker compose …` y todo `./script.sh` se lanza **desde el
  directorio vigente de la instalación** (`/opt/kronoqr-<versión>`: el que dijo
  `update.sh` al terminar, el que lleva el `docker-compose.yml` y el `.env`). Sin
  `-f`: Compose lo encuentra solo. Tras actualizar, el directorio anterior queda
  retirado y cualquier `docker compose` lanzado desde él falla a propósito
  ([`actualizacion-cliente.md`](actualizacion-cliente.md) §3).
- **Scripts.** Los de operación van en la **raíz del paquete**: `./update.sh`,
  `./doctor.sh`, `./backup.sh`, `./restore-drill.sh`, `./install.sh`. Con `sudo`
  cuando tocan el `.env` (es de `root`, `0600`). La ruta `/opt/kronoqr/scripts/…`
  que aparece en algunas órdenes es la de **dentro de las imágenes**
  (`docker compose exec scheduler bash /opt/kronoqr/scripts/backup.sh list`,
  `docker compose run --rm --no-deps restore bash /opt/kronoqr/scripts/restore.sh …`),
  no una ruta del servidor.
- **Qué contenedor para qué** (ADR-042). Copias (`backup:run`, `backup:verify`,
  `backup.sh`): `scheduler`, que recibe el rol de solo lectura `fichaje_backup`.
  Restaurar: el servicio de un solo uso `restore`. Migraciones a mano: el
  servicio `migrate`. El resto de órdenes `artisan`: `app`. Nada de eso lleva la
  credencial de migración salvo `restore` y `migrate`.
- **Base de datos.** `psql` se lanza dentro de `postgres`: con `fichaje_app` para
  leer `audit_log` y el registro (solo `SELECT`), y con `fichaje_migrator` solo
  cuando hace falta ver sesiones o catálogos del sistema.
- **Observabilidad.** Solo Grafana publica puerto (`127.0.0.1:3000`, por túnel
  SSH). Prometheus y Alertmanager se consultan desde dentro de su contenedor
  (`docker compose exec -T prometheus wget -qO- http://127.0.0.1:9090/api/v1/…`,
  `docker compose exec alertmanager amtool …`). Existen con el perfil
  `observability`.
- **Códigos de salida.** Los cinco scripts comparten una tabla única, publicada en
  [`../cliente/operacion.md`](../cliente/operacion.md) §8.
- **Sin PII.** Nada de nombres de empleados en logs, informes ni tickets: se usa
  `employee_uuid` (regla dura 21). Las consultas que muestran datos personales
  lo dicen en el propio runbook.

---

## Índice por alerta

Las 61 reglas de `infra/observability/prometheus/rules/` y el runbook al que
apunta su `runbook_url`. Si recibes una alerta, esta es la tabla.

| Runbook | Alertas |
| --- | --- |
| [`quiosco-no-responde.md`](quiosco-no-responde.md) | `QuioscoSinLatido` |
| [`cola-offline-atascada.md`](cola-offline-atascada.md) | `ColaOfflineAtascada` · `ColaOfflineSinVaciar` · `KioskQueueStorageDegraded` (§7) · `KioskUnreportedDiscards` (§8) · `ScanBatchItemNotProcessed` (§9) · `KioskDiscardedScansAttributed` (§10) |
| [`restaurar-backup.md`](restaurar-backup.md) | `CopiaDeSeguridadFallida` · `CopiaDeSeguridadSinVerificar` · `CopiaDeSeguridadAusente` · `ArchivadoDeWalDetenido` · `ArchivadoDeWalFallando` · `MedicionDeWalAusente` · `ArchiveTimeoutFueraDeRango` · `DiscoDeCopiasCasiLleno` · `SimulacroDeRestauracionNuncaEjecutado` · `SimulacroDeRestauracionCaducado` |
| [`slot-replicacion-parado.md`](slot-replicacion-parado.md) | `SlotDeReplicacionParado` |
| [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md) | `RoturaDeCadenaDeAuditoria` · `VerificacionDeAuditoriaAusente` · `ParticionDeAuditoriaAusente` · `ParticionDeAuditoriaDelProximoAnoSinPreparar` |
| [`discrepancia-registro-auditoria.md`](discrepancia-registro-auditoria.md) | `DiscrepanciaEntreRegistroYAuditoria` · `ConciliacionDelRegistroAusente` · `ConciliacionCompletaDelRegistroAusente` |
| [`divergencia-proyeccion.md`](divergencia-proyeccion.md) | `DivergenciaEnReconciliacionNocturna` · `ReconciliacionDeProyeccionAusente` · `ReconciliacionConFallos` |
| [`turno-abierto-prolongado.md`](turno-abierto-prolongado.md) | `TurnoAbiertoProlongado` · `DescansoEntreJornadasInsuficiente` · `MetricaDeIncidenciasAusente` · `DeteccionDeIncidenciasAusente` |
| [`patron-anomalo-credencial.md`](patron-anomalo-credencial.md) | `DeteccionDePatronesConFallos` · `DeteccionDePatronesAusente` |
| [`ataque-a-credenciales.md`](ataque-a-credenciales.md) | `KronoqrAuthFailureBurst` · `KronoqrAuthLockouts` · `KronoqrAuthFailureSpike` · `KronoqrManagementTwoFactorReset` · `KronoqrManagementAdminAccountCreated` · `RechazoDeFirmaQr` |
| [`bloqueo-por-origen.md`](bloqueo-por-origen.md) | `KronoqrPortalOriginLockouts` |
| [`saturacion-del-borde.md`](saturacion-del-borde.md) | `SaturacionDelBordeEnElFichaje` |
| [`errores-en-el-panel.md`](errores-en-el-panel.md) | `ErroresCriticosNuevos` · `ErroresDeServidorEnElFichaje` · `LatenciaDelFichajeAlta` · `SondaDelBordeFallida` · `DeteccionDeIncidenciasConFallos` |
| [`almacen-de-metricas-caido.md`](almacen-de-metricas-caido.md) | `AlmacenDeMetricasCaido` · `AlmacenDeMetricasAusente` |
| [`entrega-de-alertas.md`](entrega-de-alertas.md) | `EnrutadoDeAlertasCaido` · `EntregaDeAlertasFallando` |
| [`renovacion-certificado-tls.md`](renovacion-certificado-tls.md) | `CertificadoTlsProximoACaducar` · `CertificadoTlsCaducado` · `CertificadoTlsNoVerificable` |
| [`espacio-en-disco.md`](espacio-en-disco.md) | `EspacioEnDiscoBajo` · `MetricasDelAnfitrionAusentes` |
| [`ficheros-generados.md`](ficheros-generados.md) | `FicheroGeneradoDesaparecidoAntesDeCaducar` · `FicheroGeneradoSinRetirarPasadoSuPlazo` · `PurgaDeFicherosGeneradosSeHaNegadoATocarAlgo` · `PurgaDeFicherosGeneradosNoPuedeBorrar` |
| [`actualizacion-cliente.md`](actualizacion-cliente.md) | `VentanaDeMantenimientoActiva` (informativa: sostiene la inhibición durante `update.sh`, no notifica a nadie) |

---

## Índice de runbooks

### Respuesta a una alerta

| Runbook | Cuándo se usa | Destinatario |
| --- | --- | --- |
| [`quiosco-no-responde.md`](quiosco-no-responde.md) | Un quiosco lleva más de 10 min sin latido. El fichaje no se pierde: la tablet encola | IT del cliente |
| [`cola-offline-atascada.md`](cola-offline-atascada.md) | La cola de un quiosco no baja, su almacenamiento está degradado, o hay descartes y lotes sin procesar. **Nunca borrar los datos de la tablet con cola pendiente** | IT del cliente; RRHH en los descartes |
| [`divergencia-proyeccion.md`](divergencia-proyeccion.md) | La reconciliación nocturna de `daily_totals` encuentra o no resuelve una discrepancia, o no corre. Siempre cero | IT del cliente |
| [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md) | **Incidente de seguridad.** La cadena de hash de `audit_log` no verifica, no se verifica, o falta la partición. Incluye preservación de evidencia | Seguridad; IT en las particiones |
| [`discrepancia-registro-auditoria.md`](discrepancia-registro-auditoria.md) | **Posible incidente de seguridad (ADR-057 §4).** Un tramo de `shift_entries` no cuadra con su último asiento de `audit_log` (inventado, cambiado, anulado sin corrección o borrado), o la conciliación no corre | Seguridad |
| [`restaurar-backup.md`](restaurar-backup.md) | La copia o el archivado de WAL fallan; restaurar una copia (cifrada y autenticada, ADR-049) o recuperar a un punto en el tiempo; simulacro trimestral | IT del cliente |
| [`slot-replicacion-parado.md`](slot-replicacion-parado.md) | Un slot de replicación sin consumidor retiene WAL. KronoQR no usa ninguno: es también una señal de seguridad | IT del cliente |
| [`turno-abierto-prolongado.md`](turno-abierto-prolongado.md) | Turno abierto más de 12 h o descanso insuficiente. **El sistema nunca cierra un turno solo** (RN-08). No es una avería | RRHH; IT en las alertas de silencio |
| [`patron-anomalo-credencial.md`](patron-anomalo-credencial.md) | Revisar una incidencia `anomalous_pattern` sin convertir un indicio en una acusación; o la detección no corre | Responsable de departamento y RRHH; IT por el silencio |
| [`ataque-a-credenciales.md`](ataque-a-credenciales.md) | Ráfagas de fallos o bloqueos de acceso, alta de `admin` o reinicio de 2FA inesperados, firmas QR inválidas (T1110, T1136, T1098, T1606) | Seguridad |
| [`bloqueo-por-origen.md`](bloqueo-por-origen.md) | Más de 5 bloqueos de origen en el portal en una hora (T1110.003, ADR-050): origen compartido o ataque repartido; `identity:origin-unlock <ip>` | Seguridad; ejecuta el IT |
| [`saturacion-del-borde.md`](saturacion-del-borde.md) | `429` por encima de lo normal en las rutas de fichaje (T1499.002) | IT del cliente |
| [`errores-en-el-panel.md`](errores-en-el-panel.md) | Cómo lee el IT `error_events` y qué hacer con cada severidad; `5xx` y latencia del fichaje; sonda del borde; las sondas de `doctor` que ninguna alerta ve | IT del cliente |
| [`almacen-de-metricas-caido.md`](almacen-de-metricas-caido.md) | Redis (almacén de métricas) no responde o la aplicación no publica `/metrics`: las alertas de quiosco y cola no son fiables | IT del cliente |
| [`entrega-de-alertas.md`](entrega-de-alertas.md) | Alertmanager caído o sin poder entregar. No responde a una fila del catálogo del doc 01 §9.3, sino a un fallo de la propia infraestructura de alertas | IT del cliente |
| [`renovacion-certificado-tls.md`](renovacion-certificado-tls.md) | Certificado a menos de 21 días de expirar, caducado o que no verifica | IT del cliente |
| [`espacio-en-disco.md`](espacio-en-disco.md) | Menos del 20 % libre en el disco del servidor | IT del cliente |
| [`ficheros-generados.md`](ficheros-generados.md) | Exportaciones, informes y paquetes de diagnóstico del volumen `app-storage` (ADR-045): desaparecidos antes de caducar (seguridad), sin retirar, o purga que se niega a borrar | Seguridad; IT |

### Procedimientos que no responden a una alerta

| Runbook | Cuándo se usa | Lo ejecuta |
| --- | --- | --- |
| [`actualizacion-cliente.md`](actualizacion-cliente.md) | Actualizar una instalación con `update.sh` (lado a lado; encima solo desde la 2.2.0), qué significa cada salida y la vuelta atrás | IT del cliente |
| [`perdida-total-del-servidor.md`](perdida-total-del-servidor.md) | Incendio, robo, disco muerto: servidor nuevo, mismo paquete, secretos repuestos desde la custodia, copia cifrada y quioscos que reemparejar o no | IT del cliente |
| [`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) | Emparejamiento por código y vinculación de una tablet. Incluye lo que no es del producto: fijar la tablet en modo quiosco | IT del cliente |
| [`tarjeta-perdida-o-rota.md`](tarjeta-perdida-o-rota.md) | Revocación, reemisión e impresión de la nueva en el día; el caso «impresión fallida» (ADR-034) | RRHH |
| [`cuentas-de-gestion.md`](cuentas-de-gestion.md) | Alta y baja de cuentas de gestión, contraseña temporal, restablecer contraseña y 2FA, responsable de departamento, vía de recuperación por consola (ADR-051) | Cliente |
| [`portal-403.md`](portal-403.md) | El portal del empleado devuelve `403` (RF-ID-08): candado de `PORTAL_INTERNAL_CIDR`, qué IP ve el servidor y cómo abrirlo sabiendo lo que se asume | IT del cliente |
| [`rotacion-clave-qr.md`](rotacion-clave-qr.md) | Reimpresión progresiva de tarjetas sin dejar a nadie sin fichar | RRHH e IT |
| [`rotacion-secretos.md`](rotacion-secretos.md) | Rotación programada o compromiso: `APP_KEY`, base de datos, copias, WAL, Reverb, sellado de PIN | IT del cliente |
| [`requerimiento-inspeccion.md`](requerimiento-inspeccion.md) | **Cómo generar la exportación legal en menos de 1 hora** | RRHH e IT |
| [`solicitud-derechos-rgpd.md`](solicitud-derechos-rgpd.md) | Acceso, rectificación, portabilidad; la supresión **no procede** mientras dure el deber de conservación | RRHH / DPO |
| [`brecha-de-seguridad.md`](brecha-de-seguridad.md) | **Incidente de seguridad.** Procedimiento de 72 h del art. 33 RGPD, con el alcance acotado desde `audit_log` | Seguridad / DPO |
| [`vigilancia-normativa.md`](vigilancia-normativa.md) | Quién vigila el art. 34.9 ET, el convenio, la AEPD y la jurisprudencia, y por dónde entra un cambio (perfil de cumplimiento, nunca el código). Arranca con [`../cliente/preguntas-asesoria.md`](../cliente/preguntas-asesoria.md) | Cliente |
| [`incidencia-sin-acceso.md`](incidencia-sin-acceso.md) | **Diagnosticar con el paquete que envía el cliente**, sin acceso a su servidor. Es el runbook que decide si el paquete está bien diseñado | Soporte del fabricante |

### Solo del fabricante (no del cliente)

| Runbook | Cuándo se usa |
| --- | --- |
| [`fallo-de-ci.md`](fallo-de-ci.md) | Una etapa del pipeline está en rojo, o una puerta de versión bloquea una etiqueta |
| [`triaje-hallazgos-seguridad.md`](triaje-hallazgos-seguridad.md) | Un hallazgo de Semgrep comunitario o Trivy en modo informe del job de seguridad |

`alta-nuevo-empleado` no es un runbook: es el §2 de
[`../cliente/guia-rrhh.md`](../cliente/guia-rrhh.md).

---

## Qué debe contener un runbook

Que una persona del equipo pueda diagnosticar el incidente a las 06:30 sin
haber tocado nunca esa parte del sistema:

1. **Síntoma y alerta que lo dispara**, con su umbral, severidad y destinatario.
2. **Qué significa y qué no significa** — sobre todo en los que señalan a una
   persona.
3. **Impacto en el fichaje.** Lo primero que hay que saber es si alguien se ha
   quedado sin poder fichar (regla dura 19: el quiosco nunca bloquea al
   empleado).
4. **Diagnóstico**: comandos concretos, copiables **tal cual** desde el
   directorio de la instalación, con la salida esperada.
5. **Resolución**, con la vuelta atrás si la hay.
6. **Qué preservar antes de tocar nada** en los de seguridad.
7. **A quién se escala** y en cuánto tiempo.

Si añades una alerta: su `runbook_url` apunta a un runbook (o a un ancla de uno)
que la nombra, que la lista en su tabla de alertas y que dice qué hacer. Si el
modo de fallo no cabe en ninguno de los existentes, escribe el runbook en el
mismo cambio y añádelo a los dos índices de arriba.
