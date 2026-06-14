<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B30c — Invitation par mail pour parent pas encore inscrit.
 *
 * Création table parent_invitation :
 * - token sha256 (jamais en clair)
 * - email cible (parent à inviter)
 * - joueuse concernée
 * - demandeur (qui a envoyé l'invit : staff ou joueuse)
 * - expiration 14j
 *
 * Workflow :
 *   1. Staff/joueuse remplit email → INSERT parent_invitation + mail envoyé
 *   2. Parent clique lien dans mail → /parent-invitation/{token}
 *   3. Form signup pré-rempli email lock → crée User + UserClubRole PARENT
 *   4. ParentJoueur ACTIVE créé automatiquement
 *   5. invitation.accepted_at set
 */
final class Version20260612100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B30c : table parent_invitation (invitation par mail si parent pas inscrit)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE parent_invitation (
                id INT AUTO_INCREMENT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                email_cible VARCHAR(180) NOT NULL,
                joueur_id INT NOT NULL,
                demandeur_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                accepted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                accepted_user_id INT DEFAULT NULL,
                INDEX IDX_PI_TOKEN (token_hash),
                INDEX IDX_PI_JOUEUR (joueur_id),
                INDEX IDX_PI_DEMANDEUR (demandeur_id),
                INDEX IDX_PI_EMAIL (email_cible),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE parent_invitation ADD CONSTRAINT FK_PI_JOUEUR FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE parent_invitation ADD CONSTRAINT FK_PI_DEMANDEUR FOREIGN KEY (demandeur_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE parent_invitation ADD CONSTRAINT FK_PI_ACCEPTED_USER FOREIGN KEY (accepted_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parent_invitation DROP FOREIGN KEY FK_PI_JOUEUR');
        $this->addSql('ALTER TABLE parent_invitation DROP FOREIGN KEY FK_PI_DEMANDEUR');
        $this->addSql('ALTER TABLE parent_invitation DROP FOREIGN KEY FK_PI_ACCEPTED_USER');
        $this->addSql('DROP TABLE parent_invitation');
    }
}
