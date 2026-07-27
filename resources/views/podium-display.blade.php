<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Affichage podium — {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #000;
        }

        /* ===== Slideshow ===== */
        #slideshow {
            position: relative;
            width: 100vw;
            height: 100vh;
        }

        .slide {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            transition: opacity 0.8s ease;
            pointer-events: none;
        }

        .slide.active {
            opacity: 1;
            pointer-events: auto;
        }

        .slide iframe {
            border: none;
            width: 100%;
            height: 100vh;
            display: block;
            background: transparent;
        }

        /* ===== HUD ===== */
        #hud {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 100;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding-bottom: 10px;
        }

        /* Progress bar */
        #progress-bar {
            width: 100%;
            height: 3px;
            background: rgba(255, 255, 255, 0.12);
            overflow: hidden;
        }

        #progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, rgba(245,194,0,0.6), #F5C200);
            border-radius: 0 2px 2px 0;
            transition: width linear;
        }

        /* Slide labels */
        #slide-labels {
            display: flex;
            gap: 18px;
            align-items: center;
        }

        .lbl {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 0.65rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.25);
            transition: color 0.4s ease;
            user-select: none;
        }

        .lbl.active {
            color: rgba(245, 194, 0, 0.85);
        }

        .lbl-separator {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
            flex-shrink: 0;
        }
    </style>
</head>
<body>

<div id="slideshow">
    <div class="slide active" id="slide-day">
        <iframe src="/podium" scrolling="no"></iframe>
    </div>
    <div class="slide" id="slide-general">
        <iframe src="/podium-general" scrolling="no"></iframe>
    </div>
</div>

<div id="hud">
    <div id="progress-bar"><div id="progress-fill"></div></div>
    <div id="slide-labels">
        <span id="lbl-day" class="lbl active">Journée</span>
        <span class="lbl-separator"></span>
        <span id="lbl-general" class="lbl">Général</span>
    </div>
</div>

<script>
    const SLIDE_DURATION = 20000; // 20 seconds per slide
    const FADE_DURATION  = 800;   // must match CSS transition: opacity 0.8s

    const slides = ['day', 'general'];
    let current  = 0;
    let startTime = null;
    let rafId = null;

    const progressFill = document.getElementById('progress-fill');

    function getSlideEl(name)  { return document.getElementById(`slide-${name}`); }
    function getLabelEl(name)  { return document.getElementById(`lbl-${name}`); }

    function activateSlide(index) {
        const prev = slides[(index - 1 + slides.length) % slides.length];
        const next = slides[index];

        // Swap active class
        getSlideEl(prev).classList.remove('active');
        getSlideEl(next).classList.add('active');

        // Swap label class
        getLabelEl(prev).classList.remove('active');
        getLabelEl(next).classList.add('active');
    }

    function startProgress() {
        // Cancel any existing animation
        if (rafId !== null) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }

        // Reset bar instantly (no transition) then animate
        progressFill.style.transition = 'none';
        progressFill.style.width = '0%';

        // Force reflow so the reset takes effect before we re-enable transition
        progressFill.getBoundingClientRect();

        progressFill.style.transition = `width ${SLIDE_DURATION}ms linear`;
        progressFill.style.width = '100%';

        startTime = performance.now();
        scheduleNext();
    }

    function scheduleNext() {
        rafId = setTimeout(() => {
            rafId = null;
            current = (current + 1) % slides.length;
            activateSlide(current);
            startProgress();
        }, SLIDE_DURATION);
    }

    // Kick off
    activateSlide(current);
    startProgress();
</script>
</body>
</html>
