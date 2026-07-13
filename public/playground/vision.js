/**
 * vision.js — [Bloc 1, 13/07/2026] la brique de vision commune aux deux jeux.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI CE FICHIER EXISTE
 * ═══════════════════════════════════════════════════════════════════════════
 * Jusqu'ici, la détection reposait sur UN modèle générique (EfficientDet-Lite,
 * entraîné sur COCO) dont on ne gardait qu'une classe : « sports ball ».
 * Trois plafonds durs :
 *
 *   1. COCO ne connaît PAS l'arceau. D'où les deux taps de calibration :
 *      un contournement, pas une détection.
 *   2. Le modèle confond facilement un ballon avec autre chose de rond et
 *      clair (une tête, un genou, un plot, un ballon du terrain d'à côté).
 *   3. Rien ne sait qu'un ballon en vol suit une PARABOLE. La trajectoire
 *      n'est qu'une suite de points bruités reliés au trait.
 *
 * Ce fichier attaque les trois, sans modèle supplémentaire, avec de la vision
 * « à l'ancienne » : de la couleur, du mouvement, et de la physique.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'IDÉE CENTRALE, ET ELLE EST SIMPLE
 * ═══════════════════════════════════════════════════════════════════════════
 * Le ballon est ORANGE et il BOUGE.
 * L'arceau est ORANGE et il est IMMOBILE.
 *
 * Cette seule différence suffit à séparer les deux. On accumule pendant une
 * seconde les pixels orange qui NE bougent PAS, dans le haut de l'image : ce
 * qui reste, c'est l'arceau. Le ballon, lui, se disqualifie tout seul en
 * bougeant.
 *
 * HONNÊTETÉ SUR LES LIMITES : ce n'est pas un modèle entraîné sur du basket.
 * Un arceau contre un mur orange, un gymnase éclairé au néon jaune, un ballon
 * noir et blanc : ça peut rater. C'est pourquoi la détection auto ne REMPLACE
 * pas les deux taps — elle les PROPOSE, et la joueuse corrige si c'est faux.
 * Une aide qui se trompe et qu'on peut corriger vaut mieux qu'une obligation.
 */

// ───────────────────────────────────────────────────────────────────────────
// 1. LA COULEUR : qu'est-ce qu'un pixel « orange de basket » ?
// ───────────────────────────────────────────────────────────────────────────
/**
 * On reste en RGB (pas de conversion HSV : trop cher à faire sur des dizaines
 * de milliers de pixels par frame, et inutile ici).
 *
 * Un orange de ballon / d'arceau, c'est : du rouge FRANC, du vert MOYEN, du
 * bleu FAIBLE, avec une vraie dominante rouge sur bleu. Les seuils sont larges
 * à dessein : un gymnase mal éclairé écrase les couleurs, et rater le ballon
 * coûte plus cher que d'accepter quelques pixels douteux (le mouvement et la
 * forme feront le tri derrière).
 */
export function estOrange(r, g, b) {
  if (r < 90) return false;              // trop sombre : on ne devine pas
  if (r <= g + 18) return false;         // pas assez rouge par rapport au vert
  if (r <= b + 45) return false;         // pas assez rouge par rapport au bleu
  if (g < b) return false;               // orange = vert AU-DESSUS du bleu (sinon c'est du rose/violet)
  const max = Math.max(r, g, b), min = Math.min(r, g, b);
  if (max - min < 40) return false;      // gris : pas de couleur franche
  return true;
}

/**
 * Proportion de pixels orange dans une zone de l'image. Sert à VÉRIFIER une
 * détection du modèle : si la boîte qu'il propose ne contient presque pas
 * d'orange, ce n'est pas un ballon de basket — c'est une tête, un genou, un
 * plot blanc. On rejette.
 *
 * Retourne un score 0 → 1.
 */
export function scoreOrange(ctx, x, y, w, h) {
  const x0 = Math.max(0, Math.round(x));
  const y0 = Math.max(0, Math.round(y));
  const w0 = Math.max(1, Math.round(Math.min(w, ctx.canvas.width - x0)));
  const h0 = Math.max(1, Math.round(Math.min(h, ctx.canvas.height - y0)));
  if (w0 < 2 || h0 < 2) return 0;

  const d = ctx.getImageData(x0, y0, w0, h0).data;
  let orange = 0, total = 0;
  // On échantillonne 1 pixel sur 4 (pas 1 sur 1) : la précision ne change pas,
  // le coût est divisé par 4. Sur une boîte de 80×80, ça fait 1600 lectures.
  for (let i = 0; i < d.length; i += 16) {
    total++;
    if (estOrange(d[i], d[i + 1], d[i + 2])) orange++;
  }
  return total > 0 ? orange / total : 0;
}

// ───────────────────────────────────────────────────────────────────────────
// 2. L'ARCEAU : orange + IMMOBILE
// ───────────────────────────────────────────────────────────────────────────
const AR_LARGEUR = 160;        // on travaille sur une image minuscule : 160×120
const AR_HAUTEUR = 120;
const AR_SEUIL_MVT = 18;       // au-delà, le pixel a « bougé » → ce n'est pas l'arceau
const AR_FRAMES = 45;          // ~1,5 s d'accumulation à 30 fps
const AR_MIN_VOTES = 0.55;     // un pixel doit être orange+immobile 55 % du temps
const AR_ZONE_HAUTE = 0.72;    // l'arceau est dans les 72 % supérieurs de l'image

/**
 * Crée un détecteur d'arceau. On l'alimente frame après frame ; au bout de
 * ~1,5 seconde, il propose un segment.
 *
 * MÉTHODE :
 *   1. On réduit l'image à 160×120 (l'arceau reste parfaitement visible à
 *      cette taille, et on divise le coût par 60).
 *   2. Pour chaque pixel : est-il orange ? a-t-il bougé depuis la frame
 *      précédente ? On incrémente un compteur de « votes » si orange ET
 *      immobile.
 *   3. Après N frames, on garde les pixels très votés, on trouve le plus gros
 *      amas connecté (flood fill), et on en tire un segment horizontal :
 *      son bord gauche et son bord droit. C'est l'arceau vu depuis n'importe
 *      quel angle — de face c'est une ellipse, de profil une barre, mais dans
 *      les deux cas les EXTRÉMITÉS sont ce qui nous intéresse.
 */
export function creerDetecteurArceau() {
  const cv = document.createElement("canvas");
  cv.width = AR_LARGEUR;
  cv.height = AR_HAUTEUR;
  const cx = cv.getContext("2d", { willReadFrequently: true });

  const n = AR_LARGEUR * AR_HAUTEUR;
  const votes = new Uint16Array(n);      // combien de fois « orange et immobile »
  const lumPrec = new Uint8Array(n);     // luminance de la frame précédente
  let frames = 0;
  let pret = false;

  /** À appeler une fois par frame pendant la phase de calibration. */
  function alimenter(video) {
    cx.drawImage(video, 0, 0, AR_LARGEUR, AR_HAUTEUR);
    const d = cx.getImageData(0, 0, AR_LARGEUR, AR_HAUTEUR).data;

    for (let p = 0, i = 0; p < n; p++, i += 4) {
      const r = d[i], g = d[i + 1], b = d[i + 2];
      const lum = (r * 3 + g * 4 + b) >> 3;
      const bouge = frames > 0 && Math.abs(lum - lumPrec[p]) > AR_SEUIL_MVT;
      lumPrec[p] = lum;

      // La règle centrale : orange ET immobile.
      if (!bouge && estOrange(r, g, b)) {
        votes[p]++;
      } else if (bouge && votes[p] > 0) {
        // Un pixel qui bouge PERD ses votes, vite : c'est comme ça que le
        // ballon (orange mais mobile) se disqualifie tout seul, même s'il a
        // stationné une demi-seconde au même endroit.
        votes[p] = Math.max(0, votes[p] - 3);
      }
    }

    frames++;
    if (frames >= AR_FRAMES) pret = true;
  }

  /**
   * Le résultat, ou null si on n'a rien trouvé de convaincant.
   * Coordonnées rendues à l'échelle de la VIDÉO (pas du 160×120 interne).
   *
   * @returns {{a:{x,y}, b:{x,y}, len:number, confiance:number} | null}
   */
  function proposer(videoW, videoH) {
    if (!pret) return null;

    const seuil = Math.max(4, Math.round(AR_FRAMES * AR_MIN_VOTES));
    const limiteY = Math.round(AR_HAUTEUR * AR_ZONE_HAUTE);

    // Masque des pixels retenus (uniquement dans le haut de l'image : un
    // arceau au sol, ça n'existe pas, et ça évite de confondre avec le short
    // orange d'une joueuse assise).
    const masque = new Uint8Array(n);
    let retenus = 0;
    for (let y = 0; y < limiteY; y++) {
      for (let x = 0; x < AR_LARGEUR; x++) {
        const p = y * AR_LARGEUR + x;
        if (votes[p] >= seuil) { masque[p] = 1; retenus++; }
      }
    }
    if (retenus < 12) return null; // rien d'orange et de stable là-haut

    // Le plus gros amas connecté (flood fill 8-voisins, pile explicite : la
    // récursion exploserait sur une grosse zone).
    let meilleur = null;
    const vu = new Uint8Array(n);
    const pile = [];

    for (let p0 = 0; p0 < n; p0++) {
      if (!masque[p0] || vu[p0]) continue;

      pile.length = 0;
      pile.push(p0);
      vu[p0] = 1;

      let taille = 0, minX = AR_LARGEUR, maxX = -1, sommeY = 0;

      while (pile.length) {
        const p = pile.pop();
        const x = p % AR_LARGEUR;
        const y = (p / AR_LARGEUR) | 0;

        taille++;
        if (x < minX) minX = x;
        if (x > maxX) maxX = x;
        sommeY += y;

        for (let dy = -1; dy <= 1; dy++) {
          for (let dx = -1; dx <= 1; dx++) {
            const nx = x + dx, ny = y + dy;
            if (nx < 0 || ny < 0 || nx >= AR_LARGEUR || ny >= limiteY) continue;
            const q = ny * AR_LARGEUR + nx;
            if (masque[q] && !vu[q]) { vu[q] = 1; pile.push(q); }
          }
        }
      }

      if (!meilleur || taille > meilleur.taille) {
        meilleur = { taille, minX, maxX, yMoyen: sommeY / taille };
      }
    }

    if (!meilleur) return null;

    const largeur = meilleur.maxX - meilleur.minX;
    // Un arceau, c'est LARGE et PLAT. Trop étroit = un reflet, un plot, un
    // bout de maillot. On refuse plutôt que de proposer n'importe quoi.
    if (largeur < 10 || meilleur.taille < 12) return null;

    const sx = videoW / AR_LARGEUR;
    const sy = videoH / AR_HAUTEUR;
    const a = { x: meilleur.minX * sx, y: meilleur.yMoyen * sy };
    const b = { x: meilleur.maxX * sx, y: meilleur.yMoyen * sy };
    const len = Math.hypot(b.x - a.x, b.y - a.y);

    // La confiance : combien l'amas est-il « plein » et large. Sert à décider
    // si on propose franchement, ou si on demande confirmation.
    const densite = meilleur.taille / Math.max(1, largeur * 3);
    const confiance = Math.min(1, (largeur / 40) * 0.6 + Math.min(1, densite) * 0.4);

    return { a, b, len, confiance };
  }

  /** Le tel a bougé, ou la joueuse veut recaler : on repart de zéro. */
  function reinitialiser() {
    votes.fill(0);
    frames = 0;
    pret = false;
  }

  /** Progression 0 → 1 (pour afficher une jauge pendant la calibration). */
  function progression() {
    return Math.min(1, frames / AR_FRAMES);
  }

  return { alimenter, proposer, reinitialiser, progression };
}

// ───────────────────────────────────────────────────────────────────────────
// 3. LA PHYSIQUE : un ballon en vol suit une parabole
// ───────────────────────────────────────────────────────────────────────────
/**
 * Ajuste une parabole sur une trajectoire, par MOINDRES CARRÉS.
 *
 * POURQUOI : à l'écran, un ballon en vol libre décrit
 *     x(t) = x0 + vx·t                  (horizontal : vitesse constante)
 *     y(t) = y0 + vy·t + ½·g·t²         (vertical : la gravité accélère)
 * On ajuste donc une DROITE sur x(t) et une PARABOLE sur y(t).
 *
 * CE QUE ÇA APPORTE, ET C'EST ÉNORME :
 *   - Une courbe LISSE au lieu d'une suite de points qui tremblent.
 *   - On peut PROLONGER la trajectoire à travers une occlusion (le ballon
 *     passe derrière un panneau, une joueuse) : la physique comble le trou.
 *   - On obtient des métriques de COACH, celles que vendent les apps
 *     spécialisées : angle de sortie de main, hauteur de l'apex, et surtout
 *     l'ANGLE D'ENTRÉE dans l'arceau (l'idéal se situe autour de 45° : trop
 *     tendu, le ballon ne « rentre » pas, il percute).
 *
 * ATTENTION, une limite honnête : on travaille en PIXELS, dans une image en
 * perspective. Les angles obtenus sont donc des angles À L'ÉCRAN, pas les
 * angles réels dans l'espace. Filmé de profil (le bon cadrage, celui qu'on
 * demande), ils en sont très proches. Filmé de biais, ils dérivent. On le dira
 * à la joueuse plutôt que de lui vendre une précision qu'on n'a pas.
 *
 * @param {Array<{x:number,y:number,t:number}>} pts au moins 4 points
 * @returns {null | { x0,vx, y0,vy,ay, t0, evaluer:(t)=>({x,y}), angleEn:(t)=>number, apex:()=>({x,y,t}) }}
 */
export function ajusterParabole(pts) {
  if (!pts || pts.length < 4) return null;

  // On recentre le temps sur le premier point : les nombres restent petits,
  // la résolution du système reste stable numériquement.
  const t0 = pts[0].t;
  const n = pts.length;

  // ── Horizontal : régression linéaire x = x0 + vx·t ──────────────────────
  let sT = 0, sX = 0, sTT = 0, sTX = 0;
  for (const p of pts) {
    const t = (p.t - t0) / 1000; // en secondes : les coefficients restent lisibles
    sT += t; sX += p.x; sTT += t * t; sTX += t * p.x;
  }
  const denL = n * sTT - sT * sT;
  if (Math.abs(denL) < 1e-9) return null;
  const vx = (n * sTX - sT * sX) / denL;
  const x0 = (sX - vx * sT) / n;

  // ── Vertical : régression quadratique y = y0 + vy·t + a·t² ──────────────
  // Système normal 3×3 résolu par élimination de Gauss. C'est du calcul de
  // lycée, mais c'est exactement ce qu'il faut : personne n'a besoin d'une
  // bibliothèque de 200 ko pour ça.
  let s0 = n, s1 = 0, s2 = 0, s3 = 0, s4 = 0;
  let b0 = 0, b1 = 0, b2 = 0;
  for (const p of pts) {
    const t = (p.t - t0) / 1000;
    const t2 = t * t;
    s1 += t; s2 += t2; s3 += t2 * t; s4 += t2 * t2;
    b0 += p.y; b1 += t * p.y; b2 += t2 * p.y;
  }
  const M = [
    [s0, s1, s2, b0],
    [s1, s2, s3, b1],
    [s2, s3, s4, b2],
  ];
  // Élimination de Gauss avec pivot partiel.
  for (let i = 0; i < 3; i++) {
    let piv = i;
    for (let k = i + 1; k < 3; k++) if (Math.abs(M[k][i]) > Math.abs(M[piv][i])) piv = k;
    if (Math.abs(M[piv][i]) < 1e-9) return null; // système dégénéré (points alignés dans le temps)
    [M[i], M[piv]] = [M[piv], M[i]];
    for (let k = i + 1; k < 3; k++) {
      const f = M[k][i] / M[i][i];
      for (let j = i; j < 4; j++) M[k][j] -= f * M[i][j];
    }
  }
  const c = [0, 0, 0];
  for (let i = 2; i >= 0; i--) {
    let s = M[i][3];
    for (let j = i + 1; j < 3; j++) s -= M[i][j] * c[j];
    c[i] = s / M[i][i];
  }
  const [y0, vy, ay] = c;

  // Contrôle de bon sens : à l'écran, y augmente vers le BAS, donc la gravité
  // donne une accélération POSITIVE. Si l'ajustement sort une accélération
  // négative ou nulle, ce n'est pas un vol libre (c'est un dribble, une passe,
  // du bruit). On refuse : mieux vaut pas de courbe qu'une courbe fausse.
  if (!(ay > 0)) return null;

  const evaluer = (t) => {
    const s = (t - t0) / 1000;
    return { x: x0 + vx * s, y: y0 + vy * s + ay * s * s };
  };

  /**
   * L'angle de la trajectoire à l'instant t, en degrés au-dessus de
   * l'horizontale. Positif = le ballon monte, négatif = il descend.
   * L'angle d'ENTRÉE au panier, c'est cette valeur (négative) prise à
   * l'instant où le ballon coupe l'arceau, en valeur absolue.
   */
  const angleEn = (t) => {
    const s = (t - t0) / 1000;
    const dx = vx;
    const dy = vy + 2 * ay * s; // dérivée de y : la pente à cet instant
    // -dy parce que l'axe y de l'écran pointe vers le bas.
    return (Math.atan2(-dy, Math.abs(dx)) * 180) / Math.PI;
  };

  /** Le sommet de la courbe : là où le ballon arrête de monter. */
  const apex = () => {
    const s = -vy / (2 * ay); // dérivée nulle
    return { x: x0 + vx * s, y: y0 + vy * s + ay * s * s, t: t0 + s * 1000 };
  };

  return { x0, vx, y0, vy, ay, t0, evaluer, angleEn, apex };
}

/**
 * Qualité de l'ajustement (erreur moyenne en pixels). Sert de garde-fou : si
 * la parabole colle mal aux points, c'est que ce n'était pas un vol libre
 * (rebond, dribble, ballon tenu en main). On ne prétend rien dans ce cas.
 */
export function erreurParabole(pts, par) {
  if (!par || !pts?.length) return Infinity;
  let somme = 0;
  for (const p of pts) {
    const q = par.evaluer(p.t);
    somme += Math.hypot(p.x - q.x, p.y - q.y);
  }
  return somme / pts.length;
}
