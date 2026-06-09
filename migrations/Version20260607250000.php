<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration : table parent_joueur — PIRB V1.4c.
 *
 * Lien officiel Parent (User) ↔ Joueur dans le club. Permet à un parent
 * de voir/gérer le profil PIRB de son/ses enfants.
 *
 * Workflow :
 *   1. Parent demande lien depuis PIRB → status = pending
 *   2. Staff/DIRIGEANT valide depuis Manager → status = active
 *   3. Parent voit l'enfant dans son PIRB
 *
 * Sécurité : un User ne peut être lié 2 fois au même Joueur (UNIQUE).
 */
final class Version20260607250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PIRB V1.4c — table parent_joueur (lien parent-enfant avec validation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE parent_joueur (
            id INT AUTO_INCREMENT NOT NULL,
            parent_user_id INT NOT NULL,
            joueur_id INT NOT NULL,
            statut VARCHAR(20) NOT NULL DEFAULT \'pending\',
            demande_par VARCHAR(20) DEFAULT NULL,
            valide_par_id INT DEFAULT NULL,
            valide_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_parent_enfant (parent_user_id, joueur_id),
            KEY idx_parent (parent_user_id),
            KEY idx_joueur (joueur_id),
            KEY idx_statut (statut),
            CONSTRAINT fk_parent_user FOREIGN KEY (parent_user_id) REFERENCES user(id) ON DELETE CASCADE,
            CONSTRAINT fk_parent_joueur FOREIGN KEY (joueur_id) REFERENCES joueur(id) ON DELETE CASCADE,
            CONSTRAINT fk_parent_valide_par FOREIGN KEY (valide_par_id) REFERENCES user(id) ON DELETE SET NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE parent_joueur');
    }
}
