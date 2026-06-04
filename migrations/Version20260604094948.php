<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260604094948 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout workflow validation inscriptions (status pending/active/rejected, role_demande, audit). '
             . 'Migre toutes les rows existantes en status=active pour ne pas bloquer les membres déjà actifs.';
    }

    public function up(Schema $schema): void
    {
        // 1. Ajout des nouvelles colonnes — DEFAULT 'pending' pour les nouveaux UserClubRole
        $this->addSql('ALTER TABLE user_club_role ADD status VARCHAR(20) DEFAULT \'pending\' NOT NULL, ADD role_demande VARCHAR(30) DEFAULT NULL, ADD valide_at DATETIME DEFAULT NULL, ADD valide_par_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user_club_role ADD CONSTRAINT FK_3341CD6E9E77E063 FOREIGN KEY (valide_par_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_3341CD6E9E77E063 ON user_club_role (valide_par_user_id)');

        // 2. CRITIQUE : migration des données existantes
        // Tous les UserClubRole déjà en BDD étaient implicitement "actifs" (puisque le
        // workflow de validation n'existait pas avant cette migration). On les passe à
        // status='active' SANS quoi tous les membres existants seraient bloqués
        // par le ClubVoter qui exige désormais status='active'.
        $this->addSql('UPDATE user_club_role SET status = \'active\' WHERE id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_club_role DROP FOREIGN KEY FK_3341CD6E9E77E063');
        $this->addSql('DROP INDEX IDX_3341CD6E9E77E063 ON user_club_role');
        $this->addSql('ALTER TABLE user_club_role DROP status, DROP role_demande, DROP valide_at, DROP valide_par_user_id');
    }
}
