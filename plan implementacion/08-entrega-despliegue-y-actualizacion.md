# Entrega, despliegue en cliente y actualización

| Campo | Valor |
|---|---|
| **Qué es** | Bloque de referencia de la entrega. **No es una fase y no tiene horas propias** |
| **Documento origen** | [docs/02](../docs/02-stack-tecnologico-y-plan-implementacion.md) **§11.6** desarrollado, más §3.5 (scripts), §7.7 (secretos), §9.2 y §9.4 (umbrales y escenarios), §10.1 (etapa 8), §10.4 (expand/contract), §11.6.2 (requisitos), §12 (runbooks) y la Nota final |
| **Requisitos que gobierna** | `RF-PD-02`, `RF-PD-09..14`, `RQ-11`, `RL-16..21` ([doc 01](../docs/01-especificaciones-proyecto.md) §3.9, §7.3, §10) |
| **Tareas del plan que lo producen** | `5.4` (instalador y Compose), `5.7` (actualizador), `5.9` (`doctor` y diagnóstico), `5.11` (documentación de cliente), `2.11` (copias y restauración) |
| **Quién lo lee** | El **IT del cliente**, que ejecuta; y el **soporte del fabricante**, que diagnostica sin acceso al servidor |
| **Horas** | Imputadas a las tareas de arriba. Ver [`05-fase-5-productizacion.md`](05-fase-5-productizacion.md) |

> **Por qué este bloque existe separado del plan de la fase.** Los cinco scripts y los cuatro documentos de cliente son **entregables del producto** (§11.6.1), no artefactos internos: los ejecuta el IT de un hotel, en su servidor, a veces con una incidencia en marcha. El §3.5 lo dice sin rodeos: *«Merecen la misma disciplina que el código de la aplicación.»* Reunirlos en un solo sitio es lo que permite que el que escribe `update.sh` y el que escribe `operacion.md` no cuenten dos historias distintas.

**El criterio contra el que se juzga todo este bloque** es el mismo de la Fase 5 (doc 03 §4.3 y §6.5):

> *Una persona de IT de un hotel, a la que no conocemos y que no sabe Laravel, instala el sistema siguiendo la guía, lo configura, lo actualiza seis meses después y resuelve una incidencia sin llamarnos.*

---

## Índice

1. [Qué se entrega al cliente](#1-qué-se-entrega-al-cliente)
2. [Los cinco scripts, desarrollados paso a paso](#2-los-cinco-scripts-desarrollados-paso-a-paso)
3. [Actualización a versiones no consecutivas](#3-actualización-a-versiones-no-consecutivas)
4. [Requisitos de servidor publicados](#4-requisitos-de-servidor-publicados)
5. [Reparto de responsabilidades](#5-reparto-de-responsabilidades)
6. [Matriz de versiones soportadas](#6-matriz-de-versiones-soportadas)
7. [Paquete de diagnóstico](#7-paquete-de-diagnóstico)
8. [Etapa 8 de la CI](#8-etapa-8-de-la-ci)
9. [Los 20 runbooks](#9-los-20-runbooks)
10. [Gestión de secretos y su rotación](#10-gestión-de-secretos-y-su-rotación)
11. [Las verificaciones pendientes antes de la primera venta](#11-las-verificaciones-pendientes-antes-de-la-primera-venta)
- [Puntos no cubiertos por los documentos](#puntos-no-cubiertos-por-los-documentos)

---

## 1. Qué se entrega al cliente

Árbol literal del [doc 02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11.6.1:

```
fichaje-hotel-v1.4.2/
├── docker-compose.yml          # Producción, autocontenido, sin dependencias externas
├── .env.example                # Comentado, con los valores que el cliente debe rellenar
├── install.sh                  # Comprueba requisitos, genera secretos, arranca, verifica
├── update.sh                   # Copia previa, migra, verifica, vuelve atrás si falla
├── backup.sh / restore.sh      # Copia local cifrada y restauración
├── doctor.sh                   # Comprobación de salud (RF-PD-13)
├── LICENCIA.txt
└── docs/
    ├── instalacion.md          # Para el IT del cliente
    ├── operacion.md            # Copias, actualizaciones, incidencias comunes
    ├── configuracion.md        # Todos los parámetros y qué hace cada uno
    └── obligaciones-legales.md # Qué le corresponde al cliente (RL-21)
```

> Las imágenes se distribuyen desde un registro privado del fabricante, con etiquetas de versión inmutables. **Nada de `latest` en producción.**
> — §11.6.1

Dos observaciones sobre el nombre del directorio, tomadas de la nota de nomenclatura del doc 02 y del doc 01 §13: `fichaje-hotel` es un **identificador técnico interno** y no se renombra, igual que el prefijo `FH1` del payload, `BACKUP_PATH` y los nombres de servicios y de base de datos. Lo que ve el usuario en pantalla es el nombre comercial *KronoQR*, y es **configuración de marca** (`RF-PD-08`, tarea 5.8).

### 1.1 `docker-compose.yml`

| | |
|---|---|
| **Qué hace** | Define la instalación completa de producción: `nginx`, `app` (PHP 8.4-FPM), `postgres` 17 con WAL archiving, `redis` 7 con persistencia AOF, `horizon`, `scheduler` y `reverb` (§3.4) |
| **Quién lo ejecuta** | No lo ejecuta nadie directamente: lo invocan `install.sh`, `update.sh` y `doctor.sh`. El IT del cliente lo usa a mano solo para diagnóstico (`docker compose ps`, `logs`) |
| **De dónde sale** | `infra/compose.prod.yaml` del árbol del §2, tarea 5.4 |
| **Restricciones** | Autocontenido y **sin dependencias externas** (§11.6.1). Etiquetas de imagen inmutables. Cabeceras de seguridad del §7.2 en Nginx, incluida `Permissions-Policy: camera=(self)`, sin la cual la PWA del quiosco no puede acceder a la cámara — el §7.2 avisa de que *«es un fallo de configuración que se diagnostica mal y cuesta horas»* |

El stack de observabilidad (Prometheus, Grafana, Loki, Alertmanager) corre **en el mismo servidor** según el §1.4, pero es **opcional en la instalación de un cliente**: el §8.2.1 lo dice explícitamente al justificar `error_events`. Un cliente puede desactivarlo, puede no tener quien lo mire y puede perderlo al reinstalar; el diagnóstico no puede depender de él.

### 1.2 `.env.example`

| | |
|---|---|
| **Qué hace** | Plantilla de configuración, **comentada, con los valores que el cliente debe rellenar** (§11.6.1). Su contenido de referencia es el **Anexo B** del doc 02 |
| **Quién lo ejecuta** | El IT del cliente lo copia a `.env`; parte lo rellena él, parte la genera `install.sh` |
| **Tres categorías que hay que distinguir en los comentarios** | (a) lo que rellena el cliente: `APP_URL`, `MAIL_*`, `BACKUP_PATH`, `OTEL_EXPORTER_OTLP_ENDPOINT`; (b) lo que **genera el instalador** y el cliente no escribe: `APP_KEY`, `QR_SIGNING_KEY_*`, credenciales de base de datos, `REVERB_APP_*`, `BACKUP_ENCRYPTION_KEY`; (c) lo que **no se toca**: `APP_TIMEZONE=UTC` (regla dura 3) |
| **Restricción** | Ningún valor de secreto real, ni de aspecto real, en el fichero de ejemplo (§7.7) |

### 1.3 Los cinco scripts

Detallados en la [sección 2](#2-los-cinco-scripts-desarrollados-paso-a-paso). Resumen de quién ejecuta qué y cuándo:

| Script | Quién lo ejecuta | Cuándo |
|---|---|---|
| `install.sh` | IT del cliente | Una vez, en el servidor virgen |
| `update.sh` | IT del cliente | Cada actualización de versión |
| `backup.sh` | El **scheduler**, a diario; y el IT a mano antes de cualquier operación de riesgo | Diario y a demanda |
| `restore.sh` | IT del cliente; y `update.sh` de forma automática en la vuelta atrás | Recuperación, simulacro trimestral, vuelta atrás |
| `doctor.sh` | IT del cliente; y `install.sh` y `update.sh` como paso de verificación | Posinstalación, posactualización y ante cualquier incidencia |

### 1.4 `LICENCIA.txt`

Texto de la licencia del producto que acompaña al paquete. No es la **clave** de licencia: la clave firmada va en `LICENSE_KEY` (Anexo B) y se activa con `php artisan license:activate {key}` o desde el asistente (tarea 5.3).

**Su contenido no es una decisión técnica.** La Nota final del doc 02 sitúa el contrato de licencia entre lo que debe validar una asesoría laboral antes de la primera venta, junto con el contrato de encargo acotado a soporte. Lo que sí es decisión técnica es **cómo se impide publicar sin él**:

- El repositorio lleva un `LICENCIA.txt` **marcador de posición**, con el texto explícito de que no es una licencia válida.
- La **etapa 8 de la CI** comprueba que el fichero del paquete no sigue siendo el marcador. Si lo es, **la publicación falla**.
- En paralelo, se envía a la asesoría el pliego de lo que la licencia debe cubrir: derechos de uso, límites del plan, responsabilidades del reparto del §11.6.3, y la relación con el contrato de encargo de tratamiento.

El motivo de atarlo a la CI y no a una lista de comprobación: un marcador de posición que nadie verifica llega a producción. Es el mismo criterio que aplica el §3.5 a los secretos — lo que no comprueba una herramienta es una sugerencia.

### 1.5 Los cuatro documentos de `docs/`

Son la tarea **5.11**, y el §11 del doc 02 avisa de que es *«la tarea más subestimada»*. Su contenido apartado por apartado está desarrollado en [`05-fase-5-productizacion.md`, tarea 5.11](05-fase-5-productizacion.md#tarea-511--documentación-de-instalación-operación-configuración-y-obligaciones-legales).

| Documento | Qué hace | Quién lo lee |
|---|---|---|
| `instalacion.md` | Requisitos, ejecución de `install.sh`, asistente y primer quiosco, con capturas y un «qué hacer si…» por fallo previsible | IT del cliente, la primera vez |
| `operacion.md` | Copias, restauración, actualización, diagnóstico, accesos de soporte y las incidencias comunes | IT del cliente, todos los meses |
| `configuracion.md` | Cada parámetro del Anexo B y cada clave de `installation_settings`: qué hace, valor por defecto, cuándo cambiarlo y si afecta al cálculo de horas | IT del cliente y RRHH |
| `obligaciones-legales.md` | `RL-16..21`: qué asume el cliente como responsable del tratamiento y qué no hace el producto por él | Dirección, RRHH y su asesoría |

**Viajan dentro del paquete de entrega, no en un portal externo.** La instalación puede estar en una red sin salida a internet (§11.6.2), así que una documentación en línea no es documentación entregada.

---

## 2. Los cinco scripts, desarrollados paso a paso

### 2.0 Convenciones que aplican a los cinco

Tabla del §3.5, subsección «Scripts de instalación y operación», aplicada:

| Ámbito | Convención | Quién la verifica |
|---|---|---|
| Robustez | `set -euo pipefail` e `IFS=$'\n\t'` al principio de **todo** script | ShellCheck |
| Estilo | Guía de estilo de Shell de Google; formato con `shfmt -i 2` | ShellCheck + shfmt |
| Idempotencia | Re-ejecutable sin romper nada. **Comprueba el estado antes de actuar**, en lugar de asumirlo | Revisión |
| Fallo seguro | Requisitos verificados **antes** de tocar nada; si algo falla, el sistema queda como estaba. Nada de trabajo a medias | Revisión |
| Errores | El mensaje dice **qué hacer**, no solo qué falló. Códigos de salida documentados en la cabecera del script | Revisión |
| Secretos | Nunca en el script ni en su salida: se generan en el servidor del cliente (§7.7) | Semgrep |

Y el umbral bloqueante del §9.2: **ShellCheck + `shfmt -i 2 -d`, 0 hallazgos**, aplicado a `infra/scripts/` y a los scripts entregados al cliente. La etapa 1 de la CI lo ejecuta (§10.1).

**El principio que ordena la estructura de todos ellos** (§3.5, cierre de la subsección, y agente `producto-licencia`):

> *Un instalador que falla a medias es peor que uno que no arranca.*

De ahí que los cinco tengan la misma forma: **comprobar todo → decidir → actuar → verificar → informar**. Nunca comprobar y actuar entrelazados.

**Cuatro reglas de escritura que se derivan de no tener acceso al servidor:**

1. **Cada mensaje de error lleva una acción.** No «espacio insuficiente», sino «quedan 6 GB libres en `/var/lib/docker` y se necesitan 20; libere espacio o monte otro volumen y vuelva a ejecutar».
2. **Cada mensaje de error lleva su código de salida**, para que el cliente pueda citarlo por teléfono y para que `instalacion.md` y `operacion.md` tengan una entrada «qué hacer si…» por código.
3. **Nada se escribe en la salida que no se pueda pegar en un correo.** Es la consecuencia práctica de «cero secretos en la salida»: el cliente **va** a pegar la salida en un correo.
4. **El log del script se guarda en el servidor del cliente**, para que pueda viajar en el paquete de diagnóstico (§11.6.6) sin pedir una segunda ronda de información.

**Sobre los códigos de salida.** El §3.5 exige que estén *documentados en la cabecera del script*, pero **ningún documento fija sus valores**. Lo que sí se puede fijar aquí, porque se deriva de los requisitos, son las **clases** de salida que cada script tiene que distinguir; la asignación numérica queda pendiente:

⚠️ No cubierto por los documentos — decidir: los valores numéricos concretos de los códigos de salida de los cinco scripts, y que sean consistentes entre ellos (`install.sh`, `update.sh` y `doctor.sh` se invocan unos a otros).

---

### 2.1 `install.sh`

**Propósito.** Comprobar requisitos, generar secretos, arrancar y verificar (§11.6.1). Materializa `RF-PD-02`: *«el personal de IT del cliente despliega el sistema siguiendo una guía, sin intervención del fabricante»*.

**Quién lo ejecuta y en qué situación.** El IT del cliente, **una sola vez**, en un servidor virgen que cumple el §11.6.2. Después de este script, el siguiente paso es el asistente de puesta en marcha (`RF-PD-03`, tarea 5.5) desde el navegador.

**Precondiciones que verifica ANTES de actuar.** Contra la tabla publicada del §11.6.2, y **sin escribir nada en el sistema**:

| Comprobación | Umbral / criterio | Origen |
|---|---|---|
| Sistema operativo | Linux | §11.6.2 |
| Docker | versión **24+** | §11.6.2 |
| Docker Compose | **v2** | §11.6.2 |
| CPU | ≥ 2 núcleos (mínimo, ≤ 100 empleados); avisa si < 4 y el cliente declara > 100 | §11.6.2 |
| RAM | ≥ 4 GB (mínimo); avisa si < 8 GB | §11.6.2 |
| Disco | ≥ 40 GB SSD libres (mínimo); avisa si < 100 GB | §11.6.2 |
| Puertos | los que declare `docker-compose.yml`, libres | Derivado de §1.4 |
| Permisos de escritura | rutas de datos, de logs y `BACKUP_PATH` | Anexo B, RF-PD-13 |
| Red | `APP_URL` resoluble desde el propio servidor | Anexo B |
| Certificado TLS | presente y válido, o Let's Encrypt alcanzable | §3.4 |
| Instalación previa | contenedores, volúmenes o `.env` ya existentes | §3.5, idempotencia |

**Qué hace si falla una precondición.** Termina **antes de escribir nada**, con el código de salida de la clase «requisitos no cumplidos», enumerando **todas** las que fallan —no solo la primera— y qué hacer con cada una. La máquina queda exactamente como estaba. Que enumere todas importa: un IT que descubre los requisitos de uno en uno hace cinco viajes al servidor.

**Secuencia de pasos.**

| # | Paso | Qué hace si falla |
|---|---|---|
| 1 | **Cabecera y modo estricto.** `set -euo pipefail`, `IFS=$'\n\t'`, y la cabecera con propósito, uso y códigos de salida | — |
| 2 | **Comprobación de requisitos**, toda la tabla anterior, sin escribir | Sale con la clase «requisitos no cumplidos», lista completa de fallos y qué hacer. Sistema intacto |
| 3 | **Detección de instalación previa.** Si existe, **no reinstala**: informa del estado y de la versión detectada, indica que para actualizar se usa `update.sh`, y termina con su código propio. Re-ejecutar `install.sh` sobre una instalación buena **no puede romperla** | Es él mismo un caso de salida controlada, no un error |
| 4 | **Confirmación explícita** de lo que va a hacer, con las rutas de datos y de copias que va a crear | Cancelación limpia sin efectos |
| 5 | **Generación de secretos EN el servidor del cliente**: `APP_KEY`, `QR_SIGNING_KEY_CURRENT` (32 bytes, base64) con su `QR_SIGNING_KEY_CURRENT_ID`, credenciales de PostgreSQL, `REVERB_APP_ID`/`KEY`/`SECRET`, `BACKUP_ENCRYPTION_KEY`. Permisos restrictivos en `.env`. **Nunca se transmiten y nunca se imprimen** (§7.7) | Deshace el `.env` creado y sale. Sistema intacto |
| 6 | **Descarga de imágenes** por su etiqueta de versión inmutable desde el registro privado | Mensaje que distingue «sin credenciales de registro» de «sin salida a internet», con la alternativa de instalación desde imágenes locales |
| 7 | **Arranque de servicios** y espera **por condición** a que PostgreSQL y Redis estén listos. Sin `sleep` (§3.5, código de pruebas: la misma disciplina) | Detiene lo levantado, deja el log y sale |
| 8 | **Migraciones** del esquema completo | Detiene servicios, informa de la migración que falló y sale. Sin base de datos a medias |
| 9 | **Semilla mínima**: perfil de cumplimiento `ES-hosteleria` (RF-PD-07, tarea 5.2) y catálogo de motivos de corrección (doc 01 Anexo C). **Cero datos de demostración** | Sale indicando el comando de semilla a reintentar |
| 10 | **Verificación posinstalación**: `doctor` y sonda `GET /api/v1/health`. **El instalador no se declara correcto sin verificar** | Sale con la clase «instalado pero verificación fallida», dejando el sistema en marcha y remitiendo al informe de `doctor` |
| 11 | **Salida final accionable**: URL del panel, siguiente paso (el asistente), y dónde está `docs/instalacion.md` | — |

**Códigos de salida — clases que hay que distinguir.** (Valores: ⚠️ ver 2.0.)

| Clase | Significado | Qué debe hacer el cliente |
|---|---|---|
| Éxito | Instalado y verificado | Continuar con el asistente |
| Requisitos no cumplidos | Alguna precondición del §11.6.2 falla | Corregir lo listado y reejecutar |
| Instalación previa detectada | Ya hay una instalación | Usar `update.sh` |
| Cancelado por el operador | No confirmó el paso 4 | Nada; el sistema está intacto |
| Fallo durante la instalación, revertido | Algo falló y se deshizo | Leer el mensaje, corregir, reejecutar |
| Instalado pero verificación fallida | Arrancó, `doctor` en rojo | Leer el informe de `doctor` y `operacion.md` |

**Qué escribe por pantalla.** Una línea por comprobación con su resultado; una línea por paso ejecutado; ningún volcado de log en el caso feliz; y al final, el resumen accionable. **Ningún secreto.** El log completo se guarda en el servidor.

**No hay `install.ps1`** ([ADR-022](../docs/adr/ADR-022-sin-instalador-de-windows.md)). El §11.6.1 lo entregaba, pero los requisitos publicados del §11.6.2 y del [doc 05](../docs/05-presentacion-cliente.md) §10.1 solo contemplan **Linux con Docker**, y el §3.5 no define convenciones ni herramienta de verificación para PowerShell: ShellCheck y `shfmt` no cubren `.ps1`, de modo que el umbral bloqueante del §9.2 no podía aplicársele y la etapa 8 probaba una sola de las dos vías entregadas.

Un entregable que ninguna herramienta revisa y ninguna etapa de CI prueba, en manos de un IT que no conoce el producto, es peor que no tenerlo. Se retira, y con ello **ShellCheck y `shfmt` cubren el 100 % de los scripts del paquete sin exclusiones**.

Un cliente con solo infraestructura Windows instala sobre una máquina virtual Linux o WSL 2, y **eso se dice en la documentación de instalación** en lugar de descubrirse a mitad del proceso. Soportar Windows Server de verdad exigiría ampliar los requisitos publicados, elegir analizador y formateador de PowerShell, atarlos al §9.2 con su umbral, y duplicar la etapa 8: es una decisión de producto con su propio ADR, no un fichero más.

---

### 2.2 `update.sh`

**Propósito.** Copia previa, migra, verifica, **vuelve atrás si falla** (§11.6.1). Materializa `RF-PD-10`.

**Quién lo ejecuta y en qué situación.** El IT del cliente, en cada actualización de versión, preferiblemente en ventana de baja actividad. El fichaje **no se detiene** (ver [sección 3](#3-actualización-a-versiones-no-consecutivas)).

**Es el script con más riesgo de pérdida de datos de todo el producto.** El §11.2 lo dice del recorte de la tarea 5.7: *«Es el recorte con más probabilidad de acabar en pérdida de datos de un cliente.»*

**Precondiciones que verifica ANTES de actuar.**

| Comprobación | Criterio | Origen |
|---|---|---|
| Versión de origen | Dentro de la matriz de versiones soportadas | §11.6.5 |
| Versión de destino | Posterior a la de origen; si ya está en ella, no hace nada y lo dice | §3.5, idempotencia |
| Espacio libre | Suficiente para la copia completa **y** para la migración | §11.6.4 paso 1 |
| Servicios sanos | `doctor` en verde antes de empezar | §11.6.4 paso 1 |
| Copia previa posible | `BACKUP_PATH` escribible y `BACKUP_ENCRYPTION_KEY` presente | Anexo B |
| Cadena de auditoría | `compliance:verify-audit-chain` en verde **antes** de tocar nada | §7.4 |

Esa última no está en la lista del §11.6.4, pero se deriva de la regla dura 6 y del §7.4: si la cadena ya estaba rota antes de actualizar, hay que saberlo **antes**, porque después nadie podrá distinguir si la rompió la actualización. Es un incidente de seguridad con su runbook (`rotura-cadena-auditoria.md`) y la actualización no debe taparlo.

**Qué hace si falla una precondición.** Sale sin tocar nada. Dos casos merecen mensaje propio: **versión de origen no soportada** (dice a qué versión intermedia hay que ir primero, §11.6.5) y **cadena de auditoría rota** (remite a `rotura-cadena-auditoria.md` y a preservación de evidencia; no continúa).

**Secuencia de pasos.** Son los 7 del §11.6.4; el desarrollo completo está en la [sección 3](#3-actualización-a-versiones-no-consecutivas).

| # | Paso | Qué hace si falla |
|---|---|---|
| 1 | Verificar precondiciones | Sale sin tocar nada |
| 2 | **Copia de seguridad completa y verificada — bloqueante** | **No continúa.** `RF-PD-10`: «si la copia falla, la actualización no continúa». Sin bandera para omitirlo |
| 3 | Modo mantenimiento (el quiosco sigue encolando) | Sale del mantenimiento y termina |
| 4 | Migraciones en orden de versión, con **punto de control entre cada una** | Vuelta atrás desde el punto de control, y si no basta, restauración de la copia del paso 2 |
| 5 | Arrancar y ejecutar la comprobación de salud | Dispara el paso 6 |
| 6 | **Vuelta atrás automática** a la copia previa | Si la propia vuelta atrás falla: para, **no toca nada más**, y emite instrucciones explícitas de restauración manual con `restore.sh` y remisión a `restaurar-backup.md`. Es el único escenario que exige intervención humana y tiene que estar escrito |
| 7 | Informe del resultado, guardado en el servidor del cliente | Se escribe **siempre**, también cuando hubo vuelta atrás |

**Códigos de salida — clases que hay que distinguir.** (Valores: ⚠️ ver 2.0.)

| Clase | Significado | Qué debe hacer el cliente |
|---|---|---|
| Éxito | Actualizado y verificado | Nada; leer el informe |
| Ya en la versión de destino | Idempotencia | Nada |
| Precondición no cumplida | Espacio, servicios, versión de origen | Corregir lo indicado |
| Versión de origen no soportada | Fuera de la matriz del §11.6.5 | Actualizar primero a la versión intermedia indicada |
| Cadena de auditoría rota antes de empezar | Incidente de seguridad | `rotura-cadena-auditoria.md`. **No actualizar todavía** |
| **Copia previa fallida** | No se pudo generar o verificar | Resolver el almacenamiento de copias. **No se ha tocado nada** |
| Vuelta atrás ejecutada con éxito | La actualización falló y se restauró | Enviar el informe y el paquete de diagnóstico al soporte |
| **Vuelta atrás fallida** | Estado que exige intervención humana | Seguir las instrucciones impresas y `restaurar-backup.md`. **Es la única salida crítica** |

**Qué escribe por pantalla.** La versión de origen y la de destino, la cadena de versiones intermedias que va a aplicar, una línea por versión aplicada con su punto de control, el resultado de la comprobación de salud, y la ruta del informe. Si hay vuelta atrás, **en qué punto y por qué**, en lenguaje llano.

---

### 2.3 `backup.sh`

**Propósito.** Copia local **cifrada** (§11.6.1). Su contenido funcional viene de la tarea **2.11** (`RF-PR-04`, `RNF-D-05`): copia diaria cifrada, verificada y con prueba de restauración periódica.

**Quién lo ejecuta y en qué situación.** El **scheduler**, a diario (§1.4: «Reconciliación, incidencias, retención, copias»); el IT del cliente a mano antes de cualquier operación de riesgo; y **`update.sh` en su paso 2**.

**Precondiciones que verifica ANTES de actuar.**

| Comprobación | Criterio | Origen |
|---|---|---|
| `BACKUP_PATH` | Existe y es escribible | Anexo B |
| `BACKUP_ENCRYPTION_KEY` | Presente | Anexo B, RL-12 |
| Espacio libre | Suficiente para la copia completa, no solo para empezarla | Derivado de «fallo seguro» §3.5 |
| Base de datos | Alcanzable y aceptando conexiones | RF-PD-13 |

**Secuencia de pasos.**

1. Verificar precondiciones. Si falla, sale sin escribir un fichero parcial.
2. **Volcado consistente** de PostgreSQL. El §3.2 apunta WAL archiving para RPO ≤ 15 min (`RNF-D-02`): la copia lógica y el archivado de WAL son complementarios, y `operacion.md` tiene que explicar los dos.
3. Incluir lo que no está en la base de datos: `.env` **cifrado**, ficheros de marca (`BRANDING_LOGO_PATH`) y ficheros generados que no sean reconstruibles.
4. **Cifrado con `BACKUP_ENCRYPTION_KEY`** (RL-12: cifrado en reposo de copias).
5. **Verificación de la copia**, no solo su generación: `php artisan backup:verify` (Anexo C). Una copia no verificada no es una copia — es uno de los seis principios del agente `devops-observabilidad` (doc 03 §4.3).
6. **Escritura atómica**: la copia solo aparece con su nombre definitivo cuando está completa y verificada. Nunca un fichero a medias que parezca válido.
7. Rotación según la política de retención de copias, «con caducidad alineada» a la del dato (RL-11).
8. Registrar el resultado, y **alertar si falla**: el doc 01 §9.3 fija «Copia de seguridad fallida o no verificada, cualquiera, **Crítica**».

**Qué hace si falla cada paso.** En cualquier fallo: elimina el artefacto parcial, deja el motivo registrado, alerta y sale con código distinto de éxito. **Que `update.sh` distinga «copia fallida» de «copia no verificada» importa**, porque en ambos casos no debe continuar, pero la acción del cliente es distinta.

**Códigos de salida — clases.** Éxito · precondición no cumplida (ruta, clave, espacio) · fallo del volcado · **fallo de la verificación** · fallo de la rotación. (Valores: ⚠️ ver 2.0.)

**Qué escribe por pantalla.** Ruta de la copia, tamaño, duración y resultado de la verificación. **Nunca la clave de cifrado.** Dónde custodiar `BACKUP_ENCRYPTION_KEY` se explica en `operacion.md` y en `rotacion-secretos.md`, no en la salida del script (§3.5, secretos).

---

### 2.4 `restore.sh`

**Propósito.** Restauración (§11.6.1). Es la mitad que casi nunca se prueba, y la razón de que `RQ-09` exija **prueba de restauración automatizada y ejecutada al menos trimestralmente**.

**Quién lo ejecuta y en qué situación.** Tres situaciones distintas, y el script tiene que distinguirlas porque el riesgo es distinto: (a) el IT del cliente en una **recuperación real**; (b) el **simulacro trimestral** de `RQ-09`, que el §9.4 describe como «script automatizado que restaura la última copia en un contenedor limpio y valida integridad referencial y conteos»; (c) **`update.sh` en su paso 6**, de forma automática y desatendida.

**Precondiciones que verifica ANTES de actuar.**

| Comprobación | Criterio |
|---|---|
| Copia indicada | Existe, es legible y **su verificación pasa** antes de destruir nada |
| Clave de cifrado | Presente y correcta para esa copia |
| Destino | Instancia de destino identificada sin ambigüedad, y **confirmación explícita** si es la de producción |
| Compatibilidad de versión | La versión del esquema de la copia es compatible con el código instalado |
| Espacio libre | Suficiente para restaurar |

**El punto crítico:** una restauración es la única operación del producto que **destruye datos por diseño**. Por eso el orden es «verificar la copia primero, destruir después», nunca al contrario. Si se descubre que la copia no es válida **después** de vaciar la base de datos, el cliente ha perdido su registro legal de cuatro años.

**Secuencia de pasos.**

1. Verificar precondiciones, incluida la validez de la copia.
2. **Confirmación explícita** cuando el destino es producción, mostrando qué se va a sobrescribir y la fecha de la copia. En modo desatendido (invocado por `update.sh`) la confirmación se sustituye por una bandera explícita, no por su ausencia.
3. **Copia de seguridad del estado actual antes de sobrescribirlo.** Aunque el estado actual esté roto, es la única evidencia de por qué se rompió.
4. Detener los servicios de aplicación; mantener disponible lo que permita al quiosco seguir encolando en local (regla dura 19: el quiosco no bloquea al empleado, y su cola vive en IndexedDB, ADR-008).
5. Descifrar y restaurar.
6. **Validación posterior**: integridad referencial, conteos por tabla, presencia y validez de las restricciones de `RN-01` y `RN-02` (§9.4, «invariantes de base de datos») y `compliance:verify-audit-chain` (§7.4).
7. Arrancar servicios y ejecutar `doctor`.
8. **Reconciliación**: `attendance:reconcile --from= --to=` sobre el periodo afectado, porque `daily_totals` es una proyección reconstruible (regla dura 7, ADR-007) y es lo correcto tras cualquier restauración.
9. Informe de la restauración: qué copia, de qué fecha, qué se validó y qué queda por revisar.

**Qué hace si falla cada paso.** Si falla la verificación de la copia (paso 1) o la validación posterior (paso 6), lo dice con toda claridad y remite a `restaurar-backup.md`. **Nunca deja el sistema arrancado con datos que no ha validado**: un registro horario que parece correcto y no lo es es peor que un sistema caído.

**Códigos de salida — clases.** Éxito · copia inválida o ilegible (nada tocado) · cancelado en la confirmación · fallo durante la restauración · **restaurado pero validación posterior fallida** · fallo de la reconciliación. (Valores: ⚠️ ver 2.0.)

**Qué escribe por pantalla.** Qué copia, de qué fecha, qué se va a sobrescribir, y al terminar los conteos comparados y el resultado de las validaciones. Sin secretos.

✅ **RESUELTO — no hace falta imputarlo a ninguna tarea nueva.** `backup.sh` y `restore.sh` (contenido funcional de la tarea 2.11) viven en `infra/scripts/`, y la tarea **0.7** ya configura ShellCheck y `shfmt -i 2 -d` con umbral 0 hallazgos sobre **todo** `infra/scripts/` de forma transversal (§3.5, Fase 0). El endurecimiento no era un hueco: era una cobertura ya construida que faltaba señalar aquí. `update.sh` (5.7) hereda la misma garantía por depender de ambos.

---

### 2.5 `doctor.sh`

**Propósito.** Comprobación de salud (§11.6.1, `RF-PD-13`): *«un comando que valida base de datos, colas, correo, certificados, permisos y espacio en disco, y devuelve un informe accionable»*.

**Quién lo ejecuta y en qué situación.** El IT del cliente ante cualquier incidencia y como verificación posinstalación; `install.sh` en su paso 10; `update.sh` en su paso 5. Es también el primer comando que pide el soporte, y su resultado va **dentro del paquete de diagnóstico** (§11.6.6).

**Relación con `php artisan product:doctor`.** El Anexo C define el comando de consola; `doctor.sh` es el envoltorio que se ejecuta **sin entrar al contenedor** y que sigue sirviendo cuando la aplicación no arranca y `artisan` no está disponible — que es exactamente cuando más se necesita. Los dos existen y hacen falta los dos.

**Precondiciones que verifica ANTES de actuar.** Ninguna bloqueante: `doctor` tiene que poder ejecutarse **siempre**, incluso con el sistema roto. Es un script de solo lectura: **no modifica nada**, y por eso es trivialmente idempotente.

**Qué comprueba.** Las seis de `RF-PD-13`, más lo que el §11.6.6 exige que aporte al paquete de diagnóstico:

| Comprobación | Qué mira | Origen |
|---|---|---|
| Base de datos | Conexión, migraciones al día, restricciones de `RN-01` y `RN-02` presentes | RF-PD-13, §3.2 |
| Colas | Redis alcanzable, trabajos pendientes y fallidos, Horizon en marcha | RF-PD-13, §8.2 |
| Correo | SMTP del cliente alcanzable | RF-PD-13 |
| Certificados | Validez y **días hasta la expiración** (alerta del doc 01 §9.3: < 21 días, severidad Alta) | RF-PD-13 |
| Permisos | Rutas de datos, logs y `BACKUP_PATH` | RF-PD-13 |
| Espacio en disco | Alerta del doc 01 §9.3: < 20 %, severidad Alta | RF-PD-13 |
| Servicios | Estado de los del `docker-compose.yml` | §11.6.6 |
| Versión desplegada | La misma que expone `GET /api/v1/health` (§10.5) | §11.6.6 |
| Licencia | Estado y vigencia, **como información, nunca como bloqueo** (regla dura 15) | 5.3, §11.6.6 |
| Quioscos | Último latido por dispositivo y tamaño de cola pendiente | §11.6.6, §8.2 |
| Copias | Fecha y resultado de la última copia y de su verificación | RF-PR-04 |
| Cadena de auditoría | Resultado de la última verificación | §7.4 |
| Errores | Recuento de `error_events` nuevos y críticos del periodo | RF-PD-15 |

**Secuencia de pasos.** (1) Ejecutar todas las comprobaciones **sin abortar en la primera que falle** —un informe con un solo hallazgo obliga a otra ronda—; (2) clasificar cada resultado en correcto / aviso / fallo; (3) emitir el informe con **una acción concreta por cada hallazgo**; (4) devolver el código de salida de la clase más grave encontrada.

**Qué hace si falla cada comprobación.** Nada: `doctor` no arregla, informa. Pero **cada línea en rojo o ámbar dice qué hacer**, y cuando existe, enlaza su runbook del §12 (`quiosco-no-responde.md`, `cola-offline-atascada.md`, `restaurar-backup.md`, `rotura-cadena-auditoria.md`, `errores-en-el-panel.md`).

**Códigos de salida — clases.** Todo correcto · avisos sin fallos · **al menos un fallo** · no se pudo ejecutar (por ejemplo, Docker no disponible). Que `install.sh` y `update.sh` puedan distinguir «avisos» de «fallos» es lo que evita que una instalación correcta se declare fallida por un certificado que caduca en 20 días. (Valores: ⚠️ ver 2.0.)

**Qué escribe por pantalla.** Una línea por comprobación con su estado, y al final el bloque de hallazgos con su acción. **Sin secretos y sin nombres de empleados** (reglas duras 16 y 21): la salida de `doctor` viaja al fabricante dentro del paquete de diagnóstico. El informe se escribe en español e inglés según la configuración de idioma, y está redactado **para quien no conoce el sistema**.

---

## 3. Actualización a versiones no consecutivas

Del §11.6.4, literal:

> Un cliente puede estar en la 1.2.0 cuando ya va la 1.6.0. El actualizador debe encadenar las migraciones intermedias, no asumir el salto directo.

```
1. Verificar precondiciones: espacio, versión de origen soportada, servicios sanos
2. Copia de seguridad completa y verificada  ← bloqueante, sin esto no continúa
3. Modo mantenimiento (el quiosco sigue encolando offline)
4. Aplicar migraciones en orden de versión, con punto de control entre cada una
5. Arrancar y ejecutar la comprobación de salud
6. Si algo falla → vuelta atrás automática a la copia previa
7. Informe del resultado, guardado en el servidor del cliente
```

### Paso 1 — Verificar precondiciones

Las de la tabla de [2.2](#22-updatesh). Tres merecen desarrollo:

- **Espacio.** No basta el de la copia: hay que contar también el que consume la migración. Una migración que añade una columna a una tabla grande puede duplicarla temporalmente.
- **Versión de origen soportada.** Se contrasta con la matriz del §11.6.5. Si está fuera, el script **no intenta el salto** y dice a qué versión intermedia hay que ir primero. Intentarlo con migraciones que ya no existen en el paquete es la vía directa a un esquema imposible.
- **Servicios sanos.** `doctor` en verde. Actualizar sobre un sistema ya roto convierte dos problemas en uno indistinguible.

### Paso 2 — Copia de seguridad completa y verificada, **bloqueante**

`RF-PD-10` es literal: *«copia de seguridad previa automática y verificada —**si la copia falla, la actualización no continúa**—»*. Y el §11.6.4 lo marca en el propio diagrama: `← bloqueante, sin esto no continúa`.

Consecuencias de diseño:

- **No existe bandera para omitirlo.** Ni `--skip-backup`, ni `--force`, ni una variable de entorno. Cualquier atajo aquí es el que se usará en la actualización que salga mal.
- **Verificada, no solo generada.** `backup:run && backup:verify` (Anexo C).
- La ruta de la copia se registra y **se cita en el informe del paso 7**, porque es lo que el cliente necesita si algo va mal más tarde.

### Paso 3 — Modo mantenimiento

La API de gestión responde con mantenimiento. **El fichaje no se detiene**, y esa es la parte que hay que explicar bien en `operacion.md`:

> **El quiosco sigue funcionando durante la actualización** gracias a la cola offline. Es la ventaja inesperada de haber hecho el modo offline obligatorio: convierte una parada de mantenimiento en algo invisible para la plantilla.
> — §11.6.4

Cómo funciona, del §6 y de ADR-008: el quiosco decodifica la tarjeta, resuelve el nombre en el **padrón cacheado cifrado**, encola `{scan_id, payload, occurred_at, device_id}` en IndexedDB y **confirma al empleado en menos de 300 ms**, sin esperar a la red. Al volver el servidor, sincroniza por lotes de 50 contra `POST /api/v1/scan/batch`, que procesa cada elemento **por su `scan_id`, en orden de `occurred_at`**.

Cuatro consecuencias que hay que documentar y probar:

1. **La hora real se conserva.** El registro legal usa `occurred_at` (regla dura 9), así que los fichajes de la ventana quedan registrados con la hora en que ocurrieron, no con la de llegada.
2. **`recorded_at` será posterior**, y eso es correcto, no un error. Ambas marcas quedan visibles en la auditoría (§6).
3. **Puede generarse una incidencia por retraso de sincronización** si supera el umbral (`RN-15`, `RF-AT-10`). No es un fallo de la actualización: es el sistema haciendo lo que debe. `operacion.md` tiene que decirlo, o el cliente abrirá una incidencia de soporte por algo esperado.
4. **La ventana de mantenimiento debe declararse** en la observabilidad cuando exista (§8.4: silenciamiento durante ventanas de mantenimiento declaradas), para no disparar la alerta de «quiosco sin latido».

### Paso 4 — Migraciones en orden de versión, con punto de control entre cada una

**No se asume el salto directo.** De 1.2.0 a 1.6.0 se aplica 1.3 → 1.4 → 1.5 → 1.6, en ese orden, y **entre cada versión se deja un punto de control**.

Qué gobierna cada migración intermedia:

| Regla | Origen |
|---|---|
| **Ninguna migración renombra o elimina una columna en el mismo despliegue en que se deja de usar.** Expand → Migrate → Contract | §10.4, skill `/migracion-segura` |
| `CREATE INDEX CONCURRENTLY`, fuera de transacción (`$withinTransaction = false`) | §10.4, skill |
| `NOT NULL` en dos pasos: `CHECK ... NOT VALID` y después `VALIDATE CONSTRAINT` | §10.4, skill |
| `lock_timeout` bajo y `statement_timeout` acotado | §10.4, skill |
| Sin `UPDATE` masivo dentro de la migración: el relleno va **por cola, en lotes** | skill |
| `TIMESTAMPTZ` siempre; `work_date` como `DATE`; duraciones en enteros | skill, regla dura 3 |
| **`audit_log` es intocable**: el usuario de aplicación tiene `INSERT` y `SELECT`. Alterarla es una decisión de arquitectura que se consulta con `arquitecto-dominio` y `seguridad-cumplimiento` **antes** de escribir la migración | skill, regla dura 6 |
| Si una migración desactiva las restricciones de `RN-01` o `RN-02`, **el plan debe indicar cómo se restauran y cómo se verifica que ningún dato las viola al reactivarlas** | skill |
| `daily_totals` se **recalcula**, nunca se incrementa: si una migración la toca, la recuperación es `attendance:reconcile` | regla dura 7, ADR-007 |

Y el cierre de la skill `/migracion-segura`, que aquí no es una frase bonita sino el requisito del paso 6:

> *Una migración cuyo `down()` no se ha probado no tiene `down()`.*

**El punto de control** cumple tres funciones: saber **exactamente** en qué versión se detuvo el proceso, permitir que la vuelta atrás no tenga que deshacer las cuatro versiones cuando falló la última, y dejar en el informe del paso 7 la traza de qué se aplicó.

⚠️ No cubierto por los documentos — decidir: **cómo se materializa el punto de control** —copia incremental, `savepoint`, marca de versión persistida, volcado por versión— y cuánto espacio adicional exige. El §11.6.4 exige el punto de control, no su mecanismo.

### Paso 5 — Arrancar y ejecutar la comprobación de salud

Arrancar los servicios en la versión nueva y ejecutar `doctor` ([2.5](#25-doctorsh)) más la sonda `GET /api/v1/health`. **Sin comprobación posterior no hay actualización terminada**: es lo que separa «los contenedores están arriba» de «el sistema funciona».

Comprobaciones que este paso debe incluir por su relevancia legal, además de las de `doctor`: las restricciones de `RN-01` y `RN-02` siguen presentes y válidas (§9.4, «invariantes de base de datos»), y `compliance:verify-audit-chain` sigue en verde (§7.4).

### Paso 6 — Vuelta atrás automática

`RF-PD-10`: *«**vuelta atrás automática a la copia previa** si la comprobación falla»*. Sin intervención humana, y probada, no supuesta.

| Aspecto | Cómo |
|---|---|
| Disparador | Fallo en el paso 4 (una migración) o en el paso 5 (la comprobación de salud) |
| Alcance | Restauración de la copia verificada del paso 2, mediante `restore.sh` en modo desatendido |
| Antes de restaurar | Preservar el estado fallido: log de la migración, punto de control alcanzado y volcado del error. Sin ello, el soporte no puede diagnosticar por qué falló |
| Verificación posterior a la vuelta atrás | Conteos por tabla coincidentes con los previos, restricciones presentes, cadena de auditoría en verde, `doctor` en verde en la versión antigua |
| Reconciliación | `attendance:reconcile` sobre el periodo afectado, porque `daily_totals` es reconstruible |
| Salida del mantenimiento | Solo tras verificar |
| **Si la vuelta atrás falla** | Se detiene, **no toca nada más**, e imprime instrucciones explícitas de restauración manual con la ruta exacta de la copia, remitiendo a `restaurar-backup.md`. Es el único escenario del producto que exige intervención humana y por eso tiene que estar escrito antes de que ocurra |

**Lo que la vuelta atrás no puede hacer nunca:** dejar el sistema arrancado en la versión nueva con un esquema a medias. Entre «caído y restaurable» y «en marcha con datos dudosos», en un registro con valor probatorio la respuesta es siempre la primera.

### Paso 7 — Informe del resultado, guardado en el servidor del cliente

Se escribe **siempre**, también cuando hubo vuelta atrás, y **en el servidor del cliente**: el fabricante no tiene acceso (ADR-016), así que si el informe no queda ahí, no queda en ninguna parte.

Contenido mínimo, derivado de los seis pasos anteriores y de lo que el §11.6.6 necesita para diagnosticar sin una segunda ronda:

- Fecha y hora de inicio y de fin, y duración total.
- Versión de origen y versión de destino.
- **Cadena de versiones intermedias aplicadas**, con el resultado y el punto de control de cada una.
- Ruta y resultado de verificación de la copia previa.
- Duración de la ventana de mantenimiento.
- Resultado de la comprobación de salud posterior, comprobación a comprobación.
- Si hubo vuelta atrás: **en qué paso, por qué, y con qué resultado**.
- Estado final: versión desplegada y resultado de `doctor`.
- **Sin secretos y sin PII** (reglas duras 16 y 21): el informe se adjunta al paquete de diagnóstico.

### Qué exige la CI de todo esto

Escenarios ineludibles del §9.4 y etapa 8 del §10.1, detallados en la [sección 8](#8-etapa-8-de-la-ci): instalación limpia, actualización desde **cada** versión soportada, **salto no consecutivo** desde la más antigua soportada, **vuelta atrás** con fallo inyectado, y restauración de copia en contenedor limpio con validación de integridad referencial y conteos.

---

## 4. Requisitos de servidor publicados

Tabla del §11.6.2, literal:

| Recurso | Mínimo (≤ 100 empleados) | Recomendado (≤ 500) |
|---|---|---|
| CPU | 2 núcleos | 4 núcleos |
| RAM | 4 GB | 8 GB |
| Disco | 40 GB SSD | 100 GB SSD |
| SO | Linux con Docker 24+ y Compose v2 | Íd. |
| Red | Acceso desde la red interna; salida a internet **opcional** | Íd. |

El [doc 05](../docs/05-presentacion-cliente.md) §10.1 publica la misma tabla en versión comercial, y añade: *«Un servidor de estas características cubre 500 empleados y 10 tablets con holgura. El diseño soporta diez veces ese volumen sin cambios.»*

### 4.1 Qué se pierde exactamente sin salida a internet

> **Sin salida a internet el sistema funciona íntegramente.** Solo se pierden: certificados automáticos de Let's Encrypt (se usa uno propio), envío de correo si el SMTP es externo, y la telemetría opcional. La verificación de licencia es local por diseño (ADR-018).
> — §11.6.2

| Se pierde | Alternativa | Consecuencia real |
|---|---|---|
| Certificados automáticos de Let's Encrypt | Certificado propio del cliente (§3.4) | El cliente se ocupa de la renovación. La alerta de «certificado próximo a expirar, < 21 días» del doc 01 §9.3 pasa a ser imprescindible |
| Envío de correo si el SMTP es externo | SMTP interno del cliente | Se pierden notificaciones y el resumen semanal (`RF-PR-05`), que son accesorios. **El producto no depende del correo del empleado** (regla dura 12, ADR-015): el acceso al portal es con código y PIN |
| Telemetría opcional | Ninguna | Ninguna: `TELEMETRY_ENABLED=false` es el valor por defecto (`RF-PD-12`) y *«el sistema funciona idénticamente sin ella»* |
| Descarga de imágenes del registro privado | Traslado manual de imágenes al servidor | Afecta a la instalación y a la actualización, no a la operación. `install.sh` y `update.sh` deben distinguir «sin credenciales de registro» de «sin salida a internet» en sus mensajes |

**Lo que expresamente NO se pierde**, y conviene decirlo porque es contraintuitivo: la **verificación de la licencia**. Es local por diseño (ADR-018), porque *«una verificación en línea convertiría la conectividad del fabricante en punto único de fallo del registro horario de sus clientes»*. Ni el fichaje, ni el registro, ni los informes, ni la exportación legal, ni el paquete de diagnóstico dependen de internet: **no hay ningún componente alojado por el fabricante** (§1.4).

### 4.2 Por cada punto de fichaje

> **Por cada punto de fichaje:** una tablet Android 10 o superior con cámara trasera con autoenfoque, soporte de pared o mesa, cobertura wifi en esa zona y el dispositivo **gestionado en modo quiosco**.
> — §11.6.2

Y el presupuesto de rendimiento del **Anexo A** que el dispositivo tiene que sostener: LCP ≤ 2,0 s en tablet de gama media, interacción a confirmación de escaneo ≤ 800 ms (p95), y ≤ 250 MB de memoria en marcha 12 h **sin crecimiento sostenido**. El Anexo A exige además una **prueba de resistencia de 12 h en el dispositivo real** antes de dar por buena la Fase 1, porque *«las fugas de memoria en el bucle de decodificación son un fallo típico y no aparecen en pruebas cortas»*. Esa prueba de campo es una de las tres validaciones que el §11.0 excluye de las horas del plan (ver [sección 11](#11-las-verificaciones-pendientes-antes-de-la-primera-venta)).

### 4.3 El modo quiosco del dispositivo Android

> **Modo quiosco.** El dispositivo queda fijado a una sola aplicación mediante *device owner* de Android Enterprise, un MDM o el modo de aplicación fijada del fabricante: sin escritorio, sin acceso a ajustes ni a otras apps, con arranque automático de la PWA tras un reinicio o un corte de luz, brillo y suspensión fijados, y actualizaciones del sistema en ventana controlada. **No es una funcionalidad del producto**: es configuración del dispositivo, la ejecuta el IT del cliente y su procedimiento vive en el runbook `alta-nuevo-quiosco.md`. Sin ella, basta un deslizamiento accidental para dejar la tablet fuera de la aplicación, y el siguiente empleado no encuentra dónde fichar. Lo que sí aporta el producto es lo que se ejecuta dentro de esa ventana fijada (RF-KI-01): PWA instalable, a pantalla completa y con *wake lock*.
> — §11.6.2

Resumido en la frontera que hay que dejar escrita en `instalacion.md`:

| | Qué es | Quién lo hace |
|---|---|---|
| **Modo quiosco** | Fijar el dispositivo a una sola aplicación: sin escritorio, sin ajustes, sin otras apps; arranque automático de la PWA tras reinicio o corte de luz; brillo y suspensión fijados; actualizaciones del sistema en ventana controlada | **El IT del cliente.** No es funcionalidad del producto. Procedimiento en `alta-nuevo-quiosco.md` |
| **Lo que aporta el producto dentro de esa ventana** | `RF-KI-01`: PWA **instalable**, a **pantalla completa** y con **wake lock** para evitar la suspensión. Más la cola offline (`RF-KI-03..04`), el feedback visual y sonoro (`RF-KI-05..06`) y la pantalla de diagnóstico con código de servicio (`RF-KI-08`) | El fabricante |
| **Qué pasa si no se configura** | *«Basta un deslizamiento accidental para dejar la tablet fuera de la aplicación, y el siguiente empleado no encuentra dónde fichar.»* Y el riesgo colateral que apunta el doc 05 §10.1: que el dispositivo acabe usado para otra cosa | Consecuencia asumida por el cliente |

El glosario del doc 01 §13 lo confirma en una línea: *«Modo quiosco […] **Responsabilidad del cliente, no del producto**»*. Y el doc 05 §10.1 lo explica al cliente en sus términos: *«Es una función estándar de Android, no algo propio de KronoQR.»* Los tres documentos coinciden, así que aquí no hay contradicción que resolver: hay una frontera que **repetir en la documentación entregada**, porque es una de las que genera conflictos de soporte.

---

## 5. Reparto de responsabilidades

Tabla del §11.6.3, literal:

| Tarea | Cliente | Fabricante |
|---|---|---|
| Servidor, red y certificados | ✅ | Guía y requisitos |
| Instalación y actualización | ✅ | Scripts y documentación |
| Copias de seguridad y su verificación | ✅ | Herramientas y alerta si fallan |
| Configuración y perfiles de cumplimiento | ✅ | Perfil español de serie |
| Gestión de empleados, impresión y entrega de tarjetas | ✅ | Generador de PDF |
| Responsable del tratamiento | ✅ | — |
| Corrección de defectos del producto | — | ✅ |
| Diagnóstico de incidencias | Genera el paquete | Lo analiza |
| Acceso a datos | — | Solo con concesión expresa |

> Este reparto va en el contrato y en la documentación entregada. La mayoría de los conflictos de soporte en productos on-premise nacen de que nunca se escribió.
> — §11.6.3

El [doc 05](../docs/05-presentacion-cliente.md) §10.7 publica la misma tabla al cliente y repite la misma advertencia. **Coinciden, y esa coincidencia hay que mantenerla**: si el reparto cambia, hay que cambiar los dos documentos y el contrato, no uno.

### 5.1 Cruce con el §7.3 del doc 01 — el reparto de roles legales

La tabla anterior es operativa. La legal es la del [doc 01](../docs/01-especificaciones-proyecto.md) §7.3, y **es la que determina quién responde ante una autoridad de control**:

| ID | Qué establece | Consecuencia práctica en la entrega |
|---|---|---|
| `RL-16` | **El cliente es responsable del tratamiento** y operador: aloja los datos, controla los accesos y responde ante la Inspección y ante su plantilla | Todo lo que va en `obligaciones-legales.md`: registro de actividades, información a la plantilla y a su representación, EIPD si procede, custodia y copia |
| `RL-17` | **El fabricante no es encargado del tratamiento** en la operación ordinaria, porque no aloja ni accede a los datos | Es consecuencia directa de ADR-016 y ADR-020. Si el fabricante tuviera acceso permanente, esta afirmación sería falsa |
| `RL-18` | **Encargo acotado a soporte.** Cuando el fabricante accede durante una intervención (`RF-PD-11`), actúa como encargado **para ese supuesto concreto**. Requiere contrato de encargo (art. 28 RGPD) limitado a soporte, con instrucciones documentadas, confidencialidad y prohibición de conservar datos al terminar | La concesión de soporte de la tarea 5.9 es el mecanismo técnico que hace verificable ese contrato: expresa, temporal, de alcance limitado, revocable y **auditada de forma visible para el cliente** |
| `RL-19` | **El paquete de diagnóstico no contiene datos personales** por defecto. Incluirlos es acción explícita del cliente, avisada en la interfaz y registrada en auditoría | Ver [sección 7](#7-paquete-de-diagnóstico) |
| `RL-20` | **Continuidad e independencia**: el cliente exporta la totalidad de sus datos en formato abierto y sin intervención del fabricante | `product:export-all` (`RF-PD-14`, tarea 5.10). Y no puede depender de la licencia: sirve *«aunque la relación comercial termine»* |
| `RL-21` | La documentación entregada indica con claridad qué obligaciones asume el cliente. **El producto facilita el cumplimiento; no lo sustituye** | Es el requisito que la tarea 5.11 materializa en `obligaciones-legales.md` |

**Dónde se rompe este reparto si no se cuida.** En un solo punto: el acceso de soporte. Mientras el fabricante no accede, `RL-17` se sostiene solo. En el momento en que accede, entra `RL-18` y hace falta un contrato de encargo firmado **antes** de la primera intervención. La Nota final del doc 02 lo pone entre lo que debe validar una asesoría laboral antes de la primera venta.

---

## 6. Matriz de versiones soportadas

Del §11.6.5, literal:

> Se publica y se cumple: la versión menor vigente y las dos anteriores reciben correcciones de seguridad; el salto de versión mayor tiene ventana de migración anunciada con antelación. Sin esta disciplina, con veinte clientes se acaba manteniendo veinte productos.

| Regla | Qué significa | Consecuencia operativa |
|---|---|---|
| **La menor vigente y las dos anteriores** reciben correcciones de seguridad | Con la 1.6 publicada: 1.6, 1.5 y 1.4 soportadas | Es el dato que consume el paso 1 de `update.sh`: si el cliente está en 1.2, la actualización no se intenta y se le indica la versión intermedia |
| **El salto de versión mayor tiene ventana de migración anunciada con antelación** | Un cambio de 1.x a 2.0 no sorprende a nadie | Debe anunciarse por un canal que funcione con clientes sin internet |
| **Se publica y se cumple** | La matriz es un compromiso, no una intención | Va en `operacion.md` y en el contrato |

Y el motivo, que es económico antes que técnico: *«con veinte clientes se acaba manteniendo veinte productos»*. Es la misma economía que sostiene ADR-017 y la regla dura 13: **nunca una rama por cliente**, porque *«el tercer cliente convierte esa idea en un producto imposible de mantener»*.

### 6.1 Distribución de imágenes

Del §11.6.1: registro **privado** del fabricante, **etiquetas de versión inmutables**, y **nada de `latest` en producción**. Por qué importa aquí y no es una preferencia:

- Con `latest`, dos clientes que instalan el mismo día pueden acabar en versiones distintas, y ninguna matriz de soporte sobrevive a eso.
- El §10.5 exige que **la versión desplegada sea visible** en `GET /api/v1/health` y en la pantalla de diagnóstico del quiosco, *«para poder correlacionar un incidente con una versión concreta»*. Con etiquetas móviles, ese número deja de significar nada.
- La actualización a versiones no consecutivas necesita poder traer **exactamente** la 1.3, la 1.4 y la 1.5. Sin inmutabilidad, el paso 4 del §11.6.4 no es reproducible.
- La etapa 8 de la CI prueba la actualización **desde cada versión soportada** (§10.1): esas versiones tienen que seguir existiendo tal como se publicaron.
- Trivy exige 0 CVE críticos en la imagen final (§9.2), y ese resultado se predica de una imagen concreta, no de una etiqueta que se mueve.

⚠️ No cubierto por los documentos — decidir: la **duración temporal** del soporte de una versión menor. El §11.6.5 fija una política por **número de versiones** («la vigente y las dos anteriores»), no por tiempo, y sin una cadencia de publicación declarada un cliente no puede planificar cuándo tendrá que actualizar. También queda pendiente el canal por el que se anuncia la ventana de migración a un cliente sin salida a internet.

---

## 7. Paquete de diagnóstico

Del §11.6.6, literal:

> Generado por el administrador del cliente con un clic o un comando. Contiene versión, configuración **sin secretos**, estado de los servicios, el **histórico de `error_events` del periodo** con su agrupación por huella y su `trace_id` (RF-PD-15), salud de quioscos, tamaño de las colas, resultado de `doctor` y métricas agregadas.
>
> **No contiene datos personales.** Los identificadores de empleado se sustituyen por sus UUID, y no se incluyen nombres, correos ni registros de jornada. Si un incidente concreto exige incluirlos, es una acción distinta, explícita, avisada en la interfaz y auditada.

**Cómo se genera.** `POST /api/v1/diagnostics/bundle` `[rol: admin]` (doc 01 Anexo B) desde el panel —el «un clic» que el doc 05 §10.6 promete— o `php artisan product:diagnostics --anonymized` (Anexo C). Tarea **5.9**.

### 7.1 Qué contiene y qué no

| ✅ Contiene | ❌ No contiene |
|---|---|
| Versión desplegada (§10.5) | **Nombres** de empleados o de usuarios |
| Configuración **sin secretos** | **Correos** electrónicos |
| Estado de los servicios | **DNI** (que además solo existe hasheado, `RL-08`) |
| **`error_events` del periodo**, agrupado por huella, con `trace_id` (`RF-PD-15`) | **Registros de jornada**: ni tramos, ni totales, ni horas de nadie |
| Salud de quioscos: último latido, cola pendiente, versión | **Secretos**: `LICENSE_KEY`, `QR_SIGNING_KEY_*`, `BACKUP_ENCRYPTION_KEY`, `REVERB_APP_SECRET`, credenciales de base de datos |
| Tamaño de las colas | Contenido de `audit_log` con sujetos identificables |
| Resultado de `doctor` | El padrón cacheado del quiosco |
| Métricas agregadas (§8.2) | Cualquier campo no incluido explícitamente en la lista de permitidos |
| Estado de licencia y último informe de actualización (derivado de 5.3 y 5.7: son las dos primeras preguntas de cualquier incidencia) | |

**Los identificadores de empleado son sus UUID**, nunca su nombre ni su `employee_code`. Es la misma regla que gobierna `error_events` (doc 01 §5: *«`employee_uuid` (NULL, nunca el nombre)»*) y el log técnico (§8.1: *«Nunca nombres en claro»*).

### 7.2 Por qué la anonimización se implementa como lista de permitidos

Una lista de exclusiones falla en silencio la primera vez que alguien añade un campo nuevo a una tabla que el recolector recorre. Y el fallo no se detecta: el paquete se envía igual. La regla dura 21 es tajante sobre la consecuencia:

> *El histórico de errores viaja al fabricante dentro del paquete de diagnóstico: **si lleva PII, se ha filtrado**.*

De ahí que la tarea 5.9 exija, además de las pruebas unitarias del anonimizador, **inspección manual de un paquete generado sobre datos realistas** (500 empleados, 90 días) buscando nombres, correos y horas. Es la única verificación que detecta un campo nuevo que se colara.

### 7.3 Incluir datos personales es una acción distinta

`RL-19` y el §11.6.6 coinciden: *«Si un incidente concreto exige incluirlos, es una acción distinta, explícita, avisada en la interfaz y auditada.»* Los cuatro adjetivos son requisitos separados:

| Adjetivo | Qué implica en la implementación |
|---|---|
| **Distinta** | Bandera o acción propia, nunca un efecto secundario de otra opción ni el valor por defecto |
| **Explícita** | La decide el **cliente**, no el soporte. El fabricante no puede activarla |
| **Avisada en la interfaz** | Antes de generar, la pantalla explica **qué se va a incluir y qué implica** |
| **Auditada** | Entrada en `audit_log`, visible para el cliente |

Y el marco legal que lo acompaña: en el momento en que el fabricante recibe datos personales, entra `RL-18` (encargo acotado a soporte, art. 28 RGPD, con prohibición de conservarlos al terminar).

### 7.4 El paquete tiene que bastar

El principio de fondo, del ADR-020 y del agente `producto-licencia`: **no se puede entrar a arreglarlo**. De ahí que el ADR-020 cierre con *«obliga a que los errores sean autoexplicativos»*, y de ahí las tres exigencias que atraviesan todo este documento: mensajes de error que dicen **qué hacer**; registros legibles por quien no conoce el código (`error_events`, `RF-PD-15`); y un paquete que contiene lo necesario **sin una segunda ronda de información**.

El runbook que verifica si esto se ha conseguido es `incidencia-sin-acceso.md` (§12): *«Cómo diagnosticar con el paquete que envía el cliente.»* Si al escribirlo hacen falta datos que el paquete no lleva, el paquete está mal diseñado.

⚠️ No cubierto por los documentos — decidir: el **formato del fichero** del paquete, si va **cifrado**, y por qué **canal** se entrega al soporte. El §11.6.6 y el doc 05 §10.6 describen el contenido y dicen que «se envía al soporte», pero nada más. Es relevante porque el paquete atraviesa la frontera de datos del cliente y su tránsito no está cubierto por ninguna medida descrita.

---

## 8. Etapa 8 de la CI

Del §10.1, la etapa que cierra el pipeline antes de publicar:

```
… ⑦ E2E ──► ⑧ Instalación limpia
              + actualización desde versión anterior
              ~4 min
           ──► 🚀 Publicación de versión
                  imágenes etiquetadas + paquete de entrega
```

> Etapas 1–3 en cada *push* (retroalimentación en menos de 4 minutos). Etapas 4–7 en cada PR. **Etapa 8 antes de publicar una versión.**
> — §10.1

El umbral bloqueante del §9.2 lo formula así:

| Nivel | Herramienta | Umbral bloqueante |
|---|---|---|
| **Instalación** | Script en CI: instalación limpia + actualización desde versión anterior | **Verde antes de publicar (RQ-11)** |

Y `RQ-11` del doc 01 §10: *«Prueba de instalación limpia y de actualización desde la versión anterior antes de cada publicación.»*

### 8.1 Qué tiene que cubrir la etapa

Escenarios del §9.4 que aterrizan aquí:

| Escenario | Cómo se prueba | Requisito |
|---|---|---|
| **Instalación limpia** | Desde cero, en máquina virgen, siguiendo **solo la guía escrita**, con `doctor` en verde al terminar | `RF-PD-02`, `RQ-11` |
| **Actualización desde cada versión soportada** | Una ejecución por versión de la matriz del §11.6.5, con verificación posterior | `RF-PD-10`, `RQ-11` |
| **Salto no consecutivo** | Desde la más antigua soportada hasta la actual, encadenando intermedias con sus puntos de control | `RF-PD-10`, §11.6.4 |
| **Vuelta atrás** | Fallo inyectado en la comprobación de salud → se restaura la copia previa y los conteos coinciden con los previos | `RF-PD-10` |
| **Restauración de copia** | «Script automatizado que restaura la última copia en un contenedor limpio y valida integridad referencial y conteos» | `RF-PR-04`, `RQ-09` |
| **Idempotencia de los scripts** | Segunda ejecución de `install.sh` y de `update.sh`: no rompen nada y lo dicen | §3.5 |
| **Comandos de la documentación** | Extraídos de `instalacion.md` y `operacion.md` y **ejecutados**, no copiados a mano | `RF-PD-02`, tarea 5.11 |

Ese último punto es el que convierte la documentación en verificable: un comando que se ejecuta en CI no puede quedarse desfasado en silencio.

### 8.2 Qué se publica cuando la etapa 8 está en verde

Del §10.1 y del §11.6.1: **imágenes etiquetadas** (versión inmutable, registro privado) y el **paquete de entrega** con el árbol de la [sección 1](#1-qué-se-entrega-al-cliente). El flujo de publicación vive en `.github/workflows/release.yml` (§2).

### 8.3 Lo que la etapa 8 no cubre y hay que recordar

- **La prueba de carga** (`RQ-08`, k6, `RNF-P-06`: 50 fichajes/s con p95 < 150 ms) se ejecuta **antes de cada versión mayor**, no en cada publicación.
- **La prueba de resistencia de 12 h** del quiosco en dispositivo real (Anexo A) no es automatizable en CI: es prueba de campo.
- **La revisión de seguridad externa** (`RS-11`, tarea 3.8) es previa a la primera versión comercial y anual.
- **La instalación limpia hecha por una persona ajena siguiendo solo la guía** (criterio de terminado del doc 03 §6.5, tarea 5.11) no la sustituye ningún script: la CI prueba que los comandos funcionan, no que la guía se entienda.

---

## 9. Los 20 runbooks

Tabla del §12, con el «cuándo se usa» literal, y la asignación de a qué tarea o fase pertenece su redacción:

| # | Runbook | Cuándo se usa (§12) | Redacción |
|---|---|---|---|
| 1 | `quiosco-no-responde.md` | Alerta de latido perdido | Fase 3, tarea 3.2 (catálogo de alertas con runbooks) |
| 2 | `cola-offline-atascada.md` | Cola de dispositivo por encima del umbral | Fase 3, tarea 3.2 |
| 3 | `divergencia-proyeccion.md` | La reconciliación detecta discrepancia | Fase 2, tarea 2.7 (reconciliación con alerta) → completado en 3.2 |
| 4 | **`rotura-cadena-auditoria.md`** | **Incidente de seguridad.** Incluye preservación de evidencia | Fase 2, tarea 2.2 (`audit_log` encadenado, comando de verificación y alerta) |
| 5 | `restaurar-backup.md` | Recuperación y simulacro trimestral | Fase 2, tarea 2.11 → usado y completado por 5.7 |
| 6 | `rotacion-secretos.md` | Rotación programada o compromiso | §7.7; los secretos que genera el instalador se añaden en 5.4 |
| 7 | `alta-nuevo-quiosco.md` | Emparejamiento por código y vinculación | **Fase 5, tarea 5.6.** Incluye el modo quiosco, que es del cliente (§11.6.2) |
| 8 | `alta-nuevo-empleado.md` | Alta, emisión, impresión y entrega **con la antelación necesaria** | Fase 1, tarea 1.10 (§5.5: «es un requisito de proceso, no de software, y va en el runbook de alta de empleado») |
| 9 | `tarjeta-perdida-o-rota.md` | Revocación, reemisión y reimpresión en el día | Fase 1, tarea 1.10 |
| 10 | `rotacion-clave-qr.md` | Reimpresión progresiva sin dejar a nadie sin fichar | Fase 2, tarea 2.12 (`RF-QR-07`) |
| 11 | **`requerimiento-inspeccion.md`** | **Cómo generar la exportación legal en menos de 1 hora.** El más importante y el que nadie escribe hasta que hace falta | Fase 2, tarea 2.9 (exportación legal para Inspección, `RL-06`) |
| 12 | `patron-anomalo-credencial.md` | Cómo revisar una incidencia `anomalous_pattern` sin convertir un indicio en una acusación | Fase 3, tarea 3.11 (`RF-PR-06`) |
| 13 | `solicitud-derechos-rgpd.md` | Acceso, rectificación, portabilidad | **Fase 2, tarea 2.10** (`RL-10`), que es la que reúne qué se conserva, dónde y con qué plazo; referenciado desde `obligaciones-legales.md` en 5.11 |
| 14 | `brecha-de-seguridad.md` | Procedimiento de 72 h | Fase 2 (`RL-15`); referenciado desde `obligaciones-legales.md` en 5.11 |
| 15 | `actualizacion-cliente.md` | Procedimiento y vuelta atrás | **Fase 5, tarea 5.7** |
| 16 | `incidencia-sin-acceso.md` | Cómo diagnosticar con el paquete que envía el cliente | **Fase 5, tarea 5.9** |
| 17 | `errores-en-el-panel.md` | Cómo lee el IT del cliente el histórico de `error_events` y qué hacer con cada severidad | **Fase 5, tarea 5.12**, que es la que crea `error_events` y su pantalla. La alerta de 3.2 lo **enlaza**, no lo escribe |
| 18 | `turno-abierto-prolongado.md` | Turno abierto por encima de 12 h. **El sistema nunca lo cierra por su cuenta** (RN-08): el procedimiento es contactar y corregir de forma trazada con motivo `OLVIDO_FICHAJE_SALIDA` | **Fase 2, tarea 2.6**, que es la que crea la alerta. Destinatario **RRHH**, no IT: no es una avería, es trabajo de gestión sobre el registro |
| 19 | `renovacion-certificado-tls.md` | Certificado TLS próximo a expirar | **Fase 3, tarea 3.2** |
| 20 | `espacio-en-disco.md` | Espacio en disco por debajo del 20 % | **Fase 3, tarea 3.2** |

> **Los tres últimos faltaban en el §12 del doc 02 y ya están añadidos allí.** El §8.4 no admite alerta sin procedimiento —*«una alerta sin procedimiento asociado es ruido y se elimina»*—, así que la alternativa era eliminar del catálogo mínimo tres alertas, y ninguna de las tres es prescindible: una detecta precisamente el caso que RN-08 prohíbe resolver automáticamente, y las otras dos son los dos modos de fallo que dejan una instalación fuera de servicio sin aviso previo. **Con ellas la tabla suma 20, que es lo que el título dice.**

**Norma de diseño que aplica a todos** (§8.4): *«cada alerta lleva destinatario, umbral y enlace a su runbook. Una alerta sin procedimiento asociado es ruido y se elimina.»* Es también el primero de los seis principios del agente `devops-observabilidad` (doc 03 §4.3). Y la Definición de Terminado del §10.3 lo cierra por el otro lado: *«Runbook o documentación de cliente actualizada si añade un modo de fallo o un parámetro.»*

### 9.1 Los dos que el doc 02 destaca

**`requerimiento-inspeccion.md`** — *«Cómo generar la exportación legal en menos de 1 hora. **El más importante y el que nadie escribe hasta que hace falta.**»*

Por qué es el más importante: es el único runbook que se ejecuta bajo presión externa y con consecuencia sancionadora. El doc 01 §7.1 lo enmarca: *«la falta de registro o su falseamiento se tipifica como infracción grave en materia de relaciones laborales, sancionable por cada centro de trabajo»*. Y `RL-06` exige exportación en formato legible y tratable, no propietario.

Lo que el runbook tiene que dejar resuelto para cumplir su objetivo de **menos de 1 hora**: quién puede ejecutarlo (`GET /api/v1/reports/legal-export`, `[rol: auditor|rrhh]`), el comando exacto (`php artisan compliance:legal-export --from= --to= --employee=`), qué periodo y qué empleados se piden habitualmente, y qué debe contener la salida. Sobre esto último, la skill `/informe-nuevo` es explícita: *«la exportación para Inspección debe incluir las correcciones con su autor y motivo: un informe que las oculte no cumple»*. Se escribe en la tarea 2.9 y se referencia desde `obligaciones-legales.md` en la 5.11.

**`rotura-cadena-auditoria.md`** — *«**Incidente de seguridad.** Incluye preservación de evidencia.»*

Por qué es especial: no es un fallo operativo, es la señal de que alguien pudo alterar un registro con valor probatorio. El §8.2 lo dice del contador correspondiente: `audit_chain_verification_failures_total` debe permanecer **siempre en cero**, y *«cualquier incremento es un incidente de integridad, no una métrica de tendencia»*. El doc 01 §9.3 le asigna severidad **Crítica (seguridad)** y `RS-07` exige detección en menos de 24 h.

Lo que lo distingue de los demás runbooks es el orden de las acciones: **preservar evidencia antes de intentar arreglar nada**. Un `UPDATE` bienintencionado destruye la única prueba de qué ocurrió. De ahí también la precondición que el paso 1 de `update.sh` incorpora (ver [2.2](#22-updatesh)): verificar la cadena **antes** de actualizar, para que nadie tenga que averiguar después si la rompió la actualización. El refuerzo opcional del §7.4 —publicar semanalmente el último hash en un medio externo— es lo que permite acotar el alcance cuando ocurra.

---

## 10. Gestión de secretos y su rotación

Del §7.7, literal:

> Nada de secretos en el repositorio. En desarrollo, `.env` local a partir de `.env.example`. En producción, el instalador **genera los secretos en el servidor del cliente** y nunca los transmite. Rotación documentada en `docs/runbooks/rotacion-secretos.md` para: `APP_KEY`, claves HMAC de QR, credenciales de base de datos, tokens de dispositivo y claves de copia.

Y `RS-08` del doc 01 §8: *«Gestión de secretos fuera del repositorio, con rotación documentada.»* El doc 05 §10.2 se lo promete al cliente en estos términos: *«genera las claves de seguridad **en el propio servidor del hotel** (nunca se transmiten)»*.

### 10.1 Las tres reglas

| Regla | Consecuencia |
|---|---|
| **Nada de secretos en el repositorio** | Verificado por Semgrep en la etapa 5 de la CI (§9.2, §10.1). Incluye los scripts y su salida (§3.5) |
| **En producción los genera el instalador, en el servidor del cliente** | Paso 5 de `install.sh`. El fabricante **nunca los conoce**, lo cual es coherente con ADR-016 y con `RL-17`: si los conociera, la afirmación de que no accede a los datos sería más débil |
| **Nunca se transmiten** | No hay ningún flujo en el que un secreto salga de la instalación. Tampoco en el paquete de diagnóstico (§11.6.6: configuración **sin secretos**) |

### 10.2 Qué rota, y con qué cuidado

Lista literal del §7.7, con lo que cada una arrastra:

| Secreto | Dónde vive | Qué hay que cuidar al rotarlo |
|---|---|---|
| **`APP_KEY`** | `.env` (Anexo B) | Todo lo cifrado con la clave anterior deja de ser legible. El runbook debe decir qué es y qué hacer antes |
| **Claves HMAC de QR** (`QR_SIGNING_KEY_CURRENT`, `..._PREVIOUS`, con sus `key_id`) | `.env` (Anexo B) | **Es la rotación delicada.** El §5.3 la resuelve con **dos claves activas simultáneamente** (`current` y `previous`): se emite `key_id` nuevo, las tarjetas se reimprimen progresivamente, y la clave anterior se retira cuando el panel confirma que **no queda ninguna credencial activa con ese `key_id`**. *«Sin `key_id` habría que reimprimir toda la plantilla en un solo día. Con él, la operación se reparte en semanas sin dejar a nadie sin poder fichar.»* Comando `credentials:rotate-key` (Anexo C), tarea 2.12 (`RF-QR-07`), runbook `rotacion-clave-qr.md` |
| **Credenciales de base de datos** | `.env` y `postgres` | El usuario de aplicación conserva sus permisos mínimos: sin DDL, y **sin `UPDATE` ni `DELETE` sobre `audit_log`** (§7.1, regla dura 6). Una rotación que devuelva permisos de más rompe la garantía de inalterabilidad |
| **Tokens de dispositivo** | `devices.token_hash` | Rotación **automática al 80 % de vida** sobre una caducidad de 90 días (§7.3). El quiosco no puede quedarse sin poder sincronizar: regla dura 19. Al desvincular, se purga el padrón cacheado (doc 01 §8.1) |
| **Claves de copia** (`BACKUP_ENCRYPTION_KEY`) | `.env` (Anexo B) | **Las copias antiguas siguen cifradas con la clave antigua.** Rotar sin conservar la anterior las convierte en irrecuperables, y con ellas el registro de cuatro años. El runbook tiene que decir explícitamente dónde se custodia cada clave y durante cuánto tiempo |

**Dos advertencias que hay que dejar escritas en `rotacion-secretos.md`**, porque ambas pueden dejar una instalación en producción sin poder fichar o sin poder recuperar datos:

1. **Rotar la clave HMAC sin solape invalida todas las tarjetas impresas.** El solape por `key_id` existe precisamente para eso (§5.3, ADR-005). Y renombrar el prefijo `FH1` tiene el mismo efecto (nota de nomenclatura del doc 02): no se toca.
2. **Rotar `BACKUP_ENCRYPTION_KEY` sin conservar la anterior deja las copias existentes ilegibles.** No es un problema de operación: es la pérdida del registro legal que el cliente está obligado a conservar cuatro años (`RL-11`).

### 10.3 Los secretos en la salida de los scripts

El §3.5 lo prohíbe y Semgrep lo vigila. La consecuencia práctica, ya recogida en la [sección 2](#20-convenciones-que-aplican-a-los-cinco): **el cliente va a pegar la salida del script en un correo**, así que nada de lo que se imprima puede ser un secreto. Cuando el cliente necesita custodiar una clave fuera del servidor —`BACKUP_ENCRYPTION_KEY` es el caso—, el procedimiento va en `operacion.md` y en `rotacion-secretos.md`, con el comando que la muestra bajo su responsabilidad, nunca en la salida automática de la instalación.

---

## 11. Las verificaciones pendientes antes de la primera venta

### 11.1 Las dos de la Nota final del doc 02

Literal:

> Dos verificaciones quedan fuera de lo que este documento puede resolver y deben cerrarse antes de la primera venta:
>
> 1. **Validación jurídica.** La sección legal del documento 01 recoge requisitos de producto derivados del marco normativo, no asesoramiento jurídico. Debe validarla una asesoría laboral, junto con el **contrato de licencia** y el **contrato de encargo acotado a soporte**.
> 2. **Vigilancia normativa.** Existe una corriente regulatoria hacia el registro digital, interoperable y con acceso remoto para la Inspección. La arquitectura lo cubre por diseño, pero debe designarse un responsable de seguimiento antes de cada versión mayor.

Qué toca de este bloque de entrega cada una:

| Verificación | Qué artefactos de la entrega afecta |
|---|---|
| **Validación jurídica** | `LICENCIA.txt` y el contrato de licencia; el **contrato de encargo acotado a soporte** del art. 28 RGPD, sin el cual el acceso de soporte de `RF-PD-11` no puede ejercerse (`RL-18`); `docs/cliente/obligaciones-legales.md` completo (`RL-16..21`); la tabla de reparto de responsabilidades de la [sección 5](#5-reparto-de-responsabilidades), que «va en el contrato» |
| **Vigilancia normativa** | La exportación legal (`RL-06`) y su runbook `requerimiento-inspeccion.md`; el compromiso de la matriz de versiones (§11.6.5), porque un cambio normativo obliga a actualizar la base instalada; y `obligaciones-legales.md`, que quedaría desfasado |

El doc 01 §7.1 repite el aviso de vigilancia normativa y añade la misma condición: *«Debe designarse un responsable de seguimiento antes de cada versión mayor.»* Y el doc 03 §4.3 acota el papel del agente `seguridad-cumplimiento` en el mismo sentido: *«no dar asesoramiento jurídico — señala requisitos y riesgos, y remite la validación a la asesoría»*.

### 11.2 Las tres que el §11.0 excluye de las horas

El §11.0 es explícito sobre qué **no** incluyen las estimaciones del plan: *«No incluyen aprender el dominio, esperar decisiones del cliente, ni las tres validaciones de la nota final (asesoría laboral, prueba de campo del hardware, contraste de costes de impresión).»*

| Validación | Qué hay que verificar | Por qué está fuera del plan |
|---|---|---|
| **Asesoría laboral** | Lo de 11.1: marco legal, contrato de licencia y contrato de encargo acotado a soporte | No es trabajo de desarrollo y no depende del equipo |
| **Prueba de campo del hardware** | La tablet real: **resistencia de 12 h** con escaneo continuo por cámara, sin fugas de memoria (≤ 250 MB *«sin crecimiento sostenido»*), LCP ≤ 2,0 s, interacción a confirmación ≤ 800 ms (p95) y **consumo de batería con pantalla activa, que el Anexo A deja como «documentar y validar en la prueba de campo»**. Más la legibilidad del QR a 20 cm con la cámara real y una tarjeta desgastada (§5.1) | Requiere dispositivo físico y tiempo real de reloj; no se puede automatizar en CI |
| **Contraste de costes de impresión** | El material de la tarjeta: PVC plastificado si el cliente tiene impresora de tarjetas, papel plastificado como alternativa económica; el mismo diseño de PDF sirve para ambos (§5.5) | Es un coste del cliente, no del producto, y depende de su proveedor |

**Las tres condicionan la venta, no el desarrollo**, y las tres tienen que estar cerradas antes de la primera instalación en casa de un cliente. Conviene anotarlo aquí porque son exactamente el tipo de trabajo que se descubre tarde: ninguna aparece en una tabla de tareas con horas.

---

## Puntos no cubiertos por los documentos

Los de este fichero, con la sección donde se plantean. Los específicos de cada tarea de la Fase 5 están en [`05-fase-5-productizacion.md`](05-fase-5-productizacion.md#puntos-no-cubiertos-por-los-documentos) y no se repiten aquí.

| # | Sección | Punto |
|---|---|---|
| 1 | [1.4](#14-licenciatxt) | ✅ **RESUELTO en lo técnico** — El repositorio lleva un marcador de posición y **la etapa 8 de la CI impide publicar si el paquete sigue llevándolo**. ⚠️ El **texto** lo redacta o valida una asesoría laboral: no es decisión técnica, y así lo sitúa la Nota final del doc 02 |
| 2 | [2.0](#20-convenciones-que-aplican-a-los-cinco) | **Valores numéricos de los códigos de salida** de los cinco scripts, y su consistencia entre ellos, dado que `install.sh`, `update.sh` y `doctor.sh` se invocan unos a otros. El §3.5 exige que estén documentados en la cabecera, pero no los fija. ⚠️ No cubierto por los documentos — decidir |
| 3 | [2.1](#21-installsh) | ✅ **RESUELTO** — `install.ps1` se retira del paquete ([ADR-022](../docs/adr/ADR-022-sin-instalador-de-windows.md)). El §11.6.1 del doc 02 ya no lo lista. ShellCheck y `shfmt` cubren ahora el 100 % de los scripts entregados, sin exclusiones, y la etapa 8 verifica la única vía que se entrega |
| 4 | [2.4](#24-restoresh) | ✅ **RESUELTO** — No hace falta imputarlo a ninguna tarea: la **0.7** ya cubre `backup.sh` y `restore.sh` (contenido funcional de 2.11) con ShellCheck/`shfmt` sobre todo `infra/scripts/`, de forma transversal a las fases. `update.sh` (5.7) hereda la misma garantía |
| 5 | [3, paso 4](#paso-4--migraciones-en-orden-de-versión-con-punto-de-control-entre-cada-una) | **Cómo se materializa el «punto de control entre cada migración»** —copia incremental, `savepoint`, marca de versión, volcado por versión— y cuánto espacio adicional exige, que a su vez condiciona la precondición de espacio del paso 1. ⚠️ No cubierto por los documentos — decidir |
| 6 | [6.1](#61-distribución-de-imágenes) | **Duración temporal del soporte de una versión menor** (la política del §11.6.5 es por número de versiones, no por tiempo) y **canal por el que se anuncia la ventana de migración de versión mayor** a un cliente sin salida a internet. ⚠️ No cubierto por los documentos — decidir |
| 7 | [7.4](#74-el-paquete-tiene-que-bastar) | **Formato, cifrado y canal de entrega del paquete de diagnóstico.** El §11.6.6 y el doc 05 §10.6 describen su contenido y que «se envía al soporte», pero no el formato del fichero, si va cifrado ni por qué medio viaja, y el paquete atraviesa la frontera de datos del cliente. ⚠️ No cubierto por los documentos — decidir |

---

← Anterior: [Fase 4 — Evolución](07-fase-4-evolucion.md) · [Índice](README.md)
