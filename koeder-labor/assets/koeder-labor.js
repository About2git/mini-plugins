/*! Köder-Labor – Köderwahl für deutsche Süßwasser-Raubfische. Keine Abhängigkeiten. */
(function () {
	'use strict';

	var root = document.getElementById('koeder-labor');
	if (!root) { return; }

	/* ------------------------------------------------------------------ *
	 * Hilfsfunktionen
	 * ------------------------------------------------------------------ */
	var clamp = function (v, a, b) { return Math.min(b, Math.max(a, v)); };
	var lerp = function (a, b, t) { return a + (b - a) * t; };
	var smooth = function (t) { t = clamp(t, 0, 1); return t * t * (3 - 2 * t); };
	var $ = function (sel, ctx) { return (ctx || root).querySelector(sel); };
	var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); };
	var de = function (n, d) {
		d = d || 0;
		try { return n.toLocaleString('de-DE', { minimumFractionDigits: d, maximumFractionDigits: d }); }
		catch (e) { return n.toFixed(d).replace('.', ','); }
	};
	var pick = function (arr) { return arr[Math.floor(Math.random() * arr.length)]; };
	var shuffle = function (arr) {
		var a = arr.slice(), i, j, t;
		for (i = a.length - 1; i > 0; i--) { j = Math.floor(Math.random() * (i + 1)); t = a[i]; a[i] = a[j]; a[j] = t; }
		return a;
	};
	var hex2rgb = function (h) { return [parseInt(h.substr(1, 2), 16), parseInt(h.substr(3, 2), 16), parseInt(h.substr(5, 2), 16)]; };
	var rgb2css = function (c) { return 'rgb(' + Math.round(clamp(c[0], 0, 255)) + ',' + Math.round(clamp(c[1], 0, 255)) + ',' + Math.round(clamp(c[2], 0, 255)) + ')'; };
	var rgb2hex = function (c) {
		return '#' + c.map(function (v) { var s = Math.round(clamp(v, 0, 255)).toString(16); return s.length < 2 ? '0' + s : s; }).join('');
	};
	var store = {
		get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
		set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* privater Modus */ } }
	};

	/* ------------------------------------------------------------------ *
	 * Daten
	 * ------------------------------------------------------------------ */
	var SP = {
		hecht:   { n: 'Hecht',       size: [10, 20], spd: 0,    tmin: 2,  tmax: 24, note: 'Lauerjäger mit Sichtjagd. Er greift Silhouetten an, die sich klar vom Hintergrund abheben, und nimmt große Köder. Wegen der Zähne gehören Stahl-, Titan- oder dickes Fluorocarbon-Vorfach ans Ende der Schnur.' },
		zander:  { n: 'Zander',      size: [8, 13],  spd: -0.1, tmin: 3,  tmax: 26, note: 'Seine Augen sind extrem lichtempfindlich (Tapetum lucidum). Er jagt in Dämmerung und trübem Wasser und meidet grelles Licht. Tagsüber steht er oft grundnah an Kanten und Hindernissen.' },
		barsch:  { n: 'Flussbarsch', size: [4, 8],   spd: 0,    tmin: 3,  tmax: 26, note: 'Tagaktiver Schwarmjäger. Er treibt Kleinfische an Kanten und Strukturen zusammen. Kleine Köder bringen viele Bisse, mit der Köderlänge wählst du die Fischgröße.' },
		wels:    { n: 'Wels',        size: [15, 30], spd: -0.05, tmin: 12, tmax: 30, note: 'Dämmerungs- und nachtaktiv. Er ortet Beute vor allem über Seitenlinie und Barteln. Druckwelle und Vibration sind wichtiger als die Farbe. Unter etwa 12 °C ist er kaum aktiv.' },
		rapfen:  { n: 'Rapfen',      size: [6, 11],  spd: 0.25, tmin: 5,  tmax: 26, note: 'Schneller Freiwasserjäger. Er attackiert Kleinfischschwärme dicht unter der Oberfläche und steht an Strömungskanten, Wehren und Buhnenköpfen.' },
		forelle: { n: 'Bachforelle', size: [4, 8],   spd: 0.05, tmin: 2,  tmax: 17, note: 'Standorttreu hinter Steinen und unter Unterständen, sehr scheu. Kleine, natürliche Köder und ein vorsichtiger Anwurf von unterhalb des Standplatzes funktionieren am besten.' }
	};
	var SP_LIST = [['hecht', 'Hecht'], ['zander', 'Zander'], ['barsch', 'Flussbarsch'], ['wels', 'Wels'], ['rapfen', 'Rapfen'], ['forelle', 'Bachforelle']];
	var SP_ODD = { wels: ['bach', 'teich'], zander: ['bach', 'teich'], rapfen: ['see', 'teich', 'bach', 'talsperre'], forelle: ['kanal', 'teich', 'see'] };

	var WATER = {
		see:       { n: 'See / Baggersee',   s: 'stehend, 3–15 m', maxD: 12, cur: 0,    note: 'Stehendes Gewässer: Der Köder arbeitet nur durch deine Führung, Strömung hilft nicht mit.' },
		talsperre: { n: 'Talsperre',         s: 'tief, oft klar',   maxD: 25, cur: 0,    note: 'Tiefe, oft klare Talsperre: Fische stehen an Kanten, Steilufern und im Freiwasser über Baumresten.' },
		fluss:     { n: 'Fluss',             s: 'Strömung, Buhnen', maxD: 6,  cur: 1,    note: 'Strömung drückt auf den Köder: Gewicht erhöhen, Kehrwasser und Strömungskanten gezielt befischen.' },
		kanal:     { n: 'Kanal / Hafen',     s: 'Spundwand, Brücken', maxD: 5, cur: 0.25, note: 'Kanäle sind gleichmäßig tief: Fische stehen an Spundwänden, Brückenpfeilern, Schiffsliegeplätzen und Einmündungen.' },
		bach:      { n: 'Bach / kl. Fluss',  s: 'Gumpen, Kehrwasser', maxD: 2, cur: 1,   note: 'Flaches, schnelles Wasser: kleine, leichte Köder, Anwurf quer oder stromauf, Standplätze hinter Steinen.' },
		teich:     { n: 'Teich / Weiher',    s: 'flach, verkrautet', maxD: 3,  cur: 0,   note: 'Flach und warm: Fische reagieren stark auf Licht und Temperatur, Oberflächenköder und hängerarme Montagen sind oft gefragt.' }
	};
	var STRUCT = {
		kraut: { n: 'Kraut / Seerosen',    s: 'Hänger-Gefahr hoch',  weed: 1,   note: 'Im Kraut brauchst du hängerarme Montagen (Offset-Haken, Spinnerbait, Frosch). Offene Drillinge verfangen sich sofort.' },
		holz:  { n: 'Totholz / Steine',    s: 'Unterstände',          weed: 0.8, note: 'Totholz und Steinschüttungen sind Unterstand und Ansitz. Sie fressen Köder: hängerarm oder mit Überwurf-Reserve fischen.' },
		kante: { n: 'Kante / Abbruch',     s: 'Tiefenwechsel',        weed: 0.2, note: 'Kanten sind Fischautobahnen: Köder entlang der Kante führen, mit Grundkontakt am Hang.' },
		frei:  { n: 'Freiwasser / Sand',   s: 'ohne Struktur',        weed: 0,   note: 'Ohne Struktur folgen Räuber den Futterfischschwärmen. Fächerförmig werfen und die Tiefe suchen, am besten mit Echolot.' }
	};
	var CLAR = [
		{ n: 'Klar',          s: 'Sicht > 2 m',        k: 0.08, mult: 1 },
		{ n: 'Leicht trüb',   s: 'Sicht 1–2 m',        k: 0.2,  mult: 1.6 },
		{ n: 'Trüb',          s: 'Sicht 0,5–1 m',      k: 0.45, mult: 2.6 },
		{ n: 'Stark getrübt', s: 'Sicht < 0,5 m',      k: 0.9,  mult: 4 }
	];
	var WATERCOL = [[24, 122, 142], [66, 124, 112], [112, 106, 62], [124, 100, 66]];
	var SEAS = {
		winter:  { n: 'Winter',      s: 'Dez–Feb', t: 4,  sun: 0.7 },
		vorfr:   { n: 'Vorfrühling', s: 'März',    t: 8,  sun: 0.85 },
		fruehl:  { n: 'Frühling',    s: 'Apr–Mai', t: 13, sun: 1 },
		sommer:  { n: 'Sommer',      s: 'Jun–Aug', t: 21, sun: 1 },
		herbst1: { n: 'Frühherbst',  s: 'September', t: 16, sun: 0.95 },
		herbst2: { n: 'Spätherbst',  s: 'Okt–Nov', t: 9,  sun: 0.8 }
	};
	var WX = { sonnig: { n: 'Sonnig', f: 1 }, wolkig: { n: 'Bedeckt', f: 0.55 }, regen: { n: 'Regen', f: 0.35 }, nebel: { n: 'Nebel', f: 0.3 } };
	var TIME = {
		morgen:     { n: 'Morgendämmerung', l: 22 },
		vormittag:  { n: 'Vormittag',       l: 65 },
		mittag:     { n: 'Mittag',          l: 100 },
		nachmittag: { n: 'Nachmittag',      l: 78 },
		abend:      { n: 'Abenddämmerung',  l: 20 },
		nacht:      { n: 'Nacht',           l: 2 }
	};
	var ZONES = ['surface', 'shallow', 'mid', 'deep', 'bottom'];
	var ZN = { surface: 'Oberfläche', shallow: 'Flachwasser (0,5–2 m)', mid: 'Mittelwasser (2–5 m)', deep: 'Tiefes Freiwasser (ab 5 m)', bottom: 'Grundnah' };

	/* Köder: z = Zonen, sp = Tempo-Bereich (0 = kriechend, 1 = schnell), vib = Vibration 0–3,
	 * fl = Blitz/Reflexion 0–3, weed = Hängerarmut 0–1, cur = Strömungstauglichkeit 0–1, aff = Fischart-Eignung */
	var LURE = {
		shad:    { n: 'Gummifisch am Jigkopf', kind: 'soft', draw: 'fish', tail: 'paddle', jig: true, z: ['mid', 'deep', 'bottom'], sp: [0.08, 0.6], vib: 2, fl: 1, weed: 0.2, cur: 0.7, aff: { hecht: 0.8, zander: 1, barsch: 0.8, wels: 0.7, rapfen: 0.15, forelle: 0.2 }, d: 'Der Allrounder: Bleikopf mit weichem Fischimitat. Tiefe und Tempo lassen sich genau steuern, ideal für Grund- und Kantenfischerei.' },
		offset:  { n: 'Gummi offset / Texas-Rig', kind: 'soft', draw: 'fish', tail: 'fork', jig: false, z: ['shallow', 'mid', 'bottom'], sp: [0.05, 0.5], vib: 1.5, fl: 0.5, weed: 1, cur: 0.4, aff: { hecht: 0.9, zander: 0.5, barsch: 0.9, wels: 0.5, rapfen: 0, forelle: 0.05 }, d: 'Der Haken steckt im Gummi, die Spitze ist verdeckt. So lässt sich der Köder durch Kraut, Holz und Steine ziehen, ohne zu hängen.' },
		dropshot:{ n: 'Dropshot-Gummi', kind: 'soft', draw: 'worm', z: ['mid', 'deep', 'bottom'], sp: [0, 0.25], vib: 0.6, fl: 0.3, weed: 0.6, cur: 0.3, aff: { hecht: 0.1, zander: 0.8, barsch: 1, wels: 0.05, rapfen: 0, forelle: 0.25 }, d: 'Der Köder schwebt über dem Blei und bleibt auf der Stelle. Perfekt für träge, wählerische Fische und bei Druck oder Kälte.' },
		twister: { n: 'Twister / Ripper am Jig', kind: 'soft', draw: 'twister', z: ['shallow', 'mid', 'bottom'], sp: [0.12, 0.6], vib: 2.4, fl: 0.8, weed: 0.2, cur: 0.7, aff: { hecht: 0.4, zander: 0.65, barsch: 0.95, wels: 0.2, rapfen: 0.05, forelle: 0.3 }, d: 'Der Kurvenschwanz vibriert schon bei kleinem Tempo. Sehr gut auf Barsch und in leicht trübem Wasser.' },
		wobS:    { n: 'Flachläufer-Wobbler', kind: 'hard', draw: 'fish', lip: 1, tail: 'none', z: ['shallow'], sp: [0.3, 0.85], vib: 2, fl: 1.8, weed: 0.35, cur: 0.7, aff: { hecht: 0.9, zander: 0.3, barsch: 0.7, wels: 0.25, rapfen: 0.75, forelle: 0.85 }, d: 'Kurze Tauchschaufel, läuft bis etwa 2 m. Deckt Uferzonen, Krautkanten und Strömungsbereiche schnell ab.' },
		wobD:    { n: 'Tieflaufender Wobbler', kind: 'hard', draw: 'fish', lip: 2, tail: 'none', z: ['mid', 'deep'], sp: [0.3, 0.85], vib: 2.5, fl: 1.6, weed: 0.2, cur: 0.6, aff: { hecht: 0.85, zander: 0.8, barsch: 0.75, wels: 0.55, rapfen: 0.3, forelle: 0.35 }, d: 'Lange Tauchschaufel, geht je nach Modell 3 bis 8 m tief. Ideal für Kanten und Freiwasser, mit Grundkontakt zum „Aufwirbeln“.' },
		jerk:    { n: 'Jerkbait / Glidebait', kind: 'hard', draw: 'fish', lip: 0, tail: 'none', z: ['shallow', 'mid'], sp: [0.04, 0.55], vib: 1.6, fl: 2.4, weed: 0.2, cur: 0.3, aff: { hecht: 1, zander: 0.2, barsch: 0.35, wels: 0.3, rapfen: 0, forelle: 0.05 }, d: 'Schlanker Köder ohne Tauchschaufel. Mit Rutenschlägen gleitet er seitlich aus und pausiert. Spezialist für Hecht, gerade in kaltem, klarem Wasser (Suspending-Modelle).' },
		spinner: { n: 'Blattspinner', kind: 'hard', draw: 'spinner', z: ['shallow', 'mid'], sp: [0.4, 0.95], vib: 3, fl: 3, weed: 0.3, cur: 1, aff: { hecht: 0.75, zander: 0.15, barsch: 0.9, wels: 0.3, rapfen: 0.5, forelle: 1 }, d: 'Das rotierende Blatt erzeugt Vibration und Blitze. Läuft schon bei geringem Tempo, auch in Strömung. Klassiker für Forelle, Barsch und kleine Hechte.' },
		blinker: { n: 'Blinker / Löffel', kind: 'hard', draw: 'spoon', z: ['shallow', 'mid', 'deep'], sp: [0.25, 0.9], vib: 2, fl: 3, weed: 0.15, cur: 0.9, aff: { hecht: 0.85, zander: 0.35, barsch: 0.55, wels: 0.3, rapfen: 1, forelle: 0.8 }, d: 'Gewölbtes Metall, das taumelt und blitzt. Weit zu werfen, robust und schnell in Strömung. Beim Rapfen nach wie vor erste Wahl.' },
		spbait:  { n: 'Spinnerbait', kind: 'hard', draw: 'spinnerbait', z: ['surface', 'shallow', 'mid'], sp: [0.3, 0.8], vib: 3, fl: 2.5, weed: 0.9, cur: 0.3, aff: { hecht: 1, zander: 0.1, barsch: 0.6, wels: 0.5, rapfen: 0, forelle: 0.05 }, d: 'V-förmiger Draht mit Blatt und Gummirock, hängerarm. Macht Druck und Blitz im Kraut und in trübem Wasser.' },
		chatter: { n: 'Chatterbait (Bladed Jig)', kind: 'hard', draw: 'chatter', z: ['shallow', 'mid'], sp: [0.3, 0.8], vib: 3, fl: 2, weed: 0.8, cur: 0.4, aff: { hecht: 0.85, zander: 0.3, barsch: 0.7, wels: 0.35, rapfen: 0, forelle: 0 }, d: 'Jigkopf mit Metallplättchen, das hart vibriert. Gut an Krautkanten und in trübem Wasser mit mittlerem Tempo.' },
		popper:  { n: 'Popper / Stickbait', kind: 'hard', draw: 'fish', lip: 0, cup: true, tail: 'none', z: ['surface'], sp: [0.15, 0.7], vib: 2.6, fl: 1, weed: 0.5, cur: 0.2, aff: { hecht: 0.95, zander: 0, barsch: 0.55, wels: 0.8, rapfen: 0.9, forelle: 0.15 }, d: 'Oberflächenköder, der spritzt und blubbert. Die Silhouette gegen den Himmel löst Angriffe in Dämmerung und warmem Wasser aus.' },
		frog:    { n: 'Gummifrosch', kind: 'soft', draw: 'frog', z: ['surface'], sp: [0.1, 0.5], vib: 1.6, fl: 0.3, weed: 1, cur: 0.1, aff: { hecht: 1, zander: 0, barsch: 0.35, wels: 0.75, rapfen: 0, forelle: 0 }, d: 'Hohler Weichplastik-Frosch, der über Seerosen und dichtes Kraut gleitet. Die Haken liegen verdeckt oben.' },
		pilker:  { n: 'Pilker / Vertikalköder', kind: 'hard', draw: 'spoon', pilker: true, z: ['deep', 'bottom'], sp: [0, 0.4], vib: 2.2, fl: 3, weed: 0.1, cur: 0.5, aff: { hecht: 0.45, zander: 0.6, barsch: 0.25, wels: 1, rapfen: 0, forelle: 0 }, d: 'Schwerer Metallköder, der senkrecht gepilkt wird. Wels und Zander in tiefen Löchern stehen darauf, besonders mit Echolot.' }
	};

	/* Farben: nat = Natürlichkeit, con = Kontrast, dark = Silhouette, glow = nachleuchtend, deep = Sichtbarkeit in der Tiefe */
	var COL = {
		natur:      { n: 'Natur-Silber (Weißfisch)', c: ['#56707f', '#c8d2d6', '#f3f4f2'], nat: 1,   con: 0.2,  dark: 0,    glow: 0,   deep: 0.5,  fav: ['rapfen', 'forelle'], t: 'Imitiert Rotauge und Ukelei: stimmig, wenn Fische genau hinsehen.' },
		barschd:    { n: 'Barsch-Dekor',             c: ['#4b6a35', '#b0b968', '#ece4b6'], stripes: true, nat: 0.95, con: 0.3, dark: 0.2, glow: 0, deep: 0.3, fav: ['barsch', 'hecht'], t: 'Räuber fressen Barsche gern, Streifen und rote Flossen wirken vertraut.' },
		gruengold:  { n: 'Grün-Gold (Hecht-Dekor)',  c: ['#3b5a28', '#8ea446', '#e6d98c'], nat: 0.8,  con: 0.35, dark: 0.25, glow: 0,   deep: 0.35, fav: ['hecht'], t: 'Wirkt wie ein junger Hecht oder Schlei und passt zu krautigem, grünlichem Wasser.' },
		motoroil:   { n: 'Motoroil / Braun',         c: ['#2e2a1e', '#6a5a38', '#b19a62'], nat: 0.7,  con: 0.3,  dark: 0.55, glow: 0,   deep: 0.2,  fav: ['zander', 'barsch'], t: 'Dunkel mit warmem Schimmer, wirkt wie Grundel oder Krebs. Stark bei wenig Licht.' },
		blausilber: { n: 'Blau-Silber',              c: ['#27508f', '#9fb9de', '#f3f7ff'], nat: 0.75, con: 0.4,  dark: 0.3,  glow: 0,   deep: 0.45, fav: ['rapfen', 'forelle'], t: 'Wirkt wie Laube oder Ukelei im Freiwasser. Blitzt schön im Sonnenlicht.' },
		weiss:      { n: 'Weiß / Perlmutt',          c: ['#d3dce0', '#f4f7f8', '#ffffff'], nat: 0.55, con: 0.6,  dark: 0,    glow: 0.2, deep: 0.85, fav: ['zander', 'rapfen'], t: 'Hell, bleibt in der Tiefe und im trüben Wasser lange sichtbar.' },
		chartreuse: { n: 'Chartreuse / Gelbgrün',    c: ['#a8dc00', '#e5ff6a', '#ffffff'], nat: 0.1,  con: 0.9,  dark: 0,    glow: 0.3, deep: 1,    fav: ['zander', 'hecht'], t: 'Leuchtfarbe, die unter Wasser am längsten sichtbar bleibt. Trübes Wasser und Tiefe sind ihr Revier.' },
		firetiger:  { n: 'Firetiger',                c: ['#ff7a00', '#d5f000', '#fff25a'], stripes: true, nat: 0.05, con: 1, dark: 0.1, glow: 0.2, deep: 0.8, fav: ['hecht', 'barsch'], t: 'Orange-grüne Reizfarbe. Triggert Reaktionsbisse in trübem Wasser und bei Wind.' },
		gold:       { n: 'Gold / Kupfer',            c: ['#9a6a12', '#e3b53a', '#fff0b0'], nat: 0.35, con: 0.7,  dark: 0.15, glow: 0,   deep: 0.5,  fav: ['forelle', 'hecht'], t: 'Warmer Blitz bei Sonne in leicht getrübtem oder huminhaltigem Wasser (Moor, Torf).' },
		rotweiss:   { n: 'Rot-Weiß',                 c: ['#c61d1d', '#f3f3f3', '#ffffff'], nat: 0.15, con: 0.95, dark: 0.1,  glow: 0,   deep: 0.3,  fav: ['hecht'], t: 'Der Klassiker auf Hecht. Rot verschwindet in der Tiefe, der weiße Teil bleibt.' },
		orangefluo: { n: 'Fluo-Orange / Pink',       c: ['#ff4a00', '#ff8f4a', '#ffd9bd'], nat: 0.05, con: 0.95, dark: 0,    glow: 0.1,  deep: 0.2,  fav: ['barsch'], t: 'Grelle Reizfarbe für flache, trübe Stellen. Rot und Orange „verschwinden“ unter 3–5 m.' },
		schwarz:    { n: 'Schwarz / Dunkelblau',     c: ['#080b10', '#1b2531', '#39485a'], nat: 0.1,  con: 0.9,  dark: 1,    glow: 0,   deep: 0.3,  fav: ['wels', 'zander'], t: 'Klare Silhouette gegen den helleren Himmel oder Grund. Nachts und in der Dämmerung unschlagbar.' },
		glow:       { n: 'Glow (nachleuchtend)',     c: ['#b6ffd0', '#7bffa8', '#e9fff0'], nat: 0,    con: 0.9,  dark: 0.5,  glow: 1,   deep: 0.9,  fav: ['wels', 'zander'], t: 'Leuchtet nach, wenn du ihn vorher mit Lampe oder Blitz auflädst. Gut in Dunkelheit und Tiefe.' }
	};

	var ACT = {
		gleich:  { n: 'Gleichmäßig einkurbeln', a: function (s) { return { cycle: 2, move: 1, step: lerp(0.12, 0.38, s), lift: 0, angle: 0, wob: 5, shake: 0 }; },
			how: function (s) { return ['Kurbel ruhig und gleichmäßig, etwa ' + de(Math.round(lerp(0.5, 2.5, s) * 2) / 2, 1) + ' Umdrehungen pro Sekunde (' + tempoWord(s) + ').', 'Zähle den Köder nach dem Aufschlagen herunter, um die Tiefe zu wiederholen (z. B. 5 s zählen).', 'Variation: ein kurzer Stopp oder Tempowechsel provoziert Verfolger.']; } },
		stopgo:  { n: 'Stop & Go', a: function (s) { return { cycle: lerp(3.4, 1.4, s), move: 0.45, step: 0.14, lift: 0.3, angle: 6, wob: 0, shake: 0 }; },
			how: function (s) { return ['Zwei bis vier Kurbelumdrehungen, dann Pause von ' + de(pauseSec(s), 1) + ' s.', 'Gerade in der Pause beißen viele Fische: Schnur straff halten.', 'Suspending- oder Schwebe-Köder bleiben in der Pause auf Höhe, sinkende Köder fallen leicht ab.']; } },
		faulen:  { n: 'Faulenzen', a: function (s) { return { cycle: lerp(3, 1.8, s), move: 0.35, step: 0.09, lift: 1.1, angle: 0, wob: 0, shake: 0 }; },
			how: function (s) { return ['Köder bis zum Grund sinken lassen, eine Kurbelumdrehung, Rute leicht anheben.', 'Beim Absinken Schnur straff halten: Die meisten Bisse kommen in der Fallphase („Tock“ oder Schnurschlaffen).', 'Pause am Grund etwa ' + de(pauseSec(s), 1) + ' s, bei Kälte länger.']; } },
		schlepp: { n: 'Langsam über Grund schleppen', a: function () { return { cycle: 3.2, move: 0.9, step: 0.07, lift: 0.18, angle: 0, wob: 0, shake: 0 }; },
			how: function () { return ['Rute tief halten und den Köder mit der Kurbel Zentimeter für Zentimeter über den Grund ziehen.', 'Kleine Hindernisse bewusst anstoßen: Das wirbelt Sediment auf und wirkt wie fliehende Beute.', 'Bei jedem Widerstand kurz stoppen, dort sitzen oft Fische.']; } },
		twitch:  { n: 'Twitchen', a: function () { return { cycle: 1.3, move: 0.25, step: 0.06, lift: 0.1, angle: 20, wob: 0, shake: 0 }; },
			how: function () { return ['Mit kurzen Rutenspitzenschlägen den Köder zucken lassen, dazwischen Schnur einholen.', 'Rhythmus ändern: zwei Zupfer, Pause, drei Zupfer.']; } },
		jerken:  { n: 'Jerken', a: function (s) { return { cycle: lerp(3.6, 1.8, s), move: 0.28, step: 0.1, lift: 0.2, angle: 26, wob: 0, shake: 0 }; },
			how: function (s) { return ['Rute nach unten oder zur Seite schlagen, Schnur dabei einholen: Der Köder gleitet seitlich aus.', 'Danach Pause von ' + de(pauseSec(s) + 1, 1) + ' s. In dieser Zeit schwebt oder sinkt er langsam.', 'Je kälter das Wasser, desto länger die Pause (bis 10 s bei Suspending-Modellen).']; } },
		dropshot:{ n: 'Dropshot zupfen', a: function () { return { cycle: 3, move: 0.2, step: 0.02, lift: 0.1, angle: 0, wob: 0, shake: 2 }; },
			how: function () { return ['Blei am Grund, Köder schwebt darüber. Rute leicht zittern lassen, ohne Blei zu bewegen.', 'Alle 10–20 s ein bis zwei Kurbelumdrehungen weiterziehen.', 'Vorsichtig anschlagen: Bisse spürst du oft nur als Gewichtswechsel an der Schnur.']; } },
		pop:     { n: 'Poppen', a: function (s) { return { cycle: lerp(2.4, 1.1, s), move: 0.2, step: 0.07, lift: 0, angle: 8, wob: 0, shake: 0, surface: true }; },
			how: function (s) { return ['Kurzer Ruck mit der Rutenspitze: Der Popper spritzt, danach Pause von ' + de(pauseSec(s) - 0.5 > 1 ? pauseSec(s) - 0.5 : 1, 1) + ' s.', 'Viele Fische nehmen den Köder in der Ruhe nach dem Spritzen.', 'Nicht zu früh anschlagen: warten, bis die Schnur sich spannt.']; } },
		froschen:{ n: 'Frosch führen', a: function () { return { cycle: 2.2, move: 0.35, step: 0.08, lift: 0, angle: 12, wob: 0, shake: 0, surface: true }; },
			how: function () { return ['Über Seerosen und Kraut ziehen, mit kurzen Stopps in den Lücken.', 'Beim Biss eine Sekunde warten, dann kräftig anschlagen (Haken sitzt oben).']; } },
		pilken:  { n: 'Pilken', a: function () { return { cycle: 2.6, move: 0.35, step: 0.0, lift: 2, angle: 0, wob: 0, shake: 0 }; },
			how: function () { return ['Köder bis zum Grund ablassen, einen Meter anheben und frei fallen lassen.', 'Rute dabei so führen, dass die Schnur beim Fallen nie komplett schlaff wird.', 'Mit Echolot auf den Fisch ablassen und über ihm pilken.']; } }
	};
	var ACT_FOR = {
		shad:    function (s) { return s < 0.2 ? 'schlepp' : s < 0.55 ? 'faulen' : 'gleich'; },
		offset:  function (s) { return s < 0.22 ? 'schlepp' : s < 0.5 ? 'faulen' : 'stopgo'; },
		dropshot:function () { return 'dropshot'; },
		twister: function (s) { return s < 0.28 ? 'schlepp' : s < 0.5 ? 'faulen' : 'gleich'; },
		wobS:    function (s) { return s < 0.4 ? 'stopgo' : 'gleich'; },
		wobD:    function (s) { return s < 0.45 ? 'stopgo' : 'gleich'; },
		jerk:    function () { return 'jerken'; },
		spinner: function () { return 'gleich'; },
		blinker: function (s) { return s < 0.45 ? 'stopgo' : 'gleich'; },
		spbait:  function (s) { return s < 0.4 ? 'schlepp' : 'gleich'; },
		chatter: function (s) { return s < 0.5 ? 'stopgo' : 'gleich'; },
		popper:  function () { return 'pop'; },
		frog:    function () { return 'froschen'; },
		pilker:  function () { return 'pilken'; }
	};
	function tempoWord(s) { return s < 0.15 ? 'kriechend langsam' : s < 0.35 ? 'langsam' : s < 0.6 ? 'mittel' : s < 0.8 ? 'zügig' : 'schnell'; }
	function pauseSec(s) { return Math.round(lerp(5.5, 0.5, s) * 2) / 2; }

	var PRESETS = [
		{ t: 'Zander im Winterkanal', s: 'trüb, Dämmerung, 4 °C', v: { sp: 'zander', gw: 'kanal', st: 'kante', kl: 2, ss: 'winter', temp: 4, wx: 'wolkig', wind: false, tz: 'abend' } },
		{ t: 'Hecht im Sommerkraut', s: 'klar, Abend, 21 °C', v: { sp: 'hecht', gw: 'teich', st: 'kraut', kl: 1, ss: 'sommer', temp: 22, wx: 'sonnig', wind: false, tz: 'abend' } },
		{ t: 'Barsch am Mittag im klaren See', s: 'sonnig, Kante, Frühherbst', v: { sp: 'barsch', gw: 'see', st: 'kante', kl: 0, ss: 'herbst1', temp: 16, wx: 'sonnig', wind: false, tz: 'mittag' } },
		{ t: 'Wels bei Nacht im Fluss', s: 'Holz, trüb, Sommer', v: { sp: 'wels', gw: 'fluss', st: 'holz', kl: 2, ss: 'sommer', temp: 22, wx: 'wolkig', wind: false, tz: 'nacht' } },
		{ t: 'Rapfen im Abendstrom', s: 'Fluss, Wind, Sommer', v: { sp: 'rapfen', gw: 'fluss', st: 'frei', kl: 1, ss: 'sommer', temp: 20, wx: 'wolkig', wind: true, tz: 'abend' } },
		{ t: 'Forelle im Frühlingsbach', s: 'klar, Vormittag, 9 °C', v: { sp: 'forelle', gw: 'bach', st: 'holz', kl: 0, ss: 'fruehl', temp: 9, wx: 'wolkig', wind: false, tz: 'vormittag' } }
	];

	/* ------------------------------------------------------------------ *
	 * Modell
	 * ------------------------------------------------------------------ */
	var S = { sp: 'hecht', gw: 'see', st: 'kante', kl: 1, ss: 'herbst1', temp: 16, wx: 'wolkig', wind: false, tz: 'abend' };

	function interp(points, x) {
		var i;
		if (x <= points[0][0]) { return points[0][1]; }
		for (i = 1; i < points.length; i++) {
			if (x <= points[i][0]) { return lerp(points[i - 1][1], points[i][1], (x - points[i - 1][0]) / (points[i][0] - points[i - 1][0])); }
		}
		return points[points.length - 1][1];
	}

	function zoneDepth(z, w) {
		var m = w.maxD;
		return { surface: 0.15, shallow: Math.min(1.5, m * 0.5), mid: Math.min(3.5, m * 0.6), deep: Math.min(8, m * 0.8), bottom: m * 0.7 }[z];
	}

	function derive(s) {
		var sp = SP[s.sp], w = WATER[s.gw], se = SEAS[s.ss], cl = CLAR[s.kl], T = s.temp;
		var night = s.tz === 'nacht', dusk = s.tz === 'morgen' || s.tz === 'abend';
		var L0 = clamp(TIME[s.tz].l * WX[s.wx].f * se.sun, 1, 100);
		var bright = L0 / 100, low = 1 - bright;
		var spring = s.ss === 'vorfr' || s.ss === 'fruehl';

		// Tempo
		var speed = interp([[0, 0.03], [4, 0.06], [8, 0.25], [14, 0.5], [20, 0.75], [24, 0.8], [28, 0.6]], T) + sp.spd;
		if (s.wx === 'regen' || s.wind) { speed += 0.05; }
		if (s.wx === 'sonnig' && s.kl === 0 && bright > 0.6) { speed -= 0.05; }
		if (dusk) { speed += 0.04; }
		if (s.sp === 'wels' && T < 12) { speed -= 0.1; }
		speed = clamp(speed, 0.02, 1);

		// Zielzone
		var z = {};
		z.surface = (T >= 18 ? 1.5 : T >= 15 ? 0.5 : T >= 12 ? -1 : -4) + (T >= 12 ? 2.2 * low : 0) - (s.wind ? 1.2 : 0) - (s.kl >= 3 ? 1 : 0)
			+ (s.sp === 'rapfen' ? 2.2 : 0) + (s.sp === 'wels' && (night || dusk) && T >= 15 ? 1.5 : 0)
			+ ((night || dusk) && T >= 15 && (s.sp === 'hecht' || s.sp === 'wels') ? 1.2 : 0)
			+ (s.st === 'kraut' && T >= 15 ? 1.5 : 0) - (s.sp === 'zander' || s.sp === 'forelle' ? 1.5 : 0);
		z.shallow = 1 + 2.4 * low + (T >= 7 && T <= 20 ? 1 : 0) + (spring ? 1.2 : 0) + (s.kl >= 2 ? 0.6 : 0)
			- (T > 22 && bright > 0.5 ? 2 : 0) - (T < 5 ? 1.5 : 0) + (s.sp === 'zander' && !(night || dusk) ? -2.5 : 0) + (s.st === 'kraut' ? 1 : 0);
		z.mid = 1.8;
		z.deep = w.maxD >= 5 ? (-0.5 + 2.4 * bright * (s.kl <= 1 ? 1 : 0.4) + (T < 6 ? 1.8 : 0) + (T > 22 ? 2 : 0) + (s.sp === 'zander' ? 1 : 0) - (s.sp === 'rapfen' ? 2 : 0)) : -99;
		z.bottom = (T < 10 ? 1.8 : 0.5) + (s.sp === 'zander' ? 2 : 0) + (s.sp === 'wels' ? 1.4 : 0) + (s.sp === 'barsch' ? 0.4 : 0)
			+ bright * 0.8 + (s.st === 'kante' ? 1 : 0) - (s.sp === 'rapfen' ? 4 : 0);
		var zone = 'mid', best = -999;
		ZONES.forEach(function (k) { if (z[k] > best) { best = z[k]; zone = k; } });
		var zd = zoneDepth(zone, w);

		var Lw = L0 * Math.exp(-cl.k * zd);

		var c = {
			sp: sp, w: w, se: se, cl: cl, T: T, night: night, dusk: dusk, L0: L0, bright: bright, low: low,
			speed: speed, zone: zone, zoneDepth: zd, Lw: Lw,
			vibNeed: clamp(1 + s.kl * 0.55 + low * 0.8 + (s.sp === 'wels' ? 0.8 : 0) + (night ? 0.4 : 0), 0, 3),
			flashNeed: clamp(0.4 + bright * 1.6 + (s.kl >= 2 && bright > 0.4 ? 0.6 : 0) - (night ? 0.8 : 0) - (T < 6 ? 0.3 : 0), 0, 3),
			weedNeed: STRUCT[s.st].weed,
			currNeed: w.cur
		};
		return c;
	}

	function scoreLure(l, c, s) {
		var aff = l.aff[s.sp] || 0, zi = ZONES.indexOf(c.zone), zFit = 0.1;
		var sFit = (c.speed >= l.sp[0] && c.speed <= l.sp[1]) ? 1 : 1 - Math.min(1, Math.min(Math.abs(c.speed - l.sp[0]), Math.abs(c.speed - l.sp[1])) / 0.35);
		l.z.forEach(function (z) { var d = Math.abs(ZONES.indexOf(z) - zi); zFit = Math.max(zFit, [1, 0.55, 0.2][d] || 0.05); });
		var vFit = 1 - Math.abs(c.vibNeed - l.vib) / 3;
		var fFit = 1 - Math.abs(c.flashNeed - l.fl) / 3;
		var wFit = l.weed >= c.weedNeed ? 1 : Math.max(0, 1 - (c.weedNeed - l.weed) * 1.3);
		var cFit = c.currNeed > 0.5 ? 0.5 + 0.5 * l.cur : 1;
		var base = (0.2 * sFit + 0.26 * zFit + 0.12 * vFit + 0.08 * fFit + 0.14 * wFit + 0.05 * cFit) / 0.85;
		return Math.round(base * (0.25 + 0.75 * aff) * 100);
	}

	function scoreColor(col, c, s) {
		var Lw = c.Lw, cl = s.kl;
		var needNat = [0.9, 0.55, 0.25, 0.1][cl] + (Lw > 50 && cl === 0 ? 0.05 : 0) - (Lw < 20 ? 0.15 : 0)
			+ (s.ss === 'winter' || c.T < 8 ? 0.1 : 0) + (s.sp === 'barsch' || s.sp === 'forelle' ? 0.1 : 0);
		needNat = clamp(needNat, 0, 1) * (0.35 + 0.65 * Math.min(1, Lw / 40));
		var needCon = 1 - needNat;
		var needDark = Lw < 30 ? clamp((30 - Lw) / 30 * 0.9, 0, 0.9) * (cl <= 1 ? 1 : 0.6) : 0.05;
		var needGlow = Lw < 8 ? 1 : Lw < 20 ? 0.5 : 0;
		var needDeep = clamp(c.zoneDepth / 8, 0, 1) * (c.zone === 'bottom' || c.zone === 'deep' ? 1 : 0.6);
		var d = 0.3 * Math.abs(col.nat - needNat) + 0.18 * Math.abs(col.con - needCon) + 0.2 * Math.abs(col.dark - needDark)
			+ 0.17 * Math.abs(col.glow - needGlow) + 0.15 * Math.abs(col.deep - needDeep);
		var sc = 1 - d + (col.fav.indexOf(s.sp) >= 0 ? 0.06 : 0);
		return Math.round(clamp(sc, 0, 1) * 100);
	}

	function lureSize(l, c, s) {
		var b = SP[s.sp].size, f = 1;
		if (c.T < 6) { f = 0.8; } else if (c.T < 10) { f = 0.9; }
		if ((s.ss === 'herbst1' || s.ss === 'herbst2') && (s.sp === 'hecht' || s.sp === 'zander' || s.sp === 'wels')) { f *= 1.2; }
		if (s.kl >= 2) { f *= 1.1; }
		if (l.id === 'dropshot') { f *= 0.8; }
		if (l.id === 'pilker') { f *= 1.1; }
		if (l.id === 'popper' || l.id === 'frog') { f *= 0.85; }
		var lo = b[0] * f, hi = b[1] * f;
		var rnd = function (v) { return v < 10 ? Math.round(v * 2) / 2 : Math.round(v); };
		return [rnd(lo), rnd(hi)];
	}

	function lureWeight(l, c, s, size) {
		var d = c.zoneDepth, cur = c.currNeed, wind = s.wind ? 1 : 0, mid = (size[0] + size[1]) / 2, w;
		var kf = { wobS: 0.9, wobD: 1, jerk: 1.1, spinner: 0.5, blinker: 0.75, spbait: 0.7, chatter: 0.65, popper: 0.8, frog: 0.7 };
		if (l.id === 'shad' || l.id === 'twister') { w = (d * 1.2 + cur * 8 + wind * 2 + 3) * clamp(mid / 10, 0.7, 2.5); }
		else if (l.id === 'offset') { w = clamp(d * 0.6 + 3 + cur * 4, 3.5, 21); }
		else if (l.id === 'dropshot') { w = clamp(d * 1.1 + 3 + cur * 5, 3.5, 28); }
		else if (l.id === 'pilker') { w = clamp(d * 6 + cur * 25 + 20, 30, 150); }
		else { w = 6 * Math.pow(mid / 5, 1.76) * (kf[l.id] || 1); }
		return [Math.max(1, Math.round(w * 0.8)), Math.max(2, Math.round(w * 1.2))];
	}

	function shapeFor(l, c, s) {
		if (l.id === 'jerk') { return 'slim'; }
		if (l.draw === 'fish' && !l.cup) {
			if ((s.kl <= 1 && c.T < 12) || s.sp === 'zander' && s.kl <= 1) { return 'slim'; }
			if (s.kl >= 2 || s.ss === 'herbst1' || s.ss === 'herbst2') { return 'tall'; }
		}
		return 'mid';
	}

	function formText(l, c, s, shape) {
		var sh = {
			slim: 'Schlankes Profil (Stint, Ukelei): wenig Widerstand, wirkt natürlich und überzeugt bei klarem, kaltem Wasser.',
			tall: 'Hochrückiges Profil (Brassen, Rotauge): drückt viel Wasser, wirkt größer und bleibt in trübem Wasser über die Seitenlinie spürbar.',
			mid: 'Mittleres Profil (Barsch, Rotauge): bewährter Allrounder ohne Extreme.'
		}[shape];
		switch (l.id) {
			case 'shad': case 'offset':
				return (c.vibNeed >= 1.8 ? 'Paddle-Tail (Schaufelschwanz) für kräftige Vibration. ' : 'Schlanker Kopyto- oder Slim-Schwanz für feine Bewegung. ') + sh;
			case 'dropshot': return 'Schlanker Finesse-Wurm oder Slim-Shad ohne Schnickschnack: die Eigenbewegung reicht.';
			case 'twister': return 'Kurvenschwanz (Curly Tail) oder Ripper: arbeitet schon bei langsamem Einholen.';
			case 'wobS': case 'wobD':
				return (l.id === 'wobS' ? 'Kurze Tauchschaufel (läuft ca. 0,5–2 m). ' : 'Lange Tauchschaufel (läuft ca. 3–6 m). ') + (c.T < 10 ? 'Suspending-Modell (schwebt in der Pause). ' : 'Floating-Modell (steigt in der Pause). ') + (s.kl >= 2 || c.night ? 'Mit Rasselkammer, falls vorhanden. ' : '') + sh;
			case 'jerk': return (c.T < 10 ? 'Suspending-Modell, das in der Pause stehen bleibt. ' : 'Floating- oder Slow-Sinking-Modell. ') + sh;
			case 'spinner': return (c.speed > 0.6 || c.currNeed > 0.5 ? 'Weiden- oder Indianablatt: flaches Profil, dreht auch bei Tempo und Strömung. ' : 'Rundes Colorado-Blatt: viel Druckwelle, dreht schon bei langsamem Tempo. ') + 'Größe 1–2 für Barsch und Forelle, 3–5 für Hecht.';
			case 'blinker': return (c.speed > 0.55 ? 'Schlanker, langer Blinker: wirft weit und taumelt eng, gut für Tempo. ' : 'Breiter Löffel: flattert weit aus, läuft auch bei langsamem Tempo.');
			case 'spbait': return 'Ein- oder Doppelblatt. Colorado für langsam und Druck, Weidenblatt für schnelles, flaches Fischen.';
			case 'chatter': return 'Mit Trailer in Fischform. Schlanker Trailer bei Druck, Paddle-Tail für mehr Vibration.';
			case 'popper': return (s.wind || s.kl >= 2 ? 'Popper mit tiefem Maul: spritzt stark, hörbar auch in Welle. ' : 'Walking-Stickbait (S-Lauf) für ruhige Wasseroberfläche, oder Popper für Spritzgeräusche. ') + 'Drillinge ersetzen, wenn sie stumpf sind.';
			case 'frog': return 'Hohle Silikonform mit weichen Beinen: sinkt nicht, gleitet über Blätter.';
			case 'pilker': return 'Schlanker Metallköder mit Drilling hinten: Lauf stabil, fällt flatternd.';
		}
		return sh;
	}

	function whyLine(l, c, s) {
		var parts = [];
		parts.push('Zielzone: ' + ZN[c.zone].toLowerCase() + '.');
		if (c.vibNeed > 2) { parts.push('Hoher Vibrationsbedarf (' + (s.kl >= 2 ? 'trübes Wasser' : 'wenig Licht') + ').'); }
		if (c.weedNeed >= 0.8 && l.weed >= 0.8) { parts.push('Hängerarm genug für ' + STRUCT[s.st].n.toLowerCase() + '.'); }
		parts.push('Tempo ' + tempoWord(c.speed) + ' liegt im Arbeitsbereich dieses Köders.');
		return parts.join(' ');
	}

	function compute(s) {
		var c = derive(s), id, ranked = [], cols = [];
		for (id in LURE) { if (LURE.hasOwnProperty(id)) { LURE[id].id = id; ranked.push({ id: id, score: scoreLure(LURE[id], c, s) }); } }
		ranked.sort(function (a, b) { return b.score - a.score; });
		for (id in COL) { if (COL.hasOwnProperty(id)) { COL[id].id = id; cols.push({ id: id, score: scoreColor(COL[id], c, s) }); } }
		cols.sort(function (a, b) { return b.score - a.score; });

		var top = LURE[ranked[0].id], col = COL[cols[0].id];
		var size = lureSize(top, c, s), weight = lureWeight(top, c, s, size), shape = shapeFor(top, c, s);
		var akey = ACT_FOR[top.id](c.speed);
		return { s: s, c: c, ranked: ranked, cols: cols, top: top, col: col, size: size, weight: weight, shape: shape, akey: akey, act: ACT[akey], anim: ACT[akey].a(c.speed) };
	}

	function lightAt(L0, d, kl) { return L0 * Math.exp(-CLAR[kl].k * d); }

	var ABS = [0.42, 0.13, 0.09];
	function atten(hex, d, kl, bright) {
		var rgb = hex2rgb(hex), M = CLAR[kl].mult, wc = WATERCOL[kl], lf = 0.3 + 0.7 * (bright === undefined ? 1 : bright);
		return rgb.map(function (v, i) {
			var t = Math.exp(-ABS[i] * d * M);
			return (v * t + wc[i] * 0.55 * (1 - Math.exp(-0.14 * d * M))) * (d === 0 ? 1 : lf);
		});
	}

	/* ------------------------------------------------------------------ *
	 * Köder-Grafiken (SVG)
	 * ------------------------------------------------------------------ */
	var uid = 0;
	function lureSVG(l, cols, shape, withHooks) {
		var id = 'klg' + (++uid), c1 = cols[0], c2 = cols[1], c3 = cols[2];
		var h = { slim: 10, mid: 15, tall: 21 }[shape || 'mid'];
		var defs = '<linearGradient id="' + id + '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + c1 + '"/><stop offset=".55" stop-color="' + c2 + '"/><stop offset="1" stop-color="' + c3 + '"/></linearGradient>';
		var g = '', metal = '#8d989d', hook = '#7d878c', x0 = 50, len = 140, y = 50;
		var gloss = '<ellipse cx="' + (x0 + len * 0.45) + '" cy="' + (y - h * 0.5) + '" rx="' + (len * 0.34) + '" ry="' + (h * 0.22) + '" fill="#fff" opacity=".22"/>';
		var treble = function (x, yy) {
			return '<g stroke="' + hook + '" stroke-width="1.8" fill="none" stroke-linecap="round"><path d="M' + x + ',' + yy + ' v5 M' + x + ',' + (yy + 5) + ' q-7,2 -6,10 M' + x + ',' + (yy + 5) + ' q7,2 6,10"/></g>';
		};
		var body;
		if (l.draw === 'fish') {
			body = 'M' + x0 + ',' + y + ' C' + (x0 + 20) + ',' + (y - h * 1.1) + ' ' + (x0 + 90) + ',' + (y - h * 1.05) + ' ' + (x0 + len) + ',' + (y - h * 0.35) + ' L' + (x0 + len) + ',' + (y + h * 0.35) + ' C' + (x0 + 90) + ',' + (y + h * 1.1) + ' ' + (x0 + 20) + ',' + (y + h * 1.2) + ' ' + x0 + ',' + y + ' Z';
			if (l.tail === 'paddle') { g += '<path d="M' + (x0 + len - 4) + ',' + (y - h * 0.3) + ' L' + (x0 + len + 30) + ',' + (y - h * 0.95) + ' Q' + (x0 + len + 20) + ',' + y + ' ' + (x0 + len + 30) + ',' + (y + h * 0.95) + ' L' + (x0 + len - 4) + ',' + (y + h * 0.3) + ' Z" fill="' + c2 + '" stroke="rgba(0,0,0,.35)" stroke-width="1"/>'; }
			if (l.tail === 'fork') { g += '<path d="M' + (x0 + len - 4) + ',' + (y - h * 0.3) + ' L' + (x0 + len + 26) + ',' + (y - h * 0.9) + ' L' + (x0 + len + 16) + ',' + y + ' L' + (x0 + len + 26) + ',' + (y + h * 0.9) + ' L' + (x0 + len - 4) + ',' + (y + h * 0.3) + ' Z" fill="' + c2 + '" stroke="rgba(0,0,0,.35)" stroke-width="1"/>'; }
			if (l.tail === 'none' && !l.cup) { g += '<path d="M' + (x0 + len - 2) + ',' + (y - h * 0.25) + ' L' + (x0 + len + 16) + ',' + (y - h * 0.7) + ' L' + (x0 + len + 12) + ',' + y + ' L' + (x0 + len + 16) + ',' + (y + h * 0.7) + ' L' + (x0 + len - 2) + ',' + (y + h * 0.25) + ' Z" fill="' + c1 + '" stroke="rgba(0,0,0,.3)" stroke-width="1"/>'; }
			if (l.cup) { g += '<path d="M' + (x0 - 2) + ',' + (y - h * 0.55) + ' L' + (x0 + 14) + ',' + (y - h * 0.9) + ' L' + (x0 + 14) + ',' + (y + h * 0.9) + ' L' + (x0 - 2) + ',' + (y + h * 0.55) + ' Z" fill="' + c3 + '" stroke="rgba(0,0,0,.3)"/>'; }
			g += '<defs>' + defs + '<clipPath id="c' + id + '"><path d="' + body + '"/></clipPath></defs>';
			g += '<path d="' + body + '" fill="url(#' + id + ')" stroke="rgba(0,0,0,.4)" stroke-width="1.3"/>';
			if (l.stripes || COL[cols.id] && COL[cols.id].stripes) {
				g += '<g clip-path="url(#c' + id + ')" fill="rgba(0,0,0,.28)">' + [0.3, 0.45, 0.6, 0.75].map(function (f) { return '<rect x="' + (x0 + len * f) + '" y="' + (y - h * 1.3) + '" width="7" height="' + (h * 2.7) + '" transform="skewX(-8)"/>'; }).join('') + '</g>';
			}
			g += gloss;
			g += '<path d="M' + (x0 + 30) + ',' + (y - h * 0.8) + ' q-5,' + (h * 0.8) + ' 0,' + (h * 1.7) + '" stroke="rgba(0,0,0,.25)" stroke-width="1.5" fill="none"/>';
			g += '<circle cx="' + (x0 + 20) + '" cy="' + (y - h * 0.25) + '" r="4.2" fill="#fff" stroke="rgba(0,0,0,.5)" stroke-width=".8"/><circle cx="' + (x0 + 20.8) + '" cy="' + (y - h * 0.25) + '" r="2" fill="#111"/>';
			if (l.lip) { g += '<path d="M' + (x0 + 4) + ',' + (y + h * 0.35) + ' L' + (x0 - 10 - l.lip * 8) + ',' + (y + h * 0.4 + l.lip * 9) + ' L' + (x0 + 2) + ',' + (y + h * 0.7) + ' Z" fill="rgba(190,225,240,.7)" stroke="rgba(0,0,0,.35)" stroke-width="1"/>'; }
			if (l.jig) { g += '<circle cx="' + (x0 + 4) + '" cy="' + y + '" r="8" fill="' + metal + '" stroke="rgba(0,0,0,.45)"/><circle cx="' + (x0 + 6) + '" cy="' + (y - 3) + '" r="3" fill="#fff" opacity=".35"/><path d="M' + (x0 + 2) + ',' + (y + 8) + ' q-1,16 20,12" stroke="' + hook + '" stroke-width="2" fill="none"/>'; }
			else if (l.kind === 'soft') { g += '<path d="M' + (x0 + 40) + ',' + (y + h * 0.9) + ' q0,16 14,13" stroke="' + hook + '" stroke-width="2" fill="none"/>'; }
			else if (withHooks !== false) { g += treble(x0 + 40, y + h * 0.95) + treble(x0 + len - 12, y + h * 0.4); }
		} else if (l.draw === 'worm') {
			g += '<defs>' + defs + '</defs><path d="M60,38 C95,18 125,70 160,40 S205,30 215,48" stroke="rgba(0,0,0,.4)" stroke-width="13" fill="none" stroke-linecap="round"/><path d="M60,38 C95,18 125,70 160,40 S205,30 215,48" stroke="' + c2 + '" stroke-width="10" fill="none" stroke-linecap="round"/><path d="M62,34 C95,14 125,66 160,36 S205,26 213,43" stroke="' + c1 + '" stroke-width="3" fill="none" stroke-linecap="round" opacity=".8"/><path d="M52,40 L38,30 M52,40 q-12,12 -3,20" stroke="' + hook + '" stroke-width="2" fill="none"/><line x1="40" y1="60" x2="40" y2="90" stroke="' + hook + '" stroke-width="1"/><path d="M34,90 h12 l-2,8 h-8 Z" fill="' + metal + '"/>';
		} else if (l.draw === 'twister') {
			g += '<defs>' + defs + '</defs><circle cx="62" cy="48" r="10" fill="' + metal + '" stroke="rgba(0,0,0,.45)"/><circle cx="64" cy="44" r="3" fill="#fff" opacity=".35"/><path d="M70,48 L108,48" stroke="' + c2 + '" stroke-width="12" stroke-linecap="round"/><path d="M108,48 C130,48 140,22 160,32 C175,40 170,58 190,52 C205,48 210,34 222,36" stroke="' + c2 + '" stroke-width="7" fill="none" stroke-linecap="round"/><path d="M108,44 C130,44 140,18 160,28" stroke="' + c1 + '" stroke-width="2" fill="none" opacity=".7"/><path d="M60,58 q-2,18 20,15" stroke="' + hook + '" stroke-width="2" fill="none"/>';
		} else if (l.draw === 'spinner') {
			g += '<defs>' + defs + '</defs><line x1="26" y1="50" x2="205" y2="50" stroke="' + hook + '" stroke-width="2"/><ellipse cx="82" cy="40" rx="26" ry="13" transform="rotate(-18 82 40)" fill="' + c2 + '" stroke="rgba(0,0,0,.45)" stroke-width="1.2"/><ellipse cx="74" cy="34" rx="12" ry="3.4" transform="rotate(-18 74 34)" fill="#fff" opacity=".4"/><rect x="108" y="43" width="42" height="14" rx="7" fill="url(#' + id + ')" stroke="rgba(0,0,0,.4)"/><circle cx="104" cy="50" r="4" fill="' + c1 + '"/><path d="M150,50 l22,-6 M150,50 l22,6 M150,50 l22,0" stroke="' + c1 + '" stroke-width="3" stroke-linecap="round"/>' + treble(178, 52);
		} else if (l.draw === 'spoon') {
			if (l.pilker) {
				g += '<defs>' + defs + '</defs><path d="M40,50 L170,40 Q190,50 170,60 Z" fill="url(#' + id + ')" stroke="rgba(0,0,0,.45)" stroke-width="1.3"/><circle cx="52" cy="50" r="3.2" fill="#fff" stroke="rgba(0,0,0,.5)"/><circle cx="36" cy="50" r="4" fill="none" stroke="' + metal + '" stroke-width="2"/>' + treble(176, 54);
			} else {
				g += '<defs>' + defs + '</defs><path d="M46,50 C64,20 160,20 198,50 C160,80 64,80 46,50 Z" fill="url(#' + id + ')" stroke="rgba(0,0,0,.45)" stroke-width="1.3"/><ellipse cx="108" cy="38" rx="48" ry="5" fill="#fff" opacity=".28"/><circle cx="62" cy="50" r="3.4" fill="rgba(0,0,0,.35)"/><circle cx="38" cy="50" r="4.5" fill="none" stroke="' + metal + '" stroke-width="2"/>' + treble(196, 52);
			}
		} else if (l.draw === 'spinnerbait') {
			g += '<defs>' + defs + '</defs><path d="M60,66 L50,48 L38,28 M60,66 L140,32 L186,26" stroke="' + hook + '" stroke-width="2.6" fill="none" stroke-linecap="round"/><ellipse cx="170" cy="28" rx="20" ry="9" transform="rotate(-12 170 28)" fill="' + c2 + '" stroke="rgba(0,0,0,.45)"/><circle cx="62" cy="68" r="8" fill="' + metal + '" stroke="rgba(0,0,0,.45)"/><path d="M68,64 L110,52 L110,84 L68,74 Z" fill="' + c1 + '" opacity=".92"/><path d="M80,62 L108,56 M80,68 L108,68 M80,72 L108,80" stroke="' + c3 + '" stroke-width="2"/><path d="M60,76 q-2,14 14,14" stroke="' + hook + '" stroke-width="2" fill="none"/>';
		} else if (l.draw === 'chatter') {
			g += '<defs>' + defs + '</defs><path d="M54,50 L68,36 L68,64 Z" fill="' + c3 + '" stroke="rgba(0,0,0,.45)"/><circle cx="82" cy="50" r="10" fill="' + metal + '" stroke="rgba(0,0,0,.45)"/><path d="M92,50 L150,38 Q170,50 150,62 Z" fill="' + c1 + '" opacity=".9"/><path d="M150,50 C175,34 195,40 215,52 C198,58 175,66 150,50 Z" fill="' + c2 + '" stroke="rgba(0,0,0,.35)"/><path d="M80,60 q-1,16 15,15" stroke="' + hook + '" stroke-width="2" fill="none"/>';
		} else if (l.draw === 'frog') {
			g += '<defs>' + defs + '</defs><path d="M80,48 L52,72 L74,78 Z M80,52 L52,28 L74,22 Z M150,50 L180,76 L156,80 Z M150,50 L180,24 L156,20 Z" fill="' + c1 + '" stroke="rgba(0,0,0,.35)"/><ellipse cx="116" cy="50" rx="46" ry="22" fill="url(#' + id + ')" stroke="rgba(0,0,0,.45)" stroke-width="1.3"/><circle cx="144" cy="36" r="6" fill="#f6f6e0" stroke="rgba(0,0,0,.5)"/><circle cx="145" cy="36" r="2.6" fill="#111"/><circle cx="144" cy="64" r="6" fill="#f6f6e0" stroke="rgba(0,0,0,.5)"/><circle cx="145" cy="64" r="2.6" fill="#111"/><path d="M80,50 l-18,-4 M80,50 l-18,8 M80,50 l-22,2" stroke="' + c3 + '" stroke-width="3" stroke-linecap="round"/>';
		}
		return '<svg viewBox="0 0 260 100" role="img" aria-label="' + l.n + '" focusable="false"><g>' + g + '</g></svg>';
	}

	/* ------------------------------------------------------------------ *
	 * DOM aufbauen
	 * ------------------------------------------------------------------ */
	function chip(name, id, label, sub, checked, extra) {
		return '<label class="kl-chip ' + (extra || '') + '"><input type="radio" name="' + name + '" value="' + id + '"' + (checked ? ' checked' : '') + '><span><b>' + label + '</b>' + (sub ? '<small>' + sub + '</small>' : '') + '</span></label>';
	}
	function group(name, legend, items, cls) {
		return '<fieldset class="kl-grp"><legend>' + legend + '</legend><div class="kl-chips ' + (cls || '') + '">' + items.join('') + '</div></fieldset>';
	}
	function controlsHTML() {
		var h = '';
		h += '<fieldset class="kl-grp"><legend>Beispiele zum Einsteigen</legend><div class="kl-presets">' + PRESETS.map(function (p, i) {
			return '<button type="button" class="kl-preset" data-preset="' + i + '">' + p.t + '<small>' + p.s + '</small></button>';
		}).join('') + '</div></fieldset>';
		h += group('sp', 'Zielfisch', SP_LIST.map(function (a) { return chip('sp', a[0], a[1], '', S.sp === a[0]); }));
		h += group('gw', 'Gewässer', Object.keys(WATER).map(function (k) { return chip('gw', k, WATER[k].n, WATER[k].s, S.gw === k); }));
		h += group('st', 'Struktur am Platz', Object.keys(STRUCT).map(function (k) { return chip('st', k, STRUCT[k].n, STRUCT[k].s, S.st === k); }));
		h += group('kl', 'Wasserklarheit', CLAR.map(function (c, i) { return chip('kl', i, c.n, c.s, S.kl === i); }));
		h += group('ss', 'Jahreszeit', Object.keys(SEAS).map(function (k) { return chip('ss', k, SEAS[k].n, SEAS[k].s, S.ss === k); }));
		h += '<div class="kl-temp"><label for="kl-temp" class="kl-hint" style="font-weight:600;letter-spacing:.1em;text-transform:uppercase">Wassertemperatur</label><div class="kl-temp-row"><input type="range" id="kl-temp" min="2" max="28" step="1" value="' + S.temp + '"><output id="kl-tempout" for="kl-temp">' + S.temp + ' °C</output></div><span class="kl-hint">Folgt der Jahreszeit, kannst du aber verstellen (Flachwasser, kalte Quellen, Hitzewelle).</span></div>';
		h += group('wx', 'Wetter', Object.keys(WX).map(function (k) { return chip('wx', k, WX[k].n, '', S.wx === k); }));
		h += '<label class="kl-chip kl-wind"><input type="checkbox" id="kl-wind"' + (S.wind ? ' checked' : '') + '><span><b>Wind und Welle</b><small>kräuselt die Oberfläche</small></span></label>';
		h += group('tz', 'Tageszeit', Object.keys(TIME).map(function (k) { return chip('tz', k, TIME[k].n, '', S.tz === k); }));
		return h;
	}

	function build() {
		root.innerHTML =
			'<header class="kl-head"><div><h2 class="kl-title">Köder-Labor</h2><p class="kl-sub">Raubfisch-Köder verstehen: Farbe, Gewicht, Form, Größe und Führung passend zu Gewässer, Wasserklarheit, Jahreszeit, Wetter und Licht.</p></div>' +
			'<div class="kl-tabs" role="tablist" aria-label="Bereiche">' +
			'<button type="button" class="kl-tab" role="tab" id="kl-t-lab" aria-controls="kl-p-lab" data-tab="lab">Labor</button>' +
			'<button type="button" class="kl-tab" role="tab" id="kl-t-quiz" aria-controls="kl-p-quiz" data-tab="quiz">Prüfung</button>' +
			'<button type="button" class="kl-tab" role="tab" id="kl-t-wissen" aria-controls="kl-p-wissen" data-tab="wissen">Grundlagen</button></div></header>' +

			'<section id="kl-p-lab" role="tabpanel" aria-labelledby="kl-t-lab"><div class="kl-lab">' +
			'<details class="kl-ctl" id="kl-ctl" open><summary>Bedingungen<span class="kl-tog" aria-hidden="true">ändern ▾</span></summary><div class="kl-ctl-body" id="kl-ctlbody"></div></details>' +
			'<div class="kl-res" aria-live="polite">' +
			'<dl class="kl-sum" id="kl-sum"></dl>' +
			'<div id="kl-warn" class="kl-notes"></div>' +
			'<article class="kl-sheet"><div class="kl-sheet-top"><span>Köder-Datenblatt</span><span id="kl-sheetfor"></span></div><div class="kl-sheet-art" id="kl-art"></div><dl class="kl-specs" id="kl-specs"></dl></article>' +
			'<section><h3 class="kl-h">Weitere gute Optionen</h3><div class="kl-alts" id="kl-alts"></div></section>' +
			'<section><div class="kl-scene-head"><h3 class="kl-h" style="margin:0">So führst du ihn</h3><button type="button" class="kl-btn kl-ghost" id="kl-play" aria-pressed="true">Pause</button></div>' +
			'<div class="kl-canvas-wrap" style="margin-top:10px"><canvas id="kl-cv" role="img" aria-label="Seitenansicht des Gewässers mit Köderbewegung"></canvas></div><ul class="kl-steps" id="kl-steps"></ul></section>' +
			'<section><h3 class="kl-h">Farbe in der Tiefe</h3><div class="kl-depth" id="kl-depth"></div><p class="kl-hint" style="margin-top:6px">Vereinfachte Simulation: Rot wird zuerst geschluckt, Blau und Grün bleiben am längsten.</p></section>' +
			'<section><h3 class="kl-h">Warum genau so?</h3><ul class="kl-why-list" id="kl-why"></ul></section>' +
			'<section><div class="kl-notes" id="kl-notes"></div></section>' +
			'</div></div></section>' +

			'<section id="kl-p-quiz" role="tabpanel" aria-labelledby="kl-t-quiz" hidden></section>' +
			'<section id="kl-p-wissen" role="tabpanel" aria-labelledby="kl-t-wissen" hidden></section>';
		$('#kl-ctlbody').innerHTML = controlsHTML();
	}

	/* ------------------------------------------------------------------ *
	 * Ausgabe Labor
	 * ------------------------------------------------------------------ */
	var R = null, prevSpec = {}, firstPaint = true;

	function specRow(key, label, val, small, why, changed) {
		return '<div class="kl-spec' + (changed ? ' kl-changed' : '') + '" data-k="' + key + '"><dt>' + label + '</dt><dd><span class="kl-val">' + val + (small ? ' <small>' + small + '</small>' : '') + '</span><p class="kl-why">' + why + '</p></dd></div>';
	}

	function paint() {
		R = compute(S);
		var c = R.c, l = R.top, col = R.col, s = S, fromRank = R.ranked;

		// Kennzahlen
		var lightLabel = c.Lw > 60 ? 'hell' : c.Lw > 25 ? 'gedämpft' : c.Lw > 8 ? 'dunkel' : 'fast schwarz';
		$('#kl-sum').innerHTML =
			'<div class="kl-stat"><dt>Zielzone</dt><dd>' + ZN[c.zone] + '</dd></div>' +
			'<div class="kl-stat"><dt>Licht in der Zone</dt><dd class="kl-num">' + Math.round(c.Lw) + ' %<span class="kl-meter"><i style="width:' + clamp(c.Lw, 2, 100) + '%"></i></span><small class="kl-hint" style="display:block;font:400 .78rem/1.3 var(--kl-font)">' + lightLabel + ' · Oberfläche ' + Math.round(c.L0) + ' %</small></dd></div>' +
			'<div class="kl-stat"><dt>Wassertemperatur</dt><dd class="kl-num">' + c.T + ' °C</dd></div>' +
			'<div class="kl-stat"><dt>Tempo-Bedarf</dt><dd>' + tempoWord(c.speed) + '<span class="kl-meter"><i style="width:' + Math.round(c.speed * 100) + '%"></i></span></dd></div>';

		// Hinweise (Warnungen)
		var warn = [];
		if ((SP_ODD[s.sp] || []).indexOf(s.gw) >= 0) { warn.push('Ungewöhnliche Kombination: ' + SP[s.sp].n + ' kommt in „' + WATER[s.gw].n + '“ selten vor. Die Empfehlung zeigt, wie es theoretisch funktioniert.'); }
		if (c.T < SP[s.sp].tmin) { warn.push('Mit ' + c.T + ' °C liegt das Wasser unter dem aktiven Bereich des ' + SP[s.sp].n + 's (ab ca. ' + SP[s.sp].tmin + ' °C). Rechne mit sehr wenigen Bissen und fische extrem langsam.'); }
		if (c.T > SP[s.sp].tmax) { warn.push('Mit ' + c.T + ' °C ist das Wasser für ' + (s.sp === 'forelle' ? 'die Bachforelle' : 'diesen Fisch') + ' warm' + (s.sp === 'forelle' ? ': Über etwa 17 °C stehen Forellen unter Stress, bitte nicht beangeln.' : ', Sauerstoff wird knapp. Kurze Drills, schonender Umgang, lieber früh oder spät fischen.')); }
		if ((s.ss === 'vorfr' || s.ss === 'fruehl') && (s.sp === 'hecht' || s.sp === 'zander')) { warn.push('Hecht und Zander haben im Frühjahr in den meisten Bundesländern Schonzeit. Prüfe vor dem Angeln die Landesfischereiverordnung und die Gewässerordnung.'); }
		if (s.sp === 'forelle' && (s.ss === 'herbst2' || s.ss === 'winter')) { warn.push('Die Bachforelle hat im Herbst und Winter meist Schonzeit. Mindestmaße und Zeiten sind je Bundesland und Gewässer verschieden.'); }
		$('#kl-warn').innerHTML = warn.map(function (w) { return '<p class="kl-warnbox">' + w + '</p>'; }).join('');

		// Datenblatt
		var cols = col.c.slice(); cols.id = col.id;
		$('#kl-art').innerHTML = lureSVG(l, cols, R.shape);
		$('#kl-sheetfor').textContent = SP[s.sp].n + ' · ' + WATER[s.gw].n + ' · ' + c.T + ' °C';

		var sizeTxt = (R.size[0] === R.size[1] ? de(R.size[0], R.size[0] % 1 ? 1 : 0) : de(R.size[0], R.size[0] % 1 ? 1 : 0) + '–' + de(R.size[1], R.size[1] % 1 ? 1 : 0)) + ' cm';
		var wTxt = R.weight[0] + '–' + R.weight[1] + ' g';
		var wWhy = (l.id === 'shad' || l.id === 'twister') ? 'Jigkopf-Gewicht: Faustregel etwa 1 g pro Meter Wassertiefe, dazu mehr bei Strömung oder Wind. Hier ca. ' + de(c.zoneDepth, 1) + ' m Zieltiefe' + (c.currNeed > 0.5 ? ' und Strömung' : '') + '.'
			: l.id === 'offset' ? 'Kugelblei am Offset-Haken: so leicht wie möglich, so schwer wie nötig. Schwerer, um durch dichtes Kraut zu kommen.'
			: l.id === 'dropshot' ? 'Blei am Ende der Schnur: genug, um Grundkontakt zu spüren (' + de(c.zoneDepth, 1) + ' m Tiefe' + (c.currNeed > 0.5 ? ', Strömung' : '') + ').'
			: l.id === 'pilker' ? 'Pilker müssen schnell und senkrecht fallen: Tiefe ' + de(c.zoneDepth, 1) + ' m' + (c.currNeed > 0.5 ? ' plus Strömung' : '') + ' bestimmen das Gewicht.'
			: 'Das Gewicht ergibt sich aus der Länge des Köders (' + sizeTxt + '). Schwerer bedeutet weitere Würfe, schnelleres Absinken und tieferen Lauf bei Spinnern und Blinkern.';
		var sizeWhy = [];
		sizeWhy.push('Basis für ' + SP[s.sp].n + ': ' + SP[s.sp].size[0] + '–' + SP[s.sp].size[1] + ' cm.');
		if (c.T < 10) { sizeWhy.push('Kaltes Wasser: kleinere Köder, die wenig Energie kosten.'); }
		if (s.ss === 'herbst1' || s.ss === 'herbst2') { if (s.sp === 'hecht' || s.sp === 'zander' || s.sp === 'wels') { sizeWhy.push('Im Herbst fressen sich die Räuber Reserven an: größere Köder lohnen sich.'); } }
		if (s.kl >= 2) { sizeWhy.push('Trübes Wasser: etwas größeres Profil, damit der Köder gefunden wird.'); }
		sizeWhy.push(formText(l, c, s, R.shape));

		var colorWhy = col.t + ' ' + (c.Lw < 20 ? 'Bei nur ' + Math.round(c.Lw) + ' % Licht in der Zone zählt Kontrast mehr als echte Farbe.' : c.Lw > 60 && s.kl === 0 ? 'Viel Licht und klares Wasser: Räuber prüfen genau, deshalb natürlich bleiben.' : 'Bei ' + Math.round(c.Lw) + ' % Licht und „' + CLAR[s.kl].n.toLowerCase() + '“ ist ein deutlicher Kontrast hilfreich.');
		var swatches = '<div class="kl-swatches">' + R.cols.slice(0, 3).map(function (x, i) {
			var cc = COL[x.id]; return '<span class="kl-sw' + (i === 0 ? ' kl-top' : '') + '"><i style="background:linear-gradient(' + cc.c[0] + ',' + cc.c[1] + ' 60%,' + cc.c[2] + ')"></i>' + cc.n + ' <em>' + x.score + '</em></span>';
		}).join('') + '</div>';

		var rows = {
			lure: [l.n, '', l.d + ' ' + whyLine(l, c, s)],
			color: [col.n, '', colorWhy + swatches],
			weight: [wTxt, '', wWhy],
			size: [sizeTxt, SHAPE_NAME[R.shape], sizeWhy.join(' ')],
			act: [R.act.n, '(' + tempoWord(c.speed) + ', Pause ' + de(pauseSec(c.speed), 1) + ' s)', 'Die Führung entscheidet oft mehr als der Köder. ' + (c.T < 8 ? 'Bei Kälte lange Pausen: Fische folgen, schlagen aber erst zu, wenn der Köder fast steht.' : c.T > 18 ? 'Bei Wärme darf es schneller sein: Reaktionsbisse sind häufig.' : 'Wechsle bei Bissflaute das Tempo, bevor du den Köder tauschst.')]
		};
		var labels = { lure: 'Köder', color: 'Farbe', weight: 'Gewicht', size: 'Größe & Form', act: 'Aktion' };
		var html = '';
		['lure', 'color', 'weight', 'size', 'act'].forEach(function (k) {
			var sig = rows[k][0] + '|' + rows[k][1];
			var changed = !firstPaint && prevSpec[k] !== undefined && prevSpec[k] !== sig;
			prevSpec[k] = sig;
			html += specRow(k, labels[k], rows[k][0], rows[k][1], rows[k][2], changed);
		});
		$('#kl-specs').innerHTML = html;

		// Alternativen
		var alts = fromRank.slice(1, 4).map(function (r) {
			var a = LURE[r.id], sz = lureSize(a, c, s), wt = lureWeight(a, c, s, sz), sh = shapeFor(a, c, s);
			return '<div class="kl-alt">' + lureSVG(a, col.c.slice().concat([]).map(function (x) { return x; }), sh, false) +
				'<h3>' + a.n + '</h3><span class="kl-score">Passung ' + r.score + ' / 100</span>' +
				'<p>' + de(sz[0], sz[0] % 1 ? 1 : 0) + '–' + de(sz[1], sz[1] % 1 ? 1 : 0) + ' cm · ' + wt[0] + '–' + wt[1] + ' g · ' + ACT[ACT_FOR[r.id](c.speed)].n + '</p></div>';
		}).join('');
		$('#kl-alts').innerHTML = alts;
		var altSVGs = $$('.kl-alt svg');
		altSVGs.forEach(function (svg) { svg.style.maxWidth = '180px'; });

		// Schritte
		$('#kl-steps').innerHTML = R.act.how(c.speed).map(function (t) { return '<li>' + t + '</li>'; }).join('');

		paintDepth();
		paintWhy();
		paintNotes();
		sceneInit();
		firstPaint = false;
	}

	var SHAPE_NAME = { slim: 'schlank', mid: 'mittel', tall: 'hochrückig' };

	function paintDepth() {
		var c = R.c, col = R.col, depths = [0, 1, 3, 6, 10];
		var wc = WATERCOL[S.kl];
		$('#kl-depth').innerHTML = depths.map(function (d) {
			var cols = col.c.map(function (h) { return rgb2hex(atten(h, d, S.kl, c.bright)); });
			var bg = rgb2css(wc.map(function (v) { return v * (0.18 + 0.82 * c.bright) * (1.05 - Math.min(0.8, d * 0.05 * CLAR[S.kl].mult)) * 0.8; }));
			var pct = Math.round(lightAt(c.L0, d, S.kl));
			var svg = lureSVG({ n: 'Farbvorschau', draw: 'fish', tail: 'paddle', jig: false, kind: 'soft' }, cols, 'mid', false);
			return '<div class="kl-dcell" style="background:' + bg + '">' + svg + (d === 0 ? 'Oberfläche' : d + ' m') + '<br>' + pct + ' % Licht</div>';
		}).join('');
	}

	function paintWhy() {
		var c = R.c, s = S, items = [], T = c.T;
		items.push(['Fischart', SP[s.sp].note]);
		items.push(['Temperatur',
			T < 6 ? 'Bei ' + T + ' °C ist der Stoffwechsel stark gedrosselt. Fische sparen Energie: kleine, langsam geführte Köder dicht am Maul, lange Pausen.' :
			T < 11 ? 'Bei ' + T + ' °C sind Räuber träge, aber nicht apathisch. Langsame Führung mit Pausen, den Köder lange im Sichtfeld halten.' :
			T < 17 ? 'Bei ' + T + ' °C sind Fische gut aktiv. Mittleres Tempo, Stop & Go funktioniert breit.' :
			T <= 23 ? 'Bei ' + T + ' °C ist der Stoffwechsel hoch. Schnellere Köder und größere Reviere (Oberfläche, Freiwasser) werden interessant.' :
			'Über 23 °C sinkt der Sauerstoffgehalt. Fische weichen in kühles, tiefes oder strömendes Wasser aus und jagen vor allem früh, spät und nachts.']);
		items.push(['Licht', 'An der Oberfläche herrschen etwa ' + Math.round(c.L0) + ' % Tageslicht, in der Zielzone (ca. ' + de(c.zoneDepth, 1) + ' m) bleiben ' + Math.round(c.Lw) + ' %. ' +
			(c.Lw > 60 ? 'Es ist hell: Fische sehen Details, natürliche Farben und Blitz funktionieren.' : c.Lw > 25 ? 'Gedämpftes Licht: kontrastreiche Farben und etwas Vibration helfen.' : 'Wenig Licht: Silhouette und Vibration zählen, nicht die Farbnuance.')]);
		items.push(['Wasserklarheit', [
			'Klares Wasser: Räuber sehen den Köder aus der Ferne und prüfen genau. Natürliche Farben, schlanke Profile und unauffälliges Vorfach (Fluorocarbon) zahlen sich aus.',
			'Leicht getrübt: Sicht von 1–2 m. Ein Mix aus natürlichen Mustern und Kontrast, mittlere Vibration.',
			'Trübes Wasser: Sicht unter 1 m. Fische orten über die Seitenlinie: Paddle-Tails, Rasseln und Blätter erzeugen Druckwellen, Kontrastfarben helfen.',
			'Stark getrübt: Sicht fast null. Druckwellen und Geräusch sind alles, ein großes Profil und helle oder sehr dunkle Farben für den Kontrast.'][s.kl]]);
		items.push(['Wetter & Wind', {
			sonnig: 'Grelles Licht: viele Räuber stehen tiefer oder im Schatten von Struktur. Köder nah an Deckung und Kanten führen.',
			wolkig: 'Bedeckter Himmel: gleichmäßiges Streulicht, das Raubfische mutiger macht. Die Beißzeit ist oft länger als bei Sonne.',
			regen: 'Regen kräuselt die Oberfläche und nimmt Sicht von oben. Fische fühlen sich sicher und gehen flacher. Vibration hilft.',
			nebel: 'Nebel wirkt wie Dämmerung: wenig Licht, ruhige Luft. Oberflächen- und Flachwasserköder haben gute Chancen.'
		}[s.wx] + (s.wind ? ' Wind und Welle bringen Sauerstoff und Plankton, treiben Futterfische ans Windufer. Oberflächenköder sind schlechter sichtbar, Tiefe oder mehr Vibration gleichen das aus.' : '')]);
		items.push(['Tageszeit', {
			morgen: 'Morgendämmerung: Hauptfresszeit. Der Wechsel von Dunkel zu Hell löst Jagdaktivität aus, besonders bei Zander, Hecht und Wels.',
			vormittag: 'Vormittag: Das Licht steigt, Fische ziehen sich langsam von flachen Zonen zurück. Stationen an Kanten und Struktur ansteuern.',
			mittag: 'Mittagshelle: Die schlechteste Zeit für die meisten Räuber. Tiefe Kanten, Schatten, Strömungsbereiche und feine Präsentation.',
			nachmittag: 'Nachmittag: Die Aktivität steigt wieder, Futterfische kommen ins Flache. Von Struktur zu Struktur wandern.',
			abend: 'Abenddämmerung: Zweite Hauptfresszeit. Gute Zeit für Oberfläche, Flachwasser und Silhouetten.',
			nacht: 'Nacht: Wels und Zander jagen jetzt. Fische orten über Seitenlinie und die Silhouette gegen den helleren Himmel: große, dunkle, laute Köder nahe der Oberfläche oder am Ufer.'
		}[s.tz]]);
		items.push(['Gewässer & Struktur', WATER[s.gw].note + ' ' + STRUCT[s.st].note]);
		$('#kl-why').innerHTML = items.map(function (it) { return '<li><b>' + it[0] + '</b><span>' + it[1] + '</span></li>'; }).join('');
	}

	function paintNotes() {
		var out = [];
		if (S.sp === 'hecht') { out.push('Vorfach: Zähne schneiden Monofil und Geflecht. Nimm Stahl, Titan oder mindestens 0,6 mm Fluorocarbon.'); }
		out.push('Fischereischein, Gewässerkarte, Schonzeiten und Mindestmaße gelten je Bundesland und Gewässer unterschiedlich. Informiere dich vor dem Angeln beim Verpächter oder in der Landesfischereiverordnung.');
		out.push('Das Werkzeug zeigt Faustregeln aus der Angelpraxis. Fische halten sich nicht daran: Wenn nichts beißt, ändere jeweils nur eine Sache (Tempo, Tiefe, Farbe) und beobachte, was passiert.');
		$('#kl-notes').innerHTML = out.map(function (t) { return '<p class="kl-warnbox" style="border-color:var(--kl-teal)">' + t + '</p>'; }).join('');
	}

	/* ------------------------------------------------------------------ *
	 * Szene (Canvas)
	 * ------------------------------------------------------------------ */
	var cv = null, g = null, CW = 0, CH = 0, playing = true, visible = true, t0 = 0, tPaused = 0, rafId = 0, trail = [], lastWrap = 0;
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function sceneInit() {
		if (!cv) {
			cv = $('#kl-cv'); g = cv.getContext('2d');
			sceneSize();
			if (window.ResizeObserver) { new ResizeObserver(sceneSize).observe(cv); } else { window.addEventListener('resize', sceneSize); }
			if (window.IntersectionObserver) { new IntersectionObserver(function (e) { visible = e[0].isIntersecting; kick(); }).observe(cv); }
			document.addEventListener('visibilitychange', kick);
			if (reduce) { playing = false; $('#kl-play').textContent = 'Abspielen'; $('#kl-play').setAttribute('aria-pressed', 'false'); }
		}
		trail = []; tPaused = 0; t0 = performance.now(); // Szene neu starten
		drawFrame(reduce ? 3.2 : 0);
		kick();
	}
	function sceneSize() {
		if (!cv) { return; }
		var r = cv.getBoundingClientRect(), dpr = Math.min(2, window.devicePixelRatio || 1);
		if (!r.width) { return; }
		CW = r.width; CH = r.height;
		cv.width = Math.round(CW * dpr); cv.height = Math.round(CH * dpr);
		g.setTransform(dpr, 0, 0, dpr, 0, 0);
		if (R) { drawFrame(((performance.now() - t0) / 1000) % 20); }
	}
	function kick() {
		if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }
		if (playing && visible && !document.hidden && root.offsetParent !== null) { rafId = requestAnimationFrame(loop); }
	}
	function loop(now) {
		rafId = 0;
		drawFrame(((now - t0) / 1000) % 20);
		kick();
	}

	function seeded(i) { var x = Math.sin(i * 127.1 + 311.7) * 43758.5453; return x - Math.floor(x); }

	function drawFrame(t) {
		if (!R || !CW) { return; }
		var c = R.c, w = c.w, a = R.anim, col = R.col;
		var sY = 38, D = Math.max(3, w.maxD), ppm = (CH - sY - 14) / D;
		var wc = WATERCOL[S.kl], b = 0.14 + 0.86 * c.bright;
		var full = w.maxD * 0.85;
		var depthX = function (dm) { return sY + dm * ppm; };
		var bd = function (x) {
			var d;
			if (x < CW * 0.1) { return 0.25; }
			if (S.st === 'kante') {
				d = lerp(0.4, full * 0.42, smooth((x - CW * 0.1) / (CW * 0.4)));
				return lerp(d, full, smooth((x - CW * 0.5) / (CW * 0.07)));
			}
			return lerp(0.4, full, smooth((x - CW * 0.1) / (CW * 0.45)));
		};

		g.clearRect(0, 0, CW, CH);

		// Himmel
		var sky = [lerp(8, 188, c.bright), lerp(14, 220, c.bright), lerp(30, 240, c.bright)];
		g.fillStyle = rgb2css(sky); g.fillRect(0, 0, CW, sY);
		if (S.wx === 'regen') {
			g.strokeStyle = 'rgba(255,255,255,.35)'; g.lineWidth = 1;
			for (var r = 0; r < 26; r++) { var rx = seeded(r) * CW, ry = ((seeded(r + 40) * sY + t * 40) % sY); g.beginPath(); g.moveTo(rx, ry); g.lineTo(rx - 3, ry + 8); g.stroke(); }
		}
		// Wasser
		var grd = g.createLinearGradient(0, sY, 0, CH);
		grd.addColorStop(0, rgb2css(wc.map(function (v) { return v * b * 1.3; })));
		grd.addColorStop(1, rgb2css(wc.map(function (v) { return v * b * 0.3; })));
		g.fillStyle = grd; g.fillRect(0, sY, CW, CH - sY);

		// Lichtstrahlen
		if (c.bright > 0.3 && S.kl <= 2) {
			g.save();
			g.globalAlpha = 0.07 * c.bright * (1 - S.kl * 0.25);
			g.fillStyle = '#fff';
			for (var k = 0; k < 5; k++) {
				var x1 = CW * (0.22 + k * 0.17) + Math.sin(t * 0.4 + k) * 8;
				g.beginPath(); g.moveTo(x1, sY); g.lineTo(x1 + 28, sY); g.lineTo(x1 - 36, CH); g.lineTo(x1 - 90, CH); g.closePath(); g.fill();
			}
			g.restore();
		}
		// Oberfläche
		g.strokeStyle = 'rgba(255,255,255,.65)'; g.lineWidth = 1.5; g.beginPath();
		var amp = S.wind ? 3 : 1;
		for (var x = 0; x <= CW; x += 8) { var yy = sY + Math.sin(x * 0.04 + t * (S.wind ? 3 : 1.2)) * amp; if (x === 0) { g.moveTo(x, yy); } else { g.lineTo(x, yy); } }
		g.stroke();

		// Grund
		g.beginPath(); g.moveTo(0, CH);
		for (var xb = 0; xb <= CW; xb += 6) { g.lineTo(xb, Math.min(CH, depthX(bd(xb)))); }
		g.lineTo(CW, CH); g.closePath();
		g.fillStyle = rgb2css([62 * b + 20, 54 * b + 16, 40 * b + 12]); g.fill();
		// Ufer links
		g.fillStyle = rgb2css([50 * b + 18, 80 * b + 24, 40 * b + 14]); g.fillRect(0, sY - 6, CW * 0.1, 8);

		// Struktur
		var i, bx, by;
		if (S.st === 'kraut') {
			g.strokeStyle = rgb2css([40 * b + 14, 110 * b + 24, 50 * b + 14]); g.lineWidth = 2.2;
			for (i = 0; i < 38; i++) {
				bx = CW * (0.3 + seeded(i) * 0.4); by = depthX(bd(bx)); var hh = (0.8 + seeded(i + 9) * 1.4) * ppm;
				g.beginPath(); g.moveTo(bx, by); g.quadraticCurveTo(bx + Math.sin(t + i) * 5, by - hh * 0.5, bx + Math.sin(t * 0.8 + i) * 7, by - hh); g.stroke();
			}
		} else if (S.st === 'holz') {
			g.strokeStyle = rgb2css([70 * b + 20, 50 * b + 14, 34 * b + 10]); g.lineWidth = 4; g.lineCap = 'round';
			bx = CW * 0.52; by = depthX(bd(bx));
			g.beginPath(); g.moveTo(bx, by + 4); g.lineTo(bx + 46, by - 1.1 * ppm); g.moveTo(bx + 20, by - 0.5 * ppm); g.lineTo(bx - 10, by - 1.0 * ppm); g.moveTo(bx + 34, by - 0.8 * ppm); g.lineTo(bx + 60, by - 0.5 * ppm); g.stroke();
			g.lineCap = 'butt'; g.fillStyle = rgb2css([90 * b + 24, 90 * b + 24, 90 * b + 24]);
			for (i = 0; i < 7; i++) { bx = CW * (0.6 + seeded(i) * 0.25); by = depthX(bd(bx)); g.beginPath(); g.ellipse ? g.ellipse(bx, by - 4, 10 + seeded(i + 3) * 8, 7, 0, 0, Math.PI * 2) : g.arc(bx, by - 4, 10, 0, Math.PI * 2); g.fill(); }
		}

		// Tiefenskala
		g.fillStyle = 'rgba(255,255,255,.75)'; g.font = '11px ui-monospace, Menlo, Consolas, monospace'; g.textAlign = 'right';
		var step = D > 12 ? 5 : D > 6 ? 2 : 1;
		for (var m = step; m < D; m += step) { var ym = depthX(m); g.fillRect(CW - 14, ym, 10, 1); g.fillText(m + ' m', CW - 18, ym + 4); }

		// Zielzone-Band
		var zd = c.zoneDepth;
		g.fillStyle = 'rgba(255,255,255,.08)'; g.fillRect(0, depthX(Math.max(0, zd - 0.6)), CW, 1.2 * ppm);

		// Fisch (lauert an Struktur)
		var fx = CW * 0.42 + Math.sin(t * 0.5) * 6, fdep = c.zone === 'surface' ? 0.9 : c.zone === 'bottom' ? bd(fx) - 0.5 : zd;
		fdep = Math.min(fdep, bd(fx) - 0.4);
		var fy = depthX(Math.max(0.5, fdep)), fl = clamp(18 + R.size[1] * 2.5, 36, 70);
		g.fillStyle = 'rgba(8,16,20,.5)';
		g.beginPath(); g.ellipse ? g.ellipse(fx, fy, fl * 0.5, fl * 0.15, 0, 0, Math.PI * 2) : g.arc(fx, fy, fl * 0.3, 0, Math.PI * 2); g.fill();
		g.beginPath(); g.moveTo(fx - fl * 0.5, fy); g.lineTo(fx - fl * 0.8, fy - fl * 0.2 + Math.sin(t * 4) * 4); g.lineTo(fx - fl * 0.8, fy + fl * 0.2 + Math.sin(t * 4) * 4); g.closePath(); g.fill();

		// Köderposition
		var cyc = Math.floor(t / a.cycle), ph = (t % a.cycle) / a.cycle, moving = ph < a.move, m1 = moving ? ph / a.move : 1;
		var e = m1 < 0.5 ? 2 * m1 * m1 : 1 - Math.pow(-2 * m1 + 2, 2) / 2;
		var u = a.step > 0 ? clamp((cyc + e) * a.step * (a.cycle > 2.6 ? 1.3 : 1), 0, 1) : 0.45;
		var lx = lerp(CW * 0.93, CW * 0.16, u);
		var tgt = c.zone === 'bottom' ? bd(lx) - 0.22 : c.zone === 'surface' ? 0.12 : zd;
		var rise = a.lift > 0 ? a.lift * (moving ? e : (1 - (ph - a.move) / (1 - a.move))) : 0;
		var dep = clamp(tgt - rise, 0.12, Math.max(0.2, bd(lx) - 0.12));
		var ly = depthX(dep) + (a.shake ? Math.sin(t * 40) * a.shake : 0);
		var ang = (moving ? Math.sin(m1 * Math.PI * 2) * a.angle * Math.PI / 180 : 0) + (a.wob ? Math.sin(t * 22) * a.wob * Math.PI / 180 : 0);

		// Spur
		if (!trail.length || Math.abs(trail[trail.length - 1][0] - lx) > 3) { trail.push([lx, ly]); if (trail.length > 40) { trail.shift(); } }
		if (u < lastWrap - 0.5) { trail = []; }
		lastWrap = u;
		g.strokeStyle = 'rgba(255,255,255,.25)'; g.lineWidth = 1.5; g.setLineDash([2, 4]); g.beginPath();
		trail.forEach(function (p, idx) { if (idx === 0) { g.moveTo(p[0], p[1]); } else { g.lineTo(p[0], p[1]); } });
		g.stroke(); g.setLineDash([]);

		// Schnur und Rute
		g.strokeStyle = 'rgba(255,255,255,.55)'; g.lineWidth = 1;
		g.beginPath(); g.moveTo(CW * 0.06, sY - 28); g.quadraticCurveTo(lerp(CW * 0.06, lx, 0.5), sY - 2 + (ly - sY) * 0.15, lx, ly); g.stroke();
		g.strokeStyle = '#2b2b2b'; g.lineWidth = 3; g.beginPath(); g.moveTo(CW * 0.03, sY + 12); g.lineTo(CW * 0.06, sY - 28); g.stroke();

		// Köder
		var cc = atten(col.c[1], dep, S.kl, c.bright), cb = atten(col.c[0], dep, S.kl, c.bright), size = clamp(R.size[1] * 3.2, 16, 62);
		g.save(); g.translate(lx, ly); g.rotate(ang);
		if (col.glow > 0.8 && c.Lw < 25) { g.shadowColor = 'rgba(160,255,190,.9)'; g.shadowBlur = 14; }
		g.fillStyle = rgb2css(cc);
		g.beginPath(); g.moveTo(-size / 2, 0);
		g.quadraticCurveTo(-size * 0.15, -size * 0.2, size * 0.45, -size * 0.06); g.lineTo(size * 0.45, size * 0.06); g.quadraticCurveTo(-size * 0.15, size * 0.22, -size / 2, 0); g.fill();
		g.shadowBlur = 0;
		g.fillStyle = rgb2css(cb); g.beginPath(); g.moveTo(-size / 2, 0); g.quadraticCurveTo(-size * 0.15, -size * 0.2, size * 0.45, -size * 0.06); g.lineTo(size * 0.45, 0); g.lineTo(-size / 2, 0); g.fill();
		g.fillStyle = rgb2css(cc); g.beginPath(); g.moveTo(size * 0.45, 0); g.lineTo(size * 0.7, -size * 0.14 + Math.sin(t * 16) * 2); g.lineTo(size * 0.7, size * 0.14 + Math.sin(t * 16) * 2); g.closePath(); g.fill();
		g.restore();

		// Popper-Ring
		if (a.surface && ph < 0.5) {
			g.strokeStyle = 'rgba(255,255,255,' + (0.6 - ph) + ')'; g.lineWidth = 1.5;
			g.beginPath(); g.ellipse ? g.ellipse(lx, sY + 1, 6 + ph * 60, 2 + ph * 6, 0, 0, Math.PI * 2) : g.arc(lx, sY + 1, 6 + ph * 40, 0, Math.PI * 2); g.stroke();
		}
		// Beschriftung
		g.textAlign = 'left'; g.fillStyle = 'rgba(255,255,255,.85)';
		g.fillText(R.act.n + ' · ' + R.top.n, 10, CH - 8);
	}

	/* ------------------------------------------------------------------ *
	 * Prüfung
	 * ------------------------------------------------------------------ */
	var Q = null, stats = { rounds: 0, pts: 0, max: 0 };
	try { var sv = JSON.parse(store.get('kl-stats') || 'null'); if (sv && sv.rounds >= 0) { stats = sv; } } catch (e) { /* ignorieren */ }

	function scenario() {
		var sp = pick(Object.keys(SP)), odd = SP_ODD[sp] || [], gws = Object.keys(WATER).filter(function (k) { return odd.indexOf(k) < 0; });
		var ss = pick(Object.keys(SEAS)), tmp = clamp(SEAS[ss].t + Math.round((Math.random() - 0.5) * 4), 2, 26);
		if (sp === 'wels' && tmp < 14) { ss = pick(['sommer', 'herbst1']); tmp = SEAS[ss].t; }
		if (sp === 'forelle' && tmp > 16) { ss = pick(['fruehl', 'herbst2', 'vorfr']); tmp = SEAS[ss].t; }
		return { sp: sp, gw: pick(gws), st: pick(Object.keys(STRUCT)), kl: pick([0, 1, 2, 3]), ss: ss, temp: tmp, wx: pick(Object.keys(WX)), wind: Math.random() < 0.3, tz: pick(Object.keys(TIME)) };
	}
	function speedBucket(sp) { return clamp(Math.floor(sp * 4), 0, 3); }
	var BUCKETS = [['Sehr langsam', 'kriechend, lange Pausen, Grundkontakt'], ['Langsam', 'ruhig, längere Pausen'], ['Mittel', 'gleichmäßig, kurze Stopps'], ['Zügig bis schnell', 'durchgehend, wenig Pausen']];
	var WBANDS = [['bis 7 g', 0, 7], ['8–15 g', 8, 15], ['16–30 g', 16, 30], ['über 30 g', 31, 999]];
	function wBucket(w) { var m = (w[0] + w[1]) / 2; return m <= 7.5 ? 0 : m <= 15.5 ? 1 : m <= 30.5 ? 2 : 3; }

	function newQuiz() {
		var s = scenario(), r = compute(s);
		var topL = r.ranked[0].id, distr = shuffle(r.ranked.slice(5).map(function (x) { return x.id; })).slice(0, 3);
		var lureOpts = shuffle([topL].concat(distr));
		var topC = r.cols[0].id, cdistr = shuffle(r.cols.slice(4).map(function (x) { return x.id; })).slice(0, 3);
		var colOpts = shuffle([topC].concat(cdistr));
		Q = { s: s, r: r, lureOpts: lureOpts, colOpts: colOpts, done: false };
		renderQuiz();
	}
	function rankScore(list, id) {
		var idx = list.map(function (x) { return x.id; }).indexOf(id);
		return idx === 0 ? 2 : idx <= 2 ? 1 : 0;
	}
	function renderQuiz() {
		var s = Q.s, r = Q.r, c = r.c;
		var rank = rankName(stats.pts / Math.max(1, stats.max));
		var h = '<div class="kl-quiz"><div>' +
			'<div class="kl-card"><h3 class="kl-h" style="margin:0">Aufgabe</h3><dl class="kl-scn">' +
			[['Zielfisch', SP[s.sp].n], ['Gewässer', WATER[s.gw].n], ['Struktur', STRUCT[s.st].n], ['Wasser', CLAR[s.kl].n + ', ' + s.temp + ' °C'], ['Jahreszeit', SEAS[s.ss].n], ['Wetter', WX[s.wx].n + (s.wind ? ', Wind' : '')], ['Tageszeit', TIME[s.tz].n]]
				.map(function (x) { return '<div><dt>' + x[0] + '</dt><dd>' + x[1] + '</dd></div>'; }).join('') +
			'</dl></div>' +
			'<div class="kl-card"><div class="kl-rank">' + rank + '</div><p class="kl-hint">' + stats.rounds + ' Aufgaben · ' + stats.pts + ' von ' + stats.max + ' Punkten</p></div></div>' +
			'<form class="kl-q" id="kl-qform" novalidate>' +
			'<div class="kl-card"><fieldset class="kl-grp"><legend>1. Welcher Köder passt am besten?</legend><div class="kl-chips">' + Q.lureOpts.map(function (id) { return chip('q1', id, LURE[id].n, '', false); }).join('') + '</div></fieldset></div>' +
			'<div class="kl-card"><fieldset class="kl-grp"><legend>2. Welche Farbe?</legend><div class="kl-chips">' + Q.colOpts.map(function (id) { var cc = COL[id]; return '<label class="kl-chip"><input type="radio" name="q2" value="' + id + '"><span><b><i class="kl-dot" style="background:linear-gradient(' + cc.c[0] + ',' + cc.c[1] + ' 60%,' + cc.c[2] + ')"></i>' + cc.n + '</b></span></label>'; }).join('') + '</div></fieldset></div>' +
			'<div class="kl-card"><fieldset class="kl-grp"><legend>3. Wie schnell führst du ihn?</legend><div class="kl-chips">' + BUCKETS.map(function (b, i) { return chip('q3', i, b[0], b[1], false); }).join('') + '</div></fieldset></div>' +
			'<div class="kl-card"><fieldset class="kl-grp"><legend>4. Wie schwer sollte der beste Köder sein?</legend><div class="kl-chips">' + WBANDS.map(function (b, i) { return chip('q4', i, b[0], '', false); }).join('') + '</div></fieldset></div>' +
			'<div class="kl-actions"><button type="submit" class="kl-btn" id="kl-qcheck">Antworten prüfen</button><button type="button" class="kl-btn kl-ghost" id="kl-qnew">Andere Aufgabe</button></div>' +
			'<div id="kl-qfb" aria-live="polite"></div></form></div>';
		$('#kl-p-quiz').innerHTML = h;
	}
	function rankName(f) {
		if (stats.rounds < 1) { return 'Noch keine Wertung'; }
		return f < 0.4 ? 'Grundangler' : f < 0.65 ? 'Spinnfischer' : f < 0.85 ? 'Gewässerleser' : 'Raubfischprofi';
	}
	function checkQuiz(ev) {
		ev.preventDefault();
		if (Q.done) { return; }
		var f = $('#kl-qform'), val = function (n) { var el = f.querySelector('input[name="' + n + '"]:checked'); return el ? el.value : null; };
		var a1 = val('q1'), a2 = val('q2'), a3 = val('q3'), a4 = val('q4');
		if (a1 === null || a2 === null || a3 === null || a4 === null) {
			$('#kl-qfb').innerHTML = '<p class="kl-warnbox">Bitte beantworte alle vier Fragen.</p>'; return;
		}
		var r = Q.r, c = r.c, pts = 0, fb = [];
		var p1 = rankScore(r.ranked, a1); pts += p1;
		fb.push([p1, 'Köder: ' + LURE[a1].n + (p1 === 2 ? ' – genau richtig.' : p1 === 1 ? ' – brauchbar, aber besser wäre ' + r.top.n + '. ' + whyLine(r.top, c, Q.s) : ' – passt hier eher nicht. Besser: ' + r.top.n + '. ' + whyLine(r.top, c, Q.s))]);
		var p2 = rankScore(r.cols, a2); pts += p2;
		fb.push([p2, 'Farbe: ' + COL[a2].n + (p2 === 2 ? ' – genau richtig. ' : p2 === 1 ? ' – gut, ideal wäre ' + r.col.n + '. ' : ' – eher ungünstig. Ideal: ' + r.col.n + '. ') + r.col.t + ' (Licht in der Zone: ' + Math.round(c.Lw) + ' %)']);
		var b = speedBucket(c.speed), d3 = Math.abs(+a3 - b), p3 = d3 === 0 ? 2 : d3 === 1 ? 1 : 0; pts += p3;
		fb.push([p3, 'Tempo: ' + BUCKETS[+a3][0] + (p3 === 2 ? ' – richtig.' : ' – empfohlen: ' + BUCKETS[b][0] + '.') + ' Bei ' + Q.s.temp + ' °C, ' + WX[Q.s.wx].n.toLowerCase() + ' und ' + TIME[Q.s.tz].n + ' ist das Tempo „' + tempoWord(c.speed) + '“ passend.']);
		var wb = wBucket(r.weight), d4 = Math.abs(+a4 - wb), p4 = d4 === 0 ? 2 : d4 === 1 ? 1 : 0; pts += p4;
		fb.push([p4, 'Gewicht: ' + WBANDS[+a4][0] + (p4 === 2 ? ' – passt.' : ' – empfohlen: ' + WBANDS[wb][0] + ' (' + r.weight[0] + '–' + r.weight[1] + ' g).') + ' Zieltiefe ca. ' + de(c.zoneDepth, 1) + ' m.']);
		Q.done = true;
		stats.rounds++; stats.pts += pts; stats.max += 8;
		store.set('kl-stats', JSON.stringify(stats));
		$('#kl-qfb').innerHTML = '<div class="kl-card kl-fb"><div class="kl-pts">' + pts + ' / 8</div>' +
			fb.map(function (x) { return '<p class="kl-line ' + (x[0] === 2 ? 'kl-good' : x[0] === 1 ? 'kl-mid' : 'kl-bad') + '">' + x[1] + '</p>'; }).join('') +
			'<div class="kl-actions"><button type="button" class="kl-btn" id="kl-qnext">Nächste Aufgabe</button><button type="button" class="kl-btn kl-ghost" id="kl-qlab">Im Labor ansehen</button></div></div>';
		$('#kl-qcheck').disabled = true;
		var fbEl = $('#kl-qfb'); if (fbEl.scrollIntoView) { fbEl.scrollIntoView({ block: 'nearest', behavior: reduce ? 'auto' : 'smooth' }); }
	}

	/* ------------------------------------------------------------------ *
	 * Grundlagen
	 * ------------------------------------------------------------------ */
	function wissenHTML() {
		var facts = [
			['Licht bestimmt das Fressen', 'Raubfische jagen am besten, wenn sie Beute gegen einen helleren Hintergrund sehen und selbst im Dunkeln stehen: in der Dämmerung, bei bedecktem Himmel und in leicht getrübtem Wasser. Grelles Mittagslicht macht sie vorsichtig.'],
			['Farben verschwinden mit der Tiefe', 'Wasser schluckt zuerst Rot und Orange, dann Gelb, zuletzt Grün und Blau. Ab ca. 3–5 m wirkt ein roter Köder grau. Weiß, Chartreuse und Glow bleiben am längsten sichtbar.'],
			['Seitenlinie: das Druckwellen-Sinnesorgan', 'Fische spüren Vibrationen aus mehreren Metern, auch ohne Sicht. In trübem Wasser, nachts und bei Wels und Zander ist das der wichtigste Sinn. Paddle-Tails, Rasseln und Spinnerblätter nutzen das aus.'],
			['Temperatur steuert den Stoffwechsel', 'Je kälter das Wasser, desto weniger Energie wollen Fische ausgeben. Unter 8 °C: klein, langsam, lange Pausen. Über 16 °C: schneller, größer, flacher. Über 23 °C: Sauerstoff wird knapp, Randzeiten fischen.'],
			['Silhouette vor Farbe', 'Bei wenig Licht erkennen Fische keine Farben mehr, sondern nur noch Kontrast. Dunkle Köder gegen hellen Himmel (oder helle gegen dunklen Grund) sind dann die beste Wahl.'],
			['Eine Variable nach der anderen', 'Wenn es nicht läuft, ändere jeweils nur Tempo, Tiefe oder Farbe, nie alles zugleich. Nur so lernst du, was gewirkt hat. Das Labor zeigt dir mit „neu“-Markierungen, was sich pro Änderung verschiebt.']
		];
		var h = '<div class="kl-know"><section><h3 class="kl-h">Grundprinzipien</h3><div class="kl-grid">' + facts.map(function (f) { return '<div class="kl-fact"><h3>' + f[0] + '</h3><p>' + f[1] + '</p></div>'; }).join('') + '</div></section>';
		h += '<section class="kl-colortest"><h3 class="kl-h" style="margin:0">Farbtest: Was bleibt in der Tiefe?</h3><div class="kl-colortest-ctl">' +
			'<label for="kl-wd">Tiefe <input type="range" id="kl-wd" min="0" max="12" step="1" value="4"> <output id="kl-wdo" for="kl-wd">4 m</output></label>' +
			'<label for="kl-wc">Wasser <select id="kl-wc" class="kl-select">' + CLAR.map(function (x, i) { return '<option value="' + i + '"' + (i === 0 ? ' selected' : '') + '>' + x.n + ' (' + x.s + ')</option>'; }).join('') + '</select></label></div>' +
			'<div class="kl-ct-row" id="kl-ct"></div></section>';
		h += '<section><h3 class="kl-h">Köder-Lexikon</h3><div class="kl-lexi">' + Object.keys(LURE).map(function (k) {
			var l = LURE[k]; l.id = k;
			return '<div class="kl-fact">' + lureSVG(l, ['#56707f', '#c8d2d6', '#f3f4f2'], 'mid', true) + '<h3>' + l.n + '</h3><p>' + l.d + '</p><div class="kl-tags"><span>' + l.z.map(function (z) { return ZN[z].split(' (')[0]; }).join(' · ') + '</span><span>Tempo ' + tempoWord(l.sp[0]) + ' bis ' + tempoWord(l.sp[1]) + '</span><span>' + (l.weed >= 0.8 ? 'hängerarm' : l.weed >= 0.5 ? 'bedingt hängerarm' : 'Hängergefahr') + '</span></div></div>';
		}).join('') + '</div></section></div>';
		return h;
	}
	var TESTCOLS = [['Rot', '#d21f1f'], ['Orange', '#ff7a00'], ['Gelb', '#f4d90a'], ['Chartreuse', '#a8dc00'], ['Grün', '#2a9d3f'], ['Blau', '#2a5ba8'], ['Weiß', '#ffffff'], ['Schwarz', '#101216']];
	function colorTest() {
		var d = +$('#kl-wd').value, k = +$('#kl-wc').value, wc = WATERCOL[k];
		$('#kl-wdo').textContent = d + ' m';
		var bg = rgb2css(wc.map(function (v) { return v * (1.05 - Math.min(0.8, d * 0.05 * CLAR[k].mult)) * 0.75; }));
		$('#kl-ct').innerHTML = TESTCOLS.map(function (t) {
			return '<div class="kl-ct-cell" style="background:' + bg + '"><i style="background:' + rgb2css(atten(t[1], d, k, 1)) + '"></i>' + t[0] + '</div>';
		}).join('');
	}

	/* ------------------------------------------------------------------ *
	 * Interaktion
	 * ------------------------------------------------------------------ */
	var built = { quiz: false, wissen: false };
	function showTab(name) {
		['lab', 'quiz', 'wissen'].forEach(function (t) {
			var on = t === name, btn = $('#kl-t-' + t), p = $('#kl-p-' + t);
			btn.setAttribute('aria-selected', on ? 'true' : 'false'); btn.setAttribute('tabindex', on ? '0' : '-1');
			p.hidden = !on;
		});
		if (name === 'quiz' && !built.quiz) { built.quiz = true; newQuiz(); }
		if (name === 'wissen' && !built.wissen) {
			built.wissen = true; $('#kl-p-wissen').innerHTML = wissenHTML(); colorTest();
			$('#kl-wd').addEventListener('input', colorTest); $('#kl-wc').addEventListener('change', colorTest);
		}
		kick();
	}
	function syncControls() {
		['sp', 'gw', 'st', 'kl', 'ss', 'wx', 'tz'].forEach(function (n) {
			var el = root.querySelector('input[name="' + n + '"][value="' + S[n] + '"]'); if (el) { el.checked = true; }
		});
		$('#kl-temp').value = S.temp; $('#kl-tempout').textContent = S.temp + ' °C'; $('#kl-wind').checked = S.wind;
	}
	function setTheme() {
		// Artefakt-Host: dem Seitenthema folgen (im WordPress-Betrieb bleibt der Shortcode-Wert).
		var el = document.documentElement, t = el.getAttribute('data-theme');
		if (root.getAttribute('data-kl-follow') === '1' && (t === 'dark' || t === 'light')) { root.setAttribute('data-kl-theme', t); }
	}

	function init() {
		build();
		setTheme();
		if (window.MutationObserver) { new MutationObserver(setTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] }); }

		var ctl = $('#kl-ctl'), mq = window.matchMedia ? window.matchMedia('(min-width: 900px)') : null;
		var fitCtl = function () { if (mq && mq.matches) { ctl.open = true; } };
		if (mq) { if (mq.addEventListener) { mq.addEventListener('change', fitCtl); } else if (mq.addListener) { mq.addListener(fitCtl); } if (!mq.matches) { ctl.open = false; } }

		root.addEventListener('change', function (e) {
			var t = e.target, n = t.name;
			if (!t.closest('#kl-ctlbody')) { return; }
			if (t.id === 'kl-wind') { S.wind = t.checked; }
			else if (n === 'kl') { S.kl = +t.value; }
			else if (n === 'ss') { S.ss = t.value; S.temp = SEAS[t.value].t; $('#kl-temp').value = S.temp; $('#kl-tempout').textContent = S.temp + ' °C'; }
			else if (n && S.hasOwnProperty(n)) { S[n] = t.value; }
			paint();
		});
		root.addEventListener('input', function (e) {
			if (e.target.id === 'kl-temp') { S.temp = +e.target.value; $('#kl-tempout').textContent = S.temp + ' °C'; paint(); }
		});
		root.addEventListener('click', function (e) {
			var t = e.target.closest ? e.target.closest('button') : null;
			if (!t) { return; }
			if (t.hasAttribute('data-tab')) { showTab(t.getAttribute('data-tab')); }
			else if (t.hasAttribute('data-preset')) { S = JSON.parse(JSON.stringify(PRESETS[+t.getAttribute('data-preset')].v)); syncControls(); paint(); if (!mq || !mq.matches) { ctl.open = false; } var res = $('.kl-res'); if (res && res.scrollIntoView) { res.scrollIntoView({ block: 'start', behavior: reduce ? 'auto' : 'smooth' }); } }
			else if (t.id === 'kl-play') {
				playing = !playing; t.textContent = playing ? 'Pause' : 'Abspielen'; t.setAttribute('aria-pressed', playing ? 'true' : 'false');
				if (playing) { t0 = performance.now() - tPaused * 1000; } else { tPaused = ((performance.now() - t0) / 1000) % 20; }
				kick();
			}
			else if (t.id === 'kl-qnew' || t.id === 'kl-qnext') { newQuiz(); }
			else if (t.id === 'kl-qlab') { S = JSON.parse(JSON.stringify(Q.s)); syncControls(); paint(); showTab('lab'); }
		});
		root.addEventListener('submit', function (e) { if (e.target.id === 'kl-qform') { checkQuiz(e); } });
		$('.kl-tabs').addEventListener('keydown', function (e) {
			var tabs = ['lab', 'quiz', 'wissen'], cur = tabs.indexOf(document.activeElement && document.activeElement.getAttribute('data-tab'));
			if (cur < 0 || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) { return; }
			var nx = tabs[(cur + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
			showTab(nx); $('#kl-t-' + nx).focus();
		});

		paint();
		var start = root.getAttribute('data-kl-tab');
		showTab(start === 'quiz' || start === 'wissen' ? start : 'lab');
	}

	init();
})();
