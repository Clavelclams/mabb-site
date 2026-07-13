/**
 * pose.js — [Bloc 2, 13/07/2026] la joueuse, enfin visible par le jeu.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI MANQUAIT
 * ═══════════════════════════════════════════════════════════════════════════
 * Le détecteur d'objets voit un ballon. Il ne voit pas la JOUEUSE. Conséquences
 * concrètes, celles que tu as constatées au gymnase :
 *
 *   - Le dribble compte des points « au hasard » : un ballon détecté à l'autre
 *     bout du terrain, une tête ronde, un ballon posé par terre. Le jeu n'a
 *     aucun moyen de savoir si le ballon détecté est CELUI QUE TU TIENS.
 *   - Le tir ne sait pas QUAND tu lâches la balle. Il devine, à partir du moment
 *     où le ballon passe au-dessus d'une ligne. C'est grossier.
 *   - On n'a aucune règle de mesure fiable dans l'image.
 *
 * MediaPipe fournit `PoseLandmarker` : 33 points du corps (poignets, coudes,
 * épaules, hanches, genoux, chevilles), en temps réel, dans le navigateur.
 * Ce fichier l'emballe et n'expose que ce dont les jeux ont besoin.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA RÈGLE DE MESURE, ET C'EST LE POINT LE PLUS SOUS-ESTIMÉ
 * ═══════════════════════════════════════════════════════════════════════════
 * Une largeur d'épaules d'ado fait environ 38 cm. Une fois qu'on la connaît EN
 * PIXELS, on tient une règle posée dans l'image, qui suit la joueuse quand elle
 * s'éloigne ou se rapproche. C'est BEAUCOUP plus fiable que la règle actuelle
 * (la taille de l'arceau), qui est fixe et donc fausse dès que la joueuse
 * change de distance. On s'en sert pour estimer d'où elle tire.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE COÛT, ET LE GARDE-FOU
 * ═══════════════════════════════════════════════════════════════════════════
 * Deux modèles sur le GPU d'un téléphone moyen, ça peut saccader. Et un jeu qui
 * saccade est un jeu qu'on ferme. Donc :
 *   - la pose tourne à CADENCE RÉDUITE (le corps bouge lentement comparé au
 *     ballon : l'analyser 8 fois par seconde suffit largement) ;
 *   - on MESURE son coût réel, et si elle dépasse le budget trop souvent, elle
 *     se COUPE TOUTE SEULE. Les jeux continuent sans elle, en mode dégradé.
 *
 * On mesure, on n'espère pas.
 */

import { PoseLandmarker, FilesetResolver } from "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14";

// Indices des points qui nous intéressent (sur les 33 du modèle).
const NEZ = 0;
const EPAULE_G = 11, EPAULE_D = 12;
const COUDE_G = 13, COUDE_D = 14;
const POIGNET_G = 15, POIGNET_D = 16;
const HANCHE_G = 23, HANCHE_D = 24;
const CHEVILLE_G = 27, CHEVILLE_D = 28;

const VISIBILITE_MIN = 0.5;     // en dessous, le point est deviné : on ne s'y fie pas
const CADENCE_MS = 120;         // ~8 analyses par seconde : le corps ne va pas plus vite
const BUDGET_MS = 40;           // au-delà, l'analyse coûte trop cher pour ce téléphone
const ECHECS_AVANT_ARRET = 12;  // 12 dépassements → on coupe la pose, définitivement
const LARGEUR_EPAULES_M = 0.38; // une ado : ~38 cm d'épaule à épaule

export function creerPose() {
  let landmarker = null;
  let actif = false;         // le modèle est-il chargé et utilisable ?
  let coupe = false;         // s'est-on auto-coupé pour cause de lenteur ?
  let dernierT = 0;
  let dureeMoy = 20;
  let depassements = 0;

  // Le dernier squelette connu, en pixels, dans le repère de la VIDÉO BRUTE
  // (non miroir). C'est à l'appelant d'appliquer le miroir s'il en met un —
  // le dribble le fait, le tir non.
  let corps = null;

  /**
   * Charge le modèle. Ne jette JAMAIS : si ça échoue (réseau, vieux téléphone,
   * WebGL absent), les jeux doivent continuer sans la pose. Un confort qui
   * casse le produit n'est pas un confort.
   */
  async function charger() {
    try {
      const vision = await FilesetResolver.forVisionTasks(
        "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/wasm"
      );
      landmarker = await PoseLandmarker.createFromOptions(vision, {
        baseOptions: {
          // Le modèle « lite » : ~5 Mo, le plus léger des trois. Sur un
          // téléphone de gymnase, c'est le seul raisonnable.
          modelAssetPath:
            "https://storage.googleapis.com/mediapipe-models/pose_landmarker/pose_landmarker_lite/float16/1/pose_landmarker_lite.task",
          delegate: "GPU",
        },
        runningMode: "VIDEO",
        numPoses: 1, // une seule joueuse : celle qui joue. On ne veut pas du public.
      });
      actif = true;
      return true;
    } catch {
      actif = false;
      return false; // le jeu tournera sans la pose, en mode dégradé
    }
  }

  /**
   * À appeler chaque frame. Ne fait un vrai calcul qu'une fois toutes les
   * CADENCE_MS. Retourne le corps courant (ou null).
   */
  function analyser(video, t) {
    if (!actif || coupe || !landmarker) return corps;
    if (t - dernierT < CADENCE_MS) return corps; // trop tôt : on renvoie le dernier connu
    dernierT = t;

    const t0 = performance.now();
    let res;
    try {
      res = landmarker.detectForVideo(video, t);
    } catch {
      return corps; // une frame ratée n'est pas un drame
    }
    const duree = performance.now() - t0;
    dureeMoy = 0.8 * dureeMoy + 0.2 * duree;

    // LE GARDE-FOU : si l'analyse coûte trop cher, trop souvent, on coupe.
    // Mieux vaut un jeu fluide sans pose qu'un jeu intelligent qui saccade.
    if (dureeMoy > BUDGET_MS) {
      depassements++;
      if (depassements >= ECHECS_AVANT_ARRET) {
        coupe = true;
        corps = null;
        return null;
      }
    } else if (depassements > 0) {
      depassements--; // ça respire à nouveau : on pardonne
    }

    const lm = res?.landmarks?.[0];
    if (!lm) { corps = null; return null; }

    const W = video.videoWidth, H = video.videoHeight;
    // Les coordonnées du modèle sont NORMALISÉES (0 → 1). On les remet en pixels.
    const px = (i) => {
      const p = lm[i];
      if (!p || (p.visibility ?? 1) < VISIBILITE_MIN) return null;
      return { x: p.x * W, y: p.y * H, v: p.visibility ?? 1 };
    };

    const epG = px(EPAULE_G), epD = px(EPAULE_D);
    const haG = px(HANCHE_G), haD = px(HANCHE_D);

    // L'ÉCHELLE : combien de pixels pour un mètre, ici et maintenant.
    // Elle change quand la joueuse s'éloigne — c'est justement l'intérêt.
    let pxParMetre = null;
    if (epG && epD) {
      const largeurEpaules = Math.hypot(epD.x - epG.x, epD.y - epG.y);
      if (largeurEpaules > 8) pxParMetre = largeurEpaules / LARGEUR_EPAULES_M;
    }

    corps = {
      t,
      nez: px(NEZ),
      epauleG: epG, epauleD: epD,
      coudeG: px(COUDE_G), coudeD: px(COUDE_D),
      poignetG: px(POIGNET_G), poignetD: px(POIGNET_D),
      hancheG: haG, hancheD: haD,
      chevilleG: px(CHEVILLE_G), chevilleD: px(CHEVILLE_D),
      pxParMetre,
      // Le centre du buste : le point le plus stable du corps, celui qui
      // représente « où est la joueuse ».
      centre:
        epG && epD && haG && haD
          ? {
              x: (epG.x + epD.x + haG.x + haD.x) / 4,
              y: (epG.y + epD.y + haG.y + haD.y) / 4,
            }
          : null,
    };
    return corps;
  }

  /**
   * Le ballon est-il DANS LES MAINS de la joueuse (ou tout près) ?
   * C'est LA question qui manquait au dribble : sans elle, on comptait des
   * points pour un ballon posé au sol ou détecté à l'autre bout du gymnase.
   *
   * @param {{x:number,y:number,r:number}} balle position et rayon (repère vidéo brute)
   * @param {number} toleranceRayons distance max, exprimée en rayons de ballon
   */
  function balleEnMain(balle, toleranceRayons = 3.2) {
    if (!corps || !balle) return false;
    const seuil = Math.max(60, balle.r * toleranceRayons);
    for (const main of [corps.poignetG, corps.poignetD]) {
      if (!main) continue;
      if (Math.hypot(main.x - balle.x, main.y - balle.y) < seuil) return true;
    }
    return false;
  }

  /**
   * Le ballon appartient-il à CETTE joueuse ? Plus large que « en main » : un
   * ballon qui dribble à un mètre d'elle est le sien ; le même ballon à cinq
   * mètres ne l'est pas. Sert à rejeter les ballons du terrain d'à côté.
   */
  function balleAElle(balle, metres = 2.0) {
    if (!corps || !balle) return true; // pas de corps détecté : on ne bloque rien
    if (!corps.centre) return true;
    const echelle = corps.pxParMetre;
    const seuilPx = echelle ? echelle * metres : Math.max(220, balle.r * 9);
    return Math.hypot(corps.centre.x - balle.x, corps.centre.y - balle.y) < seuilPx;
  }

  /**
   * Le geste de tir : un poignet passe AU-DESSUS de l'épaule, ballon en main.
   * C'est le signal du LÂCHER, bien plus net que « le ballon a franchi une
   * ligne ». Il nous donne l'instant zéro du vol, donc une parabole juste.
   */
  function estEnPositionDeTir(balle) {
    if (!corps || !balle) return false;
    const paires = [
      [corps.poignetG, corps.epauleG],
      [corps.poignetD, corps.epauleD],
    ];
    for (const [poignet, epaule] of paires) {
      if (!poignet || !epaule) continue;
      // y augmente vers le BAS : « au-dessus » = y plus petit.
      if (poignet.y < epaule.y - 10 && balleEnMain(balle, 4)) return true;
    }
    return false;
  }

  /** Distance joueuse → point, en MÈTRES (via l'échelle du corps). null si inconnue. */
  function distanceMetres(point) {
    if (!corps?.centre || !corps.pxParMetre || !point) return null;
    return Math.hypot(corps.centre.x - point.x, corps.centre.y - point.y) / corps.pxParMetre;
  }

  /** Dessine le squelette (utile pour montrer à la joueuse qu'elle est bien vue). */
  function dessiner(ctx, miroir = false, largeurCanvas = 0) {
    if (!corps) return;
    const X = (p) => (miroir ? largeurCanvas - p.x : p.x);
    const os = [
      [corps.epauleG, corps.epauleD],
      [corps.epauleG, corps.coudeG], [corps.coudeG, corps.poignetG],
      [corps.epauleD, corps.coudeD], [corps.coudeD, corps.poignetD],
      [corps.epauleG, corps.hancheG], [corps.epauleD, corps.hancheD],
      [corps.hancheG, corps.hancheD],
    ];
    ctx.strokeStyle = "rgba(62,207,142,0.55)";
    ctx.lineWidth = 3;
    ctx.lineCap = "round";
    for (const [p, q] of os) {
      if (!p || !q) continue;
      ctx.beginPath();
      ctx.moveTo(X(p), p.y);
      ctx.lineTo(X(q), q.y);
      ctx.stroke();
    }
    // Les mains, en évidence : c'est par elles que tout passe.
    for (const main of [corps.poignetG, corps.poignetD]) {
      if (!main) continue;
      ctx.fillStyle = "rgba(245,197,66,0.9)";
      ctx.beginPath();
      ctx.arc(X(main), main.y, 7, 0, Math.PI * 2);
      ctx.fill();
    }
  }

  return {
    charger,
    analyser,
    balleEnMain,
    balleAElle,
    estEnPositionDeTir,
    distanceMetres,
    dessiner,
    get corps() { return corps; },
    get disponible() { return actif && !coupe; },
    get coutMs() { return dureeMoy; },
  };
}
