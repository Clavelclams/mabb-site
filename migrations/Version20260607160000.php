<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stats Live V2.1a — Format de match configurable.
 *
 * Ajoute sur rencontre :
 *   - nb_periodes (int, défaut 4) : 2 (mi-temps) ou 4 (quart-temps)
 *   - duree_periode_minutes (int, défaut 10)
 *
 * Pourquoi des valeurs par défaut ?
 *   - Les rencontres déjà créées (saison en cours) n'ont pas ces champs
 *   - On applique la valeur la plus fréquente (4×10 = standard FIBA seniors)
 *   - Le coach peut ajuster au cas par cas via le formulaire d'édition
 */
final class Version20260607160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stats Live V2.1a — nb_periodes + duree_periode_minutes sur rencontre';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre ADD nb_periodes INT NOT NULL DEFAULT 4');
        $this->addSql('ALTER TABLE rencontre ADD duree_periode_minutes INT NOT NULL DEFAULT 10');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre DROP nb_periodes');
        $this->addSql('ALTER TABLE rencontre DROP duree_periode_minutes');
    }
}
