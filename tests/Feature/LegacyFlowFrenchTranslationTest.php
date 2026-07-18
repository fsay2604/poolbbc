<?php

namespace Tests\Feature;

use App\Enums\DraftStatus;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolCompetitionMode;
use App\Enums\PoolMemberRole;
use App\Enums\PoolStatus;
use Tests\TestCase;

class LegacyFlowFrenchTranslationTest extends TestCase
{
    public function test_refactored_flow_messages_are_translated_in_french(): void
    {
        $previousLocale = app()->getLocale();
        app()->setLocale('fr');

        try {
            $messages = [
                'A completed draft is immutable.' => 'Un repêchage terminé ne peut plus être modifié.',
                'A pool cannot be deleted after registrations have opened.' => 'Un pool ne peut pas être supprimé après l’ouverture des inscriptions.',
                'You were removed from this pool. Contact its administrator.' => 'Vous avez été retiré de ce pool. Communiquez avec son administrateur.',
                'The explicit none option cannot be combined with another answer.' => 'L’option « Aucun » ne peut pas être combinée avec une autre réponse.',
                'Only a system administrator can publish official results.' => 'Seul un administrateur système peut publier les résultats officiels.',
                'Open — submitted predictions remain editable until the deadline.' => 'Ouvert — les prédictions soumises restent modifiables jusqu’à la date limite.',
                'Legacy imports are disabled after the canonical flow becomes authoritative.' => 'Les importations historiques sont désactivées dès que le flux canonique devient la référence.',
                'The prediction visibility setting is invalid.' => 'Le paramètre de visibilité des prédictions est invalide.',
                'Completed and archived pools are read-only.' => 'Les pools terminés et archivés sont en lecture seule.',
                'Events can only be reordered while the entire round is in draft and has no responses or results.' => 'Les événements peuvent seulement être réordonnés lorsque toute la ronde est à l’état brouillon et qu’elle ne contient aucune réponse ni aucun résultat.',
                'Events cannot be created after the pool is completed.' => 'Aucun événement ne peut être créé après la fin du pool.',
                'Events cannot be reordered after the pool is completed.' => 'Les événements ne peuvent pas être réordonnés après la fin du pool.',
                'Members cannot be changed after the pool is completed.' => 'Les membres ne peuvent pas être modifiés après la fin du pool.',
                'Publish or cancel every event before completing the pool.' => 'Publiez ou annulez chaque événement avant de terminer le pool.',
                'The event order must contain every event from this round exactly once.' => 'L’ordre des événements doit contenir exactement une fois chaque événement de cette ronde.',
                'The event positions cannot be reordered safely.' => 'Les positions des événements ne peuvent pas être réordonnées en toute sécurité.',
                'This pool status transition is not allowed.' => 'Cette transition de statut du pool n’est pas permise.',
                'You cannot change this pool status.' => 'Vous ne pouvez pas modifier le statut de ce pool.',
                'You cannot reorder events in this pool.' => 'Vous ne pouvez pas réordonner les événements de ce pool.',
            ];

            foreach ($messages as $source => $translation) {
                $this->assertSame($translation, __($source));
            }

            $this->assertSame(
                'Sélectionnez entre 1 et 3 réponses.',
                __('Select between :min and :max answers.', ['min' => 1, 'max' => 3]),
            );
            $this->assertSame(
                'Prédictions officielles — BBC 2026',
                __('Official predictions — :season', ['season' => 'BBC 2026']),
            );
            $this->assertSame('Terminé', PoolStatus::Completed->label());
            $this->assertSame('Archivé', PoolStatus::Archived->label());
            $this->assertSame('Prédictions', PoolCompetitionMode::PredictionOnly->label());
            $this->assertSame('Propriétaire', PoolMemberRole::Owner->label());
            $this->assertSame('En pause', DraftStatus::Paused->label());
            $this->assertSame('Pointage', EventStatus::ResultEntered->label());
            $this->assertSame('Prédiction', EventMode::Prediction->label());
        } finally {
            app()->setLocale($previousLocale);
        }
    }
}
