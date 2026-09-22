# Revisión interna de seguridad y cumplimiento — previa a la revisión externa (ASVS 2)

| | |
|---|---|
| **Alcance** | Todo lo implementado en el repositorio (Fases 0, 1, 2, 5 y la Fase 3 hasta la tarea 3.7 incluida): backend, tres SPA, `packages/web-kit`, `infra/`, migraciones, pruebas y documentación de cliente. |
| **Fecha** | 2026-09-22 |
| **Commit revisado** | `3bbf478b0b4aabe59a73fa88909d89127f67e19a` (`main` tras integrar la PR #72; rama de trabajo `feat/tarea-3.8-revision-seguridad`) |
| **Nivel objetivo** | OWASP ASVS **2 (estándar)**, con controles de **nivel 3 en el registro de auditoría** (doc 02 §7.6). Capítulos verificados: V1, V2, V3, V4, V5, V7, V8, V9, V12, V13, V14. |
| **Método** | Lectura de código, configuración, migraciones, reglas de Prometheus, definiciones de pruebas y documentos de decisión por `seguridad-cumplimiento` (solo lectura). Toda afirmación se apoya en fichero:línea sobre el commit revisado. La evidencia automática del §9.2 (auditorías de dependencias, SAST, Trivy, gitleaks) está en [`evidencia/`](evidencia/). |
| **Qué NO cubre** | **La revisión externa la hace un tercero** (RS-11) y este documento es solo su preparación. No hay DAST ejecutado, ni prueba de intrusión, ni verificación dinámica de la cadena TLS de una instalación real, ni revisión del entorno operado por el cliente. **No es asesoramiento jurídico**: se señalan requisitos y riesgos; la validación legal corresponde a la asesoría laboral del cliente y a su DPO. |
| **Dónde vive** | `docs/seguridad/`, que **no viaja en el paquete del cliente**: `infra/scripts/package.sh` copia únicamente `docs/cliente` y `docs/runbooks`. |
| **Cierre** | §8, al final: qué se decidió y corrigió por cada hallazgo, con su prueba de no regresión. |

---

## §1 Resumen ejecutivo

La base de seguridad del producto es sólida y está **verificada por herramienta**, no declarada: la cadena de auditoría cumple de hecho el nivel 3 de ASVS (solo-append garantizado por privilegios de PostgreSQL y probado por SQL directo contra la partición), el doble control ámbito + policy existe en cada ruta, los tres ejes de limitación de tasa del camino de fichaje están donde el diseño dice, el padrón de la tablet va cifrado con clave derivada del token del dispositivo y se purga al revocar, y la etapa ⑤ de la CI bloquea con `composer audit`, `npm audit`, Semgrep, gitleaks sobre el histórico y Trivy.

**No se ha encontrado ningún hallazgo BLOQUEANTE.** Durante la tarea, la evidencia de la CI destapó uno más (H-14, el asiento de la vuelta atrás que nunca se escribía), que se corrigió en la misma tarea. Lo que sí hay es un conjunto de **compromisos con fecha que vence justamente en esta tarea o en el cierre de la Fase 3**, y que hoy no tienen control en el código. Ninguno es una regresión introducida por la Fase 3; todos estaban anotados en doc 07 §6 con dueño y plazo, y el plazo ha llegado.

| Severidad | Nº |
|---|---|
| BLOQUEANTE | **0** |
| REVISAR | **7** (H-01 … H-06, y H-14 hallado por la evidencia de la CI durante la tarea) |
| OBSERVACIÓN | **7** (H-07 … H-13) |

Los seis `REVISAR` se agrupan en tres familias: **techos de aplicación que faltan en rutas de gestión** (H-01, H-02, H-04), **ciclo de vida de las cuentas de gestión** (H-03), y **verificación que el producto prometió y no tiene** (H-05 TLS, H-06 SRI, más H-09 DAST como observación).

---

## §2 Hallazgos

### H-01 · Dieciséis rutas de gestión sin zona de limitación de aplicación

- **Severidad:** REVISAR
- **ASVS / STRIDE:** V13 (API) y V4 (control de acceso) / **Denegación de servicio**
- **Ubicación:** `backend/routes/api_v1.php:816` — el grupo `['auth:sanctum', 'ability:employees:*']` sin `throttle`, del que cuelgan trece rutas (`POST /employees`, `PATCH /employees/{uuid}`, `/offboard`, `/pin/reset`, `/pin/deliver`, `GET`/`POST /employees/{uuid}/contracts`, las cuatro de `/departments`, `GET`/`PATCH /site`); la única con zona es `POST /employees/import`. `backend/routes/api_v1.php:454-461` — `GET /reports/legal-export`, sin `throttle`, frente a `GET /reports/period/export`, que sí lo lleva. `:691` y `:704` — `/auth/logout` y `/auth/me`, cubiertas solo por la zona de Nginx `auth`. Doc 07 §6 contaba «catorce»: enumerando el router salen dieciséis (la cuenta se hizo a mano).
- **Problema:** doc 02 §7.1 exige zona de aplicación «por cuenta además de por IP, porque el borde no puede acotar por cuenta». Estas rutas dependen exclusivamente de `limit_req zone=api burst=60` (`infra/docker/nginx/templates/kronoqr.conf.template:301`), que en un hotel es un cubo compartido por NAT entre todo el personal.
- **Consecuencia:** una cuenta de gestión legítima, o una robada, puede iterar `GET /reports/legal-export`, que devuelve **el registro horario completo de toda la plantilla**, sin que ningún control de la aplicación distinga quién lo pide ni cuántas veces. Y las rutas del grupo pasan por `ScopeGuard` (`backend/app/Modules/Shared/Application/Authorization/ScopeGuard.php:47`), cuyas denegaciones escriben `access.denied` en `audit_log` **bajo el candado global de ADR-010, el mismo por el que pasa cada fichaje**: un bucle sobre UUID ajenos mete escrituras ilimitadas en el camino crítico del cambio de turno.
- **Corrección:** `throttle:management` en el grupo y en `GET /reports/legal-export` y `GET /auth/me`; `POST /auth/logout` exenta en lista cerrada con motivo (solo revoca el token del que llama, no lleva `ability:` y acepta a propósito la sesión pendiente de 2FA). El limitador ya existe y acota por IP **y** por UUID de cuenta (`backend/app/Modules/Identity/IdentityServiceProvider.php:739-750`); el `429` se declara en el contrato para cada ruta que lo gana. Agente: **`backend-laravel`**.
- **Requisito:** RS-02, RS-04, RS-05; doc 02 §7.1; doc 07 §6 fila «rutas de gestión sin zona de limitación», plazo «cierre de la Fase 3».
- **Prueba de no regresión (doc 02 §9.5 → endpoint):** feature + contrato + autorización negativa. `->group('RS-02', 'RS-04')` sobre el `429` por cuenta en `GET /reports/legal-export` y en una ruta del grupo, más el contrato que declare el `429`.

### H-02 · No existe ninguna prueba que enumere el *router* y exija ámbito, zona de límite y prueba negativa por ruta

- **Severidad:** REVISAR
- **ASVS / STRIDE:** V4 (control de acceso) y V1 (arquitectura) / **Elevación de privilegios**
- **Ubicación:** `backend/tests/Feature/AuthorizationNegativeTest.php` (**lista escrita a mano** de pares rol × endpoint) más los `*Authorization*Test.php` por módulo. Ninguna prueba recorre `Router::getRoutes()` con ese fin. El contrato declara **80 operaciones** en 69 rutas. `POST /scan/batch` no tiene **ninguna** prueba de autorización negativa (`ScanBatchTest.php` no envía un 401 ni un 403; `KioskEndpointsAuthorizationTest.php:47-55` solo cubre `roster` y `heartbeat`). Tres *datasets* (`kioskPairingEndpoints`, `complianceProfileEndpoints`, `installationSettingsEndpoints`) se prueban solo contra `rrhh`.
- **Problema:** la regla dura 18 se sostiene hoy sobre la disciplina de quien escribe la ruta, no sobre una herramienta. Una ruta nueva sin `ability`, sin zona de límite o sin prueba negativa **no rompe nada**.
- **Consecuencia:** es el mecanismo por el que H-01 pudo existir dos fases sin que nadie lo notara. En un producto cuyo dato es el registro horario de personas, una ruta de gestión sin policy es acceso a la plantilla entera.
- **Corrección:** prueba Feature que recorra `Router::getRoutes()` (precedente: `backend/tests/Feature/Product/SupportScopeRoutesTest.php`; la suite Architecture no arranca el framework) y exija zona `throttle:` en toda ruta con `auth:sanctum`, con lista cerrada de exenciones justificadas; segunda prueba que exija que cada zona usada tenga su `RateLimiter::for()`; `ScanBatchAuthorizationTest` con el modelo de `ScanAuthorizationTest`; los tres *datasets* huérfanos por los seis actores; etiqueta `RS-05` en las pruebas de autorización negativa que la ficha exige. Agentes: **`qa-testing`**, con `backend-laravel` para las rutas que la prueba destape.
- **Requisito:** RQ-07, RS-02, RS-04, regla dura 18.
- **Prueba de no regresión:** la prueba **es** el artefacto. `->group('RS-02', 'RS-04', 'RS-05')`.

### H-03 · El producto sigue sin baja de cuentas de gestión ni rotación de contraseña

- **Severidad:** REVISAR
- **ASVS / STRIDE:** V2 (autenticación) y V4 (control de acceso) / **Elevación de privilegios**, **Divulgación**
- **Ubicación:** `users.is_active` se consulta al autenticar (`backend/app/Modules/Identity/Infrastructure/Persistence/EloquentUserAccounts.php:47,59`) y en el callback de Sanctum (`IdentityServiceProvider.php:791`), pero **las dos únicas escrituras del campo en todo `backend/app` lo ponen a `true`**: `Infrastructure/Console/CreateManagementUserCommand.php:99` y `Infrastructure/Persistence/EloquentManagementAccountRegistry.php:82`. No hay endpoint, ni comando, ni pantalla.
- **Problema:** una cuenta `rrhh` o `admin` solo puede desactivarse editando la fila a mano en PostgreSQL.
- **Consecuencia:** un jefe de recepción que deja el hotel conserva una cuenta plenamente válida, con contraseña y TOTP propios, con acceso a los datos de toda la plantilla **y a la corrección de jornadas**, que es donde se falsea un registro horario. El control compensatorio que hoy existe (fila 17 de `docs/cliente/endurecimiento.md`: revisión trimestral manual, y todo lo que haga la cuenta deja asiento) hace el uso indebido **detectable y no repudiable, no impedido**.
- **Corrección:** baja de cuenta con asiento `user.deactivated` (actor y motivo) y rotación de contraseña con asiento, por consola en esta tarea (`identity:deactivate-user`, `identity:reset-password`, el mismo canal que `identity:create-user` y `identity:2fa-reset`); la pantalla del panel queda como decisión de producto pendiente. Cautelas: (1) el alta pública del primer administrador cuenta también las cuentas desactivadas, así que la baja no puede reabrir esa ventana; (2) la sesión abierta de la cuenta desactivada deja de valer en la petición siguiente (el callback de Sanctum ya lo comprueba: se prueba). Fila 17 de `endurecimiento.md` reescrita con el comando. Agentes: **`backend-laravel`** (comandos, asientos, guía); pantalla, **`frontend-panel`** cuando se decida.
- **Requisito:** RS-05, RS-06, RL-16, regla dura 18; doc 07 §6 fila «El producto no tiene baja de cuentas de gestión», plazo «antes de la primera versión comercial».
- **Prueba de no regresión:** feature de los comandos (asiento, `is_active`, la sesión abierta deja de valer en la petición siguiente, el alta pública del primer administrador sigue cerrada). `->group('RS-05', 'RS-06', 'RL-16')`.

### H-04 · Amplificación de asientos `license.plan_exceeded` en la importación masiva de plantilla

- **Severidad:** REVISAR
- **ASVS / STRIDE:** V7 (logs) / **Denegación de servicio** (autoinfligida)
- **Ubicación:** `backend/app/Modules/Product/Application/UseCase/RecordPlanUsageHandler.php:78` publica un `PlanLimitExceeded` **por cada evaluación**; el oyente se engancha por fila en `backend/app/Modules/Product/ProductServiceProvider.php:1621` (`Event::listen(EmployeeHired::class, …)`); `backend/app/Modules/Workforce/Application/UseCase/ApplyEmployeeImport.php:67,93` reutiliza `RegisterEmployeeHandler`, que publica `EmployeeHired` por fila.
- **Problema:** un hotel con plan de 80 que importe 300 personas escribe **asientos casi idénticos** en `audit_log`, cada uno bajo el `pg_advisory_xact_lock` global de ADR-010. Medido al corregir: eran **N asientos, no N − plan** (10 con plan 3 y 10 filas), porque la evaluación se difiere al `afterCommit` del lote y cada fila ve ya el recuento final; y todos con `first_crossing: false`, así que ni siquiera quedaba la fecha del cruce (`firstCrossing: $usage->excess() === 1` solo es correcto de una en una).
- **Consecuencia:** esas 220 escrituras comparten candado con **cada fichaje del hotel**. No bloquea a nadie (los quioscos encolan, regla dura 19) pero es carga evitable en el único candado que el producto no puede permitirse congestionar; el síntoma sería «el quiosco va lento» durante una importación de RRHH.
- **Corrección:** un solo asiento por importación, con el recuento y el cruce calculados una vez. El camino individual (alta de una en una) se queda como está. Agentes: **`producto-licencia`** con **`arquitecto-dominio`** si toca el dominio.
- **Requisito:** RF-PD-04, RF-GP-05, ADR-010, ADR-028; doc 07 §6 fila «Amplificación de asientos de exceso de plan», plazo «Fase 3», ya vencido una vez.
- **Prueba de no regresión:** feature del endpoint de importación que cuenta asientos tras importar N > plan. `->group('RF-PD-04', 'RF-GP-05')`.

### H-05 · La vigilancia del certificado TLS no comprueba ni la cadena ni la identidad

- **Severidad:** REVISAR
- **ASVS / STRIDE:** V9 (comunicaciones) / **Divulgación**, **Manipulación**
- **Ubicación:** `infra/observability/blackbox/blackbox.yml:29-38` — un único módulo, `http_2xx_tls`, con `tls_config.insecure_skip_verify: true`. Las dos alertas de TLS (`infra/observability/prometheus/rules/tls.yml`) se calculan sobre `probe_ssl_earliest_cert_expiry`, que es una fecha.
- **Problema:** el `insecure_skip_verify` está **bien justificado para la sonda interna** (el certificado del hotel no lleva el nombre `nginx`, y el cliente puede usar una CA propia, doc 02 §3.4) y no debe tocarse. El hueco es que **no existe una segunda sonda** que sí verifique, contra `APP_URL`, desde donde los clientes reales llegan.
- **Consecuencia:** una cadena incompleta, un certificado sustituido por otro válido pero de otro emisor, o un nombre que dejó de coincidir tras un cambio de dominio, **no disparan ninguna alerta**. El síntoma le llega primero al empleado (navegador con aviso) que al IT del cliente. RL-12 pide TLS 1.3 en tránsito; vigilar su fecha no es vigilar su validez.
- **Corrección:** segundo módulo de blackbox (`http_2xx_tls_verified`, `insecure_skip_verify: false`) y un *target* nuevo en `prometheus.yml` apuntando a `APP_URL`, con alerta propia que distinga «no verificable» de «caducado», tolerando el modo autofirmado declarado (`TLS_ALLOW_SELF_SIGNED`, `backend/config/security.php:62`). Agente: **`devops-observabilidad`**.
- **Requisito:** RL-12, doc 01 §9.3; doc 07 §6 **A-8**.
- **Prueba de no regresión:** `promtool check rules` + `promtool test rules` con la serie nueva, y `AlertCatalogueTest` con la alerta nombrando `docs/runbooks/renovacion-certificado-tls.md`. `->group('RL-12')`.

### H-06 · El doc 02 §7.1 promete SRI en los assets y no existe en ninguna de las tres SPA

- **Severidad:** REVISAR
- **ASVS / STRIDE:** V14 (configuración) y V5 / **Manipulación**
- **Ubicación:** `docs/02-stack-tecnologico-y-plan-implementacion.md:666` («Cliente | … SRI en assets …») frente a los tres `index.html` y los cuatro `vite.config.ts`: **cero apariciones de `integrity=`**.
- **Problema:** contradicción entre un documento de autoridad 4 y el código, no un riesgo activo. El valor real de SRI aquí es bajo: los assets son del mismo origen, los sirve el mismo Nginx, y la CSP es `script-src 'self'` sin `unsafe-inline` (`infra/docker/nginx/snippets/security-headers.conf:10`). No hay CDN.
- **Consecuencia:** un revisor externo leerá la promesa, buscará el control y no lo encontrará. La decisión lleva dos fases aplazada (doc 07 §6: «decisión vencida y forzada en el cierre de Fase 5»).
- **Corrección:** **retirar la promesa del doc 02 §7.1** y dejar escrito por qué (CSP estricta + mismo origen + sin CDN), que es lo que doc 07 ya recomendaba. Agente: documentación.
- **Requisito:** RS-09; doc 07 §6 fila «SRI en assets».
- **Prueba de no regresión:** ninguna (es texto; `docs:consistency` vigila las contradicciones declaradas).

### H-07 · Falta la guarda automática que impida un sumidero HTML para el SVG de marca en Blade

- **Severidad:** OBSERVACIÓN
- **ASVS / STRIDE:** V12 (ficheros) y V5 / **Elevación**
- **Ubicación:** `backend/resources/views/pdf/*.blade.php` (8 plantillas) sin ninguna prueba de arquitectura que prohíba `{!! !!}`.
- **Problema y matiz:** la fila del doc 07 §6 daba por pendientes **dos** guardas y solo falta una. `vue/no-v-html` **sí bloquea de facto** en las tres SPA y en `packages/web-kit` (`flat/recommended` lo declara `warn` y los cuatro `package.json` lintan con `--max-warnings 0`). Lo que no existe es la guarda equivalente sobre Blade.
- **Consecuencia:** el logotipo entra como bytes SVG y hoy se consume por `<img src>` o `data:` en un Chromium sin red (ADR-016). Un cambio futuro que lo incruste en línea en una plantilla PDF dejaría la lista negra de `LogoFileInspector` como única defensa.
- **Corrección:** prueba de arquitectura que prohíba `{!! !!}` en `backend/resources/views/**`, y `vue/no-v-html: 'error'` explícito en los cuatro `eslint.config.js`. Agente: **`qa-testing`**.
- **Requisito:** RS-04, ADR-016; doc 07 §6 fila del filtro SVG, plazo «3.7», vencido.
- **Prueba de no regresión:** arquitectura. `->group('RS-04')`.

### H-08 · La condición de revisión de A-13 no es medible con el escenario `reject` actual

- **Severidad:** OBSERVACIÓN
- **ASVS / STRIDE:** V7 / **Divulgación** (canal lateral)
- **Ubicación:** `load-tests/k6/scan-peak.js:413` — `REJECT_CLASSES = ['signature', 'unknown', 'revoked']`. El rechazo de RN-18 se ejercita solo dentro del escenario `batch`, no en el escenario `reject`, que es el único cuyo veredicto `RS-03` compara clases entre sí (`load-tests/k6/aggregate.js`).
- **Problema:** A-13 se aceptó «a revisar en el cierre de la Fase 3, con la medición real de la separación de tiempos que produzca la prueba de carga». Esa medición no existe.
- **Consecuencia:** la fila no se puede prorrogar «con evidencia».
- **Corrección:** escenario `reject-out-of-order` (`RS-03 RN-18`) y veredicto **informativo** en `aggregate.js` que publique la separación frente a las tres clases de credencial; A-13 está aceptado sin suelo de tiempo y lo que faltaba era la cifra. Agente: **`qa-testing`**.
- **Requisito:** RS-03, RN-18, regla dura 17.
- **Prueba de no regresión:** k6 (`requirements: 'RS-03 RN-18'`) + `node --test load-tests/k6/aggregate.test.js`.

### H-09 · Sin DAST — el plazo de la fila era esta tarea

- **Severidad:** OBSERVACIÓN
- **ASVS / STRIDE:** V14 (configuración) / transversal
- **Ubicación:** `.github/workflows/ci.yml`, etapa ⑤: `composer audit`, `npm audit --audit-level=high`, Semgrep propio y comunitario, gitleaks sobre el histórico completo, Trivy `fs` e `image`, SBOM. **Ningún escáner dinámico.**
- **Problema:** la fila «Sin DAST» de doc 07 §6 se aceptó con plazo **3.8** y control propuesto «ZAP *baseline* contra el entorno de Compose, sin tarea asignada».
- **Consecuencia:** la ausencia es defendible (superficie pequeña, API con contrato y *Bearer* de Sanctum, tres SPA del mismo origen, SAST y autorización negativa cubriendo la mayor parte; un *baseline* encontraría sobre todo cabeceras, que RS-09 ya ata) pero **la revisión externa es el primer análisis dinámico que verá el producto**.
- **Corrección:** reaceptar la fila con fecha y argumento nuevos: pendiente de `devops-observabilidad` para el cierre de la Fase 3 como `make dast` manual, fuera de `ci.yml` por presupuesto; el paquete del revisor lo declara como no cubierto; si el revisor externo lo exige, se adelanta.
- **Requisito:** RS-10; doc 07 §6 fila «Sin DAST».
- **Prueba de no regresión:** n/a.

### H-10 · Tres técnicas del modelo de amenazas siguen sin señal, y una tenía fecha vencida

- **Severidad:** OBSERVACIÓN
- **ASVS / STRIDE:** V7 (logs y monitorización) / **Denegación de servicio**, **Suplantación**, **Elevación**
- **Ubicación:** las reglas de `infra/observability/prometheus/rules/` no contienen ninguna alerta sobre `429`, `limit_req` ni saturación del borde; `api.yml` tiene solo `ErroresDeServidorEnElFichaje`, `LatenciaDelFichajeAlta` y `SondaDelBordeFallida`. La métrica `scans_total{device,result}` existe (`MetricCatalogue.php:123-129`) y ninguna regla la consume. `access.denied` lo escribe únicamente `ScopeGuard` (denegaciones por alcance).
- **Problema:** doc 01 §8.1 dice que la señal de `T1499.002` «llega con la tarea 3.2», y la 3.2 cerró sin ella. `T1606` (rechazo de firma QR) y `T1550.001` (token de quiosco contra gestión) siguen sin señal.
- **Consecuencia:** un control sin señal no distingue «nadie lo intentó» de «lo intentaron y falló». Una campaña de payloads forjados contra un quiosco es hoy invisible salvo que alguien abra el cuadro de mando.
- **Corrección:** alerta de saturación del borde (`429`) y alerta de rechazos de firma (`rate(scans_total{result="rejected_signature"}[15m])`, destinatario seguridad), ambas con runbook. `T1550.001` no gana asiento: es un hecho que el atacante controla y cada asiento pasa por el candado global (ADR-037); se documenta como decisión. Agente: **`devops-observabilidad`**.
- **Requisito:** RS-02, RS-13, doc 01 §8.1 y §9.3.
- **Prueba de no regresión:** `promtool test rules` y `AlertCatalogueTest`. `->group('RS-02')`.

### H-11 · La única prueba etiquetada `RS-11` está mal etiquetada

- **Severidad:** OBSERVACIÓN
- **ASVS / STRIDE:** V1 (proceso) / n/a
- **Ubicación:** `backend/tests/Feature/Product/CorruptSettingsDoNotBlockClockingTest.php:167` — `->group('RF-PD-01', 'RS-11')`.
- **Problema:** esa prueba demuestra que el log de `product.settings_anomaly` lleva el nombre de la clave y el motivo técnico pero **no su valor**. Eso es RL-19 y la regla dura 21, no RS-11.
- **Consecuencia:** `docs/trazabilidad-pruebas.md` presenta a RS-11 como cubierto por una prueba que no lo cubre.
- **Corrección:** ver §6.
- **Requisito:** RS-11, RQ-13.

### H-12 · El modelo de amenazas del doc 01 §8.1 tiene once filas, y el plan y la ficha hablan de doce

- **Severidad:** OBSERVACIÓN
- **ASVS / STRIDE:** V1 (arquitectura) / n/a
- **Ubicación:** `docs/01-especificaciones-proyecto.md:710-720` — once filas de datos. `plan implementacion/06-fase-3-operacion-y-refuerzo.md` (ficha 3.8, paso 1) dice «las doce filas» y enumera once.
- **Problema:** las seis categorías STRIDE están representadas; la discrepancia es de conteo.
- **Consecuencia:** ninguna técnica; ruido en la revisión externa.
- **Corrección:** corregir el número en la ficha. Candidatas a fila nueva, si el usuario quiere ampliar el modelo: manipulación del reloj del quiosco (cubierta por RF-AT-12) y repudio de la lectura de datos por un responsable. Agente: documentación.
- **Requisito:** doc 01 §8.1.
- **Cierre (restos de la 3.8, 22-09-2026):** el número de la ficha se corrigió en la propia 3.8. Decisión del usuario sobre las candidatas: **se añade** «Manipulación del reloj del quiosco» (`T1070.006`; la mitigación es RF-AT-09/RF-AT-10, no RF-AT-12: el desfase se tolera, se avisa y abre incidencia `clock_skew` sin rechazar) y **no se añade** el repudio de la lectura de datos por un responsable, que RS-05 ya cubre con el asiento `personal_data.accessed`. El modelo quedó entonces en doce filas; la doceava está en el §4. **Desde la tarea 3.10 (22-09-2026) tiene trece**: su revisión de cumplimiento añadió la fila de divulgación del dato de salud del registro de ausencias (`T1213`), recogida en el §4 como fila 13 y en doc 07 §6 como riesgo aceptado A-17 por su retención sin plazo.

### H-13 · Sin `needsRehash` de contraseñas ni de PIN al iniciar sesión

- **Severidad:** OBSERVACIÓN
- **ASVS / STRIDE:** V2 (autenticación) / **Suplantación**
- **Ubicación:** cero apariciones de `needsRehash` en `backend/app`. No existe `backend/config/hashing.php`, así que el coste es el de `BcryptHasher` (12), correcto pero **inmutable en la práctica**.
- **Problema:** el coste está fijado y verificado; lo que no existe es el camino para cambiarlo cuando deje de bastar.
- **Consecuencia:** el día que el coste 12 se quede corto, la instalación no tendrá forma de migrar los hashes sin un restablecimiento masivo.
- **Corrección:** `needsRehash` en el acceso de gestión y en el del portal, rehasheando dentro de la misma transacción del acceso correcto. Va junto con H-03. Agente: **`backend-laravel`**.
- **Requisito:** OWASP A07; doc 07 §6 fila «Sin rehash», plazo «Fase 3, con la tarea de cuentas de gestión».
- **Prueba de no regresión:** integración: autenticar con un hash de coste inferior y comprobar que la fila queda rehasheada y que la contraseña sigue valiendo. `->group('RS-06')`.

---

### H-14 · La vuelta atrás del actualizador nunca escribía el asiento `system.restored_from_backup` (hallado por la evidencia de la CI durante esta tarea)

- **Severidad:** REVISAR (era BLOQUEANTE para RS-07 hasta corregirse en esta misma tarea)
- **ASVS / STRIDE:** V7 (logs, nivel 3 en auditoría) / **Repudio**
- **Ubicación:** `infra/scripts/update.sh` (camino de vuelta atrás, la pregunta «¿la versión restaurada conoce `compliance:record-system-event`?») y `.github/workflows/ci.yml` (paso U3 de ⑧b), que hacían la misma pregunta con la misma tubería: `… php artisan list --raw 2>/dev/null | grep -q '^compliance:record-system-event'`.
- **Problema:** `grep -q` cierra su entrada al primer acierto; el cliente de Docker sigue volcando el catálogo (194 líneas, el comando es la 35), recibe EPIPE y termina con 1; con `pipefail` la tubería vale 1 y la respuesta es **no** cuando es **sí**. Medido en local: 5 fallos en 40 invocaciones. Con `2>/dev/null` además «no he podido preguntar» y «no lo conoce» eran indistinguibles. Es la misma trampa que el cierre de la Fase 5 corrigió en `install.sh`, `doctor.sh` y otro punto de `update.sh`: este cuarto sitio quedó sin corregir y se copió a la CI.
- **Consecuencia:** desde el cierre de la Fase 5, **toda vuelta atrás dejaba el registro sin la única prueba, dentro de la propia cadena, de que un intervalo de fichajes reales quedó fuera de la base que sirve ahora** (RF-PD-10, RL-04, RS-07, regla dura 6). La CI lo tapaba: sus dos copias de la pregunta se equivocaban a la vez y ⑧b salía verde o rojo según a cuál le tocara fallar (verde en la ejecución 35697335929, rojo en la 35700735466 con el mismo código).
- **Corrección:** `infra/scripts/lib/app-commands.sh` con `kq_app_knows_command` (captura el catálogo entero en una variable, compara por campo exacto con `awk`, sin `2>/dev/null`, reintenta por condición y distingue tres desenlaces: 0 lo conoce, 1 no lo conoce, 2 no se pudo preguntar); `update.sh` y el paso U3 de `ci.yml` la usan; mensaje ES/EN `u_rollback_audit_entry_unknown`. Agente: **`producto-licencia`**.
- **Requisito:** RF-PD-10, RS-07, RL-04, regla dura 6.
- **Prueba de no regresión:** `backend/tests/Integration/Install/UpdateScriptTest.php`, cuatro pruebas `->group('RF-PD-10', 'RS-07')`: regresión con control negativo sobre un catálogo mayor que el *buffer* de la tubería (la forma antigua responde «no» de forma determinista; la nueva, «sí»), campo exacto, desenlace 2 con su mensaje, y guarda de que ni `update.sh` ni `ci.yml` vuelven a contener `artisan list --raw … | … grep`. El ciclo completo lo demuestra ⑧b en la CI de esta rama, que por primera vez toma la rama «+1 asiento» y lo verifica con `compliance:verify-audit-chain` sobre la versión restaurada.
- **Deuda hermana anotada, no tocada:** `update.sh:1874`, `install.sh:1294` y `doctor.sh:266` siguen con `2>/dev/null || true` (sin el fallo de SIGPIPE, pero un fallo real de `docker compose exec` se les presenta como «el paquete no trae el comando»).

---

## §3 ASVS por capítulo

| Capítulo | Veredicto | Evidencia de lo que cumple | Lo que no cumple | No aplica, y por qué |
|---|---|---|---|---|
| **V1 Arquitectura** | **Parcial** | Hexagonal con frontera verificada: `backend/deptrac.yaml`, `tests/Architecture/DomainPurityTest.php`, `CoreBoundariesTest.php`, `DomainEventLayerTest.php`, `AggregateBoundaryTest.php`. Decisiones en `docs/adr/` con orden de autoridad explícito. Contrato como fuente de verdad (`docs/api/openapi.yaml`, 69 rutas / 80 operaciones) con Redocly y `tests/Contract/OpenApiContractTest.php`. Modelo de amenazas vivo (doc 01 §8.1) y madurez revisada por fase (doc 07). | H-02, H-12. | Segregación multi-inquilino: no hay SaaS, cada cliente es una instalación completa. |
| **V2 Autenticación** | **Parcial** | 2FA TOTP obligatorio para `admin`/`rrhh`/`auditor` con ámbito aislado `2fa:pending` y policy propia (`routes/api_v1.php:668-686`); bloqueo por intentos con contadores independientes para contraseña, código TOTP y PIN (`config/identity.php:34,40,108,110`); PIN escalonado 3/5/10 → 5/15/60 min **por empleado y por origen** (`config/identity.php:296-344`) más `lockout_reset_hours`; contraseña ≥ 12 con política completa (`config/identity.php:244`); bcrypt coste 12; suelo de tiempo constante único (`config/security.php:46`, `Shared/Application/Support/ConstantTimeFloor.php`) con `Integration/Identity/ConstantTimeRejectionTest.php`. | H-03, H-13. | Recuperación por correo (regla dura 12, ADR-015), credencial en móvil / TOTP de empleado (ADR-014), biometría (ADR-009), federación. |
| **V3 Sesión** | **Cumple** | Sin estado de sesión: `config/sanctum.php:57` (`stateful: []`), `:63` (`guard: []`), `:68` (`expiration: null`: la caducidad va token a token, gestión corta, portal 2 h por ADR-015, quiosco 90 días con rotación al 80 %). Revocación inmediata para los tres `tokenable` en `IdentityServiceProvider.php:779-800`. `session.complete` cierra el único endpoint sin ámbito. | — | CSRF y fijación de sesión: no hay cookies ni rutas `web`. |
| **V4 Control de acceso** | **Parcial** | Dos controles por ruta: ámbito (`ability`) **más** policy (`ScanPolicy`, `ShiftEntryPolicy`, `KioskPolicy`, `DataExportPolicy`, `TwoFactorPolicy`, `SelfJournalPolicy`…). Alcance por departamento aplicado **dentro de la consulta** (`api_v1.php:794-798`). Portal sin `{uuid}` en la ruta por diseño. Denegaciones por alcance auditadas (`ScopeGuard` → `access.denied`). 16 ficheros `*Authorization*Test.php` + `AuthorizationNegativeTest.php`. | H-02, H-01, H-03. | — |
| **V5 Validación** | **Cumple** | `FormRequest` por endpoint con reglas explícitas (`ImportEmployeesRequest.php:60`: extensiones y tamaño); `whereUuid()` en rutas paramétricas; invariantes en la base (`shift_entries_no_overlap`, `scan_events_chk_result`, `audit_log_chk_actor_type`, `audit_log_chk_hash_format`) probadas por `tests/Integration/Schema/` y `MigrationSafetyTest`. Sin `DELETE` en toda la API (regla dura 5). | — | Inyección SQL por concatenación: los `GRANT` son la única concatenación y está acotada (`AuditLogSchema.php:206-212`). |
| **V7 Logs y errores** *(objetivo nivel 3)* | **Cumple el nivel 3 en auditoría; parcial en monitorización** | `audit_log` solo-append: el rol de la aplicación tiene **únicamente** `INSERT, SELECT` (`AuditLogSchema.php:182-186`), no es propietario ni superusuario, y la migración `2026_08_19_099000` **se niega a ejecutarse** con el rol de la app o si este es superusuario. `tests/Integration/Compliance/AuditLogTest.php` prueba `UPDATE`, `DELETE`, `TRUNCATE`, el ataque **directo sobre la partición**, la detección de alteración y de borrado, la canonicidad del JSON y la negativa a escribir en un año sin partición (`RS-07`). Cadena SHA-256 con génesis fija, verificada a diario (`routes/console.php:71`) con alerta `RoturaDeCadenaDeAuditoria`. Sin PII: `ErrorMessageSanitizer`, `ErrorContextAllowlist`, `ErrorEventsHaveNoPersonalDataTest`, `ErrorCaptureBoundaryTest`, y el log de Nginx sin cuerpos, sin `Authorization` y sin el token del QR (`kronoqr.conf.template:110-134`). | H-10. | — |
| **V8 Protección de datos** | **Cumple** | DNI hasheado en `bytea` con índice único; correo y foto opcionales (RL-08). Padrón mínimo por tipo: `RosterEntry` lleva **solo** `tokenHash` y `displayName`. Cifrado en reposo en la tablet: AES-GCM con clave derivada por HKDF del token del dispositivo (`frontend-kiosk/src/features/offline/infrastructure/rosterCipher.ts:59-107`) y **purga al revocar** (`cachedRoster.ts:94-102`). Retención por perfil de cumplimiento (`config/compliance.php:120-164`, regla dura 14). Agrupación de asientos de divulgación **cerrada** a `live_presence` (`config/compliance.php:83`) y vigilada por `DisclosureDatasetsInventoryTest`. | — | Transferencias internacionales: ningún servicio externo con datos personales; telemetría opcional y apagada. |
| **V9 Comunicaciones** | **Parcial** | TLS 1.3 **exclusivo** (`kronoqr.conf.template:238`), sin *session tickets*, HSTS de 2 años con `includeSubDomains`; el puerto 80 solo redirige y sirve `/healthz` y `/metrics` tras `geo`. | H-05. | mTLS para los quioscos: el control equivalente es el token con ámbito mínimo + VLAN. |
| **V12 Ficheros** | **Parcial** | Subida acotada por extensión y tamaño con confirmación por *checksum* en tiempo constante (`ImportEmployeesHandler.php:64`). Logotipo: ruta bajo `BRANDING_LOGO_ROOT`, inspeccionada por `LogoFileInspector` y consumida siempre por `<img src>` o `data:` en Chromium sin red (ADR-016), con CSP `default-src 'none'; sandbox` al servirlo. Exportaciones y paquetes en `storage/app/*` con `0600` y purga. | H-07. | Antivirus del fichero subido: el actor es el IT del cliente sobre su propio servidor y el CSV no se ejecuta. |
| **V13 API** | **Parcial** | Contrato modificado antes que el código (ADR-012), validado en pruebas y Redocly en CI. Ámbitos por tipo de cliente (doc 02 §7.3). `ProblemDetails` uniforme y rechazo de escaneo genérico (`urn:kronoqr:problem:scan-rejected`). `207` en el lote (regla dura 19). | H-01. | — |
| **V14 Configuración** | **Parcial** | `APP_DEBUG=false` y arranque que se niega con `production` + `debug`; `APP_TIMEZONE=UTC`. Secretos generados en el servidor del cliente (doc 02 §7.7) con `rotacion-secretos.md`. Etapa ⑤ bloqueante: `composer audit`, `npm audit`, Semgrep propio + comunitario, gitleaks sobre el histórico, Trivy `fs` e `image`, SBOM, `.trivyignore.yaml` con caducidad. Roles de PostgreSQL de privilegio mínimo. `TrustProxies` propio. `/metrics` tras `geo`. Cabeceras completas y CSP **sin `unsafe-inline`** con `Permissions-Policy: camera=(self)`. | H-09, H-06. | Gestión centralizada de secretos: servidores sin salida a internet (ADR-016). |

---

## §4 STRIDE × las trece filas del doc 01 §8.1

| # | Categoría | Vector | Control existente, con evidencia | Estado | Hueco |
|---|---|---|---|---|---|
| 1 | Suplantación | QR falso para un compañero | HMAC verificado con `hash_equals` en el dominio (`Identity/Domain/ValueObject/QrSigningKey.php:95-103`); `FH1.<key_id>.<token>.<sig>` con `key_id` para rotación; nunca PII en el QR. Rechazo genérico con suelo de tiempo constante. | **Cubierta** (mitigación) · **parcial** (detección) | `scans_total{result}` existe pero ninguna alerta la consume (H-10, `T1606`). |
| 2 | Suplantación | Préstamo de tarjeta | Fraude autolimitado por diseño (ADR-014, doc 04). Detección de patrones: métrica y `AnomalyDetectionPolicy`. | **Parcial** | RF-PR-06 es de la 3.11; aceptado con fecha en doc 07 §6. No es hallazgo. |
| 3 | Suplantación | Fuerza bruta del PIN | Tres controles independientes: Nginx (`zone=portal` 10 r/m + `geo PORTAL_INTERNAL_CIDR`; `zone=scan_*`), `throttle:portal`/`scan-pin` por IP y por sujeto, bloqueo escalonado por empleado **y por puerta** (`config/identity.php:296-344`). `PortalPinLockoutTest`, E2E `pin-lockout.spec.ts`. | **Cubierta** | Ninguno. |
| 4 | Manipulación | Alterar horas en base de datos | `audit_log` solo-append por privilegios reales; cadena SHA-256; verificación diaria; versionado de correcciones (RN-13); `daily_totals` reconstruible. Probado por SQL directo, incluida la partición (`AuditLogTest`, `RS-07`). | **Cubierta** | Ninguno. Sostiene ASVS nivel 3. |
| 5 | Manipulación | Alteración de la clave de licencia | Licencia Ed25519 verificada localmente; por ADR-019 **la licencia jamás bloquea el fichaje** (regla dura 15). | **Cubierta** | Ninguno de seguridad. |
| 6 | Repudio | «Yo sí fiché» / «esa corrección no la hice yo» | `scan_events` inmutable con desenlace por intento (también los rechazados); doble marca `occurred_at`/`recorded_at`; asientos con actor, momento, valor anterior y motivo. | **Cubierta** | Ninguno. |
| 7 | Divulgación | Filtración del padrón cacheado | Datos mínimos por tipo, AES-GCM con clave derivada del token (`rosterCipher.ts`), purga al revocar. | **Cubierta** | Token en `localStorage` en claro: aceptado por diseño (doc 07 §6, `endurecimiento.md` §6). |
| 8 | Divulgación | PII en el paquete de diagnóstico | Anónimo por defecto (RL-19), lista de permitidos, `value_redacted`, purga automática; `error_events` sin PII (`ErrorEventsHaveNoPersonalDataTest`). | **Cubierta** | `employee_uuid`/`device_id` en el histórico técnico: aceptado por diseño. |
| 9 | Denegación | Inundación del fichaje | Dos zonas de borde según origen, `limit_conn`, `throttle:scan`/`scan-batch`/`scan-pin`, colas, modo offline (regla dura 19). | **Cubierta** (mitigación) · **sin cubrir** (detección) | Ninguna alerta de saturación (H-10); rutas de gestión sin techo de aplicación (H-01). |
| 10 | Elevación | Token de quiosco contra gestión | `ability` + policy en cada ruta; tres ámbitos exactos; `AuthorizationNegativeTest` prueba el 403 del token de quiosco (`RS-04`). | **Cubierta** (mitigación) · **sin cubrir** (detección) | Un 403 por ámbito no deja asiento ni alerta (H-10, decisión: no se añade asiento); cobertura negativa manual (H-02). |
| 11 | Elevación | Acceso de soporte fuera del incidente | Concesión expresa, temporal, de alcance limitado y revocable; `RecordSupportAccess` en el grupo entero; `DataExportPolicy` rechaza a todo actor de soporte. | **Cubierta** | Agrupación de 900 s aceptada y alineada con el cliente; `audit:read` sin consumidor, aceptado hasta el primer endpoint de auditoría. |
| 12 | Manipulación | Reloj del quiosco movido (fila añadida el 22-09-2026 en los restos de la tarea, H-12) | `recorded_at` lo pone el servidor (RF-AT-09). Desfase tolerado hasta `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` (`ClockSkew` en el dominio de Attendance); por encima, el fichaje se registra igual con `clock_skew_seconds`, incidencia `clock_skew` y aviso en el quiosco (RF-AT-10; `ClockSkewIncidentTest`). Anti-rebote (RF-AT-06) y RN-16 acotan el efecto. Nunca rechaza (regla dura 19). | **Cubierta** (mitigación) · **parcial** (detección) | La señal es la incidencia en la bandeja (`incidents_open{type="clock_skew"}`), sin alerta a propósito: la juzga una persona. Sin hallazgo. |
| 13 | Divulgación | Dato de salud en el registro de ausencias: el tipo `sick_leave` de una persona identificada, y la nota que se escriba junto a él (fila añadida el 22-09-2026 en la tarea 3.10) | Lectura acotada a `rrhh` y `admin`; el `responsable_departamento` ve solo su departamento y **sin el campo `note`** —omitido, no `null`— (RF-GP-04, RF-ID-03). Asiento `personal_data.accessed` con conjunto `absence_register` en cada página leída (RS-05). `note` nunca en `audit_log` (solo `has_note`), ni en logs técnicos, ni en `error_events`, ni en el paquete de diagnóstico (regla dura 21). El informe por periodo no desglosa por tipo a propósito. | **Cubierta** (mitigación) · **cubierta** (detección) | La retención no tiene plazo: `RetentionScope` no cubre `absences` y no hay purga automática. **Aceptado, pendiente de la asesoría laboral del cliente** (doc 07 §6, fila A-17, revisión en el cierre de Fase 3). |

---

## §5 Dictamen sobre las filas de doc 07 §6 con plazo en 3.8 / Fase 3 / cierre de Fase 3 / primera versión comercial

| Fila | Plazo declarado | Estado verificado | Dictamen |
|---|---|---|---|
| **Sin DAST** | `3.8` | Sin escáner dinámico en `.github/workflows/`. | **Vencida.** → H-09: se reacepta con fecha y argumento nuevos (cierre de la Fase 3, `devops-observabilidad`, `make dast` manual). |
| **Sin revisión de seguridad externa** | Primera versión comercial | Este informe es la preparación; la revisión externa **no se ha hecho**. | **Abierta, correctamente.** Sigue bloqueando la primera versión comercial. |
| **SRI en assets prometido y no implementado** | Antes de la primera versión comercial | `docs/02:666` lo promete; cero `integrity=`. | **Vencida dos veces.** → H-06: se retira la promesa con el motivo escrito. |
| **Sin rehash de contraseñas ni PIN** | Fase 3, con la tarea de cuentas de gestión | Cero `needsRehash`. | **Vencida.** → H-13, con H-03. |
| **El paquete descomprimido ENCIMA de la instalación anterior** | Cierre de la Fase 3 | `check_installation` (`infra/scripts/update.sh:1071`), `.env.kronoqr-pre-update` en `0600` (`:1466`), imágenes antiguas no borradas. | **Se prorroga, con evidencia.** Sin cambio desde la 5.7. |
| **El filtro del SVG del logotipo es una lista negra corta** | Fase 3, tarea 3.7 | La guarda de las SPA existe de facto (`vue/no-v-html` + `--max-warnings 0`); la de Blade no. | **Parcialmente resuelta.** → H-07. |
| **Agrupación por ventana de los asientos de divulgación en vivo** | Cierre de la Fase 3 | `config/compliance.php:83`: `datasets: ['live_presence']`; los conjuntos de la Fase 3 (`compliance_summary` incluido) **no han entrado**. | **Se prorroga, con evidencia.** |
| **Amplificación de asientos de exceso de plan** | Fase 3 | `RecordPlanUsageHandler.php:78` por fila; `ProductServiceProvider.php:1621` escucha por fila; `ApplyEmployeeImport.php:67,93`. | **Vencida por segunda vez.** → H-04. |
| **`obligaciones-legales.md` prometía un detalle de auditoría de soporte** | Fase 3 | Fila **cerrada** (texto alineado en el cierre de Fase 5); queda una mejora opcional. | **Cerrada; la mejora no es exigible.** Se retira el plazo. |
| **El producto no tiene baja de cuentas de gestión** | Antes de la primera versión comercial | Las dos únicas escrituras de `is_active` lo ponen a `true`. | **Vencida en el hito que esta tarea bloquea.** → H-03. |
| **Rutas de gestión sin zona de limitación** | Cierre de la Fase 3 | 13 rutas del grupo + `legal-export` + `auth/me` + `auth/logout` sin `throttle`; `GET /employees` y `GET /employees/{uuid}` sí lo tienen. | **Vencida.** → H-01 y H-02. Son dieciséis, no catorce. |
| **A-2: `db.query.text` lleva el texto de la consulta** | Revisión de la 3.8 | `DatabaseSpans.php:20,88`: siempre con marcadores `?`, nunca valores. | **Confirmada. Se mantiene aceptada.** |
| **A-7: marca de mantenimiento escribible desde `BACKUP_PATH/metrics`** | Cierre de la Fase 3 | Tope de 2 h en `rules/maintenance.yml:58`; la inhibición no alcanza integridad, copia ni auditoría. | **Se prorroga, sin cambio.** |
| **A-8: las alertas de TLS vigilan la caducidad, no la validez** | Candidata a la 3.8 | Un único módulo blackbox con `insecure_skip_verify: true`. | **Asumida.** → H-05. |
| **A-11: el runner no alcanza RNF-P-06** | Banco físico | Resuelto en la 3.6 con `baseline`. | Sin efecto de seguridad. |
| **A-12: la reconciliación nocturna y la fila de `daily_totals` inexistente** | Cierre de la Fase 3 | `ReconcileDailyTotals.php:112,300-316`: candado de la cadena, `FOR UPDATE`, relectura; `CorrectionOutcome` con cuatro desenlaces. | **Se prorroga, con evidencia.** La proyección es reconstruible (regla dura 7). |
| **A-13: el rechazo de RN-18 no es de tiempo constante** | Cierre de la Fase 3, con medición | `scan-peak.js:413` compara solo tres clases. | **No prorrogable sin la cifra.** → H-08. |

---

## §6 RS-11 en la trazabilidad

La etiqueta `RS-11` de `CorruptSettingsDoNotBlockClockingTest.php:167` es un error: la prueba demuestra que un dato del cliente no sale de la instalación por un canal que viaja a Loki y al paquete de diagnóstico, es decir, **RL-19** y la regla dura 21. Se sustituye por `RL-19`.

RS-11 es **un requisito de proceso**: ninguna prueba puede demostrar que un tercero revisó el producto. Lo que sí es automatizable es **que los artefactos del proceso existen, están completos y no han caducado**, el mismo razonamiento de `ClientDocumentationTest`. `backend/tests/Architecture/SecurityReviewEvidenceTest.php` (`->group('RS-11', 'RQ-13')`) afirma:

1. Que este informe existe en `docs/seguridad/` con fecha en el nombre, y declara commit revisado, nivel objetivo y los once capítulos ASVS del doc 02 §7.6.
2. Que [`paquete-revisor.md`](paquete-revisor.md) existe y cada uno de sus enlaces resuelve: arquitectura, diseño de seguridad, `docs/api/openapi.yaml`, `docs/trazabilidad-pruebas.md` y `docs/adr/`.
3. Que la fecha límite de la siguiente revisión declarada en `paquete-revisor.md` no está en el pasado: es la aserción que se pondrá en rojo sola el día que la periodicidad anual se incumpla.
4. Que `docs/seguridad/` no aparece en `infra/scripts/package.sh`.
5. Que el informe no contiene nada con forma de secreto real.

Lo que esa prueba **no afirma**: que la revisión externa se hiciera, ni que fuera buena, ni que sus hallazgos se cerraran. Eso lo demuestra el informe del tercero y su cierre, que son artefactos humanos.

`backend/config/quality.php` fija `current_phase => 5`, así que RS-11 aún no es exigible por `qa:traceability --check`; cuando la Fase 3 cierre y avance, la evidencia ya estará.

---

## §7 Lo que la revisión externa debería mirar con más atención

*(Para el paquete del revisor: dónde mirar, sin recetas de explotación.)*

1. **La inalterabilidad del registro, atacada desde la base de datos.** Con la conexión de la aplicación en la mano: sobre la tabla y sobre cada partición, con `TRUNCATE`, con reasignación de propietario, con funciones, y comprobando que el verificador diario detecta lo que no pudo impedirse. Ficheros de partida: `AuditLogSchema.php`, la migración `2026_08_19_099000`, `infra/docker/postgres/initdb/02-application-roles.sh`.
2. **Las respuestas de tiempo constante del camino de fichaje.** La afirmación es medible, y el entorno real de un hotel (CPU compartida, caché fría) no es el del banco de pruebas.
3. **La frontera entre ámbito de token y policy.** Un barrido sistemático de las 80 operaciones del contrato con cada uno de los cinco tipos de portador (los cuatro roles de gestión, el token de quiosco y la sesión de portal) es la comprobación de mayor rendimiento de toda la revisión.
4. **El ciclo de vida completo de una cuenta de gestión**: alta, activación del segundo factor, ventana entre las dos, pérdida del dispositivo y baja. La ventana de auto-alta del TOTP está documentada y aceptada con control compensatorio; conviene que un tercero juzgue si basta.
5. **Los tres caminos por los que datos salen de la instalación**: paquete de diagnóstico, exportación íntegra y telemetría. Interesa si algún dato personal se cuela por composición.
6. **El quiosco como dispositivo físicamente accesible**: token en `localStorage`, padrón cifrado con clave derivada de ese token, pantalla de diagnóstico con código de servicio, comportamiento sin red. Verificar que «el quiosco nunca bloquea al empleado» no se ha convertido en una vía para inyectar fichajes que no ocurrieron.
7. **El borde**: las dos zonas de fichaje por CIDR, `limit_conn`, y qué pasa cuando el cliente coloca otro proxy delante y todas las peticiones llegan con la misma IP.

**Fuera del alcance por decisión:** biometría (ADR-009), credencial en móvil y TOTP de empleado (ADR-014), correo electrónico como dependencia (regla dura 12), acceso permanente del fabricante a los datos del cliente (ADR-020).

---

## §8 Cierre de los hallazgos (tarea 3.8)

| Hallazgo | Decisión | Corrección | Prueba de no regresión | Estado |
|---|---|---|---|---|
| H-01 | Zona en las dieciséis rutas; `logout` exenta en lista cerrada | `throttle:management` en el grupo `employees:*` (la zona sube al grupo y sale de `/employees/import`), en `GET /reports/legal-export` y en `GET /auth/me`; `POST /auth/logout` sin zona con el motivo escrito en las rutas y en el contrato; `GET /auth/me` declara el `429` en `openapi.yaml` (las demás ya lo declaraban) y los tres `schema.d.ts` regenerados | `RouteRateLimitZonesTest` (3, `RS-02 RS-04 RS-05`, de rojo a verde); `ManagementRateLimitTest` «cubre con el mismo cupo la exportación legal y el alta de plantilla» (`RS-02 RS-05 RL-06`); contrato en verde | Cerrado |
| H-02 | Prueba que enumera el router + `ScanBatchAuthorizationTest` + datasets por los seis actores | `RouteRateLimitZonesTest` (enumera el router; `logout` en lista cerrada con motivo; cada zona con su `RateLimiter::for()`), `ScanBatchAuthorizationTest` (11 casos), `AuthorizationNegativeTest` con los tres *datasets* por los seis actores (+28 casos) y `RS-05` en las seis pruebas grandes | Las propias pruebas: `RS-02 RS-04 RS-05`; `RS-04 RS-03 RQ-07 RF-KI-04 RF-ID-04 RF-ID-07` | Cerrado |
| H-03 | Baja y rotación de contraseña por consola con asiento; pantalla pendiente de decisión de producto | Comandos `identity:deactivate-user {email} --reason=` e `identity:reset-password {email}`; puerto `ManagementAccountLifecycle`, casos de uso `DeactivateManagementAccountHandler`/`ResetManagementPasswordHandler` (desenlaces `Deactivated`/`NotFound`/`AlreadyInactive`), eventos `ManagementAccountDeactivated`/`ManagementPasswordReset`, acciones `user.deactivated` (`user_uuid`, `reason`) y `user.password_reset` (solo `user_uuid`) en el catálogo cerrado; revocación de todos los tokens en la misma transacción; repetir la baja no escribe asiento (ADR-010); guías `endurecimiento.md` fila 17, `operacion.md` §9, `configuracion.md`, `instalacion.md` ES/EN. Pantalla del panel: decisión de producto pendiente | `ManagementAccountLifecycleCommandsTest` (10, `RS-05 RS-06 RL-16`, con `RF-PD-03` en «no reabre el alta pública del primer administrador» y `RF-ID-01` en la política de la contraseña) + unitarias de los dos casos de uso (`tests/Unit/Identity/Application/`) | Cerrado (consola); pantalla pendiente |
| H-04 | Un asiento por importación | `EmployeeHired` gana `viaImport` (por defecto `false`: quien lo olvide cuenta de más, nunca de menos); `ObservePlanLimits` descarta las altas por importación y se engancha una vez a `EmployeesImported` (evento de lote ya existente, publicado tras confirmar); `RecordPlanUsageHandler::handleBatch()`; aritmética pura en `PlanUsage::crossedBy()`/`excessAmong()`; `PlanLimitExceeded` y el asiento ganan `added_in_excess`. `license_limit_exceeded_total` sube una vez por operación | `PlanExcessSeatsOnImportTest` (4, `RF-PD-04 RF-GP-05`; tres en rojo antes: 10 asientos con plan 3 y 10 filas) y `RecordPlanUsageBatchTest` (10, Unit, borde «justo en el tope») | Cerrado |
| H-05 | Segundo módulo blackbox verificado contra `APP_URL` | Módulo `http_2xx_tls_verified` (`insecure_skip_verify: false`), `prometheus.yml.template` + `render-config.sh` con el job `kronoqr-uptime-tls-verified` contra `APP_URL`, omitido si `TLS_ALLOW_SELF_SIGNED=true`; alerta `CertificadoTlsNoVerificable` (crítica, IT del cliente, `renovacion-certificado-tls.md` §3.4) | `promtool test rules` (`tls-verificado.test.yml`: verificable, no verificable, autofirmado declarado que no dispara); `AlertCatalogueTest`, `BackupAndAlertingTest` | Cerrado |
| H-06 | Retirar la promesa de SRI del doc 02 §7.1 | Doc 02 §7.1: promesa retirada con el motivo (CSP `script-src 'self'` sin `unsafe-inline`, mismo origen, sin CDN); doc 07 §6 fila cerrada | n/a (texto) | Cerrado |
| H-07 | Guarda de Blade + `vue/no-v-html: 'error'` explícito | `BladeTemplatesTest` (lista blanca vacía: ninguna plantilla usa `{!! !!}`); `vue/no-v-html: 'error'` explícito en los cuatro `eslint.config.js` | `BladeTemplatesTest` (`RS-04`), comprobada con una plantilla mutante; ESLint en los cuatro paquetes | Cerrado |
| H-08 | Escenario `reject-out-of-order` informativo en k6 | Escenario `reject-out-of-order` en `scan-peak.js` sembrado por `RegisterScanHandler` en `provision-fixtures.php`; `aggregate.js` publica `reject_out_of_order` (p50, mínimo y separación firmada frente a `signature`/`unknown`/`revoked`) y el veredicto `RS-03-RN-18` con `status: info`; `verify-after-load.php` y `run.sh` ajustados | `aggregate.test.js` (38, seis nuevas), `ScanPeakScenariosTest` (20); la cifra real llega con la primera pasada de `load-test.yml` (la línea base del runner puede avisar por el cambio de perfil: decidir si se regenera) | Cerrado en el código; cifra pendiente del runner |
| H-09 | Reaceptado con dueño y fecha (cierre de la Fase 3) | doc 07 §6 | n/a | Reaceptado (doc 07 §6) |
| H-10 | Alertas de saturación del borde y de rechazos de firma; sin asiento para `T1550.001` | `rules/threat-detection.yml`: `SaturacionDelBordeEnElFichaje` (`http_requests_total{route=~"attendance\\.scan…",status="429"}` > 20 en 5 min, alta, IT del cliente, `saturacion-del-borde.md`; mide la capa de aplicación porque no hay exportador de Nginx) y `RechazoDeFirmaQr` (`scans_total{result="rejected_signature"}` > 20 en 15 min, crítica, seguridad, `ataque-a-credenciales.md` §8); panel de 429 en `salud-api.json`; `SondaDelBordeFallida` acotada a `job="kronoqr-uptime"`; doc 01 §8.1 anota que `T1550.001` no gana asiento | `promtool test rules` (`threat-detection.test.yml`, dispara / no dispara); `AlertCatalogueTest`, `GrafanaDashboardsTest` | Cerrado |
| H-11 | `RS-11` → `RL-19`; `SecurityReviewEvidenceTest` | `CorruptSettingsDoNotBlockClockingTest:167` → `RL-19`; `SecurityReviewEvidenceTest` + `tests/Architecture/Support/SecurityReview.php` | `SecurityReviewEvidenceTest` (8, `RS-11 RQ-13`), comprobada mutando los dos documentos | Cerrado (RS-11 sigue pendiente del tercero) |
| H-12 | Corregir «doce» por «once» en la ficha | plan 06 | n/a | Cerrado |
| H-13 | `needsRehash` en gestión y portal | `rehashIfStale` en `EloquentUserAccounts::verifyCredentials` y en `HashedEmployeePinVerifier::verify`, solo en el acierto y sin cambiar el coste (12) | `Integration/Identity/PasswordRehashTest` (4, `RS-06`, una con `RS-03`: el rechazo no gana trabajo) y `Integration/Shared/PinRehashTest` (3, `RS-12`) | Cerrado |
| H-14 | Pregunta por el comando con `kq_app_knows_command`, nunca con una tubería | `infra/scripts/lib/app-commands.sh`, `update.sh`, paso U3 de `ci.yml`, mensaje `u_rollback_audit_entry_unknown` | `UpdateScriptTest` (4, `RF-PD-10 RS-07`); ⑧b de la CI de esta rama | Cerrado; ⑧b pendiente de ver en el runner |

---

## Nota final

Ninguna afirmación de este informe sustituye el criterio de la asesoría laboral del cliente ni el de su DPO. En particular, la valoración de si el registro producido satisface el art. 34.9 ET ante una inspección concreta, la base jurídica del tratamiento y la necesidad de EIPD (RL-13) son suyas: aquí solo se señala qué requisito está implementado, con qué evidencia, y dónde falta.
