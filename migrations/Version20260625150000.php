<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Joueurs éphémères (V2.2) + mode stats rencontre.
 *
 * Joueur :
 *   - is_temporaire       : flag "joueuse rapide" créée pour une seule rencontre
 *   - equipe_ephemere     : NULL = notre équipe, STRING = nom de l'équipe adverse
 *   - couleur_maillot     : code hex (#ef4444) ou nom couleur libre
 *   - rencontre_origine_id : FK vers la rencontre qui l'a créée (SET NULL si supprimée)
 *
 * Rencontre :
 *   - mode_stats : 'full' | 'light' | 'none'
 *     full  = toutes les actions (tirs, rebonds, passes, fautes…)
 *     light = points + rebonds + passes seulement (open gym, recrutement rapide)
 *     none  = pas de stats live pour cette rencontre
 *
 * Design :
 *   Joueur.isTemporaire = true permet de réutiliser 100% du moteur ActionMatch
 *   existant sans nouvelle table. La conversion "Recruter" met isTemporaire=false
 *   et le joueur devient officiel — les ActionMatch sont conservées intactes.
 */
final class Version20260625150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Joueurs éphémères pour rencontres exhibition + mode stats par rencontre';
    }

    public function up(Schema $schema): void
    {
        // --- TABLE joueur ---
        $this->addSql(<<<'SQL'
            ALTER TABLE joueur
                ADD is_temporaire   TINYINT(1)   NOT NULL DEFAULT 0    COMMENT 'Joueuse créée rapidement pour une rencontre (recrutement, open gym)',
                ADD equipe_ephemere VARCHAR(100) NULL                   COMMENT 'NULL = notre équipe, STRING = nom équipe adverse',
                ADD couleur_maillot VARCHAR(20)  NULL                   COMMENT 'Couleur de maillot pour distinguer en stats live (#hex ou nom)',
                ADD rencontre_origine_id INT      NULL                   COMMENT 'FK rencontre ayant créé cette joueuse éphémère',
                ADD CONSTRAINT FK_joueur_rencontre_origine
                    FOREIGN KEY (rencontre_origine_id)
                    REFERENCES rencontre(id)
                    ON DELETE SET NULL
        SQL);

        $this->addSql('CREATE INDEX IDX_joueur_rencontre_origine ON joueur (rencontre_origine_id)');

        // --- TABLE rencontre ---
        $this->addSql(<<<'SQL'
            ALTER TABLE rencontre
                ADD mode_stats VARCHAR(10) NOT NULL DEFAULT 'full'
                    COMMENT 'full=stats complètes, light=points+rebonds+passes, none=sans stats'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Supprimer la contrainte FK avant DROP COLUMN
        $this->addSql('ALTER TABLE joueur DROP FOREIGN KEY FK_joueur_rencontre_origine');
        $this->addSql('DROP INDEX IDX_joueur_rencontre_origine ON joueur');
        $this->addSql('ALTER TABLE joueur DROP is_temporaire, DROP equipe_ephemere, DROP couleur_maillot, DROP rencontre_origine_id');

        $this->addSql('ALTER TABLE rencontre DROP mode_stats');
    }
}
