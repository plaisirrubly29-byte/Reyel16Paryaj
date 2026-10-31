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

// Export CSV avec motif, journalisé (P6-08). Lecture seule : pas de clé d'idempotence.
Route::middleware(['auth:sanctum', 'active', 'mfa', 'permission:audit.export'])->group(function () {
    Route::post('/admin/audit-logs/export', [AuditLogController::class, 'export']);
});
