<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stats FFBB — Étape A : ajout des 3 chemins PDF à Rencontre.
 *
 * Stocke uniquement le NOM DU FICHIER (pas le chemin absolu) :
 *   - Si on déplace le dossier d'upload, on change la config, pas la BDD
 *   - Si on déploie sur un autre serveur, les paths absolus ne suivent pas
 *
 * Pattern aligné sur Joueur::photoPath ajouté en Version20260604140000.
 */
final class Version20260605100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stats FFBB Étape A — ajout 3 chemins PDF à rencontre (résumé, feuille, positions)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre ADD resume_path VARCHAR(255) DEFAULT NULL, ADD feuille_match_path VARCHAR(255) DEFAULT NULL, ADD positions_tirs_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre DROP resume_path, DROP feuille_match_path, DROP positions_tirs_path');
    }
}
