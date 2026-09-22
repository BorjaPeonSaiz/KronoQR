# absences

Registro de ausencias — vacaciones, baja médica, permiso (RF-GP-04, tarea 3.10). Sin flujo de
aprobación: es Fase 4 y el doc 05 §8 lo acota («pero sin flujo de aprobación»). Ningún estado
«pendiente» ni botón «aprobar».

Carpeta por _feature_ y no dentro de `employees/` (doc 02 §3.5, decisión 9 de la ficha de la
tarea): la pantalla es transversal a la plantilla, no una faceta de la ficha de una persona.

- `absences.api.ts` — listar, ver el detalle con historial, registrar, corregir, anular e
  importar. Las formas (`Absence`, `AbsenceDetail`, `AbsenceCollection`, `CreateAbsenceRequest`,
  `CorrectAbsenceRequest`, `VoidAbsenceRequest`, `AbsenceImportReport`) están generadas del
  contrato y alias en `@/shared/api/types`, como el resto del panel: aquí no se inventa ninguna.
  `AbsenceDetail` envuelve la versión pedida (`detail.absence`) y sus versiones anteriores
  (`detail.history`, de la más antigua a la más reciente, sin incluir la propia).
- `AbsenceListView.vue` — ruta `/absences`. Filtros de periodo, departamento, tipo y estado; tabla
  con paginación en servidor; acciones de escritura solo con `employees:*`.
- `AbsenceRegisterDialog.vue` — alta, con buscador de persona por código o nombre reutilizando
  `employees.api.ts`. `other` exige nota.
- `AbsenceCorrectDialog.vue` — corrección. Enseña **qué cambia, desde qué valor y hacia cuál**
  antes de confirmar (doc 03 §4.3), con motivo libre de 3 a 500 caracteres.
- `AbsenceVoidDialog.vue` — anulación, con el resumen de lo que se anula y motivo.
- `AbsenceHistoryPanel.vue` — las versiones de una ausencia, de la más antigua a la más reciente.
  Nada se borra (regla dura 5).
- `AbsenceImportDialog.vue` — carga por fichero en dos fases (`validate` → `apply`), calcada del
  paso «employees» del asistente de puesta en marcha (`onboarding/steps/EmployeesImportStep.vue`).

La nota (`note`) solo la reciben `admin` y `rrhh`: para `responsable_departamento` el campo no
viaja en la respuesta, y la pantalla no pinta ninguna columna ni ningún dato cuando eso ocurre —
nunca lo confunde con «sin nota».
