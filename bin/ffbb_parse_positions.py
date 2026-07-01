#!/usr/bin/env python3
"""
FFBB positions_tirs.pdf parser — v2
Extrait les tirs réussis de chaque joueuse MABB et exporte en JSON.

Usage:
  python3 parse_positions_tirs.py <pdf_path> --club "METROPOLE AMIENOISE" [--verbose]

Structure PDF e-Marque (A4 = 595×842 pts, 2 joueuses/page):
  - Image 32×32px : marqueur tir réussi ⊙ (placé à chaque tir)
  - Image 506×470px : template terrain (basket ring JPEG, réutilisé ×12/page)

Par page :
  Slot 0 → terrain agrégé : Rect(9, 171, 258, 403)
  Slot 1 → terrain agrégé : Rect(9, 478, 258, 710)

Normalisation dans le terrain agrégé :
  norm_x = (cx - 9) / 249   [0=gauche, 1=droite]
  norm_y = (cy - top) / 232  [0=panier (haut), 1=milieu terrain (bas)]

Output JSON:
  [{ "nom": "SANO", "prenom_initial": "S", "page": 9, "slot": 1,
     "norm_x": 0.396, "norm_y": 0.095 }, ...]
"""

import fitz
import json
import sys
import re
import os
from pathlib import Path

os.environ.setdefault("TESSDATA_PREFIX", "/usr/share/tesseract-ocr/4.00/tessdata")

try:
    import pytesseract
    from PIL import Image
    import io
    HAS_OCR = True
except ImportError:
    HAS_OCR = False
    print("[WARN] pytesseract/Pillow manquant → noms non extraits", file=sys.stderr)

# ─── Géométrie ────────────────────────────────────────────────────────────────

AGGREGATE_COURTS = [
    (0, fitz.Rect(9, 171, 258, 403)),
    (1, fitz.Rect(9, 478, 258, 710)),
]

PLAYER_NAME_BANDS = [
    (0, fitz.Rect(0, 130, 595, 170)),
    (1, fitz.Rect(0, 440, 595, 477)),
]

OCR_WHITELIST = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-.() "


# ─── Helpers ─────────────────────────────────────────────────────────────────

def find_marker_xref(page: fitz.Page) -> int | None:
    """Retourne le xref de l'image 32×32 (marqueur ⊙) ou None."""
    for item in page.get_images(full=True):
        xref = item[0]
        info = page.parent.extract_image(xref)
        if info["width"] == 32 and info["height"] == 32:
            return xref
    return None


def ocr_band(page: fitz.Page, rect: fitz.Rect) -> str:
    """OCR d'une bande horizontale (rendu ×4, eng)."""
    if not HAS_OCR:
        return ""
    try:
        pix = page.get_pixmap(matrix=fitz.Matrix(4, 4), clip=rect)
        img = Image.open(io.BytesIO(pix.tobytes("png")))
        txt = pytesseract.image_to_string(
            img, lang="eng",
            config=f"--psm 7 -c tessedit_char_whitelist='{OCR_WHITELIST}'"
        ).strip()
        # Nettoyer les artefacts de fin
        txt = re.sub(r'\s*-\s*-\s*$', '', txt).strip()
        return txt
    except Exception as e:
        return f"OCR_ERR:{e}"


def parse_player_header(raw: str) -> dict:
    """
    Extrait nom, prenom_initial, club, is_equipe depuis le texte OCR.

    Exemples :
      "GUELFAT C.- METROPOLE AMIENOISE BASKETBALL (B)"
      "EQUIPE METROPOLE AMIENOISE BASKETBALL (B)"
      "MERDA M.- ESC TERGNIER (A)"
    """
    result = {
        "raw": raw,
        "is_equipe": False,
        "nom": None,
        "prenom_initial": None,
        "club": None,
    }
    raw = raw.strip()

    # Entrée équipe (agrégat) ?
    if raw.upper().startswith("EQUIPE"):
        result["is_equipe"] = True
        # Club = tout ce qui suit "EQUIPE "
        result["club"] = re.sub(r'^EQUIPE\s+', '', raw, flags=re.I).strip()
        return result

    # Séparateur nom ← → club
    sep = re.search(r'\s*-\s*', raw)
    if sep:
        player_part = raw[:sep.start()].strip()
        result["club"] = raw[sep.end():].strip()
    else:
        player_part = raw

    # Décomposer "NOM F." ou "NOM COMPOSE F." (noms composés : BEN SALAH O., VAN POELVOORDE S.)
    m = re.match(r'^(.*\S)\s+([A-Z])\.?\s*$', player_part.strip())
    if m:
        result["nom"] = m.group(1).strip()
        result["prenom_initial"] = m.group(2)
    else:
        result["nom"] = player_part  # fallback

    return result


def normalize_in_court(cx: float, cy: float, court: fitz.Rect) -> tuple[float, float]:
    nx = (cx - court.x0) / (court.x1 - court.x0)
    ny = (cy - court.y0) / (court.y1 - court.y0)
    return round(max(0.0, min(1.0, nx)), 4), round(max(0.0, min(1.0, ny)), 4)


# ─── Parser principal ────────────────────────────────────────────────────────

def parse_pdf(pdf_path: str, club_filter: str | None = None, verbose: bool = False) -> list[dict]:
    """
    Retourne la liste des tirs réussis (terrain agrégé uniquement).

    Si club_filter est fourni, ne retourne que les joueuses dont le club
    contient cette chaîne (insensible à la casse).
    """
    doc = fitz.open(pdf_path)
    shots = []

    for page_num, page in enumerate(doc):
        marker_xref = find_marker_xref(page)
        if marker_xref is None:
            continue

        marker_rects = list(page.get_image_rects(marker_xref))

        # OCR des headers
        players = {}
        for slot, band_rect in PLAYER_NAME_BANDS:
            raw = ocr_band(page, band_rect)
            info = parse_player_header(raw)
            players[slot] = info
            if verbose:
                print(f"  [p{page_num+1}|s{slot}] {raw!r} → nom={info['nom']!r} club={info['club']!r} equipe={info['is_equipe']}")

        # Filtrer les marqueurs dans les terrains agrégés
        for slot, court in AGGREGATE_COURTS:
            player = players.get(slot, {})

            # Skip équipe (agrégat)
            if player.get("is_equipe"):
                if verbose:
                    print(f"  [p{page_num+1}|s{slot}] SKIP équipe agrégat")
                continue

            # Filtre club si demandé
            if club_filter and player.get("club"):
                if club_filter.lower() not in player["club"].lower():
                    if verbose:
                        print(f"  [p{page_num+1}|s{slot}] SKIP autre club: {player['club']!r}")
                    continue

            # Collecter les marqueurs dans ce terrain
            n = 0
            for mrect in marker_rects:
                cx = (mrect.x0 + mrect.x1) / 2
                cy = (mrect.y0 + mrect.y1) / 2
                if court.contains(fitz.Point(cx, cy)):
                    nx, ny = normalize_in_court(cx, cy, court)
                    shots.append({
                        "page": page_num + 1,
                        "slot": slot,
                        "nom": player.get("nom", ""),
                        "prenom_initial": player.get("prenom_initial", ""),
                        "club": player.get("club", ""),
                        "norm_x": nx,
                        "norm_y": ny,
                        "raw_x": round(cx, 2),
                        "raw_y": round(cy, 2),
                    })
                    n += 1

            if verbose:
                print(f"  [p{page_num+1}|s{slot}] {player.get('nom','?')}: {n} tirs réussis")

    doc.close()
    return shots


# ─── CLI ─────────────────────────────────────────────────────────────────────

def main():
    args = sys.argv[1:]
    if not args or "--help" in args:
        print(__doc__)
        sys.exit(0)

    pdf_path = args[0]
    verbose = "--verbose" in args
    club_filter = None
    if "--club" in args:
        i = args.index("--club")
        club_filter = args[i + 1]

    print(f"=== Parsing: {Path(pdf_path).name} ===", file=sys.stderr)
    if club_filter:
        print(f"    Filtre club: {club_filter!r}", file=sys.stderr)

    shots = parse_pdf(pdf_path, club_filter=club_filter, verbose=verbose)

    # Résumé par joueuse
    from collections import defaultdict
    by_player: dict = defaultdict(list)
    for s in shots:
        key = f"{s['nom']} {s['prenom_initial']}."
        by_player[key].append(s)

    print(f"\n=== {len(shots)} tirs réussis ===", file=sys.stderr)
    for name, ps in sorted(by_player.items()):
        print(f"  {name}: {len(ps)} tirs", file=sys.stderr)

    # Sortie JSON sur stdout (pour pipe vers PHP)
    print(json.dumps(shots, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
