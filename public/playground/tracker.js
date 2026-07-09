/**
 * tracker.js — [10/07/2026] suiveur de mouvement PARTAGÉ des jeux Playground
 * (importé par dribble.html et tir.html : `import { creerSuiveur } from "./tracker.js"`).
 *
 * LE PROBLÈME QU'IL RÉSOUT (retour terrain du 09/07, gymnase) :
 * MediaPipe (EfficientDet) réduit l'image à ~320-448 px avant d'analyser.
 * Un ballon shooté à 6 mètres ne fait plus que quelques pixels + du flou de
 * mouvement → le modèle ne le voit qu'une frame sur trois ou quatre. La
 * trajectoire était donc hachée, et des tirs entiers passaient à la trappe.
 *
 * LA SOLUTION : entre deux détections « sûres » du modèle, on suit le ballon
 * NOUS-MÊMES, en pleine résolution, mais seulement dans une PETITE fenêtre
 * (ROI) autour de la position prédite :
 *   1. on prédit où devrait être le ballon (dernière position + vitesse) ;
 *   2. on compare la fenêtre de l'image actuelle à celle de l'image
 *      précédente (différence de luminance pixel à pixel) ;
 *   3. le « centre de gravité » des pixels qui ont bougé = le ballon
 *      (c'est l'objet le plus rapide de la fenêtre — le flou de mouvement,
 *      ennemi du modèle, devient ici notre ALLIÉ : plus ça bouge, mieux
 *      on le voit).
 * Coût : quelques milliers de pixels par frame (la ROI), pas l'image entière
 * → ça tourne à 60 fps même sur un tel moyen.
 *
 * RÈGLE DE CONFIANCE : la détection modèle fait toujours FOI (elle recale le
 * suiveur). Le suiveur ne fait que COMBLER LES TROUS, et il abandonne
 * au bout de MAX_SANS_DETECTION_MS sans confirmation du modèle — on préfère
 * perdre le ballon que suivre n'importe quoi (un bras, une coéquipière…).
 */

// Demi-résolution : suffisant pour un centroïde, 4× moins de pixels à lire.
const ECHELLE = 0.5;
// Seuil de différence de luminance (0-255) pour dire « ce pixel a bougé ».
const SEUIL_DIFF = 26;
// En dessous de ce nombre de pixels en mouvement, la ROI est « calme » → pas de ballon.
const MIN_PIXELS_MOUVEMENT = 12;
// Sans confirmation du MODÈLE pendant ce temps, le suiveur s'arrête (anti-dérive).
const MAX_SANS_DETECTION_MS = 700;

export function creerSuiveur() {
  // Deux canvas hors écran : l'image courante et la précédente, en demi-rés.
  // `willReadFrequently` : indique au navigateur qu'on va lire les pixels
  // souvent → il garde le canvas en mémoire CPU (getImageData bien plus rapide).
  const cvA = document.createElement("canvas");
  const cvB = document.createElement("canvas");
  const cxA = cvA.getContext("2d", { willReadFrequently: true });
  const cxB = cvB.getContext("2d", { willReadFrequently: true });
  let courant = { cv: cvA, cx: cxA }, precedent = { cv: cvB, cx: cxB };
  let framePrete = false;

  // État du suivi : position/vitesse en coordonnées PLEINE résolution.
  let pos = null;            // { x, y }
  let vel = { x: 0, y: 0 };  // px / ms
  let rayon = 30;            // rayon estimé du ballon (px pleine rés)
  let derniereDetection = 0; // dernière confirmation du MODÈLE
  let dernierPoint = 0;      // dernier point produit (modèle OU mouvement)

  /** À appeler UNE fois par frame vidéo, avant detection/suivi. */
  function nouvelleFrame(video) {
    if (cvA.width === 0) {
      cvA.width = cvB.width = Math.round(video.videoWidth * ECHELLE);
      cvA.height = cvB.height = Math.round(video.videoHeight * ECHELLE);
    }
    // L'image « courante » de la frame passée devient la « précédente ».
    [courant, precedent] = [precedent, courant];
    courant.cx.drawImage(video, 0, 0, courant.cv.width, courant.cv.height);
    framePrete = cvA.width > 0 && cvB.width > 0;
  }

  /** Le MODÈLE a vu le ballon → on recale tout (position, vitesse, rayon). */
  function confirmerDetection(x, y, r, t) {
    if (pos && dernierPoint > 0) {
      const dt = Math.max(1, t - dernierPoint);
      // Vitesse lissée (70 % nouvelle mesure, 30 % ancienne) : amortit le
      // bruit de la boîte de détection qui « tremble » d'une frame à l'autre.
      vel = { x: 0.7 * ((x - pos.x) / dt) + 0.3 * vel.x, y: 0.7 * ((y - pos.y) / dt) + 0.3 * vel.y };
    } else {
      vel = { x: 0, y: 0 };
    }
    pos = { x, y };
    rayon = Math.max(12, r);
    derniereDetection = t;
    dernierPoint = t;
  }

  /**
   * Le modèle n'a RIEN vu cette frame → on tente le suivi par mouvement.
   * Retourne { x, y } (pleine résolution) ou null si on ne trouve rien de
   * fiable. Ne « voit » jamais plus loin que la fenêtre autour de la
   * prédiction : pas de téléportation possible.
   */
  function suivreParMouvement(t) {
    if (!pos || !framePrete) return null;
    if (t - derniereDetection > MAX_SANS_DETECTION_MS) { pos = null; return null; }

    // 1. Prédiction balistique simple : dernière position + vitesse × dt.
    const dt = t - dernierPoint;
    const px = pos.x + vel.x * dt;
    const py = pos.y + vel.y * dt;

    // 2. Fenêtre de recherche autour de la prédiction (en demi-rés).
    const demi = Math.max(24, rayon * 2.2) * ECHELLE;
    const x0 = Math.max(0, Math.round(px * ECHELLE - demi));
    const y0 = Math.max(0, Math.round(py * ECHELLE - demi));
    const w = Math.min(courant.cv.width - x0, Math.round(demi * 2));
    const h = Math.min(courant.cv.height - y0, Math.round(demi * 2));
    if (w < 8 || h < 8) { return null; }

    const a = courant.cx.getImageData(x0, y0, w, h).data;
    const b = precedent.cx.getImageData(x0, y0, w, h).data;

    // 3. Centroïde des pixels dont la luminance a changé (= qui ont bougé).
    let somme = 0, sx = 0, sy = 0;
    for (let i = 0, p = 0; i < a.length; i += 4, p++) {
      // Luminance approx (pondération verte dominante, entiers → rapide).
      const la = (a[i] * 3 + a[i + 1] * 4 + a[i + 2]) >> 3;
      const lb = (b[i] * 3 + b[i + 1] * 4 + b[i + 2]) >> 3;
      const d = la > lb ? la - lb : lb - la;
      if (d > SEUIL_DIFF) {
        somme++;
        sx += p % w;
        sy += (p / w) | 0;
      }
    }
    if (somme < MIN_PIXELS_MOUVEMENT) return null; // fenêtre calme : pas de ballon ici

    // 4. Retour en coordonnées pleine résolution + mise à jour de l'état.
    const nx = (x0 + sx / somme) / ECHELLE;
    const ny = (y0 + sy / somme) / ECHELLE;
    const dtP = Math.max(1, t - dernierPoint);
    vel = { x: 0.6 * ((nx - pos.x) / dtP) + 0.4 * vel.x, y: 0.6 * ((ny - pos.y) / dtP) + 0.4 * vel.y };
    pos = { x: nx, y: ny };
    dernierPoint = t;
    return { x: nx, y: ny };
  }

  /** Le suiveur a-t-il encore un ballon « vivant » ? */
  function actif(t) {
    return pos !== null && t - derniereDetection <= MAX_SANS_DETECTION_MS;
  }

  return { nouvelleFrame, confirmerDetection, suivreParMouvement, actif };
}
