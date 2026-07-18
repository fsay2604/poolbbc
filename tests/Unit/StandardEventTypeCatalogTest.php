<?php

namespace Tests\Unit;

use App\Enums\AnswerSource;
use App\Enums\EventMode;
use App\Enums\OfficialRoundTemplate;
use App\Support\StandardEventTypeCatalog;
use Tests\TestCase;

class StandardEventTypeCatalogTest extends TestCase
{
    public function test_catalog_defines_five_complete_standard_event_types(): void
    {
        $catalog = app(StandardEventTypeCatalog::class);
        $definitions = $catalog->all();

        $this->assertSame([
            'head-of-household',
            'nomination',
            'veto-winner',
            'eviction',
            'season-winner',
        ], array_keys($definitions));

        foreach ($definitions as $slug => $definition) {
            $config = $catalog->normalizeDefaultConfig($slug, $definition['default_config']);

            $this->assertSame($slug, $definition['slug']);
            $this->assertInstanceOf(EventMode::class, $definition['default_mode']);
            $this->assertSame(AnswerSource::Houseguests, $definition['answer_source']);
            $this->assertLessThanOrEqual($config['prediction_max_selections'], $config['prediction_min_selections']);
            $this->assertLessThanOrEqual($config['result_max_selections'], $config['result_min_selections']);
            $this->assertIsInt(data_get($config, 'owner.points_per_match'));
            $this->assertIsInt(data_get($config, 'prediction.points_per_correct'));
            $this->assertArrayHasKey('exact_match_bonus', $config['prediction']);
            $this->assertArrayHasKey('wrong_answer_penalty', $config['prediction']);
            $this->assertArrayHasKey('allow_negative', $config);
            $this->assertSame('immediate', $config['result_publication_mode']);
        }
    }

    public function test_every_round_composition_references_catalog_slugs(): void
    {
        $catalog = app(StandardEventTypeCatalog::class);

        foreach (OfficialRoundTemplate::cases() as $template) {
            foreach ($catalog->composition($template) as $item) {
                $this->assertTrue($catalog->has($item['slug']));
            }
        }

        $this->assertCount(4, $catalog->composition(OfficialRoundTemplate::Standard));
        $this->assertCount(3, $catalog->composition(OfficialRoundTemplate::NoVeto));
        $this->assertCount(4, $catalog->composition(OfficialRoundTemplate::DoubleEviction));
        $this->assertCount(1, $catalog->composition(OfficialRoundTemplate::Finale));
    }
}
