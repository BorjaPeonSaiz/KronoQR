# Memoria del Trabajo de Fin de Máster

**KronoQR — Control de presencia y registro horario con valor legal para hoteles**

| | |
| --- | --- |
| **Alumno** | Borja Peón Saiz · <borja.peon.saiz@gmail.com> |
| **Máster** | Máster de Desarrollo con IA · BIG School · Módulo 12, Proyecto Final |
| **Repositorio** | <https://github.com/BorjaPeonSaiz/KronoQR> |
| **Despliegue** | <https://kronoqr.kodigolab.es> (en la 2.2.0; ver [`despliegue.md`](despliegue.md)) |
| **Periodo** | Del 11 de agosto al 9 de octubre de 2026 |
| **Versión entregada** | 2.2.0, publicada el 9 de octubre de 2026 ([release](https://github.com/BorjaPeonSaiz/KronoQR/releases/tag/v2.2.0)) |

## Índice

1. [Resumen](#1-resumen)
2. [Contexto y motivación](#2-contexto-y-motivación)
3. [Objetivos](#3-objetivos)
4. [Método de trabajo: desarrollo asistido por IA](#4-método-de-trabajo-desarrollo-asistido-por-ia)
5. [El producto](#5-el-producto)
6. [Arquitectura y decisiones](#6-arquitectura-y-decisiones)
7. [Calidad: cómo se sabe que funciona](#7-calidad-cómo-se-sabe-que-funciona)
8. [Seguridad y cumplimiento](#8-seguridad-y-cumplimiento)
9. [Productización, despliegue y operación](#9-productización-despliegue-y-operación)
10. [Resultados](#10-resultados)
11. [Lo que no salió bien y lo que aprendí](#11-lo-que-no-salió-bien-y-lo-que-aprendí)
12. [Límites y trabajo futuro](#12-límites-y-trabajo-futuro)
13. [Referencias dentro del repositorio](#13-referencias-dentro-del-repositorio)

---

## 1. Resumen

KronoQR es un sistema de fichaje para hoteles. Cada empleado escanea su tarjeta con código QR en una tablet situada en la entrada de personal, y el sistema construye el registro horario que la legislación española obliga a llevar, conservar cuatro años y poner a disposición del trabajador y de la Inspección de Trabajo (art. 34.9 del Estatuto de los Trabajadores).

No es una aplicación de demostración. Es un **producto licenciado** que un hotel instala en su propio servidor, sin depender del fabricante: trae instalador, actualizador, copias de seguridad cifradas, diagnóstico, documentación para el cliente en dos idiomas y una licencia firmada que, si caduca, nunca bloquea el registro legal.

El trabajo tiene dos resultados. El primero es el producto: un monolito modular con arquitectura hexagonal en PHP 8.4 y Laravel 13, PostgreSQL 17, tres aplicaciones Vue 3 con TypeScript estricto (quiosco, panel y portal), 5 582 pruebas automáticas etiquetadas por requisito y una instalación en funcionamiento. La versión entregada es la **2.2.0**, la primera pensada para clientes. El segundo es el método: todo el código se ha escrito con Claude Code a partir de una especificación y un plan previos, con un andamiaje de reglas permanentes, once agentes especializados, siete skills y un protocolo de continuidad entre sesiones. Esta memoria cuenta las dos cosas, y también lo que no funcionó: la 2.1.0 pasó todas las herramientas en verde y una verificación con los agentes concluyó que no debía entregarse a un cliente. La 2.2.0 es la respuesta a ese veredicto.

## 2. Contexto y motivación

Desde mayo de 2019 toda empresa española debe llevar un registro diario de la jornada de cada trabajador. En hostelería esa obligación choca con la realidad del sector:

| La realidad del hotel | Lo que provoca en un sistema genérico |
| --- | --- |
| Turnos de noche que cruzan la medianoche | Los sistemas parten el turno en dos y descuadran las horas y el descanso entre jornadas |
| Jornada partida en cocina y sala | Varias entradas y salidas el mismo día |
| Alta rotación en temporada | Altas continuas; gente que empieza mañana y aún no puede fichar |
| Mucha plantilla sin correo ni móvil de empresa; cocinas donde el teléfono está prohibido | Cualquier sistema que dependa del email o del smartphone deja gente fuera |
| Wifi que falla justo en el cambio de turno | Treinta personas fichando a la vez y el sistema caído |

El resultado habitual es una hoja de papel en recepción y un registro que no aguanta una inspección.

Elegí este problema por tres razones. Conozco el sector y el dolor es real. Es un dominio con **valor legal**, donde el software no puede ser un CRUD: la inalterabilidad, la trazabilidad y la retención no son mejoras de calidad, son el requisito. Y es un **producto**, no un proyecto: se vende a clientes distintos y cada uno lo instala en su servidor, lo que obliga a resolver instalación, actualización, configuración sin código, licencia y soporte sin acceso a los datos. Eso convierte el TFM en un ejercicio completo de ingeniería y no solo de programación.

## 3. Objetivos

**Objetivo del producto.** Un sistema de registro horario instalable por el IT de un hotel, legalmente defendible, que funcione sin internet, que nunca deje a nadie sin fichar y que pueda venderse a un segundo cliente sin tocar el código.

**Objetivo del método.** Demostrar que un desarrollador puede construir un producto de este tamaño con asistencia de IA manteniendo la arquitectura, la calidad y la seguridad bajo control, y documentar con honestidad dónde está el límite.

De ellos salen los criterios de terminado que gobiernan cada tarea (doc 02 §10.3): arquitectura verificada por herramienta, pruebas en todos los niveles aplicables, análisis estático al máximo nivel, contrato de la API actualizado antes que el código, autorización probada en negativo para cada rol, auditoría escrita, migración reversible, textos en español e inglés, y nada específico de un cliente en el código.

## 4. Método de trabajo: desarrollo asistido por IA

### 4.1 Primero los documentos, después el código

Antes de la primera línea de código se escribieron cuatro documentos, y los cuatro se escribieron con la IA como interlocutor, no como redactor automático:

- **Especificación** ([`docs/01`](../docs/01-especificaciones-proyecto.md)): 168 requisitos identificados (`RF-*` funcionales, `RN-*` reglas de negocio, `RL-*` legales, `RS-*` seguridad, `RQ-*` calidad), modelo de dominio y glosario del lenguaje ubicuo (*tramo* → `ShiftEntry`, *jornada* → `WorkDay`).
- **Stack y plan** ([`docs/02`](../docs/02-stack-tecnologico-y-plan-implementacion.md)): arquitectura, convenciones atadas a una herramienta que las verifica, estrategia de pruebas con la tabla que decide el nivel de prueba de cada tipo de cambio, y un plan por fases con unas 50 tareas, cada una con su agente, su skill, sus artefactos y sus pruebas exigidas.
- **Agentes y skills** ([`docs/03`](../docs/03-agentes-y-skills-ia.md)): el andamiaje de IA.
- **Presentación al cliente** ([`docs/05`](../docs/05-presentacion-cliente.md)): lo que se promete. Tiene una regla propia en `CLAUDE.md`: si lo que se va a implementar contradice este documento, o el documento promete algo que no existe como requisito, hay que parar.

La disciplina de escribir primero obligó a decidir pronto las cosas difíciles: que la credencial fuera una tarjeta física y no el móvil ([`docs/04`](../docs/04-decision-credencial.md)), que no hubiera biometría, que un turno de noche fuera un solo tramo, que la licencia nunca bloqueara el fichaje.

### 4.2 El andamiaje

```text
┌──────────────────────────────────────────────────────────────┐
│  CLAUDE.md — contexto permanente                              │
│  21 reglas duras. Se cargan solas en cada sesión.             │
└───────────────────────────┬──────────────────────────────────┘
                            │
        ┌───────────────────┴───────────────────┐
        │                                       │
┌───────▼──────────────────┐        ┌───────────▼──────────────┐
│  11 AGENTES              │        │  7 SKILLS                │
│  roles con criterio      │        │  procedimientos fijos    │
└──────────────────────────┘        └──────────────────────────┘
                            │
        ┌───────────────────┴───────────────────┐
        │  PLAN (doc 02 §11 + plan implementacion/) │
        │  cada tarea indica agente, skill y pruebas │
        └───────────────────────────────────────┘
```

**`CLAUDE.md`** contiene las reglas que, si se incumplen, producen un registro legalmente inválido o un producto invendible: dominio sin framework, nunca `now()` en el dominio (se inyecta un puerto `Clock`), todo instante en UTC, los turnos no se parten a medianoche, nada se borra, toda acción legal escribe en una auditoría solo‑append, idempotencia por `scan_id`, dos marcas de tiempo, QR firmado, cero biometría, nada específico de un cliente en el código. Cada regla nombra la herramienta que la verifica.

**Los agentes** replican las fronteras de la arquitectura: `arquitecto-dominio` defiende el hexágono y diseña antes de que se escriba código de negocio; `backend-laravel` vive en las capas de fuera; `frontend-quiosco`, `frontend-panel` y `frontend-portal-empleado` tienen cada uno su aplicación; `qa-testing` escribe la pirámide de pruebas; `devops-observabilidad` la infraestructura y la CI; `producto-licencia` todo lo que hace el sistema instalable por terceros; `ui-ux` el sistema visual compartido. Dos son **de solo lectura** a propósito: `revisor-codigo` y `seguridad-cumplimiento`. Quien encuentra un problema no lo arregla en el mismo paso: el hallazgo se enuncia por escrito y lo corrige otro.

**Las skills** son procedimientos que siempre se ejecutan igual: crear un caso de uso, añadir un endpoint (contrato primero, policy, prueba negativa por rol), una regla de negocio nueva (prueba antes que código), una migración segura (bloqueos acotados, `CONCURRENTLY`, validación fuera de la transacción), un informe nuevo, una revisión de cumplimiento y un análisis de código.

### 4.3 Continuidad entre sesiones

El proyecto se trabajó en decenas de sesiones a lo largo de dos meses, y una sesión de IA no recuerda la anterior. Tres piezas resuelven la continuidad:

- **`HANDOFF.md`**, en la raíz y versionado: estado, objetivo actual, siguiente acción, pendientes y trampas conocidas. Es lo primero que lee cada sesión y lo último que se actualiza. Manda sobre cualquier otra memoria.
- **Engram**, una memoria local buscable con el *porqué* de cada decisión, el diagnóstico de cada trampa y lo que corrigió cada revisión. No se versiona y nunca contiene datos de clientes.
- **Los ADR** ([`docs/adr/`](../docs/adr/), 57 en la versión entregada): una decisión arquitectónica solo se cambia con otro ADR, y los agentes tienen instrucción de parar y preguntar si van a contradecir uno.

### 4.4 Cómo se ejecutaba una tarea

1. Leer `CLAUDE.md` y `HANDOFF.md`; abrir una rama desde `main`.
2. Lanzar el agente que indica el plan con la skill correspondiente. Para una regla de negocio, `arquitecto-dominio` diseña y `qa-testing` escribe la prueba antes de que `backend-laravel` implemente.
3. Al terminar, `revisor-codigo` y `seguridad-cumplimiento` revisan en solo lectura y emiten un veredicto (APTO, APTO CON CORRECCIONES, NO APTO) con hallazgos clasificados. Las correcciones las aplica el agente que implementó.
4. CI completa en verde: calidad, arquitectura, unitarias y mutación, trazabilidad, integración y contrato, seguridad, frontend, E2E e instalación limpia desde el paquete.
5. Pull request, merge, `HANDOFF.md` y resumen de sesión.

Mi papel fue el de **product owner, arquitecto y revisor final**: decidir las reglas de negocio y las prioridades, responder cuando un agente paraba a preguntar, leer cada veredicto de revisión y juzgar lo que las herramientas no miden. En dos meses hubo 841 commits y 131 pull requests, 111 de ellas integradas.

### 4.5 Cómo se verificaba una versión

Por encima de la tarea hay un segundo bucle, el de la versión, y fue el que más enseñó. Un plan de verificación propio ([`docs/plan-verificacion-kronoqr.md`](../docs/plan-verificacion-kronoqr.md)) repite desde cero, con los agentes en **modo solo registrar** (nadie corrige mientras verifica), la calidad de código, las pruebas, la funcionalidad, el caos (Redis y PostgreSQL caídos, red intermitente), la seguridad, el cumplimiento, la observabilidad, el rendimiento, el despliegue, la documentación y la accesibilidad. La 2.2.0 pasó por este ciclo:

```text
Verificación de la 2.1.0 ──► NO-GO: 7 bloqueantes, 91 altos, 181 medios
        │
        ▼
Plan de correcciones por bloques (aprobado el 29-09-2026)
        │   cada bloque: su rama, sus revisiones, su CI completa, su PR
        ▼
Re-verificación sobre main (01 y 02-10) ──► 284 de 286 hallazgos eran reales;
        │                                     aparecen otros nuevos: bloques 15 a 22
        ▼
Verificación final sobre main (08 y 09-10) ──► 0 críticos y 0 altos de seguridad,
        │                                       0 bloqueantes del revisor
        ▼
Cinco correcciones más de update.sh y doctor (PR #130) ──► publicación de la 2.2.0
```

## 5. El producto

### 5.1 Tres aplicaciones y un servidor

| Aplicación | Quién la usa | Qué hace |
| --- | --- | --- |
| **Quiosco** (PWA en tablet Android) | Empleados | Escaneo continuo por cámara; fichaje por código y PIN (6 u 8 cifras) como respaldo; cola offline en IndexedDB con padrón cifrado; confirmación visual y sonora; emparejamiento por código; diagnóstico; aviso de privacidad configurable. |
| **Panel** (web) | Responsables, RRHH, auditores, administración | Presencia en tiempo real, detalle de jornada, correcciones trazadas, bandeja de incidencias, vista de cumplimiento, plantilla, departamentos, contratos, ausencias, credenciales, quioscos, informes, exportaciones, configuración, marca, licencia, soporte y, desde la 2.2.0, **cuentas de gestión**. |
| **Portal** (web) | Empleados | Consulta y descarga del propio registro (CSV o PDF sellado) con código y PIN. Solo lectura. |

El servidor es una pila Docker Compose: Nginx, PHP‑FPM, Horizon (colas), Scheduler, Reverb (WebSocket), PostgreSQL 17 y Redis 7, con observabilidad Prometheus, Grafana, Loki, Tempo y Alertmanager (61 reglas de alerta, cada una con su runbook).

### 5.2 Las decisiones que definen el producto

- **La credencial es una tarjeta física.** Cubre al 100 % de la plantilla y no pide a nadie que use su teléfono personal para una finalidad laboral.
- **Sin biometría.** Datos de categoría especial con una autoridad de protección de datos restrictiva. Descartado por diseño.
- **Nada se borra ni se sobrescribe.** Una corrección crea una versión nueva y conserva la anterior con autor, momento y motivo del catálogo.
- **Un turno de noche es un solo turno**, atribuido al día en que empezó. Los cambios de hora de marzo y octubre duran las horas reales.
- **La tablet nunca deja a nadie sin fichar.** Sin red, encola y confirma; al volver, sincroniza conservando la hora real (`occurred_at`) además de la de recepción (`recorded_at`). Todo fichaje es idempotente por `scan_id` (UUID v7 generado en el cliente).
- **El QR va firmado** (`FH1.<key_id>.<token>.<sig>`, HMAC), sin nombre ni número de empleado. Los rechazos son genéricos y de tiempo constante. La amenaza real es el préstamo de tarjeta, y se combate con supervisión y con detección de patrones anómalos que abre incidencias.
- **La licencia nunca bloquea el fichaje ni el acceso al registro legal.** Se degradan funciones accesorias.
- **Nada específico de un cliente vive en el código.** Marca, umbrales legales (descanso, jornada máxima, pausas, retención), idiomas y funcionalidades son configuración; los umbrales se leen de un perfil de cumplimiento a través de un puerto.

### 5.3 Lo que cambió en la 2.2.0

La 2.2.0 no añade un módulo: cierra lo que la verificación de la 2.1.0 encontró y lo que hacía falta para venderla. Lo más visible ([`CHANGELOG.md`](../CHANGELOG.md)):

- **Cuentas de gestión desde el panel** (ADR‑051): alta con contraseña temporal, cambio obligado en el primer acceso y reautenticación para las acciones sensibles; el responsable de cada departamento se asigna con asiento de auditoría.
- **Segundo factor obligatorio también para los responsables de departamento**: ya lo tienen los cuatro roles de gestión.
- **Portal accesible desde internet, con defensas** (ADR‑050): PIN configurable de 6 u 8 cifras, bloqueo por origen (20 fallos en 15 minutos bloquean esa conexión una hora) y `ADMIN_INTERNAL_CIDR` para cerrar el panel a una red.
- **Conciliación diaria y semanal del registro horario con su auditoría** (ADR‑057 §4): una edición directa de los tramos en la base de datos sale como alerta crítica.
- **Copias y archivado WAL cifrados y autenticados**, con un rol de base de datos de solo lectura para las copias; el runtime ya no recibe la credencial de migración ni la de copia.
- **Versiones inmutables** (ADR‑053): una versión publicada no se reescribe y el paquete fija sus imágenes por digest.
- **Actualización lado a lado**: el paquete nuevo se descomprime en su propio directorio y el anterior queda retirado, para que nadie lo vuelva a levantar por costumbre sobre una base ya migrada.
- **Resiliencia**: con PostgreSQL o Redis caídos, la API responde 503 con `Retry-After` en lugar de colgarse, y el fichaje sigue encolándose.
- **Quiosco**: ningún fichaje sale de la cola sin desenlace del servidor, el token del dispositivo rota en el latido y el aviso de privacidad se configura desde la marca.

### 5.4 Lo que el producto no hace

Calcular la nómina (se exporta), planificar cuadrantes, aprobar vacaciones, fichar desde el móvil o desde casa, geolocalizar, reconocer caras o huellas, abrir puertas. Decirlo explícitamente ([`docs/05`](../docs/05-presentacion-cliente.md) §8) evitó varias tentaciones durante el desarrollo.

## 6. Arquitectura y decisiones

### 6.1 Monolito modular hexagonal

Ocho módulos en `backend/app/Modules/` (`Attendance` como núcleo, `Compliance`, `Workforce`, `Identity`, `Reporting`, `Kiosk`, `Product`, `Shared`), cada uno con la misma disposición: `Domain/` puro → `Application/` (casos de uso y puertos) → `Infrastructure/` (Eloquent, adaptadores, proyecciones) + `Http/` (controladores, requests, recursos, policies). Deptrac verifica que el dominio no importa nada de Laravel, de Eloquent, de otro módulo ni de ninguna librería de infraestructura; la CI falla si ocurre.

Un monolito y no microservicios porque cada cliente instala el sistema entero en un servidor modesto y lo opera su IT; dos servicios que coordinar serían el doble de cosas que fallan de noche. Modular y hexagonal porque el dominio del registro horario tiene que poder probarse en milisegundos, sin base de datos, con un reloj inyectado que permite simular la medianoche y los cambios de hora.

### 6.2 La base de datos defiende las invariantes

Las reglas que no pueden fallar viven también en PostgreSQL: un único turno abierto por empleado (índice único parcial) y tramos sin solape (`EXCLUDE USING gist` sobre `tstzrange`). Una prueba de integración intenta violarlas por SQL directo y comprueba que la base las rechaza. `audit_log` está particionada por año, es solo‑append y encadenada por hash, y el rol de base de datos de la aplicación no tiene `UPDATE` ni `DELETE` sobre ella. Hay tres roles con credencial (aplicación, migración y copia de solo lectura), y desde la 2.2.0 cada contenedor recibe solo la suya: la de migración, que es superusuario, ya no está en el proceso que atiende peticiones. El esquema se construye con 65 migraciones reversibles.

### 6.3 Proyecciones reconstruibles

Los totales diarios (`daily_totals`) son una proyección: se recalculan en la misma transacción que el fichaje, nunca se incrementan, y una reconciliación nocturna los compara con los eventos origen y alerta si divergen. Si algún día hay un error de cálculo, se corrige el código y se reconstruye; el registro legal son los tramos, no los totales.

### 6.4 El contrato manda

[`docs/api/openapi.yaml`](../docs/api/openapi.yaml) es la fuente de verdad de la API `/api/v1`: se modifica antes que el código, las pruebas de contrato lo verifican con Spectator, y los clientes TypeScript de las tres aplicaciones se generan desde él. Un cambio de forma en una respuesta rompe la compilación del frontend antes de llegar a producción.

### 6.5 Decisiones registradas

57 ADR documentan por qué las cosas son como son, desde las primeras (monolito modular, hexagonal, PostgreSQL, UTC, QR firmado, turnos sin partir, offline‑first, sin biometría, auditoría encadenada) hasta las que salieron de la experiencia: el token de la tarjeta nace al imprimir y no al emitir (ADR‑034), la corrección estrena identificador y no cambia de jornada (ADR‑035), el token del quiosco rota en el latido con solape (ADR‑044), ningún fichaje sale de la cola sin desenlace del servidor (ADR‑047) o el portal accesible desde internet como decisión del cliente (ADR‑050). Los siete últimos los trajo la 2.2.0: cuentas de gestión con contraseñas temporales (ADR‑051), sesiones con token *bearer* sin cookies (ADR‑052), versiones inmutables fijadas por digest (ADR‑053), la vuelta atrás de una actualización es restaurar la copia (ADR‑054), el veredicto de cumplimiento lo da la vista y la bandeja no lo contradice (ADR‑055), la zona horaria del centro fija desde el primer fichaje (ADR‑056, aceptada para la 2.2.x) y el registro horario que no se borra ni se reescribe con la credencial del runtime (ADR‑057), que **enmienda** ADR‑042: este prometía más de lo que el código cumplía.

## 7. Calidad: cómo se sabe que funciona

**El nivel de prueba no lo decide quien implementa.** Una tabla (doc 02 §9.5) lo fija por tipo de cambio: regla de negocio → unitaria; esquema o restricción → integración; endpoint → feature, contrato y autorización negativa por cada rol; recorrido de usuario → E2E; escritura del quiosco → los cinco, más idempotencia concurrente.

**Cada prueba se etiqueta con los requisitos que cubre** (`->group('RN-05', 'RF-AT-08')`). Un comando genera la matriz requisito → prueba ([`docs/trazabilidad-pruebas.md`](../docs/trazabilidad-pruebas.md)) y **la CI falla si un requisito implementado no tiene prueba**. En la versión entregada: 168 requisitos en catálogo, 167 con prueba automática (el que falta, RNF‑M‑05, es un presupuesto de deuda técnica y se verifica por revisión), y 5 582 pruebas etiquetadas: 5 233 de Pest, 343 de Playwright y 6 escenarios de k6. Con los *datasets*, la CI de la versión ejecuta 9 254 casos de Pest.

**La pirámide completa:**

| Nivel | Herramienta | Qué garantiza |
| --- | --- | --- |
| Unitarias de dominio | Pest, sin base de datos, con presupuesto de duración (la suite entera en pocos segundos) | Reglas de negocio, cálculo de tiempo, medianoche, DST, propiedades sobre duraciones |
| Integración | Pest contra PostgreSQL real | Restricciones del esquema, concurrencia (treinta fichajes a la vez), proyecciones, cadena de auditoría, planes de consulta |
| Feature y contrato | Pest + Spectator contra `openapi.yaml` | Cada endpoint, la forma de cada respuesta y una **matriz de autorización generada del router**: un caso por ruta y por rol |
| Arquitectura | Pest + Deptrac + PHPStan 9 | Dominio puro, lenguaje de los identificadores, vocabulario de errores, nivel de prueba por requisito, guardas que vigilan la propia CI |
| Mutación | Pest `--mutate` sobre el dominio | MSI ≥ 80 % (88,22 % medido): las pruebas detectan cambios en la lógica, no solo la cubren |
| E2E | Playwright con cámara simulada que lee un QR real, más axe | Recorridos completos del quiosco (sin red → reconexión → sincronización → totales), panel y portal, con la CSP de producción; accesibilidad AA |
| Carga | k6 | Objetivo de diseño de 50 fichajes por segundo con p95 por debajo de 150 ms, y que el registro escrito cuadre después de la carga |
| Instalación | CI, jobs ⑧ y ⑧b | Instalación limpia desde el paquete de entrega y actualización desde la versión anterior con vuelta atrás, en cada cambio |

**Cobertura medida en la CI de la versión:** 95,99 % del dominio y 93,3 % del backend (umbrales 90 % y 75 %); en las tres SPA, entre el 87 % y el 99 % según la verificación final.

**Calidad estática atada a herramienta:** Pint, PHPStan nivel 9 sin baseline, Deptrac, Rector, ESLint con reglas propias (ninguna SPA declara un color; los identificadores van en inglés), `vue-tsc` estricto sin `any`, ShellCheck y shfmt sobre los scripts de instalación, Redocly sobre el contrato. La regla del proyecto es que **una convención que no verifica una herramienta es una sugerencia**.

## 8. Seguridad y cumplimiento

- **Modelo de amenazas STRIDE** y mapa a técnicas ATT&CK ([`docs/07`](../docs/07-seguridad-madurez-y-amenazas.md)), con los riesgos aceptados escritos y fechados.
- **Revisión interna OWASP ASVS** ([`docs/seguridad/`](../docs/seguridad/)) con corrección de hallazgos y prueba de no regresión por cada uno.
- **Autoevaluación OWASP SAMM 2.0** con la evidencia del repositorio; un nivel solo sube con evidencia, y se revisa en cada cierre de fase.
- **Autenticación y autorización:** Sanctum con tokens de ámbito, 2FA obligatorio para los cuatro roles de gestión (administración, RRHH, auditor y responsable de departamento), RBAC con ámbito por departamento, policy y prueba negativa por rol en cada endpoint, límites de tasa por dispositivo y por origen, bloqueo progresivo del PIN por empleado y, en el portal, bloqueo por origen.
- **Datos personales:** del DNI solo se guarda una huella irreversible; el correo es opcional; ningún nombre de empleado en logs ni en el histórico de errores (se usa `employee_uuid`, y el texto del histórico pasa por una lista blanca de palabras); aviso de privacidad en el quiosco; retención diferenciada; cifrado en tránsito y en reposo para copias, WAL y datos de la tablet.
- **Integridad del registro:** auditoría encadenada por hash y, desde la 2.2.0, conciliación diaria entre los tramos y sus asientos. Su límite está escrito: hoy el registro horario se protege por detección, no por privilegios; retirar al runtime el `UPDATE` y el `DELETE` sobre los tramos es ADR‑057 §1–§3, aceptado para la 2.2.x.
- **El fabricante no accede a los datos.** El paquete de diagnóstico va anonimizado por defecto y el acceso de soporte exige concesión expresa, temporal, acotada y auditada.
- **Cadena de suministro:** Semgrep, gitleaks, Trivy, `composer audit`, `npm audit` y SBOM CycloneDX por versión en la CI; imágenes fijadas por digest; política de divulgación en [`SECURITY.md`](../SECURITY.md).

## 9. Productización, despliegue y operación

Lo que separa «un sistema» de «un producto» es la Fase 5 del plan, y es donde más se notó la diferencia entre programar y hacer ingeniería:

- **Instalador** (`install.sh`): comprueba requisitos, genera todos los secretos en el servidor del cliente, levanta la pila, aplica el esquema, verifica y, si algo falla o se interrumpe (un corte de SSH, Ctrl+C), deshace. Idempotente. Probado en la CI en cada cambio.
- **Asistente de puesta en marcha** en el primer acceso al panel: organización, centro y zona horaria, departamentos, perfil de cumplimiento, primer administrador, primer quiosco.
- **Actualizador** (`update.sh`): el paquete nuevo se instala **al lado** del anterior, con copia previa verificada, migraciones reversibles, comprobación de salud sin exponer la versión nueva y vuelta atrás automática que restaura la copia y relanza la versión anterior (ADR‑054). Al terminar, el directorio anterior queda retirado. Soporta saltos entre versiones no consecutivas.
- **Copias** diarias cifradas y autenticadas, archivado WAL también cifrado, y simulacro de restauración que restaura de verdad en un contenedor limpio y cuenta filas. En la verificación final, la restauración tardó 61 segundos (52 con WAL) frente al límite de 4 horas.
- **Diagnóstico** (`doctor.sh` / `product:doctor`), histórico de errores agrupado por huella y paquete de diagnóstico anonimizado.
- **Licencia** firmada con ed25519 y verificada en local, con un emisor del fabricante en `tools/license-issuer/`.
- **Documentación de cliente** en español e inglés: instalación, configuración, operación, endurecimiento, guía de RRHH, guía del portal, obligaciones legales, preguntas para la asesoría y hoja para el empleado, más 33 runbooks de operación. Una prueba de arquitectura comprueba que los comandos y ficheros que cita existen.
- **Versiones inmutables**: una versión publicada no se reescribe y el paquete fija sus imágenes por digest (ADR‑053).

El despliegue de <https://kronoqr.kodigolab.es> se hizo con ese mismo paquete y ese mismo instalador, con la 2.1.0, y se actualizó a la 2.2.0 con el procedimiento lado a lado que se describe en [`despliegue.md`](despliegue.md).

## 10. Resultados

| Indicador | Valor en la versión entregada |
| --- | --- |
| Periodo | 11‑08‑2026 → 09‑10‑2026 (dos meses) |
| Commits · pull requests | 841 · 131 (111 integradas) |
| Versiones | 1.0.0, 1.1.0, 1.2.0, 2.0.0, 2.1.0 y **2.2.0** (09‑10‑2026), la primera para clientes |
| Fases del plan completadas | 0, 1, 2, 5 y 3 de 6 (la Fase 4, «Evolución», se decide con datos de uso) |
| Requisitos en catálogo | 168: 167 con prueba automática y 1 que se verifica por revisión |
| Pruebas etiquetadas | 5 582: 5 233 Pest · 343 Playwright · 6 k6 (9 254 casos de Pest ejecutados en la CI) |
| Cobertura · mutación | 95,99 % dominio, 93,3 % backend, 87–99 % frontend · MSI 88,22 % |
| ADR · migraciones | 57 · 65 |
| Módulos · aplicaciones | 8 · 3 |
| Documentación | 7 documentos de diseño, 57 ADR, contrato OpenAPI, 9 guías de cliente en dos idiomas, 33 runbooks |
| Agentes · skills · reglas duras | 11 · 7 · 21 |

Las cifras de pruebas y requisitos salen de la matriz de trazabilidad versionada; las de cobertura y mutación, de la CI manual completa del commit que se publicó (run 37929544729), y la CI de la etiqueta `v2.2.0` (run 37935103940) terminó en verde.

**Las verificaciones.** Antes de dar la 2.1.0 por entregable se hizo una **verificación pre‑release** de 14 pasos y 12 fases ([`docs/verificacion/`](../docs/verificacion/)) con los agentes en modo solo lectura. Todo lo que miden las herramientas estaba en verde. La verificación encontró, en lo que no mide ninguna herramienta, 7 hallazgos bloqueantes, 91 altos y 181 medios, y concluyó que la versión **no debía entregarse a un cliente tal como estaba**. De ahí salió el plan de correcciones de la 2.2.0. Una re‑verificación a mitad del plan confirmó que 284 de los 286 hallazgos eran reales y destapó otros nuevos, que dieron ocho bloques más. En total se integraron 25 bloques, cada uno con su rama, sus revisiones y su CI completa.

La **verificación final** sobre `main` (08 y 09‑10‑2026) cerró con 0 hallazgos críticos y 0 altos de `seguridad-cumplimiento` y 0 bloqueantes de `revisor-codigo`, y con nada que produjera datos legales incorrectos, también con Redis o PostgreSQL caídos. No cerró limpia: dejó 18 hallazgos altos nuevos en la escala del revisor, ninguno de seguridad. Decidí corregir antes de publicar los cinco que podía encontrarse un cliente en su primera actualización (caminos de error de `update.sh` y avisos de `doctor`) y diferir el resto a la 2.2.x, por escrito.

Considero la primera verificación el resultado más valioso del proyecto, precisamente porque el veredicto fue negativo; y la 2.2.0, la prueba de que un veredicto negativo se puede convertir en un plan y cerrarse.

## 11. Lo que no salió bien y lo que aprendí

**Las herramientas en verde no significan producto entregable.** Cobertura del 95 %, mutación del 86 % y trazabilidad completa convivían en la 2.1.0 con un fichaje por PIN que fallaba siempre en producción (una cabecera CSP sin `'wasm-unsafe-eval'` que ninguna prueba ejercitaba porque los E2E corrían sin CSP), con un instalador que no llegaba a buen puerto siguiendo la guía, y con una funcionalidad sin pantalla. La cobertura mide lo que se prueba; no lo que se olvidó probar. En la 2.2.0 los E2E del quiosco corren con la CSP de producción.

**El registro era alterable con la credencial del runtime.** La cadena de auditoría protegía `audit_log`, pero el usuario de base de datos de la aplicación conservaba `UPDATE` y `DELETE` sobre los tramos. Una ejecución de código en PHP habría podido cambiar una hora sin romper la cadena. El hallazgo (AUD‑1) obligó a separar las credenciales del runtime, a un ADR nuevo que enmienda al que prometía lo contrario, a una conciliación diaria entre registro y auditoría en la 2.2.0, a planificar la retirada de privilegios para la 2.2.x y a corregir los textos que prometían al cliente más de lo que el producto hacía. Fue la lección más importante: **la documentación de cliente es una promesa, y la seguridad hay que revisarla contra la promesa, no contra el código.**

**Verificar una vez no basta.** La re‑verificación encontró defectos que la primera no vio, y la verificación final, otros que habían entrado con las propias correcciones. Cada bloque cerraba en verde y con sus revisiones; aun así, mirar el conjunto desde cero volvió a encontrar cosas. Repetir la verificación completa antes de publicar no fue redundancia: fue lo que separó una versión «corregida» de una versión entregable.

**La IA se olvida de lo que no está escrito.** Cada sesión empieza de cero. Lo que no estaba en `CLAUDE.md`, en `HANDOFF.md` o en un ADR se perdía o se reinventaba de otra forma. La inversión en documentación estable y en el protocolo de continuidad no fue sobrecarga: fue lo que permitió avanzar dos meses sin degradar la arquitectura.

**Los agentes de solo lectura valen más que los que escriben.** Las revisiones de `revisor-codigo` y `seguridad-cumplimiento` encontraron, en cada bloque, cosas que el agente implementador había dado por buenas: una vuelta atrás que levantaba la versión nueva sobre la base restaurada, una purga que podía tapar un borrado, un comentario que una guarda tomaba por código. Separar quien implementa de quien revisa, y obligar a que el hallazgo se escriba, es lo que más calidad aportó por hora invertida.

**Las trampas del entorno cuestan más que las del código.** Un bind mount de Docker Desktop que omitía ficheros al recorrer `tests/`, un `sh` que en la CI es `dash`, un heredoc que se come las barras invertidas, un `kill -0` sobre `sudo` que devuelve EPERM según la imagen del runner, una mutación completa que roza el límite de 90 minutos del runner. Cada una se documentó como memoria para no repetirla; sin esa disciplina se habrían repetido.

**El criterio sigue siendo humano.** Los agentes tienen instrucción de parar y preguntar ante una regla de negocio no documentada o una contradicción con un ADR. Esa instrucción solo funciona si al otro lado hay alguien dispuesto a decidir en lugar de contestar «haz lo que te parezca». Decidir que el portal se abre a internet con un PIN más largo y no con un segundo factor, que la licencia comercial es de 100 empleados y un quiosco, que la conciliación entra en la 2.2.0 y los privilegios en la 2.2.x, o qué cinco hallazgos de la verificación final se corregían antes de publicar, no lo hizo ninguna herramienta.

## 12. Límites y trabajo futuro

- **Validación legal.** Los documentos lo dicen: esto no es asesoramiento jurídico. El convenio aplicable a cada cliente, la retención, el aviso de privacidad, la huella del DNI y la exportación para la Inspección deben revisarse con la asesoría laboral y el DPO antes de la primera venta.
- **Prueba en tablet real** durante jornadas completas (8 a 12 horas): cámara, memoria y batería, guantes, ruido, poca luz, tarjeta plastificada que sobrevive a una temporada en una cocina. La 2.2.0 está probada como PWA en navegador, no en una tablet en la pared.
- **Rendimiento en el hardware de referencia.** 50 fichajes por segundo con p95 por debajo de 150 ms es un objetivo de diseño: falta medirlo en una máquina de 4 vCPU y 8 GB. La prueba de carga que lanzó la etiqueta `v2.2.0` dio un veredicto «no fiable» por falta de datos para juzgar ese umbral, aunque la comprobación posterior del registro escrito salió en verde.
- **Revisión externa de seguridad** antes de la primera venta: la interna existe; la externa es una condición, no un hallazgo.
- **Pendientes de la 2.2.x** ya registrados: privilegios de base de datos por columnas y *trigger* de transiciones (ADR‑057 §1–§3), zona horaria fija desde el primer fichaje (ADR‑056), suelo legal de la retención por jurisdicción, firma de imágenes con cosign, mecanismo para dar por revisada una discrepancia de conciliación, y los hallazgos altos de la verificación final que no se corrigieron: entre otros, un sonido de confirmación igual para entrada y salida con tarjeta, opciones del menú del auditor que el servidor le niega, un informe por periodo que se congela con el máximo de filas, una prueba de tiempo constante del PIN que no puede fallar, y `make test` que no termina en el entorno de desarrollo (en la CI sí).
- **2.3.0**: baja programada con fecha de cese futura y readmisión de quien ya causó baja; acceso SQL entre módulos por puertos.
- **Fase 4, «Evolución»**: planificación de cuadrantes, integraciones con nómina, varios centros por instalación. Se decide con datos de uso reales.

## 13. Referencias dentro del repositorio

| Qué | Dónde |
| --- | --- |
| Requisitos, reglas de negocio, modelo de dominio | [`docs/01-especificaciones-proyecto.md`](../docs/01-especificaciones-proyecto.md) |
| Arquitectura, stack, pruebas, CI/CD, plan por fases | [`docs/02-stack-tecnologico-y-plan-implementacion.md`](../docs/02-stack-tecnologico-y-plan-implementacion.md) |
| Agentes y skills de IA | [`docs/03-agentes-y-skills-ia.md`](../docs/03-agentes-y-skills-ia.md), [`.claude/`](../.claude/), [`CLAUDE.md`](../CLAUDE.md) |
| Decisiones de arquitectura | [`docs/adr/`](../docs/adr/) |
| Contrato de la API | [`docs/api/openapi.yaml`](../docs/api/openapi.yaml) |
| Lo que se promete al cliente | [`docs/05-presentacion-cliente.md`](../docs/05-presentacion-cliente.md) |
| Seguridad, madurez y amenazas | [`docs/07-seguridad-madurez-y-amenazas.md`](../docs/07-seguridad-madurez-y-amenazas.md), [`docs/seguridad/`](../docs/seguridad/) |
| Plan de verificación | [`docs/plan-verificacion-kronoqr.md`](../docs/plan-verificacion-kronoqr.md) |
| Verificación de la 2.1.0, plan de correcciones, re‑verificación y verificación final de la 2.2.0 | [`docs/verificacion/`](../docs/verificacion/) (empezar por [`2.2.0-verificacion-final-resumen.md`](../docs/verificacion/2.2.0-verificacion-final-resumen.md)) |
| Documentación de cliente y runbooks | [`docs/cliente/`](../docs/cliente/), [`docs/runbooks/`](../docs/runbooks/) |
| Matriz requisito → prueba | [`docs/trazabilidad-pruebas.md`](../docs/trazabilidad-pruebas.md) |
| Historial de versiones | [`CHANGELOG.md`](../CHANGELOG.md) |
