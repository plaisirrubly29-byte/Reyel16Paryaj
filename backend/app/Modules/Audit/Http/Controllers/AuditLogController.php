<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Http\Resources\AuditLogResource;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Http\ApiException;
use App\Support\Http\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    /** Au-delà, l'export est refusé : affiner les filtres (période, action…). */
    public const EXPORT_MAX_ROWS = 10_000;

    private const EXPORT_COLUMNS = [
        'id', 'created_at', 'action', 'actor_id', 'actor_email', 'subject_type', 'subject_id',
        'reason', 'before', 'after', 'metadata', 'ip_address', 'request_id',
    ];

    public function index(Request $request, AuditLogger $audit): AnonymousResourceCollection
    {
        $filters = $this->filters($request);

        $logs = $this->query($filters)
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        // La consultation du journal est elle-même journalisée.
        $audit->record(AuditAction::AuditViewed, metadata: ['filters' => $filters, 'page' => $logs->currentPage()]);

        return AuditLogResource::collection($logs);
    }

    /**
     * Export CSV des lignes filtrées (permission audit.export), avec motif : l'export est journalisé
     * (audit.exported) AVANT l'envoi du fichier. Les cellules qui commencent par = + - @ sont neutralisées
     * (injection de formules dans un tableur).
     *
     * @throws ApiException export_too_large
     */
    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        $filters = $this->filters($request);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $count = $this->query($filters)->count();
        if ($count > self::EXPORT_MAX_ROWS) {
            throw new ApiException('export_too_large', __('Trop de lignes à exporter : affinez les filtres (période, action…).'), 422, [
                'count' => $count,
                'max' => self::EXPORT_MAX_ROWS,
            ]);
        }

        $audit->record(AuditAction::AuditExported, reason: $reason, metadata: ['filters' => $filters, 'rows' => $count, 'format' => 'csv']);

        $query = $this->query($filters);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM : accents lisibles dans Excel
            fputcsv($out, self::EXPORT_COLUMNS, ';', '"', '');
            $query->chunkById(500, function ($logs) use ($out) {
                foreach ($logs as $log) {
                    fputcsv($out, array_map(self::cell(...), [
                        $log->id, $log->created_at->toIso8601String(), $log->action->value, $log->actor_id, $log->actor_email,
                        $log->subject_type, $log->subject_id, $log->reason, $log->before, $log->after, $log->metadata,
                        $log->ip_address, $log->request_id,
                    ]), ';', '"', '');
                }
            });
            fclose($out);
        }, 'journal-audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function cell(mixed $value): string
    {
        $value = match (true) {
            $value === null => '',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'actor_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters): Builder
    {
        return AuditLog::query()
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($filters['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v));
    }
}
