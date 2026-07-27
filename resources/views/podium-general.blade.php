<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Classement général — {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            background: linear-gradient(160deg, #0d1b2a 0%, #1a2e4a 50%, #0d1b2a 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #fff;
            overflow-x: hidden;
            padding-bottom: 3rem;
        }

        /* Étoiles de fond */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                radial-gradient(1px 1px at 10% 15%, rgba(255,255,255,0.6) 0%, transparent 100%),
                radial-gradient(1px 1px at 25% 55%, rgba(255,255,255,0.4) 0%, transparent 100%),
                radial-gradient(1px 1px at 40% 25%, rgba(255,255,255,0.5) 0%, transparent 100%),
                radial-gradient(1px 1px at 60% 70%, rgba(255,255,255,0.3) 0%, transparent 100%),
                radial-gradient(1px 1px at 75% 35%, rgba(255,255,255,0.6) 0%, transparent 100%),
                radial-gradient(1px 1px at 88% 80%, rgba(255,255,255,0.4) 0%, transparent 100%),
                radial-gradient(1px 1px at 15% 85%, rgba(255,255,255,0.3) 0%, transparent 100%),
                radial-gradient(1px 1px at 50% 10%, rgba(255,255,255,0.5) 0%, transparent 100%),
                radial-gradient(1px 1px at 92% 45%, rgba(255,255,255,0.4) 0%, transparent 100%);
            pointer-events: none;
        }

        h1 {
            font-size: clamp(1.4rem, 3vw, 2.2rem);
            font-weight: 300;
            letter-spacing: 6px;
            text-transform: uppercase;
            color: #F5C200;
            text-shadow: 0 0 30px rgba(245,194,0,0.4);
            margin-bottom: 0.25rem;
        }

        .comp-name {
            font-size: clamp(0.75rem, 1.5vw, 1rem);
            color: rgba(255,255,255,0.5);
            letter-spacing: 3px;
            text-transform: uppercase;
            margin-bottom: 3rem;
        }

        /* Podium container */
        .podium {
            display: flex;
            align-items: flex-end;
            justify-content: center;
            gap: 1.5rem;
            width: 100%;
            max-width: 1024px;
            padding: 0 1rem;
        }

        .podium-slot {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            max-width: 260px;
        }

        /* Médaille SVG */
        .medal-wrap {
            position: relative;
            width: 100%;
            display: flex;
            justify-content: center;
            margin-bottom: -8px;
            z-index: 2;
        }

        .medal-wrap svg {
            width: 100%;
            max-width: 200px;
            filter: drop-shadow(0 8px 24px rgba(0,0,0,0.5));
            transition: transform 0.3s ease;
        }

        .podium-slot:hover .medal-wrap svg {
            transform: translateY(-6px) scale(1.04);
        }

        /* Photo pilote dans la médaille */
        .pilot-photo {
            position: absolute;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.3);
        }

        /* Marche du podium */
        .step {
            width: 100%;
            border-radius: 10px 10px 0 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 0.5rem 1rem 0.8rem;
            position: relative;
            overflow: hidden;
        }

        .step-1 {
            background: linear-gradient(180deg, rgba(245,194,0,0.25) 0%, rgba(245,194,0,0.08) 100%);
            border: 1px solid rgba(245,194,0,0.3);
            border-bottom: none;
            height: 260px;
        }
        .step-2 {
            background: linear-gradient(180deg, rgba(192,192,192,0.2) 0%, rgba(192,192,192,0.06) 100%);
            border: 1px solid rgba(192,192,192,0.25);
            border-bottom: none;
            height: 190px;
        }
        .step-3 {
            background: linear-gradient(180deg, rgba(205,127,50,0.2) 0%, rgba(205,127,50,0.06) 100%);
            border: 1px solid rgba(205,127,50,0.25);
            border-bottom: none;
            height: 140px;
        }

        /* Numéro de rang */
        .rank-number {
            font-size: 1.4rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        .step-1 .rank-number { color: #F5C200; text-shadow: 0 0 20px rgba(245,194,0,0.6); }
        .step-2 .rank-number { color: #C0C0C0; text-shadow: 0 0 20px rgba(192,192,192,0.5); }
        .step-3 .rank-number { color: #CD7F32; text-shadow: 0 0 20px rgba(205,127,50,0.5); }

        .pilot-name {
            font-size: clamp(0.85rem, 1.6vw, 1.05rem);
            font-weight: 600;
            text-align: center;
            margin-bottom: 0.2rem;
            color: #fff;
        }

        .pilot-callsign {
            font-size: 0.75rem;
            color: rgba(255,255,255,0.5);
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }

        .pilot-glider {
            font-size: 0.72rem;
            color: rgba(255,255,255,0.4);
            text-align: center;
            margin-bottom: 0.5rem;
        }

        .pilot-score {
            font-size: clamp(1.1rem, 2.5vw, 1.6rem);
            font-weight: 700;
        }
        .step-1 .pilot-score { color: #FFE566; }
        .step-2 .pilot-score { color: #D8D8D8; }
        .step-3 .pilot-score { color: #E09050; }

        .pilot-score-label {
            font-size: 0.65rem;
            color: rgba(255,255,255,0.35);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .pilot-day-breakdown {
            font-size: 0.68rem;
            color: rgba(255,255,255,0.4);
            letter-spacing: 0.5px;
            margin-top: 0.4rem;
            text-align: center;
        }

        /* Ligne de sol */
        .ground {
            width: 100%;
            max-width: 1024px;
            height: 4px;
            background: linear-gradient(90deg, transparent, rgba(245,194,0,0.3), rgba(245,194,0,0.6), rgba(245,194,0,0.3), transparent);
            border-radius: 2px;
            box-shadow: 0 0 20px rgba(245,194,0,0.2);
        }

        .footer {
            margin-top: 2rem;
            font-size: 0.7rem;
            color: rgba(255,255,255,0.25);
            letter-spacing: 2px;
        }

        #lastUpdate { color: rgba(255,255,255,0.4); }

        /* Vide / chargement */
        .empty { color: rgba(255,255,255,0.3); font-size: 0.9rem; font-style: italic; }

        /* ===== Classement général ===== */
        .ranking-section {
            width: 100%;
            max-width: 1024px;
            margin-top: 3rem;
            padding: 0 1rem;
        }

        .ranking-section h2 {
            font-size: 0.8rem;
            font-weight: 400;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.4);
            text-align: center;
            margin-bottom: 1.2rem;
        }

        .ranking-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.2rem;
        }

        @media (max-width: 600px) {
            .ranking-cols { grid-template-columns: 1fr; }
        }

        .ranking-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        .ranking-table thead tr {
            border-bottom: 1px solid rgba(255,255,255,0.12);
        }

        .ranking-table thead th {
            padding: 0.4rem 0.6rem;
            color: rgba(255,255,255,0.35);
            font-weight: 400;
            font-size: 0.7rem;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .ranking-table thead th:not(:first-child) { text-align: center; }

        .ranking-table thead th.th-total {
            color: rgba(255,255,255,0.65);
            font-weight: 700;
        }

        .ranking-table tbody tr {
            border-bottom: 1px solid rgba(255,255,255,0.05);
            transition: background 0.15s;
        }

        .ranking-table tbody tr:hover {
            background: rgba(255,255,255,0.04);
        }

        .ranking-table tbody td {
            padding: 0.5rem 0.6rem;
            vertical-align: middle;
        }

        .ranking-table tbody td:not(:first-child):not(:nth-child(2)) {
            text-align: center;
            color: rgba(255,255,255,0.6);
            font-size: 0.75rem;
        }

        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            font-size: 0.7rem;
            font-weight: 700;
            background: rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.5);
            margin-right: 0.5rem;
            flex-shrink: 0;
        }

        .pilot-cell {
            display: flex;
            align-items: center;
        }

        .total-cell {
            font-weight: 700;
            color: #fff !important;
            font-size: 0.88rem !important;
        }
    </style>
</head>
<body>

<h1>Classement Général</h1>
<div class="comp-name" id="compName">Chargement…</div>
<div class="comp-name" id="compSub" style="margin-top:-0.5rem;margin-bottom:3rem;font-size:0.65rem;"></div>

<div class="podium" id="podium">
    <!-- Rendu par JS -->
</div>
<div class="ground"></div>

<!-- Classement général (4ème et au-delà) -->
<div class="ranking-section" id="rankingSection" style="display:none">
    <h2>Classement général</h2>
    <div class="ranking-cols" id="rankingCols"></div>
</div>

<div class="footer">
    Mise à jour : <span id="lastUpdate">–</span>
</div>

<script>
// SVG médaille selon rang
function medalSvg(rank) {
    const sizes  = { 1: 160, 2: 130, 3: 105 };
    const rings  = { 1: '#F5C200', 2: '#C0C0C0', 3: '#CD7F32' };
    const darks  = { 1: '#C9960C', 2: '#888888', 3: '#8B5010' };
    const sz = sizes[rank];
    const cx = sz / 2;
    const rOuter = cx - 4;
    const rInner = rOuter - 10;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${sz} ${sz}" width="${sz}" height="${sz}">
  <defs>
    <clipPath id="clip${rank}"><circle cx="${cx}" cy="${cx}" r="${rInner - 2}"/></clipPath>
  </defs>
  <circle cx="${cx}" cy="${cx}" r="${rOuter}" fill="${rings[rank]}" stroke="${darks[rank]}" stroke-width="4"/>
  <circle cx="${cx}" cy="${cx}" r="${rInner}" fill="#B0B8C4"/>
  <ellipse cx="${cx}" cy="${cx + rInner * 0.38}" rx="${rInner * 0.42}" ry="${rInner * 0.55}" fill="#8A9BAA" id="sil-body-${rank}"/>
  <circle  cx="${cx}" cy="${cx - rInner * 0.25}" r="${rInner * 0.32}" fill="#8A9BAA" id="sil-head-${rank}"/>
  <image id="photo-${rank}" href="" x="${cx - rInner + 2}" y="${cx - rInner + 2}"
         width="${(rInner - 2) * 2}" height="${(rInner - 2) * 2}"
         clip-path="url(#clip${rank})" preserveAspectRatio="xMidYMid slice" style="display:none"/>
</svg>`;
}

function setPhoto(rank, url) {
    const img = document.getElementById(`photo-${rank}`);
    const head = document.getElementById(`sil-head-${rank}`);
    const body = document.getElementById(`sil-body-${rank}`);
    if (!img || !url) return;
    img.setAttribute('href', url);
    img.style.display = '';
    if (head) head.style.display = 'none';
    if (body) body.style.display = 'none';
}

async function loadPodium() {
    try {
        const res = await fetch('/api/competition', { cache: 'no-store' });
        if (res.ok) {
            const cfg = await res.json();
            if (cfg?.name) document.getElementById('compName').textContent = cfg.name;
        }
    } catch {}

    const res = await fetch('/api/general-ranking', { cache: 'no-store' });
    if (!res.ok) return;
    const data = await res.json();

    // Find all day numbers
    const allDayNums = new Set();
    (data.pilots || []).forEach(p => Object.keys(p.dayScores || {}).forEach(d => allDayNums.add(Number(d))));
    const sortedDays = [...allDayNums].sort((a, b) => a - b);

    // Per-day validation: a day is validated only if ALL pilots have is_validated=true for that day
    const dayIsValidated = {};
    sortedDays.forEach(d => {
        dayIsValidated[d] = (data.pilots || []).every(p => (p.dayValidated || {})[d] === true);
    });
    const anyProvisional = sortedDays.some(d => !dayIsValidated[d]);

    const dayCount = sortedDays.length;
    let subText = dayCount > 0 ? `${dayCount} journée${dayCount > 1 ? 's' : ''} de vol` : '';
    if (anyProvisional) subText += ' · scores provisoires';
    document.getElementById('compSub').textContent = subText;
    if (anyProvisional) document.getElementById('compSub').style.color = '#ff6b6b';

    // Pilots already sorted by total from API
    const pilots = data.pilots || [];
    const top3 = pilots.slice(0, 3);

    // Display order: 2 - 1 - 3
    const order = [
        { rank: 2, pilot: top3[1] || null },
        { rank: 1, pilot: top3[0] || null },
        { rank: 3, pilot: top3[2] || null },
    ];

    const stepClasses = { 1: 'step-1', 2: 'step-2', 3: 'step-3' };

    const podiumEl = document.getElementById('podium');
    podiumEl.innerHTML = '';

    for (const { rank, pilot } of order) {
        const slot = document.createElement('div');
        slot.className = 'podium-slot';

        const medalWrap = document.createElement('div');
        medalWrap.className = 'medal-wrap';
        medalWrap.innerHTML = medalSvg(rank);
        slot.appendChild(medalWrap);

        const step = document.createElement('div');
        step.className = `step ${stepClasses[rank]}`;

        if (pilot) {
            const breakdown = sortedDays.length > 0
                ? sortedDays.map(d => {
                    const pts = (pilot.dayScores || {})[d] ?? 0;
                    const prov = !dayIsValidated[d] ? ' <span style="color:#ff6b6b;font-size:0.55rem;">PROV</span>' : '';
                    return `J${d}: ${pts}${prov}`;
                }).join(' · ')
                : '';
            const anyPilotProv = sortedDays.some(d => !dayIsValidated[d]);
            const provTag = anyPilotProv
                ? `<div style="font-size:0.55rem;font-weight:700;letter-spacing:1.5px;color:#ff6b6b;text-transform:uppercase;margin-top:0.2rem;">Provisoire</div>`
                : '';
            step.innerHTML = `
                <div class="rank-number">#${rank}</div>
                <div class="pilot-name">${pilot.name}</div>
                ${pilot.callsign ? `<div class="pilot-callsign">${pilot.callsign}</div>` : ''}
                <div class="pilot-score">${pilot.total.toLocaleString('fr-FR')}</div>
                <div class="pilot-score-label">pts au total</div>
                ${breakdown ? `<div class="pilot-day-breakdown">${breakdown}</div>` : ''}
                ${provTag}
            `;
            if (pilot.photoUrl) {
                requestAnimationFrame(() => setPhoto(rank, pilot.photoUrl));
            }
        } else {
            step.innerHTML = `<div class="rank-number">#${rank}</div><div class="empty">–</div>`;
        }

        slot.appendChild(step);
        podiumEl.appendChild(slot);
    }

    document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString();

    // Rest table: 4th place and beyond — already sorted by total from API
    const rest = pilots.slice(3);
    if (rest.length > 0) {
        const half = Math.ceil(rest.length / 2);
        const cols = [rest.slice(0, half), rest.slice(half)];
        const startRanks = [4, 4 + half];

        const colsEl = document.getElementById('rankingCols');
        colsEl.innerHTML = '';

        cols.forEach((col, ci) => {
            if (col.length === 0) return;
            const startRank = startRanks[ci];

            let html = `<table class="ranking-table">
                <thead><tr>
                    <th colspan="2">Pilote</th>
                    ${sortedDays.map(d => {
                        const prov = !dayIsValidated[d]
                            ? ` <span style="font-size:0.55rem;color:#ff6b6b;font-weight:700;">PROV</span>`
                            : '';
                        return `<th>J${d}${prov}</th>`;
                    }).join('')}
                    <th class="th-total">Total</th>
                </tr></thead>
                <tbody>`;

            col.forEach((p, idx) => {
                const rank = startRank + idx;
                const dayScores = sortedDays.map(d =>
                    `<td>${(p.dayScores || {})[d] ?? '–'}</td>`
                ).join('');
                html += `<tr>
                    <td style="width:32px">
                        <span class="rank-badge">${rank}</span>
                    </td>
                    <td>
                        <div style="font-weight:600;color:#fff;">${p.name}</div>
                        ${p.callsign ? `<div style="font-size:0.68rem;color:rgba(255,255,255,0.35);letter-spacing:1px;">${p.callsign}</div>` : ''}
                    </td>
                    ${dayScores}
                    <td class="total-cell">${p.total.toLocaleString('fr-FR')}</td>
                </tr>`;
            });

            html += '</tbody></table>';
            colsEl.insertAdjacentHTML('beforeend', html);
        });

        document.getElementById('rankingSection').style.display = '';
    }
}

loadPodium();
</script>
</body>
</html>
