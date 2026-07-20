# API versioning (Phase 7)

## Current version

Supported versions are configured in `config/config.php`:

- `API_SUPPORTED_VERSIONS` — comma-separated list (default: `1`)
- Override via environment: `API_SUPPORTED_VERSIONS=1,2`

## Client usage

Send optional header on JSON API requests:

```
X-API-Version: 1
```

If the header is **omitted**, the request is accepted (backward compatible with existing web UI).

If the header is **present** and not in the supported list, the API returns:

```json
{
  "status": "error",
  "code": "unsupported_api_version",
  "message": "Unsupported API version. Supported: 1"
}
```

HTTP status: `400`.

## Future mobile routes

When adding dedicated mobile endpoints, prefer URL prefix:

- `/api/v1/sales/...` routed through `public/index.php` (future router extension)

Until then, existing `sales/*` JSON endpoints use the header gate in `BaseController::guardJsonApi()`.