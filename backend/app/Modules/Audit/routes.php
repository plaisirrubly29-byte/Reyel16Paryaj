<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

/*
| Module : Audit & Reporting
| Routes chargées par routes/api.php sous le préfixe /api/v1.
*/

Route::middleware(['auth:sanctum', 'active', 'mfa', 'permission:audit.view'])->group(function () {
    Route::get('/admin/audit-logs', [AuditLogController::class, 'index']);
});
