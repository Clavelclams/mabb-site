<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [Dé-mock, 13/07/2026] Colonne confidentialite (JSON, nullable) sur joueur :
 * les 6 réglages de confidentialité de l'app (ConfidentialiteSettings).
 * Null = jamais réglé = TOUT PRIVÉ (défaut appliqué au contrôleur).
 * Migration écrite à la main, une seule colonne, zéro drift.
 */
final class Version20260713150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'De-mock : reglages de confidentialite (JSON) sur joueur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur ADD confidentialite JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur DROP confidentialite');
    }
}
