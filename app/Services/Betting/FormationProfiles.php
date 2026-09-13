<?php

namespace App\Services\Betting;

/**
 * PROFILS TACTIQUES DES FORMATIONS
 * Basé sur analyses tactiques modernes + StatsBomb
 */
class FormationProfiles
{
    public const PROFILES = [
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // FORMATIONS EN 4
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        '4-3-3' => [
            'style' => 'attacking',
            'offensiveImpact' => 15,
            'defensiveImpact' => 0,
            'controlImpact' => 5,
            'description' => 'Formation offensive équilibrée, pressing haut, ailiers larges',
            'strengths' => ['Largeur', 'Jeu combiné', 'Domination milieu', 'Transitions rapides', 'Pressing'],
            'weaknesses' => ['Vulnérable contre-attaque', 'Centre défensif exposé'],
            'expectedGoalsModifier' => 0.2,
            'bttsLikelihood' => 10,
            'overLikelihood' => 12,
            'cleanSheetLikelihood' => 0,
        ],
        '4-2-3-1' => [
            'style' => 'balanced',
            'offensiveImpact' => 10,
            'defensiveImpact' => 5,
            'controlImpact' => 10,
            'description' => 'Formation polyvalente, double pivot défensif, meneur de jeu libre',
            'strengths' => ['Équilibre', 'Contrôle milieu', 'Transitions', 'Solidité défensive'],
            'weaknesses' => ['Isolement attaquant', 'Dépendance au numéro 10'],
            'expectedGoalsModifier' => 0.1,
            'bttsLikelihood' => 5,
            'overLikelihood' => 5,
            'cleanSheetLikelihood' => 0,
        ],
        '4-4-2' => [
            'style' => 'balanced',
            'offensiveImpact' => 5,
            'defensiveImpact' => 5,
            'controlImpact' => 0,
            'description' => 'Formation classique équilibrée, compacte, duo d\'attaque',
            'strengths' => ['Équilibre', 'Compacité', 'Duo d\'attaque', 'Simplicité'],
            'weaknesses' => ['Milieu en infériorité numérique parfois', 'Manque de créativité'],
            'expectedGoalsModifier' => 0,
            'bttsLikelihood' => 0,
            'overLikelihood' => 0,
            'cleanSheetLikelihood' => 0,
        ],
        '4-4-2-losange' => [
            'style' => 'control',
            'offensiveImpact' => 8,
            'defensiveImpact' => 3,
            'controlImpact' => 12,
            'description' => 'Formation axiale, domination du milieu, jeu intérieur',
            'strengths' => ['Domination du milieu', 'Jeu intérieur', 'Deux attaquants', 'Triangulations'],
            'weaknesses' => ['Peu de largeur', 'Dépend des latéraux pour largeur'],
            'expectedGoalsModifier' => 0.05,
            'bttsLikelihood' => 3,
            'overLikelihood' => 5,
            'cleanSheetLikelihood' => 0,
        ],
        '4-1-4-1' => [
            'style' => 'control',
            'offensiveImpact' => 0,
            'defensiveImpact' => 10,
            'controlImpact' => 10,
            'description' => 'Formation contrôle, sentinelle protectrice, pressing intelligent',
            'strengths' => ['Protection défense', 'Transitions', 'Compacité', 'Stabilité'],
            'weaknesses' => ['Attaquant seul', 'Peu créatif', 'Dépend des contre-attaques'],
            'expectedGoalsModifier' => -0.1,
            'bttsLikelihood' => -5,
            'overLikelihood' => -8,
            'cleanSheetLikelihood' => 15,
        ],
        '4-5-1' => [
            'style' => 'defensive',
            'offensiveImpact' => -10,
            'defensiveImpact' => 15,
            'controlImpact' => -5,
            'description' => 'Formation défensive, bloc bas, contre-attaques rapides',
            'strengths' => ['Bloc compact', 'Solide', 'Contre-attaque rapide', 'Fermeture espaces'],
            'weaknesses' => ['Peu de créativité', 'Attaquant isolé', 'Peu de possession'],
            'expectedGoalsModifier' => -0.2,
            'bttsLikelihood' => -10,
            'overLikelihood' => -15,
            'cleanSheetLikelihood' => 20,
        ],
        '4-3-1-2' => [
            'style' => 'balanced',
            'offensiveImpact' => 10,
            'defensiveImpact' => 5,
            'controlImpact' => 10,
            'description' => 'Sapin de Noël, jeu intérieur, combinaisons entre les lignes',
            'strengths' => ['Domination axiale', 'Combinaisons', 'Deux attaquants', 'Meneur libre'],
            'weaknesses' => ['Manque largeur', 'Dépend des latéraux'],
            'expectedGoalsModifier' => 0.05,
            'bttsLikelihood' => 3,
            'overLikelihood' => 5,
            'cleanSheetLikelihood' => 0,
        ],
        '4-1-2-1-2' => [
            'style' => 'control',
            'offensiveImpact' => 8,
            'defensiveImpact' => 8,
            'controlImpact' => 12,
            'description' => 'Formation très axiale, jeu en losange très technique',
            'strengths' => ['Jeu technique', 'Domination axiale', 'Triangulations', 'Compacité'],
            'weaknesses' => ['Pas de largeur', 'Très exigeant techniquement', 'Latéraux très sollicités'],
            'expectedGoalsModifier' => 0.05,
            'bttsLikelihood' => 0,
            'overLikelihood' => 3,
            'cleanSheetLikelihood' => 0,
        ],
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // FORMATIONS EN 3
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        '3-4-3' => [
            'style' => 'attacking',
            'offensiveImpact' => 18,
            'defensiveImpact' => -5,
            'controlImpact' => 0,
            'description' => 'Formation ultra-offensive, pistons actifs, 3 attaquants',
            'strengths' => ['Surnombre offensif', 'Largeur extrême', 'Pression', 'Pistons très hauts'],
            'weaknesses' => ['Vulnérable sur les côtés', 'Défense à 3 risquée', 'Exigeant physiquement'],
            'expectedGoalsModifier' => 0.3,
            'bttsLikelihood' => 15,
            'overLikelihood' => 20,
            'cleanSheetLikelihood' => 0,
        ],
        '3-5-2' => [
            'style' => 'balanced',
            'offensiveImpact' => 8,
            'defensiveImpact' => 8,
            'controlImpact' => 12,
            'description' => 'Formation contrôle milieu, pistons actifs, duo attaquants',
            'strengths' => ['Surnombre milieu', 'Pistons', 'Duo attaque', 'Bloc solide'],
            'weaknesses' => ['Défense à 3 exposée', 'Demande beaucoup physiquement'],
            'expectedGoalsModifier' => 0.1,
            'bttsLikelihood' => 8,
            'overLikelihood' => 10,
            'cleanSheetLikelihood' => 0,
        ],
        '3-4-2-1' => [
            'style' => 'attacking',
            'offensiveImpact' => 12,
            'defensiveImpact' => 3,
            'controlImpact' => 8,
            'description' => 'Formation offensive moderne, 2 meneurs derrière pointe',
            'strengths' => ['Créativité', 'Surnombre offensif', 'Pressing', 'Deux numéros 10'],
            'weaknesses' => ['Défense à 3', 'Physiquement exigeant', 'Vulnérable transitions'],
            'expectedGoalsModifier' => 0.15,
            'bttsLikelihood' => 10,
            'overLikelihood' => 12,
            'cleanSheetLikelihood' => 0,
        ],
        '3-6-1' => [
            'style' => 'defensive',
            'offensiveImpact' => -15,
            'defensiveImpact' => 18,
            'controlImpact' => 5,
            'description' => 'Formation ultra-défensive, milieu surchargé, fermeture totale',
            'strengths' => ['Milieu surchargé', 'Fermeture espaces', 'Bloc compact', 'Solidité'],
            'weaknesses' => ['Attaque minimale', 'Très peu créatif', 'Attaquant seul'],
            'expectedGoalsModifier' => -0.3,
            'bttsLikelihood' => -15,
            'overLikelihood' => -20,
            'cleanSheetLikelihood' => 25,
        ],
        '3-3-3-1' => [
            'style' => 'attacking',
            'offensiveImpact' => 15,
            'defensiveImpact' => -3,
            'controlImpact' => 8,
            'description' => 'Formation pressing total, très exigeante, style Bielsa',
            'strengths' => ['Pressing total', 'Jeu de position', 'Haute intensité', 'Domination'],
            'weaknesses' => ['Très exigeant physiquement', 'Risqué', 'Demande une préparation spécifique'],
            'expectedGoalsModifier' => 0.2,
            'bttsLikelihood' => 12,
            'overLikelihood' => 15,
            'cleanSheetLikelihood' => 0,
        ],
        '3-1-4-2' => [
            'style' => 'balanced',
            'offensiveImpact' => 10,
            'defensiveImpact' => 5,
            'controlImpact' => 10,
            'description' => 'Formation axiale/agressif, sentinelle, domination cœur du jeu',
            'strengths' => ['Domination axiale', 'Deux attaquants', 'Sentinelle', 'Équilibre'],
            'weaknesses' => ['Manque largeur', 'Défense à 3', 'Dépend des pistons'],
            'expectedGoalsModifier' => 0.1,
            'bttsLikelihood' => 5,
            'overLikelihood' => 8,
            'cleanSheetLikelihood' => 0,
        ],
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // FORMATIONS EN 5
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        '5-3-2' => [
            'style' => 'defensive',
            'offensiveImpact' => -10,
            'defensiveImpact' => 15,
            'controlImpact' => -5,
            'description' => 'Formation défensive, ligne de 5 compacte, contre ultra-rapide',
            'strengths' => ['Bloc compact', 'Solidité défensive', 'Contre-attaque rapide', '5 défenseurs'],
            'weaknesses' => ['Manque créativité', 'Peu de largeur', 'Attaque limitée'],
            'expectedGoalsModifier' => -0.2,
            'bttsLikelihood' => -10,
            'overLikelihood' => -15,
            'cleanSheetLikelihood' => 20,
        ],
        '5-4-1' => [
            'style' => 'defensive',
            'offensiveImpact' => -15,
            'defensiveImpact' => 20,
            'controlImpact' => -10,
            'description' => 'Formation ultra-défensive, bloc bas, fermeture couloirs',
            'strengths' => ['Solidité défensive maximale', 'Compacité', 'Contre', 'Fermeture totale'],
            'weaknesses' => ['Peu de créativité', 'Attaquant isolé', 'Peu de possession', 'Jeu stérile'],
            'expectedGoalsModifier' => -0.3,
            'bttsLikelihood' => -15,
            'overLikelihood' => -20,
            'cleanSheetLikelihood' => 25,
        ],
        '5-2-3' => [
            'style' => 'balanced',
            'offensiveImpact' => 5,
            'defensiveImpact' => 12,
            'controlImpact' => 0,
            'description' => 'Formation offensif mais équilibrée, trois attaquants + solidité',
            'strengths' => ['Solidité défensive', 'Trois attaquants', 'Équilibre', 'Transitions'],
            'weaknesses' => ['Milieu en infériorité', 'Dépend des pistons', 'Exigeant physiquement'],
            'expectedGoalsModifier' => 0.05,
            'bttsLikelihood' => 5,
            'overLikelihood' => 8,
            'cleanSheetLikelihood' => 15,
        ],
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // FORMATIONS RARES
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        '2-3-5' => [
            'style' => 'attacking',
            'offensiveImpact' => 20,
            'defensiveImpact' => -10,
            'controlImpact' => 5,
            'description' => 'Formation historique/moderne en phase attaque, positionnel',
            'strengths' => ['Surnombre offensif massif', 'Domination possession', 'Supériorités numériques'],
            'weaknesses' => ['Très vulnérable défensivement', 'Rare en phase défensive', 'Risqué'],
            'expectedGoalsModifier' => 0.3,
            'bttsLikelihood' => 20,
            'overLikelihood' => 25,
            'cleanSheetLikelihood' => 0,
        ],
        '4-2-4' => [
            'style' => 'attacking',
            'offensiveImpact' => 18,
            'defensiveImpact' => -8,
            'controlImpact' => 0,
            'description' => 'Formation très offensive, deux ailiers très hauts + deux attaquants',
            'strengths' => ['Très offensif', 'Largeur extrême', 'Transitions rapides', '4 attaquants'],
            'weaknesses' => ['Très vulnérable', 'Milieu en infériorité', 'Défense exposée'],
            'expectedGoalsModifier' => 0.25,
            'bttsLikelihood' => 18,
            'overLikelihood' => 22,
            'cleanSheetLikelihood' => 0,
        ],
        '3-2-4-1' => [
            'style' => 'control',
            'offensiveImpact' => 15,
            'defensiveImpact' => 0,
            'controlImpact' => 15,
            'description' => 'Formation Guardiola-style, contrôle total, supériorités partout',
            'strengths' => ['Contrôle total ballon', 'Domination milieu', 'Supériorités numériques', 'Jeu positionnel'],
            'weaknesses' => ['Très complexe', 'Demande technicité extrême', 'Vulnérable transitions'],
            'expectedGoalsModifier' => 0.2,
            'bttsLikelihood' => 10,
            'overLikelihood' => 15,
            'cleanSheetLikelihood' => 0,
        ],
    ];

    /**
     * Récupère le profil d'une formation
     */
    public static function get(string $formation): ?array
    {
        // Normaliser le nom (ex: "4-4-2 (losange)" -> "4-4-2-losange")
        $key = self::normalizeFormationKey($formation);
        return self::PROFILES[$key] ?? self::getDefaultProfile();
    }

    /**
     * Normalise la clé de formation
     */
    private static function normalizeFormationKey(string $formation): string
    {
        return str_replace([' ', '(', ')'], ['', '-', ''], strtolower($formation));
    }

    /**
     * Profil par défaut si formation inconnue
     */
    private static function getDefaultProfile(): array
    {
        return [
            'style' => 'balanced',
            'offensiveImpact' => 0,
            'defensiveImpact' => 0,
            'controlImpact' => 0,
            'description' => 'Formation non reconnue - Valeurs neutres appliquées',
            'strengths' => [],
            'weaknesses' => [],
            'expectedGoalsModifier' => 0,
            'bttsLikelihood' => 0,
            'overLikelihood' => 0,
            'cleanSheetLikelihood' => 0,
        ];
    }

    /**
     * Liste toutes les formations disponibles
     */
    public static function getAllFormations(): array
    {
        return array_keys(self::PROFILES);
    }

    /**
     * Filtre les formations par style
     */
    public static function getByStyle(string $style): array
    {
        return array_filter(self::PROFILES, fn($p) => $p['style'] === $style);
    }
}