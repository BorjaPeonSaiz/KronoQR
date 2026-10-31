# employees

Alta, baja, baja programada, readmisión y consulta de plantilla (RF-GP-01, RF-GP-03, RN-14, RN-27). Tareas 1.6, 5.5, 25.6 y 25.7. Los departamentos y
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
- `OffboardDialog.vue` + `CancelScheduledOffboardingDialog.vue` + `offboardPlan.ts` — la baja con fecha de hoy o futura queda
  **programada** (se cambia o se anula mientras no pase la fecha), con `Aplicar ya` para el efecto inmediato. `offboardPlan.ts`
  es la tabla del contrato (RN-14) y el diálogo dice antes de confirmar qué va a pasar.
- `RehireDialog.vue` + `RehireBatchDialog.vue` + `RehireOutcome.vue` (+ `RehirePinsDialog.vue`, `RehireCardDialog.vue`) —
  «Dar de alta de nuevo» (RN-27): un solo dato obligatorio, la fecha de alta; PIN nuevo visto una vez y tarjeta nueva ofrecida
  para imprimir. En lote desde Plantilla con el filtro «De baja»: todo o nada, con el error por posición. `rehirePlan.ts`
  lleva los dos avisos del propietario (31-10-2026).
- `EmploymentPeriodsSection.vue` + `TerminationCorrectionDialog.vue` — historia de alta y baja y corrección auditada de la
  fecha de cese (motivo de 10 a 1000 caracteres).
- `employmentProblems.ts` + `EmploymentProblemNotice.vue` — los 409 de la baja, la readmisión y la corrección, cada uno con su «qué hacer».
- `TeleworkingBadge.vue` + `TeleworkingCheckbox.vue` — marca informativa de teletrabajo: no cambia
  cómo ficha la persona ni cómo se calculan sus horas.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
