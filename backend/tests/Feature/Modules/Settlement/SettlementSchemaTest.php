<?php

namespace Tests\Feature\Modules\Settlement;

use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Models\Result;
use App\Modules\Settlement\Models\Settlement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Modules\Betting\BuildsBetTickets;
use Tests\TestCase;

class SettlementSchemaTest extends TestCase
{
    use BuildsBetTickets;
    use RefreshDatabase;

    private function resultVersion(int $eventId, int $version = 1): Result
    {
        return Result::create([
            'event_id' => $eventId,
            'version' => $version,
            'status' => ResultStatus::Final,
            'home_score' => '1',
            'away_score' => '0',
            'source' => 'theodds',
        ]);
    }

    public function test_one_row_per_result_version(): void
    {
        $eventId = $this->market()->event_id;
        $this->resultVersion($eventId);

        $this->expectException(QueryException::class);
        $this->resultVersion($eventId);
    }

    public function test_results_are_append_only(): void
    {
        $result = $this->resultVersion($this->market()->event_id);

        $this->expectException(LogicException::class);
        $result->update(['home_score' => '5']);
    }

    private function settlement(int $ticketId): Settlement
    {
        return Settlement::create(['bet_ticket_id' => $ticketId, 'status' => BetTicketStatus::Lost, 'payout_minor' => 0, 'currency' => 'HTG', 'results' => []]);
    }

    public function test_one_settlement_per_ticket_revision(): void
    {
        $ticket = $this->ticket();
        $this->assertSame(1, $this->settlement($ticket->id)->fresh()->revision);

        $this->expectException(QueryException::class);
        $this->settlement($ticket->id);
    }

    public function test_settlements_are_append_only(): void
    {
        $settlement = $this->settlement($this->ticket()->id);

        $this->expectException(LogicException::class);
        $settlement->delete();
    }
}
