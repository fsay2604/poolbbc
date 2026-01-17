<?php

use Livewire\Livewire;

it('can render', function () {
    $component = Livewire::test('leaderboard.show');

    $component->assertSee('');
});
