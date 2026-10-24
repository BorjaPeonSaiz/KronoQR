// Donde esta guardando la tablet su cola ahora mismo (`queue_storage` del latido). Vive
// en `shared` porque el latido lo declara y la cola lo produce: ni uno depende del otro.
export type QueueStorageKind = 'durable' | 'memory' | 'unavailable'
