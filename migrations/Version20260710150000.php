<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [Recap v4, 10/07/2026] Colonne tirs (JSON, nullable) sur
 * pirb_seance_playground : le détail par tir du mode tir auto
 * ({ reussi, zone }), zones ajustées par la joueuse au debrief.
 *
 * Migration SÉPARÉE de Version20260710120000 (création de la table) :
 * celle-ci a pu déjà être appliquée en prod — on n'édite JAMAIS une
 * migration potentiellement passée, on en ajoute une.
 */
final class Version20260710150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recap v4 : detail par tir (JSON) sur pirb_seance_playground';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pirb_seance_playground ADD tirs JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pirb_seance_playground DROP tirs');
    }
}
