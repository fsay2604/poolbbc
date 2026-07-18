<?php

namespace Database\Seeders;

use App\Models\EventType;
use App\Support\StandardEventTypeCatalog;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EventTypeSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(StandardEventTypeCatalog $catalog): void
    {
        foreach ($catalog->all() as $definition) {
            EventType::query()->firstOrCreate(
                ['scope_key' => 'global', 'slug' => $definition['slug']],
                [
                    'pool_id' => null,
                    'name' => $definition['name'],
                    'is_standard' => true,
                    'default_mode' => $definition['default_mode'],
                    'answer_source' => $definition['answer_source'],
                    'default_config' => $definition['default_config'],
                ],
            );
        }
    }
}
