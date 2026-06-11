<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B2 — Sécu jury : table connexion_log + rgpd_request.
 *
 * connexion_log : trace TOUTES les tentatives de connexion (succès + échec)
 *   - audit RGPD (qui s'est connecté, depuis quelle IP, à quand ?)
 *   - anti-brute-force (compter les échecs par IP sur fenêtre 10min)
 *   - logs de référence en cas de litige (compte piraté, accès non autorisé)
 *
 * rgpd_request : trace les demandes de droit à l'oubli
 *   - audit RGPD obligatoire (CNIL : "vous devez tracer toute demande")
 *   - workflow : demande → admin valide → anonymisation effectuée → archivé
 *   - on NE supprime PAS le User (préserve les FK historiques pour
 *     les stats, présences, votes — on anonymise les champs perso)
 */
final class Version20260610110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B2 sécu : tables connexion_log + rgpd_request (logs + droit à l\'oubli)';
    }

    public function up(Schema $schema): void
    {
        // === connexion_log ===
        $this->addSql(<<<'SQL'
            CREATE TABLE connexion_log (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT DEFAULT NULL,
                email_tente VARCHAR(180) DEFAULT NULL,
                ip VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(500) DEFAULT NULL,
                succes TINYINT(1) NOT NULL,
                raison_echec VARCHAR(100) DEFAULT NULL,
                contexte VARCHAR(30) DEFAULT NULL COMMENT 'manager, pirb, admin',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_CL_USER (user_id),
                INDEX IDX_CL_IP (ip),
                INDEX IDX_CL_CREATED (created_at),
                INDEX IDX_CL_SUCCES (succes),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE connexion_log
            ADD CONSTRAINT FK_CL_USER FOREIGN KEY (user_id)
            REFERENCES `user` (id) ON DELETE SET NULL
        SQL);

        // === rgpd_request ===
        $this->addSql(<<<'SQL'
            CREATE TABLE rgpd_request (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                type VARCHAR(20) NOT NULL COMMENT 'effacement, export',
                statut VARCHAR(20) NOT NULL COMMENT 'pending, validee, effectuee, refusee',
                motif_user VARCHAR(500) DEFAULT NULL,
                motif_admin VARCHAR(500) DEFAULT NULL,
                requested_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                traitee_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                traitee_par_id INT DEFAULT NULL,
                INDEX IDX_RGPD_USER (user_id),
                INDEX IDX_RGPD_STATUT (statut),
                INDEX IDX_RGPD_TRAITEE_PAR (traitee_par_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE rgpd_request
            ADD CONSTRAINT FK_RGPD_USER FOREIGN KEY (user_id)
            REFERENCES `user` (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE rgpd_request
            ADD CONSTRAINT FK_RGPD_TRAITEE_PAR FOREIGN KEY (traitee_par_id)
            REFERENCES `user` (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connexion_log DROP FOREIGN KEY FK_CL_USER');
        $this->addSql('ALTER TABLE rgpd_request DROP FOREIGN KEY FK_RGPD_USER');
        $this->addSql('ALTER TABLE rgpd_request DROP FOREIGN KEY FK_RGPD_TRAITEE_PAR');
        $this->addSql('DROP TABLE connexion_log');
        $this->addSql('DROP TABLE rgpd_request');
    }
}
