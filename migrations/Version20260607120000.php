<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bureau Manager Phase D.2 — Notes de frais.
 *
 * Crée la table `note_frais` et ajoute une FK note_frais_id sur operation_tresorerie.
 *
 * RELATIONS :
 *   - note_frais.club_id      → club (CASCADE)
 *   - note_frais.demandeur_id → user (SET NULL si user supprimé, RGPD)
 *   - note_frais.validateur_id → user (SET NULL)
 *   - operation_tresorerie.note_frais_id → note_frais (SET NULL, OneToOne)
 *
 * INDEX :
 *   - (club_id, statut) → l'usage principal est "lister les notes EN_ATTENTE d'un club"
 *   - (club_id, demandeur_id) → pour les "mes notes" filtrées
 *
 * CONTRAINTE UNIQUE :
 *   - Une opération a au plus UNE note de frais d'origine (relation 1:1).
 *     L'index UNIQUE sur note_frais_id garantit cela côté BDD.
 */
final class Version20260607120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bureau D.2 — Notes de frais : table note_frais + FK note_frais_id sur operation_tresorerie';
    }

    public function up(Schema $schema): void
    {
        // === Création de la table note_frais ===
        $this->addSql(<<<SQL
            CREATE TABLE note_frais (
                id INT AUTO_INCREMENT NOT NULL,
                club_id INT NOT NULL,
                demandeur_id INT DEFAULT NULL,
                validateur_id INT DEFAULT NULL,
                montant NUMERIC(10, 2) NOT NULL,
                date_depense DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                libelle VARCHAR(255) NOT NULL,
                notes LONGTEXT DEFAULT NULL,
                justificatif_path VARCHAR(255) NOT NULL,
                justificatif_nom_original VARCHAR(255) NOT NULL,
                justificatif_mime_type VARCHAR(100) NOT NULL,
                justificatif_taille INT NOT NULL,
                statut VARCHAR(16) NOT NULL,
                date_validation DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                motif_rejet LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_NF_CLUB (club_id),
                INDEX IDX_NF_DEMANDEUR (demandeur_id),
                INDEX IDX_NF_VALIDATEUR (validateur_id),
                INDEX IDX_NF_CLUB_STATUT (club_id, statut),
                INDEX IDX_NF_CLUB_DEMANDEUR (club_id, demandeur_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE note_frais
            ADD CONSTRAINT FK_NF_CLUB FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<SQL
            ALTER TABLE note_frais
            ADD CONSTRAINT FK_NF_DEMANDEUR FOREIGN KEY (demandeur_id) REFERENCES user (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<SQL
            ALTER TABLE note_frais
            ADD CONSTRAINT FK_NF_VALIDATEUR FOREIGN KEY (validateur_id) REFERENCES user (id) ON DELETE SET NULL
        SQL);

        // === Ajout FK note_frais_id sur operation_tresorerie (OneToOne) ===
        $this->addSql(<<<SQL
            ALTER TABLE operation_tresorerie
            ADD note_frais_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<SQL
            ALTER TABLE operation_tresorerie
            ADD CONSTRAINT FK_OP_TRESO_NOTE_FRAIS FOREIGN KEY (note_frais_id) REFERENCES note_frais (id) ON DELETE SET NULL
        SQL);
        // UNIQUE pour garantir la 1:1 (une note → max une opération)
        $this->addSql(<<<SQL
            CREATE UNIQUE INDEX UNIQ_OP_TRESO_NOTE_FRAIS ON operation_tresorerie (note_frais_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operation_tresorerie DROP FOREIGN KEY FK_OP_TRESO_NOTE_FRAIS');
        $this->addSql('DROP INDEX UNIQ_OP_TRESO_NOTE_FRAIS ON operation_tresorerie');
        $this->addSql('ALTER TABLE operation_tresorerie DROP note_frais_id');

        $this->addSql('ALTER TABLE note_frais DROP FOREIGN KEY FK_NF_CLUB');
        $this->addSql('ALTER TABLE note_frais DROP FOREIGN KEY FK_NF_DEMANDEUR');
        $this->addSql('ALTER TABLE note_frais DROP FOREIGN KEY FK_NF_VALIDATEUR');
        $this->addSql('DROP TABLE note_frais');
    }
}
