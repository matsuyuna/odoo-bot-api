# Base de conocimiento del proyecto `odoo-bot-api`

## Estado actual

Este repositorio **sí contiene conocimiento de negocio**, pero está distribuido en código y pruebas, no en una base de conocimiento formal (por ejemplo: FAQs, documentos funcionales centralizados, embeddings o vector store).

El conocimiento principal vive en:

- Integración con **Odoo** vía XML-RPC para productos y contactos.
- Integración con **WATI** para sincronización de atributos/contactos.
- Conversión de precios usando tasa **BCV** almacenada localmente.
- Comandos de sincronización entre Odoo ↔ WATI.

## Fuentes de conocimiento detectadas

### 1) Dominio de productos

- Búsqueda inteligente por tokens y fallback de coincidencias en Odoo.
- Campos de salida esperados por el bot (`name`, `qty_available`, `price`, `precio_bcv`, etc.).
- Lógica de disponibilidad y texto de respuesta para WhatsApp.

Archivo clave:

- `app/Services/OdooXmlRpc.php`
- `app/Http/Controllers/BotProductoController.php`

### 2) Dominio de contactos

- Búsqueda de contactos por nombre/email/teléfono.
- Estructura de contacto normalizada para downstream (incluye `preferred_whatsapp`).
- Flujo de sincronización y cola en `odoo_contact_syncs`.
- En sincronización Odoo → WATI se consulta compras del cliente en Odoo:
  - Modelo de órdenes: `sale.order` (filtrado por estados `sale` y `done`).
  - Modelo de líneas: `sale.order.line` para extraer productos comprados.
  - Se persisten dos insights en la cola local: `ultimo_producto_comprado` y `producto_mas_comprado`.
  - Al enviar a WATI se mapean en atributos `ultimoproductocomprado` y `productomascomprado`.
- Normalización de teléfonos de Venezuela por canal:
  - **Odoo → WATI:** `+58XXXXXXXXXX` (ejemplo `+584244162964`).
  - **WATI → Odoo:** `0XXXXXXXXXX` (ejemplo `04244162964`).

Archivos clave:

- `app/Services/OdooXmlRpc.php`
- `app/Http/Controllers/BotContactoController.php`
- `app/Console/Commands/SyncOdooContactsToQueueCommand.php`
- `app/Console/Commands/PushPendingContactsToWatiCommand.php`
- `app/Console/Commands/SyncWatiContactsToOdooCommand.php`
- `app/Support/VenezuelanPhoneFormatter.php`

### 3) Dominio BCV / precios en bolívares

- Persistencia de tasa BCV por fecha.
- Consumo de la tasa más reciente para mostrar precios en Bs.
- Inspección segura (limitada) de modelos Odoo potencialmente relacionados a tasa BCV:
  - `res.currency.rate`
  - `res.currency`
  - `ir.config_parameter`

Archivos clave:

- `app/Models/BcvRate.php`
- `database/migrations/2026_03_05_000001_create_bcv_rates_table.php`
- `app/Console/Commands/SyncBcvRateCommand.php`
- `app/Console/Commands/InspectOdooRateTablesCommand.php`

### 4) Contratos de integración externa

- Variables de entorno requeridas para Odoo y WATI.
- Contratos HTTP/XML-RPC y manejo de errores con mensajes concretos.

Archivos clave:

- `app/Services/OdooXmlRpc.php`
- `app/Services/WatiApi.php`

## Conclusión: ¿hay base de conocimiento?

- **Sí hay conocimiento**, pero **no está formalizado como KB central**.
- Hoy funciona más como una “KB implícita en código + tests”.
- Para escalar soporte, onboarding y consistencia del bot, conviene consolidarla en documentos operativos.

## Entrenamiento del asistente sobre esta base

Como asistente, no puedo “re-entrenarme” permanentemente desde este entorno, pero sí puedo operar con un flujo equivalente:

1. **Ingesta local de contexto**: leer archivos de dominio, rutas, comandos y pruebas.
2. **Síntesis operativa**: convertir lógica técnica en reglas de negocio accionables.
3. **Playbooks**: documentar escenarios típicos (buscar producto, sincronizar contactos, fallos de autenticación).
4. **Checklist de diagnóstico**: mapear errores comunes a causas probables y comandos de verificación.

Este documento es el primer paso de esa formalización.

## Siguiente paso recomendado

Crear un `docs/runbook-bot-operacion.md` con:

- Flujos E2E (consulta de producto/contacto).
- Matriz de errores (Odoo, WATI, BCV, colas).
- Variables de entorno obligatorias y cómo validarlas.
- Comandos de operación diaria para soporte.
