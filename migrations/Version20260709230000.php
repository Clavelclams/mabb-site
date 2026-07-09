<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [Social V1, 09/07/2026] Table pirb_follow — « X suit Y » dans l'app PIRB.
 *
 * Migration ÉCRITE À LA MAIN (le diff auto Doctrine/MySQL 8.4 est cassé,
 * cf. dette technique) : elle ne contient QUE la table Follow, aucun drift.
 *
 *  - uniq (suiveuse, suivie) : une paire n'existe qu'une fois (le toggle
 *    du contrôleur s'appuie dessus, la base est le dernier rempart).
 *  - index sur suivie_id : le COUNT des abonnées d'une joueuse est la
 *    lecture la plus fréquente (affiché sur chaque profil).
 *  - ON DELETE CASCADE des deux côtés : une fiche joueuse supprimée
 *    (droit RGPD à l'effacement) emporte ses liens sociaux avec elle.
 */
final class Version20260709230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Social V1 : table pirb_follow (suivi entre joueuses, app PIRB)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE pirb_follow (
                id INT AUTO_INCREMENT NOT NULL,
                suiveuse_id INT NOT NULL,
                suivie_id INT NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_pirb_follow_paire (suiveuse_id, suivie_id),
                INDEX idx_pirb_follow_suivie (suivie_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4'
        );
        $this->addSql('ALTER TABLE pirb_follow ADD CONSTRAINT FK_pirb_follow_suiveuse FOREIGN KEY (suiveuse_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pirb_follow ADD CONSTRAINT FK_pirb_follow_suivie FOREIGN KEY (suivie_id) REFERENCES joueur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pirb_follow');
    }
}
