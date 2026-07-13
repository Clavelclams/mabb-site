<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Anonymat réel des retours de séance.
 *
 * Trois choses, dans cet ordre :
 *
 * 1. On crée feedback_participation : elle dit QUI a répondu, jamais QUOI.
 *    C'est ce qui permet de retirer joueur_id des feedbacks anonymes sans casser
 *    l'anti-doublon ni les badges.
 *
 * 2. On rapatrie note_seance dans feedback_seance, puis on la supprime.
 *    C'était un doublon : deux tables, deux formulaires, la même fonction. Pire,
 *    note_seance affichait "ton commentaire a été transmis anonymement au coach"
 *    tout en stockant l'identité de la joueuse, sans même un drapeau d'anonymat.
 *    Ces retours sont donc réimportés EN ANONYME : les joueuses se sont exprimées
 *    en croyant l'être, on tient la promesse rétroactivement.
 *
 * 3. On coupe le lien pour tous les retours déjà marqués anonymes.
 *    joueur_id passe à NULL. C'est irréversible, et c'est le but.
 */
final class Version20260713100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anonymat reel des retours de seance : table feedback_participation, fusion de note_seance, coupure du lien joueur.';
    }

    public function up(Schema $schema): void
    {
        // 1. Qui a répondu. Rien d'autre.
        $this->addSql(<<<'SQL'
            CREATE TABLE feedback_participation (
                id INT AUTO_INCREMENT NOT NULL,
                joueur_id INT NOT NULL,
                seance_id INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_participation_joueur_seance (joueur_id, seance_id),
                INDEX IDX_FP_JOUEUR (joueur_id),
                INDEX IDX_FP_SEANCE (seance_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE feedback_participation ADD CONSTRAINT FK_FP_JOUEUR FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE feedback_participation ADD CONSTRAINT FK_FP_SEANCE FOREIGN KEY (seance_id) REFERENCES seance (id) ON DELETE CASCADE');

        // 2. Les participations des feedbacks déjà en base (avant de couper le lien).
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO feedback_participation (joueur_id, seance_id, created_at)
            SELECT f.joueur_id, f.seance_id, f.created_at
            FROM feedback_seance f
            WHERE f.joueur_id IS NOT NULL
        SQL);

        // 3. Rapatriement de note_seance vers feedback_seance, en anonyme.
        //    On force joueur_id = NULL et est_anonyme = 1 : c'est ce qui avait été
        //    promis aux joueuses, on le rend vrai.
        $this->addSql(<<<'SQL'
            INSERT INTO feedback_seance (seance_id, joueur_id, note, commentaire, est_anonyme, created_at)
            SELECT n.seance_id, NULL, n.note, n.commentaire, 1, n.created_at
            FROM note_seance n
        SQL);

        // Leurs participations (pour l'anti-doublon et le compteur de badges).
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO feedback_participation (joueur_id, seance_id, created_at)
            SELECT n.joueur_id, n.seance_id, n.created_at
            FROM note_seance n
        SQL);

        $this->addSql('DROP TABLE note_seance');

        // 4. Coupure du lien pour tout ce qui est anonyme. Irréversible, et c'est le but.
        $this->addSql('UPDATE feedback_seance SET joueur_id = NULL WHERE est_anonyme = 1');
    }

    public function down(Schema $schema): void
    {
        // On ne restaure PAS le lien joueur, il a été détruit volontairement.
        // Redescendre ne peut que recréer les structures vides.
        $this->addSql(<<<'SQL'
            CREATE TABLE note_seance (
                id INT AUTO_INCREMENT NOT NULL,
                joueur_id INT NOT NULL,
                seance_id INT NOT NULL,
                note SMALLINT NOT NULL,
                commentaire LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNQ_NS_JOUEUR_SEANCE (joueur_id, seance_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('DROP TABLE feedback_participation');
    }
}
