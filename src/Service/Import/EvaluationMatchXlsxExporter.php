<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Sport\Rencontre;
use App\Repository\Sport\EvaluationMatchRepository;
use App\Repository\Sport\JoueurRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * [B22b-bis V2 — 15/06/2026] Génère un template Excel "FFBB only" pour saisie
 * en masse des EvaluationMatch d'une rencontre.
 *
 * 🎯 PORTÉE STRICTE : ce template ne contient QUE les colonnes que la FFBB
 *    publie dans ses PDFs officiels (feuille de match + resume + positions).
 *    Les stats avancées (passes décisives, interceptions, rebonds détaillés,
 *    contres, pertes de balle) ne sont PAS dans ce template car la FFBB ne
 *    les capture pas — elles arrivent uniquement via Stats Live MABB en direct
 *    pendant le match.
 *
 * Colonnes du template :
 *   A: joueur_id (caché, technique pour match exact lors import)
 *   B: Nom Prénom
 *   C: N° maillot
 *   D: Starter (1=oui, 0=non)
 *   E: Minutes
 *   F: Points (total)
 *   G: 2pts R / H: 2pts T
 *   I: 3pts R / J: 3pts T
 *   K: LF R / L: LF T
 *   M: Fautes commises
 *   N: Notes coach
 */
class EvaluationMatchXlsxExporter
{
    public function __construct(
        private readonly JoueurRepository $joueurRepo,
        private readonly EvaluationMatchRepository $evalRepo,
    ) {}

    public function exportToTempFile(Rencontre $rencontre): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Stats FFBB');

        // === Métadonnées en haut ===
        $sheet->setCellValue('A1', sprintf(
            'Stats FFBB : %s vs %s — %s',
            $rencontre->getEquipe()?->getNom() ?? '?',
            $rencontre->getAdversaire() ?? '?',
            $rencontre->getDate()?->format('d/m/Y H:i') ?? '?',
        ));
        $sheet->mergeCells('A1:N1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', sprintf(
            'Rencontre ID %d • Lit le PDF FFBB resume_*.pdf — ne remplis QUE ce que le PDF officiel donne',
            $rencontre->getId()
        ));
        $sheet->mergeCells('A2:N2');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);

        // === En-têtes colonnes (ligne 4) — 14 colonnes "FFBB only" ===
        $headers = [
            'A' => 'ID joueur (NE PAS modifier)',
            'B' => 'Joueuse',
            'C' => 'N° maillot',
            'D' => 'Starter (1=oui, 0=non)',
            'E' => 'Minutes',
            'F' => 'Points',
            'G' => '2pts R', 'H' => '2pts T',
            'I' => '3pts R', 'J' => '3pts T',
            'K' => 'LF R', 'L' => 'LF T',
            'M' => 'Fautes',
            'N' => 'Notes coach',
        ];
        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . '4', $label);
        }

        // Style en-tête : MABB red
        $sheet->getStyle('A4:N4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C8102E']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(35);

        // === Lignes joueuses ===
        $joueuses = $this->joueurRepo->findBy(
            ['equipe' => $rencontre->getEquipe(), 'isActive' => true],
            ['nom' => 'ASC', 'prenom' => 'ASC']
        );

        // Pré-charge évals existantes pour pré-remplir (mode incrémental)
        $evalsExistantes = $this->evalRepo->evaluationsRencontre($rencontre);
        $evalsParJoueur = [];
        foreach ($evalsExistantes as $e) {
            $evalsParJoueur[$e->getJoueur()->getId()] = $e;
        }

        $row = 5;
        foreach ($joueuses as $joueur) {
            $eval = $evalsParJoueur[$joueur->getId()] ?? null;
            // Calcul points si on a déjà des tirs marqués (formule Excel native)
            $points = $eval
                ? ($eval->getTirs2ptsReussis() * 2) + ($eval->getTirs3ptsReussis() * 3) + $eval->getLancersReussis()
                : 0;

            $sheet->setCellValue('A' . $row, $joueur->getId());
            $sheet->setCellValue('B' . $row, $joueur->getNom() . ' ' . $joueur->getPrenom());
            $sheet->setCellValue('C' . $row, $joueur->getNumeroMaillot());
            $sheet->setCellValue('D' . $row, $eval && $eval->isStarter() ? 1 : 0);
            $sheet->setCellValue('E' . $row, $eval?->getMinutesJouees() ?? 0);
            $sheet->setCellValue('F' . $row, $points);
            $sheet->setCellValue('G' . $row, $eval?->getTirs2ptsReussis() ?? 0);
            $sheet->setCellValue('H' . $row, $eval?->getTirs2ptsTentes() ?? 0);
            $sheet->setCellValue('I' . $row, $eval?->getTirs3ptsReussis() ?? 0);
            $sheet->setCellValue('J' . $row, $eval?->getTirs3ptsTentes() ?? 0);
            $sheet->setCellValue('K' . $row, $eval?->getLancersReussis() ?? 0);
            $sheet->setCellValue('L' . $row, $eval?->getLancersTentes() ?? 0);
            $sheet->setCellValue('M' . $row, $eval?->getFautesCommises() ?? 0);
            $sheet->setCellValue('N' . $row, $eval?->getNotesCoach() ?? '');
            $row++;
        }

        // Bordures + alignement central
        $lastRow = $row - 1;
        if ($lastRow >= 5) {
            $sheet->getStyle('A5:N' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle('C5:M' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A5:A' . $lastRow)->getFont()->setItalic(true)->getColor()->setRGB('999999');
        }

        // === Notice explicative en bas ===
        $noticeRow = $lastRow + 2;
        $sheet->setCellValue('A' . $noticeRow, '💡 Notice — Stats FFBB only :');
        $sheet->getStyle('A' . $noticeRow)->getFont()->setBold(true);
        $sheet->setCellValue('A' . ($noticeRow + 1), '• Ne modifie PAS la colonne A (ID joueur).');
        $sheet->setCellValue('A' . ($noticeRow + 2), '• Recopie depuis le PDF resume_*.pdf FFBB officiel.');
        $sheet->setCellValue('A' . ($noticeRow + 3), '• Cohérence : tirs réussis ≤ tentés.');
        $sheet->setCellValue('A' . ($noticeRow + 4), '• Points = 2R×2 + 3R×3 + LFR (à recalculer si tu modifies les tirs).');
        $sheet->setCellValue('A' . ($noticeRow + 5), '• Stats AVANCÉES (passes D, interceptions, rebonds, pertes) = Stats Live uniquement (pas FFBB).');
        $sheet->setCellValue('A' . ($noticeRow + 6), '• Ré-importable à volonté : update sans doublon.');

        // Largeurs colonnes
        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(8);
        $sheet->getColumnDimension('D')->setWidth(10);
        $sheet->getColumnDimension('E')->setWidth(8);
        $sheet->getColumnDimension('F')->setWidth(8);
        foreach (range('G', 'L') as $col) {
            $sheet->getColumnDimension($col)->setWidth(7);
        }
        $sheet->getColumnDimension('M')->setWidth(8);
        $sheet->getColumnDimension('N')->setWidth(25);

        $sheet->freezePane('A5');

        $tempFile = tempnam(sys_get_temp_dir(), 'evals_rencontre_' . $rencontre->getId() . '_');
        $tempFile .= '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return $tempFile;
    }

    public function suggestedFilename(Rencontre $rencontre): string
    {
        $adversaire = preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($rencontre->getAdversaire() ?? 'adv'));
        return sprintf(
            'mabb-stats-ffbb-%s-vs-%s.xlsx',
            $rencontre->getDate()?->format('Y-m-d') ?? 'date',
            $adversaire,
        );
    }
}
