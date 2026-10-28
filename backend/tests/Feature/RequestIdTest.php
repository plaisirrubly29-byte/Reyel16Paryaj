<?php

namespace Tests\Feature;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logs = new TestHandler;
        Log::driver()->getLogger()->pushHandler($this->logs);

        Route::get('/_test/log', function () {
            Log::info('dans la requête');

            return response()->json(['ok' => true]);
        });

        Route::get('/_test/dispatch', function () {
            LogFromJob::dispatch()->onConnection('database');

            return response()->json(['ok' => true]);
        });
    }

    public function test_generates_request_id_when_header_missing(): void
    {
        $id = $this->getJson('/api/v1/health')
            ->assertOk()
            ->headers->get(AssignRequestId::HEADER);

        $this->assertTrue(Str::isUuid($id));
    }

    public function test_reuses_valid_incoming_request_id(): void
    {
        $this->getJson('/api/v1/health', [AssignRequestId::HEADER => 'proxy-abc123.XYZ'])
            ->assertHeader(AssignRequestId::HEADER, 'proxy-abc123.XYZ');
    }

    public function test_reuses_incoming_request_id_up_to_64_characters(): void
    {
        $this->getJson('/api/v1/health', [AssignRequestId::HEADER => str_repeat('a', 64)])
            ->assertHeader(AssignRequestId::HEADER, str_repeat('a', 64));
    }

    public function test_replaces_unsafe_incoming_request_id(): void
    {
        foreach (["abc12345\ninjection", 'court', str_repeat('a', 65), 'avec espace'] as $unsafe) {
            $id = $this->getJson('/api/v1/health', [AssignRequestId::HEADER => $unsafe])
                ->headers->get(AssignRequestId::HEADER);

            $this->assertTrue(Str::isUuid($id), "Valeur acceptée : {$unsafe}");
        }
    }

    public function test_request_logs_contain_request_id(): void
    {
        $id = $this->getJson('/_test/log')->headers->get(AssignRequestId::HEADER);

        $this->assertSame($id, $this->lastRecord('dans la requête')->extra['request_id'] ?? null);
    }

    public function test_request_id_is_propagated_to_queued_jobs(): void
    {
        $id = $this->getJson('/_test/dispatch')->headers->get(AssignRequestId::HEADER);

        Context::flush(); // le worker est un autre processus : il ne connaît que le payload
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true]);

        $this->assertSame($id, $this->lastRecord('dans le job')->extra['request_id'] ?? null);
    }

    public function test_json_channel_writes_one_json_object_per_line(): void
    {
        $path = storage_path('logs/test-'.uniqid().'.json.log');
        Context::add('request_id', 'test-request-id');

        Log::build(['path' => $path] + config('logging.channels.json'))
            ->warning('message structuré', ['ticket' => 42]);

        $line = json_decode(trim(file_get_contents($path)), true);
        unlink($path);

        $this->assertSame('message structuré', $line['message']);
        $this->assertSame(['ticket' => 42], $line['context']);
        $this->assertSame('test-request-id', $line['extra']['request_id']);
    }

    private function lastRecord(string $message): LogRecord
    {
        $records = array_filter($this->logs->getRecords(), fn (LogRecord $r) => $r->message === $message);
        $this->assertNotEmpty($records, "Aucun log « {$message} »");

        return end($records);
    }
}

class LogFromJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        Log::info('dans le job');
    }
}
