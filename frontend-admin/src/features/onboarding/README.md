# onboarding

Asistente de puesta en marcha e importación masiva de plantilla (RF-PD-03,
RF-GP-05, tarea 5.5).

Es la primera pantalla del producto: quien abre el panel de una instalación
recién montada, sin ninguna cuenta de gestión ni ningún centro, entra aquí
—la guarda de rutas (`router/guards.ts`) lo decide a partir de
`GET /setup/status`— y sale con el sistema listo para fichar.

## Ficheros

- **`OnboardingView.vue`** — el marco: progreso, foco y anuncio al cambiar de
  paso, y qué pantalla toca (un paso pendiente, la revisión final o el
  resumen de cierre).
- **`setup.api.ts`** / **`setup.store.ts`** — los cinco endpoints de `/setup/*`
  y el estado compartido con la guarda de rutas: la guarda decide si hay que
  entrar aquí sin repetir la llamada en cada navegación.
- **`employeeImport.api.ts`** — `POST /employees/import` (multipart, dos
  fases).
- **`steps.ts`** — los ocho pasos, en el orden del contrato, y su clave i18n.
- **`ReviewStep.vue`** — la revisión final antes de `POST /setup/complete`
  (el asistente no se cierra solo).
- **`CompletionSummary.vue`** — el resumen accionable de RF-PD-03: qué falta
  antes del primer día, con la cifra de tarjetas pendientes por delante de
  todo lo demás.
- **`steps/*.vue`** — un componente por paso. Dos de ellos **reutilizan
  pantallas ya existentes** en vez de duplicarlas: `ComplianceProfileStep`
  incrusta `features/settings/ComplianceProfileView.vue` (tarea 5.2) y
  `LicenseStep` incrusta `features/settings/LicenseView.vue` (tarea 5.3), las
  dos con `heading-level="h3"` para no competir con el título de la página. El
  alta del segundo factor del primer administrador reutiliza
  `features/auth/TwoFactorEnrolPanel.vue`, extraído de `LoginView` para que
  exista un único sitio que enseña un secreto TOTP.

## Reglas duras que gobiernan este código

- **6 — Auditoría.** El primer administrador va antes que el centro: todo lo
  que se crea después tiene una cuenta detrás en `audit_log`.
- **15 — La licencia nunca bloquea.** El paso de licencia es omitible.
- **19 — Nunca un callejón sin salida.** El estado se relee de
  `GET /setup/status`: se puede abandonar el asistente y retomarlo, incluso
  en otra sesión.
- **21 — Sin nombres en logs técnicos.** El informe línea a línea de la
  importación (con nombres) solo vive en esta pantalla, en una respuesta
  autenticada; nunca se manda al reportador de errores del cliente.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
