<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Éval Match — patch cosmétique post-Étape 1.
 *
 * Aligne le schéma sur ce que Doctrine attend par défaut :
 *   - Retire le commentaire DC2Type:datetime_immutable (obsolète avec PHP 8 types stricts)
 *   - Renomme les index custom (IDX_EM_*) vers les noms auto-générés Doctrine
 *
 * Aucun impact fonctionnel — uniquement de la cohérence pour que
 * `doctrine:schema:validate` retourne OK. Pas de perte de données.
 *
 * Pourquoi une migration séparée et pas modifier Version20260604150000 ?
 *   Cette dernière a déjà tourné en local (et en prod). Modifier une migration
 *   exécutée n'a aucun effet sur les machines où elle est déjà appliquée.
 *   Toujours créer une migration suivante pour patcher.
 */
final class Version20260604150100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Éval Match — patch cosmétique (datetime + noms index)';
    }

    public function up(Schema $schema): void
    {
        // Retire le commentaire DC2Type obsolète
        $this->addSql('ALTER TABLE evaluation_match CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');

        // Aligne les noms d'index sur ceux que Doctrine génère par défaut
        $this->addSql('ALTER TABLE evaluation_match RENAME INDEX idx_em_joueur TO IDX_BFACB8F4A9E2D76C');
        $this->addSql('ALTER TABLE evaluation_match RENAME INDEX idx_em_rencontre TO IDX_BFACB8F46CFC0818');
    }

    public function down(Schema $schema): void
    {
        // Restauration des noms d'index custom
        $this->addSql('ALTER TABLE evaluation_match RENAME INDEX IDX_BFACB8F46CFC0818 TO idx_em_rencontre');
        $this->addSql('ALTER TABLE evaluation_match RENAME INDEX IDX_BFACB8F4A9E2D76C TO idx_em_joueur');

        // Restaure le commentaire DC2Type
        $this->addSql("ALTER TABLE evaluation_match CHANGE created_at created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }
}
