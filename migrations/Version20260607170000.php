<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stats Live V2.1b — Présence sur le terrain (5 + temps de jeu).
 *
 * Crée la table presence_terrain qui track les entrées/sorties de joueuses
 * pendant un match. Permet de calculer le temps de jeu par joueuse.
 *
 * INDEX :
 *   - (rencontre_id, secondes_sortie) : pour findEnCoursByRencontre()
 *     (utilisé pour savoir qui est ACTUELLEMENT sur le terrain)
 *   - (joueur_id, rencontre_id) : pour le calcul temps de jeu d'un joueur
 */
final class Version20260607170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stats Live V2.1b — table presence_terrain (5 sur terrain + temps de jeu)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE presence_terrain (
                id INT AUTO_INCREMENT NOT NULL,
                joueur_id INT NOT NULL,
                rencontre_id INT NOT NULL,
                secondes_entree INT NOT NULL,
                secondes_sortie INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_PT_JOUEUR (joueur_id),
                INDEX IDX_PT_RENCONTRE (rencontre_id),
                INDEX IDX_PT_RENCONTRE_SORTIE (rencontre_id, secondes_sortie),
                INDEX IDX_PT_JOUEUR_RENCONTRE (joueur_id, rencontre_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE presence_terrain
            ADD CONSTRAINT FK_PT_JOUEUR FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<SQL
            ALTER TABLE presence_terrain
            ADD CONSTRAINT FK_PT_RENCONTRE FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE presence_terrain DROP FOREIGN KEY FK_PT_JOUEUR');
        $this->addSql('ALTER TABLE presence_terrain DROP FOREIGN KEY FK_PT_RENCONTRE');
        $this->addSql('DROP TABLE presence_terrain');
    }
}
