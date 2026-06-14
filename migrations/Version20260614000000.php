<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [B22b-bis 14/06/2026] Validation manuelle Stats FFBB par le coach/staff.
 *
 * Pivot après constat 14/06 : les PDFs FFBB sont SCANNÉS (rendu image),
 * impossible à parser sans OCR. À la place, on ajoute un simple bouton
 * "J'ai vérifié les stats FFBB" sur la fiche rencontre Manager. Le coach
 * confirme avoir comparé sa saisie EvaluationMatch avec le PDF officiel.
 *
 * 3 colonnes sur rencontre :
 *   - ffbb_stats_validated_at : DATETIME quand le coach a cliqué
 *   - ffbb_stats_validated_by_id : FK User (qui a validé)
 *   - ffbb_stats_validation_note : commentaire optionnel (écart constaté, etc.)
 *
 * Toutes nullable car la majorité des matchs ne sont pas validés (ou pas encore).
 */
final class Version20260614000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B22b-bis : validation manuelle Stats FFBB sur rencontre (coach confirme PDF officiel)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE rencontre
                ADD ffbb_stats_validated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD ffbb_stats_validated_by_id INT DEFAULT NULL,
                ADD ffbb_stats_validation_note LONGTEXT DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE rencontre
                ADD CONSTRAINT FK_RENC_FFBB_VALID_BY
                FOREIGN KEY (ffbb_stats_validated_by_id)
                REFERENCES `user` (id)
                ON DELETE SET NULL
        SQL);

        $this->addSql('CREATE INDEX IDX_RENC_FFBB_VALID_BY ON rencontre (ffbb_stats_validated_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre DROP FOREIGN KEY FK_RENC_FFBB_VALID_BY');
        $this->addSql('DROP INDEX IDX_RENC_FFBB_VALID_BY ON rencontre');
        $this->addSql(<<<'SQL'
            ALTER TABLE rencontre
                DROP COLUMN ffbb_stats_validated_at,
                DROP COLUMN ffbb_stats_validated_by_id,
                DROP COLUMN ffbb_stats_validation_note
        SQL);
    }
}
