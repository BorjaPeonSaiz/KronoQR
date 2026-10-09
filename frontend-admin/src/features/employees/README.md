# employees

Alta, baja y consulta de plantilla (RF-GP-01, RF-GP-03). Tareas 1.6 y 5.5. Los departamentos y
el centro tienen su propia pantalla en `departments/`.

- `employees.api.ts` — listado con filtros, ficha, alta, edición, baja y PIN.
- `EmployeeListView.vue` — ruta `/employees`, con filtros de departamento, teletrabajo y estado del PIN.
- `EmployeeCreateDialog.vue` — alta de una persona.
- `EmployeeDetailView.vue` — ficha (`/employees/:uuid`): datos, edición, baja, contratos y PIN.
  Un PIN pendiente (`pin_status: pending`, p. ej. tras una importación masiva) se emite desde aquí;
  uno ya emitido se restablece.
- `PinRevealDialog.vue` — visualización única del PIN (RF-ID-09): de 6 u 8 cifras según
  `IDENTITY_PIN_LENGTH` (ADR-050), sin copiar al portapapeles y sin guardarlo en ningún sitio.
- `EmployeeContractsSection.vue` + `ContractRegisterDialog.vue` + `contracts.api.ts` — serie
  histórica de contratos de la persona y alta de uno nuevo (RF-GP-02). Sin editar ni borrar
  (regla dura 5).
- `TeleworkingBadge.vue` + `TeleworkingCheckbox.vue` — marca informativa de teletrabajo: no cambia
  cómo ficha la persona ni cómo se calculan sus horas.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
