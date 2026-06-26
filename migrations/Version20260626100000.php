<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ENT Documents — table `document` pour l'Espace Numérique de Travail du club.
 *
 * DESIGN :
 *   Un document appartient directement à un Club (multi-tenant direct, pas via
 *   une entité intermédiaire comme les ReunionDocument).
 *
 *   La visibilité est gérée par une colonne VARCHAR simple (STAFF / MEMBRES / PARENTS).
 *   Ce choix délibéré évite une table pivot inutile : les règles sont stables.
 *
 *   Les types (COMPTE_RENDU, PLANNING, REGLEMENT, FORMULAIRE, DOCUMENT_JOUEUR,
 *   MEDIA, CONVOCATION, AUTRE) déterminent la visibilité par défaut mais peuvent
 *   être surchargés à l'upload.
 *
 *   Relation optionnelle joueur_id : pour les documents liés à une joueuse
 *   spécifique (fiche médicale, licence individuelle, etc.).
 *
 * STOCKAGE : public/uploads/ent/{clubId}/{uniqid}.{ext}
 *   (géré par DocumentUploader, pas dans la BDD)
 */
final class Version20260626100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ENT Documents — table document (type, visibilité, fichier, club)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE document (
                id              INT AUTO_INCREMENT NOT NULL,
                club_id         INT           NOT NULL          COMMENT 'Club propriétaire (multi-tenant)',
                joueur_id       INT           NULL              COMMENT 'Joueuse concernée (optionnel)',
                uploade_par_id  INT           NULL              COMMENT 'User qui a uploadé',
                titre           VARCHAR(255)  NOT NULL          COMMENT 'Titre affiche dans ENT',
                type            VARCHAR(50)   NOT NULL          COMMENT 'COMPTE_RENDU | PLANNING | REGLEMENT | FORMULAIRE | DOCUMENT_JOUEUR | MEDIA | CONVOCATION | AUTRE',
                visibilite      VARCHAR(20)   NOT NULL          COMMENT 'STAFF | MEMBRES | PARENTS',
                description     LONGTEXT      NULL              COMMENT 'Description optionnelle',
                nom_original    VARCHAR(255)  NOT NULL          COMMENT 'Nom original du fichier cote utilisateur',
                path            VARCHAR(255)  NOT NULL          COMMENT 'Chemin relatif dans uploads/ent/{clubId}/',
                mime_type       VARCHAR(100)  NOT NULL          COMMENT 'MIME type valide a upload',
                taille          INT           NOT NULL DEFAULT 0 COMMENT 'Taille en octets',
                created_at      DATETIME      NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT FK_document_club      FOREIGN KEY (club_id)        REFERENCES club(id)   ON DELETE CASCADE,
                CONSTRAINT FK_document_joueur    FOREIGN KEY (joueur_id)      REFERENCES joueur(id) ON DELETE SET NULL,
                CONSTRAINT FK_document_uploader  FOREIGN KEY (uploade_par_id) REFERENCES user(id)   ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Indexes pour les requêtes courantes (liste par club, filtre type, filtre visibilité)
        $this->addSql('CREATE INDEX idx_doc_club       ON document (club_id)');
        $this->addSql('CREATE INDEX idx_doc_type       ON document (type)');
        $this->addSql('CREATE INDEX idx_doc_visibilite ON document (visibilite)');
        $this->addSql('CREATE INDEX idx_doc_joueur     ON document (joueur_id)');
        $this->addSql('CREATE INDEX idx_doc_created    ON document (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE document');
    }
}
