# ADR-036 — Las SPA comparten un paquete de cálculo y presentación, no cada una el suyo

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 27 de agosto de 2026 |
| **Decide** | Usuario, a propuesta de `revisor-codigo` en el cierre de la Fase 1 |
| **Afecta a** | `frontend-admin/`, `frontend-portal/`, `frontend-kiosk/` (en lo que aplique), Definición de Terminado (doc 02 §10.3) |
| **Requisitos** | RF-PA-03, RF-ID-05, RL-05 |

## Contexto

La tarea 1.11 (portal del empleado) se construyó como una SPA nueva sin ningún mecanismo para reutilizar código de `frontend-admin`, que ya resolvía el mismo problema (detalle de jornada, tarea 1.16) semanas antes. El agente que construyó el portal copió, en vez de reutilizar: cliente HTTP, formateo de fecha/hora, un anunciador de accesibilidad, descarga de ficheros, validación de rango de fechas, cinco componentes de UI compartidos y, sobre todo, **el cálculo de totales de una jornada** (`workdayTotals.ts`), con el cuerpo de la función idéntico carácter por carácter y solo los comentarios reescritos.

La revisión de cierre de fase (`revisor-codigo`) encontró que esa copia **ya había divergido**: el portal no leía `performed_at_local` (el instante de una corrección ya resuelto por el servidor en la zona horaria del centro) y en su lugar reconvertía `performed_at` (UTC) con la zona del navegador — con riesgo real de discrepancia en una noche de cambio de hora, exactamente el escenario que el proyecto existe para no tener.

El backend no tiene este problema: la frontera hexagonal de `Domain/`→`Application/`→`Infrastructure/` (doc 02 §1.6) obliga a cada agente a decidir explícitamente dónde vive una pieza de lógica compartida entre módulos (`Shared/`), y esa disciplina se sostuvo entre las nueve oleadas de la Fase 1 (verificado en la misma revisión: `PinAttempts`, `HashedEmployeePinVerifier`, `ReadEmployeeWorkDays` se reutilizaron de verdad). Entre las tres SPA no existe ninguna frontera declarada ni ningún mecanismo de reutilización, así que copiar fue el camino de menor resistencia — y nada en `make quality`/`npm run lint` lo penaliza, porque cada aplicación es un `package.json` aislado.

## Decisión

**Las tres SPA comparten un paquete interno para lo que no es específico de una pantalla**: cálculo (totales de jornada, agregación de tramos), presentación (lectura de instantes ya resueltos por el servidor en la zona del centro, nunca reconversión en el navegador), y utilidades transversales sin lógica de negocio (cliente HTTP base, anunciador de accesibilidad, descarga de ficheros, construcción/validación de rango de fechas).

Mecanismo: `npm workspaces` desde la raíz del repositorio, con un paquete nuevo `packages/web-kit` (nombre de paquete `@kronoqr/web-kit`). `frontend-admin`, `frontend-portal` y, en lo que aplique, `frontend-kiosk` lo declaran como dependencia de workspace y consumen de ahí en vez de mantener su propia copia.

**Qué NO va en el paquete compartido**: nada específico de una pantalla o de un flujo de una sola aplicación (el escaneo QR y la cola offline del quiosco, el panel de credenciales, el formulario de alta de empleado). El paquete crece solo cuando una segunda aplicación necesita de verdad lo que la primera ya construyó — no se anticipa contenido especulativo.

**Regla para el futuro** (Fase 2 en adelante): antes de escribir en una SPA una utilidad de cálculo o presentación que ya exista en otra, se comprueba `packages/web-kit` primero. Si la pieza es candidata a compartirse y no está allí, se mueve como parte de la misma tarea que la necesita — no se copia "por ahora".

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Aceptar la duplicación, con un ADR que la justifique y una prueba de contraste entre panel y portal** | Evita el trabajo de reestructurar el monorepo ahora, pero dos copias de la aritmética que decide cuánto ha trabajado alguien es exactamente el riesgo que el producto existe para eliminar (RL-05, RF-PA-03); una prueba de contraste detecta la divergencia después de que ocurra, no la previene. Descartada explícitamente por el usuario al decidir esta ADR. |
| **Publicar el paquete en un registro npm privado** | Añade infraestructura de publicación (versionado semántico, credenciales de registro) que un monorepo de tres aplicaciones desplegadas juntas no necesita. `npm workspaces` resuelve la misma dependencia por enlace simbólico local, sin publicar nada. |
| **Un cuarto directorio `frontend-shared/` sin gestor de paquetes, importado por ruta relativa** | Sin `package.json` propio, sin control de qué depende de qué, y sin que `npm run build` de cada SPA sepa que debe reconstruirlo — exactamente el problema que los workspaces resuelven. |

## Consecuencias

- Nuevo directorio `packages/web-kit/` con su propio `package.json`, `tsconfig.json` y build (o, si el proyecto lo prefiere, consumido directamente en TypeScript sin paso de build propio — decisión de quien lo implemente, documentada en el README del paquete).
- El `package.json` raíz declara `workspaces` incluyendo `frontend-admin`, `frontend-portal`, `frontend-kiosk` y `packages/*`.
- `frontend-portal` pierde sus copias de cliente HTTP, `datetime.ts`, `announcer.ts`, `downloadDocument.ts`, `dateRange.ts`, los cinco componentes de UI genéricos y `workdayTotals.ts`, y consume `@kronoqr/web-kit` en su lugar. De paso se corrige el defecto de `performed_at_local` encontrado en el cierre: al compartir la misma función de lectura de instante local que ya usa el panel, deja de ser posible que un mismo campo se lea de dos formas distintas en dos aplicaciones.
- `frontend-admin` migra sus equivalentes al mismo paquete en vez de mantenerlos localmente, para que exista un solo origen y no dos que casualmente coinciden hoy.
- Presupuesto de bundle de cada SPA (RNF-P-07/quiosco, y los umbrales equivalentes de panel/portal si los hay): el paquete compartido debe permanecer *tree-shakeable* — cada aplicación importa solo lo que usa, no el paquete entero. Se verifica con el mismo comando de presupuesto que ya existe por aplicación.
- El catálogo de motivos de corrección (Anexo C) traducido por separado en panel y portal, señalado como hallazgo menor en el mismo cierre, se resuelve como consecuencia natural si sus claves de i18n también se centralizan en el paquete — no es obligatorio hacerlo en el mismo cambio, pero es el candidato inmediato siguiente.

## Verificación

- `npm run type-check && npm run lint && npm run test:unit && npm run build` en verde en `frontend-admin` y `frontend-portal` tras la migración, sin duplicar la suite de `workdayTotals` (una sola suite, en el paquete, ejercitada por ambas aplicaciones vía su dependencia).
- Ningún fichero de `frontend-portal/src/shared/` ni `frontend-portal/src/features/my-records/workdayTotals.ts` sigue conteniendo lógica de cálculo o presentación duplicada de `frontend-admin` — se sustituye por el import del paquete.
- El histórico de correcciones del portal pinta la misma hora que el panel para la misma corrección (caso de prueba explícito con un instante en la noche de cambio de hora de octubre, que ya tiene datos de semilla en `EdgeCaseSeeder`).
- `grep -r "@kronoqr/web-kit" frontend-admin/src frontend-portal/src` encuentra usos reales, no una dependencia declarada y sin consumir.
