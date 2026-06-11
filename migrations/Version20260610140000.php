<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B19 — Ajout numeroMatch + codeEMarque + saison sur Rencontre.
 *
 * Permet d'importer les rencontres FFBB et de matcher les PDFs officiels
 * par leur numéro de match (idempotent : ré-importer ne crée pas de doublons).
 *
 * - numeroMatch : "5", "10", "33" (numéro de la rencontre dans la division FFBB)
 * - codeEMarque : "KQ7B388D" (code de la feuille de match e-Marque V2)
 * - saison      : "2025-2026"
 */
final class Version20260610140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B19 : Rencontre + numeroMatch / codeEMarque / saison (import FFBB)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre ADD numero_match VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE rencontre ADD code_e_marque VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE rencontre ADD saison VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE rencontre ADD division VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE rencontre ADD forfait_equipe TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE rencontre ADD forfait_adverse TINYINT(1) DEFAULT 0 NOT NULL');

        // Idempotence pour import : un même n° match dans une même saison
        // ne peut exister qu'une fois pour un club donné.
        $this->addSql('CREATE UNIQUE INDEX UNQ_R_CLUB_NUMERO_SAISON ON rencontre (club_id, numero_match, saison)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNQ_R_CLUB_NUMERO_SAISON ON rencontre');
        $this->addSql('ALTER TABLE rencontre DROP numero_match');
        $this->addSql('ALTER TABLE rencontre DROP code_e_marque');
        $this->addSql('ALTER TABLE rencontre DROP saison');
        $this->addSql('ALTER TABLE rencontre DROP division');
        $this->addSql('ALTER TABLE rencontre DROP forfait_equipe');
        $this->addSql('ALTER TABLE rencontre DROP forfait_adverse');
    }
}
