<?php

declare(strict_types=1);

use App\Mcp\Servers\BallisticServer;
use Laravel\Mcp\Server\Facades\Mcp;

Mcp::web('ballistic', BallisticServer::class)->middleware(['auth:sanctum']);
Mcp::local('ballistic', BallisticServer::class);
