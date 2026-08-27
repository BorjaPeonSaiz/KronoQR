# credentials

Estado, emisión, impresión y entrega de las tarjetas físicas, y rotación de clave de firma
(RF-QR-04..08). Tareas 1.10 y 2.12.

**No hay reimpresión** ([ADR-034](../../../../docs/adr/ADR-034-el-token-nace-al-imprimir-no-al-emitir.md)):
el token del QR se acuña al imprimir, así que «volver a imprimir» solo puede significar acuñar
otro y matar la tarjeta que quizá ya está en un bolsillo. Reponer una tarjeta perdida son tres
actos con tres asientos de auditoría: revocar → reemitir → imprimir la nueva.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
