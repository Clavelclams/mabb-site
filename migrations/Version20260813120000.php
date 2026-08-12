<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [VC-8 13/08/2026] Identité visuelle par club : deux colonnes couleur
 * (« #RRGGBB », nullable) sur club. Null = couleurs MABB par défaut.
 * Consommées par l'app Venaball Club via GET /api/club/moi.
 * Migration écrite à la main, zéro drift.
 */
final class Version20260813120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Couleurs de club (primaire/secondaire) pour le theme de l\'app Venaball Club';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD couleur_primaire VARCHAR(7) DEFAULT NULL, ADD couleur_secondaire VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP couleur_primaire, DROP couleur_secondaire');
    }
}
