<?php

namespace Tests\Unit\Modules\Catalogue;

use App\Modules\Catalogue\Http\Resources\MarketResource;
use App\Modules\SportsData\Services\HouseMarkets;
use App\Modules\SportsData\Services\MarketKeys;
use PHPUnit\Framework\TestCase;

/** Libellés et découpage des marchés de mi-temps, corners et cartons (V2-D09). */
class StatisticsMarketLabelsTest extends TestCase
{
    public function test_every_statistics_market_has_a_french_name(): void
    {
        foreach (MarketKeys::STATISTICS as $key) {
            $this->assertNotSame($key, MarketResource::marketName($key, 'soccer'), "Libellé manquant : {$key}");
        }
        $this->assertSame('Nombre de corners', MarketResource::marketName('alternate_totals_corners', 'soccer'));
    }

    public function test_halftime_fulltime_labels(): void
    {
        $this->assertSame('X/1', MarketResource::selectionLabel('draw/home', null));
        $this->assertSame('2/2', MarketResource::selectionLabel('away/away', null));
        $this->assertSame('> 10.5', MarketResource::selectionLabel('over_10.5', null));
    }

    public function test_house_market_names_and_labels(): void
    {
        foreach (HouseMarkets::KEYS as $key) {
            $this->assertNotSame($key, MarketResource::marketName($key, 'soccer'), "Libellé manquant : {$key}");
        }
        $labels = array_map(fn ($k) => MarketResource::selectionLabel($k, null), [
            'goals_0', 'goals_5plus', 'range_2_3', 'range_4plus', 'home_clean', 'neither', 'home_by_1', 'away_by_3plus', 'draw', 'draw_and_under', 'home_and_over',
        ]);
        $this->assertSame(['0', '5+', '2-3', '4+', '1', 'Aucune', '1 par 1', '2 par 3+', 'X', 'X & -2.5', '1 & +2.5'], $labels);
    }

    public function test_market_keys_split_into_bet_type_and_measure(): void
    {
        $this->assertSame(['totals', MarketKeys::FIRST_HALF], [MarketKeys::base('totals_h1'), MarketKeys::measure('totals_h1')]);
        $this->assertSame(['alternate_team_totals', MarketKeys::CORNERS], [MarketKeys::base('alternate_team_totals_corners'), MarketKeys::measure('alternate_team_totals_corners')]);
        $this->assertSame(['alternate_spreads', MarketKeys::CARDS], [MarketKeys::base('alternate_spreads_cards'), MarketKeys::measure('alternate_spreads_cards')]);
        $this->assertSame(['h2h', MarketKeys::GOALS], [MarketKeys::base('h2h'), MarketKeys::measure('h2h')]);
        $this->assertFalse(MarketKeys::needsStatistics('alternate_totals'));
        $this->assertTrue(MarketKeys::needsStatistics(MarketKeys::HALFTIME_FULLTIME));
    }
}
