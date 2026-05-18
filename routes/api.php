<?php

declare(strict_types=1);


/*
|------------------------------------------------------------------------------
| SlotFlow API — v1
|------------------------------------------------------------------------------
|
| Versioned from the first commit. `/api/v1` costs nothing today and is the
| only thing that lets you change a response shape in eighteen months without
| breaking every client at once.
|
| Every route runs behind `tenant`, which resolves the workspace from the
| authenticated user or an `X-Tenant` header before any query touches a
| tenant-owned table.
|
| Full reference: docs/API.md · interactive: /docs/api · Postman collection in
| postman/.
|
*/

