<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * deploy/schema-reference.txt, empreinte du schéma du portable, sert à setup.sh pour
 * vérifier que le VPS a la même structure. Elle doit couvrir exactement les
 * migrations du dépôt : une migration ajoutée sans régénérer la référence échoue
 * ici, pas au prochain setup.sh sur le VPS.
 */
class SchemaReferenceTest extends TestCase
{
    public function test_reference_lists_every_migration(): void
    {
        $root = dirname(__DIR__, 2);
        $reference = $root.'/deploy/schema-reference.txt';

        $this->assertFileExists($reference, 'Régénérer sur le portable : scripts/schema-fingerprint.sh > deploy/schema-reference.txt');

        $lines = file($reference, FILE_IGNORE_NEW_LINES);
        $listed = [];
        foreach (array_slice($lines, 1) as $line) {
            if ($line === '') {
                break;
            }
            $listed[] = $line;
        }

        $files = array_map(fn ($f) => basename($f, '.php'), glob($root.'/database/migrations/*.php'));
        sort($files);

        $this->assertSame('# Migrations passées', $lines[0]);
        $this->assertSame($files, $listed, 'Référence périmée : php artisan migrate puis scripts/schema-fingerprint.sh > deploy/schema-reference.txt, sur le portable');
    }
}
