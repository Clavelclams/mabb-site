<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B15 — Notifications in-app pour PIRB
 *
 * Pourquoi cette table ?
 *   Remonter les actions coach (validation/rejet séance shot chart) à la
 *   joueuse dans son espace PIRB sans dépendre de la config email
 *   (MAILER_DSN peut être null sur les environnements sans config Brevo).
 *
 * Choix techniques :
 *   - Multi-tenant via club_id (isolation stricte par club).
 *   - Index composite (destinataire_id, club_id, lue) : optimisé pour
 *     la requête COUNT "notifs non-lues de cet user dans ce club" qui
 *     s'exécute sur chaque page PIRB via la Twig extension.
 *   - ON DELETE CASCADE sur destinataire et club : pas d'orphelins si
 *     un user ou un club est supprimé.
 *   - lien_route nullable : lien contextuel optionnel (ex: pirb_shot_chart).
 *   - 50 notifs max récupérées côté PHP (pas une contrainte BDD, géré en repo).
 */
final class Version20260629100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B15 — Table notification (notifications in-app PIRB)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notification (
                id              INT AUTO_INCREMENT NOT NULL,
                destinataire_id INT NOT NULL,
                club_id         INT NOT NULL,
                type            VARCHAR(60) NOT NULL,
                message         LONGTEXT DEFAULT NULL,
                lien_route      VARCHAR(100) DEFAULT NULL,
                lue             TINYINT(1) NOT NULL DEFAULT 0,
                created_at      DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_notif_user_club_lue (destinataire_id, club_id, lue),
                CONSTRAINT FK_notif_destinataire FOREIGN KEY (destinataire_id)
                    REFERENCES user(id) ON DELETE CASCADE,
                CONSTRAINT FK_notif_club FOREIGN KEY (club_id)
                    REFERENCES club(id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE `utf8mb4_unicode_ci`
              ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification');
    }
}
