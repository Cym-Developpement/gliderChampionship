<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="openaip-key" content="{{ env('OPENAIP_API_KEY', '') }}">
    <meta name="openaip-tiles-url" content="{{ env('OPENAIP_TILES_URL', 'https://tiles.openaip.net/tiles/{z}/{x}/{y}.png?apiKey={API_KEY}') }}">
    <title>Glider Championship - Live</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>

    <style>
        #map { height: 100vh; }
        .score-badge { min-width: 3rem; }
        .glider-div-icon { background: transparent; border: 0; }
        /* Transition plus lente pour l'alternance du classement */
        #pilotRankingList.fade { transition: opacity 1s ease; }
        /* Alerte proximité turnpoint */
        @keyframes blink-gold {
            0%, 100% { background-color: #6c757d; }
            50% { background-color: #FFD700; color: #000; }
        }
        .blink-gold { animation: blink-gold 1s ease-in-out infinite; }
    </style>
</head>
<body>
<div class="container-fluid ps-0">
    <div class="row g-0">
        <div class="col-12 col-lg-8">
            <div id="map"></div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="p-3 p-lg-4 d-flex flex-column min-vh-100">
                <h1 class="h4 mb-1" id="compName">Classement live</h1>
                <div class="text-muted mb-3"><small id="compSub">–</small></div>
                <h2 class="h6 mb-2" id="rankingTitle">Classement pilotes</h2>
                <div class="list-group flex-grow-1 fade show" id="pilotRankingList" aria-live="polite" aria-busy="true"></div>
                <div class="mt-auto small text-muted border-top pt-2">
                    Mise à jour: <span id="lastUpdate">-</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Overlay classement général (affiché quand pas de journée active) -->
<div id="generalOverlay" class="d-none" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.92);z-index:9999;overflow:hidden;">
    <div class="container-fluid py-4 px-4 h-100 d-flex flex-column">
        <h1 class="text-center mb-1" id="generalOverlayTitle">Classement général</h1>
        <p class="text-center text-muted mb-3" id="generalOverlaySub"></p>
        <div id="generalRankingTable" class="flex-grow-1"></div>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
// Seuils de proximité turnpoint (en mètres) — valeurs par défaut, écrasées par /api/settings
let PROXIMITY_FAR_M = 2000;   // seuil bleu
let PROXIMITY_NEAR_M = 1000;  // seuil vert
let mapViewMode = 'start_radius';
let mapRadiusKm = null;  // override du rayon (km), null = utilise competition.json
let mapZoom = null;       // override du zoom, null = auto via fitBounds

// Configuration des endpoints API (DB)
// L'application peut être servie dans un sous-répertoire : toutes les URL
// sont préfixées par la base calculée par Laravel plutôt que par « / ».
const BASE = @json(rtrim(url('/'), '/'));
const PARTICIPANTS_URL = BASE + '/api/participants';
const COMPETITION_URL = BASE + '/api/competition';
const PILOTS_URL = BASE + '/api/pilots';
const LIVE_PILOTS_URL = BASE + '/api/live-pilots';
const GENERAL_RANKING_URL = BASE + '/api/general-ranking';
const TURNPOINTS_URL = BASE + '/api/turnpoints';
const SETTINGS_URL = BASE + '/api/settings';

// Carte Leaflet
const map = L.map('map', { zoomControl: false, scrollWheelZoom: false, dragging: false });
const tileLayer = L.tileLayer(BASE + '/tiles/osm/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
});
const baseLayers = { 'OSM (cache)': tileLayer };

tileLayer.addTo(map);

// Overlay OpenAIP via proxy si une clé est fournie
const OPENAIP_API_KEY = document.querySelector('meta[name="openaip-key"]')?.content || '';
let openAipLayer = null;
if (OPENAIP_API_KEY) {
    openAipLayer = L.tileLayer(BASE + '/tiles/openaip/{z}/{x}/{y}.png', {
        opacity: 0.6,
        maxZoom: 19,
        attribution: 'Map data &copy; OpenAIP'
    }).addTo(map);
    L.control.layers(baseLayers, { 'OpenAIP (cache)': openAipLayer }).addTo(map);
}

// État client
let participantIdToMarker = new Map();
let participants = [];
let participantIdToLastPos = new Map();
let pilots = [];
// Affichage: uniquement classement pilotes
let turnpointsLayer = L.layerGroup().addTo(map);
let posIntervalId = null;
let liveIntervalId = null;
let isPaused = false;
let pilotPage = 0; // pagination par blocs de 10
// Proximité turnpoints
let proximityCircles = new Map(); // turnpointId -> L.circle
let turnpoints = [];              // turnpoints chargés
let pilotsNearTurnpoint = new Set(); // pilot IDs proches d'un turnpoint
let validatedPairs = new Set(); // Set of "pilotId_turnpointId" already sent
let devMode = false; // activé si DEV_FAKE_POSITIONS=true côté serveur
let activeDayInfo = null; // { id, dayNumber } or null
let participantIdToRank = new Map(); // participant id -> rang classement
let participantIdToNoRank = new Set(); // participant ids with 0 points
let totalRanked = 0; // nombre total de participants classés

// Génère une icône pivotée selon le cap (hdg) avec marker numéroté par rang
function buildRotatedIcon(angleDeg, rank, noRank) {
    const angle = Number.isFinite(angleDeg) ? angleDeg : 0;
    if (noRank) {
        const html = `<img src="${BASE}/markers/marker_no_classement.png" width="82" height="75" style="transform: rotate(${angle}deg); transform-origin: 50% 50%;" alt="glider"/>`;
        return L.divIcon({ className: 'glider-div-icon', html, iconSize: [82, 75], iconAnchor: [41, 38] });
    }
    const isLast = rank && totalRanked > 1 && rank === totalRanked;
    const markerNum = (rank && rank >= 1 && rank <= 50) ? rank : null;
    const src = isLast ? `${BASE}/markers/marker_last.png` : (markerNum ? `${BASE}/markers/marker_${markerNum}.png` : `${BASE}/glider.png`);
    const html = `<img src="${src}" width="82" height="75" style="transform: rotate(${angle}deg); transform-origin: 50% 50%;" alt="glider"/>`;
    return L.divIcon({
        className: 'glider-div-icon',
        html,
        iconSize: [82, 75],
        iconAnchor: [41, 38]
    });
}

// Normalisation des immatriculations (REG): upper-case et suppression des caractères non alphanumériques
function normalizeReg(value) {
    if (!value) return '';
    return String(value).toUpperCase().replace(/[^A-Z0-9]/g, '');
}

// Distance haversine en mètres
function haversineDistance(lat1, lng1, lat2, lng2) {
    const R = 6371000;
    const toRad = v => v * Math.PI / 180;
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// Mapping reg normalisée -> pilot id
function buildRegToPilotId() {
    const m = new Map();
    for (const p of pilots) {
        const key = normalizeReg(p.reg);
        if (key) m.set(key, p.id);
    }
    return m;
}

// Mapping reg normalisée -> participant id
function buildRegToParticipantId() {
    const m = new Map();
    for (const p of participants) {
        const key = normalizeReg(p.reg);
        if (key) m.set(key, p.id);
    }
    return m;
}

function updateProximityAlerts() {
    const newPilotsNear = new Set();
    const regToPilotId = buildRegToPilotId();
    const activeTpIds = new Set();

    for (const tp of turnpoints) {
        if (typeof tp.lat !== 'number' || typeof tp.lng !== 'number') continue;
        // Compute min distance only for pilots who haven't already validated this turnpoint
        let minDist = Infinity;

        for (const [pid, pos] of participantIdToLastPos.entries()) {
            const part = participants.find(p => p.id === pid);
            if (!part) continue;
            const pilotId = regToPilotId.get(normalizeReg(part.reg));
            // Skip pilots who already validated this turnpoint
            if (pilotId != null && tp.id != null && validatedPairs.has(`${pilotId}_${tp.id}`)) continue;
            const dist = haversineDistance(tp.lat, tp.lng, pos.lat, pos.lng);
            if (dist < minDist) {
                minDist = dist;
            }
        }

        const tpKey = tp.id ?? `${tp.lat}_${tp.lng}`;

        if (minDist <= PROXIMITY_FAR_M) {
            activeTpIds.add(tpKey);
            const isNear = minDist <= PROXIMITY_NEAR_M;
            const color = isNear ? '#28a745' : '#007bff';
            const radius = isNear ? PROXIMITY_NEAR_M : PROXIMITY_FAR_M;

            // Supprimer l'ancien cercle s'il existe pour forcer le re-render (changement couleur/rayon)
            if (proximityCircles.has(tpKey)) {
                map.removeLayer(proximityCircles.get(tpKey));
                proximityCircles.delete(tpKey);
            }
            const circle = L.circle([tp.lat, tp.lng], {
                radius,
                color,
                fillColor: color,
                fillOpacity: 0.18,
                weight: 2,
                dashArray: '6 4'
            }).addTo(map);
            proximityCircles.set(tpKey, circle);

            // Identifier tous les pilotes proches de ce turnpoint (seulement ceux non validés)
            for (const [pid, pos] of participantIdToLastPos.entries()) {
                const dist = haversineDistance(tp.lat, tp.lng, pos.lat, pos.lng);
                if (dist <= PROXIMITY_FAR_M) {
                    const part = participants.find(p => p.id === pid);
                    if (part) {
                        const pilotId = regToPilotId.get(normalizeReg(part.reg));
                        if (pilotId != null) {
                            // Skip already validated pairs for alerts and auto-validation
                            if (tp.id != null && validatedPairs.has(`${pilotId}_${tp.id}`)) continue;
                            newPilotsNear.add(pilotId);
                            // Auto-validate turnpoint when pilot is within NEAR threshold
                            if (dist <= PROXIMITY_NEAR_M && tp.id != null) {
                                const pairKey = `${pilotId}_${tp.id}`;
                                validatedPairs.add(pairKey);
                                fetch(BASE + '/api/validate-turnpoint', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                    },
                                    body: JSON.stringify({ pilot_id: pilotId, turnpoint_id: tp.id })
                                }).catch(err => console.warn('validate-turnpoint error', err));
                            }
                        }
                    }
                }
            }
        }
    }

    // Supprimer les cercles des turnpoints plus actifs
    for (const [tpKey, circle] of proximityCircles.entries()) {
        if (!activeTpIds.has(tpKey)) {
            map.removeLayer(circle);
            proximityCircles.delete(tpKey);
        }
    }

    pilotsNearTurnpoint = newPilotsNear;
}

function formatTime(ts) {
    try { return new Date(ts).toLocaleTimeString(); } catch { return '-'; }
}

function buildMarkerTooltip(participant) {
    const regKey = normalizeReg(participant.reg);
    const pilot = regKey ? pilots.find(p => normalizeReg(p.reg) === regKey) : null;
    const pilotName = pilot ? pilot.name : '';
    const glider = [participant.gliderBrand, participant.gliderModel].filter(Boolean).join(' ');
    const lines = [pilotName, glider, participant.reg].filter(Boolean);
    return lines.join('<br>');
}

function upsertMarker(p) {
    const key = p.id;
    const has = participantIdToMarker.has(key);
    const latlng = [p.lat, p.lng];
    const rank = participantIdToRank.get(key);
    const noRank = participantIdToNoRank.has(key);
    const icon = buildRotatedIcon(p.hdg, rank, noRank);
    const participant = participants.find(part => part.id === key);
    const tooltip = participant ? buildMarkerTooltip(participant) : '';
    if (!has) {
        const marker = L.marker(latlng, { icon, interactive: true, draggable: devMode }).addTo(map);
        if (tooltip) marker.bindTooltip(tooltip, { permanent: false, direction: 'top', offset: [0, -10] });
        if (devMode) {
            marker.on('dragend', function(e) {
                const ll = e.target.getLatLng();
                fetch(BASE + '/api/dev/positions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ participant_id: key, lat: ll.lat, lng: ll.lng })
                });
            });
        }
        participantIdToMarker.set(key, marker);
    } else {
        const marker = participantIdToMarker.get(key);
        marker.setLatLng(latlng);
        marker.setIcon(icon);
        if (tooltip) marker.setTooltipContent(tooltip);
    }
}

// plus de classement planeurs dans la vue

function renderPilotRanking(live) {
    const list = document.getElementById('pilotRankingList');
    list.innerHTML = '';
    const sorted = [...pilots]
        .map(p => ({...p, points: (live.points[String(p.id)] ?? 0)}))
        .sort((a,b) => b.points - a.points);
    // Construire le mapping participantId -> rang pour les markers sur la carte
    const regToPidLocal = new Map();
    for (const part of participants) {
        const key = normalizeReg(part.reg);
        if (key) regToPidLocal.set(key, part.id);
    }
    participantIdToRank.clear();
    participantIdToNoRank.clear();
    sorted.forEach((p, idx) => {
        const regKey = normalizeReg(p.reg);
        if (regKey) {
            const pid = regToPidLocal.get(regKey);
            if (pid != null) {
                participantIdToRank.set(pid, idx + 1);
                if (p.points === 0) participantIdToNoRank.add(pid);
            }
        }
    });
    totalRanked = participantIdToRank.size;
    // Mettre à jour les icônes des markers existants avec le nouveau rang
    for (const [pid, marker] of participantIdToMarker.entries()) {
        const pos = participantIdToLastPos.get(pid);
        if (pos) {
            const rank = participantIdToRank.get(pid);
            const noRank = participantIdToNoRank.has(pid);
            marker.setIcon(buildRotatedIcon(pos.hdg ?? 0, rank, noRank));
        }
    }
    const totalCount = sorted.length;
    const pageSize = 10;
    const start = pilotPage * pageSize;
    const pageItems = sorted.slice(start, start + pageSize);
    pageItems.forEach((p, idx) => {
        const rank = start + idx + 1;
        const item = document.createElement('a');
        item.href = '#';
        item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
        const label = p.callsign ? `${p.name} (${p.callsign})` : p.name;
        const sub = [p.reg, p.gliderBrand, p.gliderModel].filter(Boolean).join(' · ');
        const avatar = `<img src="${p.photoUrl || BASE + '/pilote-picture.png'}" class="rounded-circle me-2" width="28" height="28" alt="${label}" style="object-fit:cover;"/>`;
        // Récupérer télémétrie via reg -> participantId -> participantIdToLastPos
        const regKey = p.reg ? normalizeReg(p.reg) : '';
        let alt = null, gs = null, climb = null;
        if (regKey) {
            // reconstruire un mapping local reg->pid
            const regToPidLocal = new Map();
            for (const part of participants) {
                const key = normalizeReg(part.reg);
                if (key) regToPidLocal.set(key, part.id);
            }
            const pid = regToPidLocal.get(regKey);
            const tlm = pid ? participantIdToLastPos.get(pid) : null;
            if (tlm) { alt = tlm.alt; gs = tlm.gs; climb = tlm.climb; }
        }
        const altClass = (typeof alt === 'number' && alt > 500) ? 'text-bg-primary' : 'text-bg-danger';
        const climbClass = (typeof climb === 'number' && climb > 0) ? 'text-bg-success' : 'text-bg-danger';
        const altBadge = `<span class="badge rounded-pill ${altClass} me-1">${(alt ?? '–')} m</span>`;
        const gsBadge = `<span class="badge rounded-pill text-bg-secondary me-1">${(gs ?? '–')} Km/h</span>`;
        const climbDisplay = (typeof climb === 'number') ? `${climb > 0 ? '+' : (climb < 0 ? '-' : '')}${Math.abs(climb).toFixed(1)}` : '–';
        const climbBadge = `<span class="badge rounded-pill ${climbClass}">${climbDisplay} m/s</span>`;
        const inlineTelemetry = `<span class="ms-2">${altBadge}${gsBadge}${climbBadge}</span>`;
        // Couleurs médailles: Or #FFD700, Argent #C0C0C0, Bronze #CD7F32
        // Dernier: #ff5555ff
        let rankBadge = '';
        if (p.points === 0) {
            rankBadge = `<span class="badge me-2 text-dark border">#</span>`;
        } else if (rank === 1) {
            rankBadge = `<span class="badge me-2" style="background-color:#FFD700;color:#000;">#${rank}</span>`;
        } else if (rank === 2) {
            rankBadge = `<span class="badge me-2" style="background-color:#C0C0C0;color:#000;">#${rank}</span>`;
        } else if (rank === 3) {
            rankBadge = `<span class="badge me-2" style="background-color:#CD7F32;color:#fff;">#${rank}</span>`;
        } else if (rank === totalCount && totalCount > 0) {
            rankBadge = `<span class="badge me-2" style="background-color:#ff5555ff;color:#fff;">#${rank}</span>`;
        } else {
            rankBadge = `<span class="badge bg-dark me-2">#${rank}</span>`;
        }
        const left = `<span class="d-flex align-items-center">${rankBadge}${avatar}<span><span class="d-flex align-items-center">${label}${inlineTelemetry}</span><small class="fst-italic text-muted ms-2">${sub}</small></span></span>`;
        const nearClass = pilotsNearTurnpoint.has(p.id) ? ' blink-gold' : '';
        const provisoire = (live.validated === false && p.points > 0)
            ? `<span class="badge text-bg-danger ms-1" style="font-size:0.55rem;letter-spacing:0.5px;">PROV.</span>`
            : '';
        const right = `<span class="d-flex align-items-center gap-1">${provisoire}<span class="badge bg-secondary rounded-pill score-badge${nearClass}">${p.points}</span></span>`;
        item.innerHTML = `${left}${right}`;
        list.appendChild(item);
    });
}

async function loadParticipants() {
    const res = await fetch(PARTICIPANTS_URL);
    if (!res.ok) throw new Error('participants.json introuvable');
    const data = await res.json();
    participants = data.participants || [];
    // Vue par défaut (France), on ajustera aux positions OGN lorsque disponibles
    map.setView([46.5, 2.5], 6);
}

async function loadPilots() {
    const res = await fetch(PILOTS_URL, { cache: 'no-store' });
    if (!res.ok) return;
    const data = await res.json();
    pilots = data.pilots || [];
}

async function loadCompetitionConfig() {
    try {
        const res = await fetch(COMPETITION_URL, { cache: 'no-store' });
        if (!res.ok) return;
        const cfg = await res.json();
        if (cfg.devMode) {
            devMode = true;
            map.dragging.enable();
            map.scrollWheelZoom.enable();
        }
        if (cfg.activeDay) {
            activeDayInfo = cfg.activeDay;
        }
        if (cfg.name) {
            document.getElementById('compName').textContent = cfg.name;
            document.title = `${cfg.name} - Live`;
        }
        if (activeDayInfo) {
            document.getElementById('rankingTitle').textContent = `Jour ${activeDayInfo.dayNumber} — Classement`;
        }
        if (cfg.start && typeof cfg.start.lat === 'number' && typeof cfg.start.lng === 'number') {
            const radiusKm = mapRadiusKm || (typeof cfg.start.radiusKm === 'number' ? cfg.start.radiusKm : 30);
            const lat = cfg.start.lat; const lng = cfg.start.lng;
            if (mapViewMode === 'start_radius') {
                if (mapZoom) {
                    map.setView([lat, lng], mapZoom);
                } else {
                    // Créer des bounds à partir du rayon (approx: 1° lat ≈ 111.32 km, 1° lon ≈ 111.32*cos(lat) km)
                    const dLat = radiusKm / 111.32;
                    const dLng = radiusKm / (111.32 * Math.cos(lat * Math.PI / 180));
                    const sw = L.latLng(lat - dLat, lng - dLng);
                    const ne = L.latLng(lat + dLat, lng + dLng);
                    const bounds = L.latLngBounds(sw, ne);
                    map.fitBounds(bounds.pad(-0.1));
                }
            }
            const startName = cfg.start.name ? `${cfg.start.name} — ` : '';
            document.getElementById('compSub').textContent = `${startName}Point de départ: ${lat.toFixed(4)}, ${lng.toFixed(4)} • Rayon ${radiusKm} km`;
        }
    } catch (e) {
        console.warn('competition.json non chargé', e);
    }
}

// suppression du rafraîchissement des points planeurs

async function loadLiveAndRenderPilots() {
    if (isPaused) return;
    if (!activeDayInfo) {
        document.getElementById('pilotRankingList').innerHTML = '<div class="text-muted p-3 text-center">Aucune journée en cours.</div>';
        return;
    }
    const res = await fetch(LIVE_PILOTS_URL, { cache: 'no-store' });
    if (!res.ok) return;
    const live = await res.json();
    renderPilotRanking(live);
    // Mettre à jour l'heure d'affichage côté UI
    document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString();
}

function getBoundsQuery() {
    const b = map.getBounds();
    const ne = b.getNorthEast();
    const sw = b.getSouthWest();
    // OGN: b=lat_max, c=lat_min, d=lon_max, e=lon_min
    return {
        b: ne.lat.toFixed(6),
        c: sw.lat.toFixed(6),
        d: ne.lng.toFixed(6),
        e: sw.lng.toFixed(6),
        z: map.getZoom()
    };
}

function clearMarkers() {
    for (const [id, marker] of participantIdToMarker.entries()) {
        map.removeLayer(marker);
    }
    participantIdToMarker.clear();
    participantIdToLastPos.clear();
    for (const [, circle] of proximityCircles.entries()) {
        map.removeLayer(circle);
    }
    proximityCircles.clear();
}

async function loadOgnPositions() {
    if (isPaused) return;
    if (!activeDayInfo) {
        clearMarkers();
        return;
    }
    const q = getBoundsQuery();
    const url = `${BASE}/positions?a=0&b=${q.b}&c=${q.c}&d=${q.d}&e=${q.e}&z=${q.z}`;
    const res = await fetch(url, { cache: 'no-store' });
    if (!res.ok) return;
    const data = await res.json();
    if (!data.items) return;
    // Index participants par ogn_id (hex, prioritaire) puis par reg normalisé
    // Ignorer les reg/ogn_id qui ressemblent à un hex brut (pas une vraie immatriculation)
    const isHexOnly = v => /^[0-9A-Fa-f]{6,}$/.test(v);
    const ognIdToPid = new Map();
    const regToPid = new Map();
    for (const p of participants) {
        const hasRealReg = p.reg && !isHexOnly(p.reg);
        if (p.ognId && hasRealReg) ognIdToPid.set(p.ognId.toLowerCase(), p.id);
        if (hasRealReg) regToPid.set(normalizeReg(p.reg), p.id);
    }
    const matchedPositions = [];
    const seenIds = new Set();
    for (const item of data.items) {
        const itemOgnId = item.id ? item.id.toLowerCase() : '';
        const regUpper = item.reg ? normalizeReg(item.reg) : '';
        // Priorité 1: ogn_id (hex ID), Priorité 2: reg
        let pid = null;
        if (itemOgnId && ognIdToPid.has(itemOgnId)) pid = ognIdToPid.get(itemOgnId);
        if (!pid && regUpper && regToPid.has(regUpper)) pid = regToPid.get(regUpper);
        if (!pid) continue; // n'affiche pas les appareils non présents dans les participants
        const participant = participants.find(p => p.id === pid);
        if (!participant) continue;
        participantIdToLastPos.set(pid, {
            lat: item.lat,
            lng: item.lng,
            alt: item.alt_raw,
            gs: item.ground_speed,
            climb: item.climb,
            hdg: item.hdg
        });
        matchedPositions.push([item.lat, item.lng]);
        // Points seront insérés lors de loadLiveAndRender
        upsertMarker({ id: pid, name: participant.name, lat: item.lat, lng: item.lng, points: 0, hdg: item.hdg });
        seenIds.add(pid);
    }
    // Recadrer la carte sur les participants si mode fit_participants
    if (mapViewMode === 'fit_participants' && matchedPositions.length > 0) {
        map.fitBounds(L.latLngBounds(matchedPositions).pad(0.1));
    }
    // Mettre à jour les alertes de proximité turnpoint
    updateProximityAlerts();
    // Ne pas recentrer/zoomer automatiquement sur les marqueurs OGN
    // Supprimer les marqueurs non vus lors de ce cycle (perte de position)
    for (const [id, marker] of participantIdToMarker.entries()) {
        if (!seenIds.has(id)) {
            map.removeLayer(marker);
            participantIdToMarker.delete(id);
            participantIdToLastPos.delete(id);
        }
    }
}

async function loadValidatedTurnpoints() {
    try {
        const res = await fetch(BASE + '/api/validated-turnpoints', { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        const pairs = data.validated || [];
        for (const [pilotId, turnpointId] of pairs) {
            validatedPairs.add(`${pilotId}_${turnpointId}`);
        }
    } catch (e) {
        console.warn('validated-turnpoints non chargés', e);
    }
}

async function loadTurnpoints() {
    try {
        const res = await fetch(TURNPOINTS_URL, { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        const tps = data.turnpoints || [];
        turnpoints = tps;
        turnpointsLayer.clearLayers();
        tps.forEach(tp => {
            if (typeof tp.lat !== 'number' || typeof tp.lng !== 'number') return;
            // Cercle pour le rayon
            if (tp.radius_m) {
                L.circle([tp.lat, tp.lng], {
                    radius: tp.radius_m,
                    color: '#ff8c00',
                    fillColor: '#ff8c00',
                    fillOpacity: 0.15,
                    weight: 2
                }).addTo(turnpointsLayer);
            }
            // Marker numéroté
            const label = tp.order != null ? String(tp.order) : '•';
            const icon = L.divIcon({
                className: '',
                html: `<div style="background:#ff8c00;color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:bold;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.4);">${label}</div>`,
                iconSize: [24, 24],
                iconAnchor: [12, 12]
            });
            L.marker([tp.lat, tp.lng], { icon, interactive: true })
                .bindTooltip(tp.name || `TP${tp.order}`, { permanent: false, direction: 'top', offset: [0, -12] })
                .addTo(turnpointsLayer);
        });
    } catch (e) {
        console.warn('turnpoints non chargés', e);
    }
}

async function loadSettings() {
    try {
        const res = await fetch(SETTINGS_URL, { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        const s = data.settings || {};
        if (s.proximity_far_m) PROXIMITY_FAR_M = parseInt(s.proximity_far_m, 10);
        if (s.proximity_near_m) PROXIMITY_NEAR_M = parseInt(s.proximity_near_m, 10);
        if (s.map_view_mode) mapViewMode = s.map_view_mode;
        if (s.map_radius_km) mapRadiusKm = parseFloat(s.map_radius_km);
        if (s.map_zoom) mapZoom = parseInt(s.map_zoom, 10);
    } catch (e) {
        console.warn('settings non chargés', e);
    }
}

async function showGeneralOverlay() {
    const overlay = document.getElementById('generalOverlay');
    const titleEl = document.getElementById('generalOverlayTitle');
    const subEl = document.getElementById('generalOverlaySub');
    const tableEl = document.getElementById('generalRankingTable');

    const compName = document.getElementById('compName').textContent;
    if (compName && compName !== 'Classement live') {
        titleEl.textContent = compName;
        subEl.textContent = 'Classement général';
    }

    overlay.classList.remove('d-none');

    try {
        const res = await fetch(GENERAL_RANKING_URL, { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        const pilotsList = data.pilots || [];

        if (pilotsList.length === 0) {
            tableEl.innerHTML = '<p class="text-center text-muted">Aucun score disponible.</p>';
            return;
        }

        // Collect all day numbers
        const dayNumbers = new Set();
        pilotsList.forEach(p => {
            Object.keys(p.dayScores || {}).forEach(d => dayNumbers.add(Number(d)));
        });
        const sortedDays = [...dayNumbers].sort((a, b) => a - b);

        // Split pilots into 3 columns
        const third = Math.ceil(pilotsList.length / 3);
        const columns = [pilotsList.slice(0, third), pilotsList.slice(third, third * 2), pilotsList.slice(third * 2)];
        const offsets = [0, third, third * 2];

        function buildTable(list, startIdx) {
            let html = '<table class="table table-striped table-hover table-sm mb-0">';
            html += '<thead><tr><th>#</th><th>Pilote</th>';
            sortedDays.forEach(d => { html += `<th class="text-center">J${d}</th>`; });
            html += '<th class="text-center">Total</th></tr></thead><tbody>';
            list.forEach((p, idx) => {
                const rank = startIdx + idx + 1;
                let rankStyle = '';
                if (rank === 1) rankStyle = 'background-color:#FFD700;color:#000;';
                else if (rank === 2) rankStyle = 'background-color:#C0C0C0;color:#000;';
                else if (rank === 3) rankStyle = 'background-color:#CD7F32;color:#fff;';
                html += '<tr>';
                const noPoints = p.total === 0;
                const rankLabel = noPoints ? '#' : rank;
                html += (rankStyle && !noPoints)
                    ? `<td><span class="badge" style="${rankStyle}">${rankLabel}</span></td>`
                    : `<td><span class="badge ${noPoints ? 'text-dark border' : 'bg-dark'}">${rankLabel}</span></td>`;
                html += `<td>${p.name}</td>`;
                sortedDays.forEach(d => {
                    const pts = (p.dayScores || {})[d] ?? '-';
                    html += `<td class="text-center">${pts}</td>`;
                });
                html += `<td class="text-center"><strong>${p.total}</strong></td>`;
                html += '</tr>';
            });
            html += '</tbody></table>';
            return html;
        }

        let html = '<div class="row g-3">';
        html += `<div class="col-4">${buildTable(columns[0], offsets[0])}</div>`;
        html += `<div class="col-4">${buildTable(columns[1], offsets[1])}</div>`;
        html += `<div class="col-4">${buildTable(columns[2], offsets[2])}</div>`;
        html += '</div>';
        tableEl.innerHTML = html;
    } catch (e) {
        tableEl.innerHTML = '<p class="text-center text-danger">Erreur de chargement.</p>';
        console.warn('general ranking error', e);
    }
}

async function bootstrap() {
    try {
        await loadSettings();
        await loadParticipants();
        await loadPilots();
        await loadValidatedTurnpoints();
        await loadCompetitionConfig();
        await loadTurnpoints();
        if (activeDayInfo) {
            await loadOgnPositions();
            await loadLiveAndRenderPilots();
            posIntervalId = setInterval(loadOgnPositions, 10000);
            liveIntervalId = setInterval(() => {
                const list = document.getElementById('pilotRankingList');
                list.classList.remove('show');
                setTimeout(() => {
                    loadLiveAndRenderPilots();
                    const totalPages = Math.ceil(pilots.length / 10) || 1;
                    pilotPage = (pilotPage + 1) % totalPages;
                    requestAnimationFrame(() => list.classList.add('show'));
                }, 1000);
            }, 15000);
            map.on('moveend', loadOgnPositions);

            // Surveillance clôture de journée → redirection automatique vers podium-display
            setInterval(async () => {
                try {
                    const r = await fetch(COMPETITION_URL, { cache: 'no-store' });
                    if (!r.ok) return;
                    const cfg = await r.json();
                    if (!cfg.activeDay) {
                        window.location.href = '/podium-display';
                    }
                } catch {}
            }, 15000);
        } else {
            showGeneralOverlay();
        }

    } catch (e) {
        console.error(e);
    }
}

bootstrap();

// Contrôles depuis la console
window.pauseRefresh = function pauseRefresh() {
    if (isPaused) return true;
    if (posIntervalId) { clearInterval(posIntervalId); posIntervalId = null; }
    if (liveIntervalId) { clearInterval(liveIntervalId); liveIntervalId = null; }
    isPaused = true;
    return true;
};

window.resumeRefresh = function resumeRefresh() {
    if (!isPaused) return true;
    posIntervalId = setInterval(loadOgnPositions, 10000);
    liveIntervalId = setInterval(loadLiveAndRenderPilots, 15000);
    isPaused = false;
    return true;
};
</script>
</body>
</html>

