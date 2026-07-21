/* Efeitos de ambiente: brasas, wisps vermelhos, runas flutuantes e névoa. */
(() => {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    // ---- névoa em movimento ----
    for (const cls of ['fx-fog-a', 'fx-fog-b']) {
        const d = document.createElement('div');
        d.className = 'fx-fog ' + cls;
        document.body.appendChild(d);
    }

    // ---- runas flutuantes ----
    const RUNES = [
        'M12,4 v40 M12,4 l16,11 M12,21 l16,11',   // fehu
        'M4,6 l20,28 M24,6 l-20,28',              // gebo
        'M14,44 v-40 M2,16 l12,-12 l12,12',       // tiwaz
        'M12,4 v40 M12,10 l14,9 l-14,9',          // thurisaz
        'M6,44 l8,-40 l8,40 M9,30 h10',           // ansuz estilizada
        'M4,44 v-40 l20,20 l-20,20',              // uruz estilizada
    ];
    for (let i = 0; i < 7; i++) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 28 48');
        svg.setAttribute('class', 'fx-rune');
        const p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        p.setAttribute('d', RUNES[i % RUNES.length]);
        svg.appendChild(p);
        svg.style.left = (4 + Math.random() * 92) + 'vw';
        svg.style.animationDuration = (20 + Math.random() * 16) + 's';
        svg.style.animationDelay = (-Math.random() * 36) + 's';
        document.body.appendChild(svg);
    }

    // ---- partículas: brasas douradas + wisps vermelhos ----
    const canvas = document.createElement('canvas');
    canvas.id = 'fx-canvas';
    document.body.appendChild(canvas);
    const ctx = canvas.getContext('2d');
    let W, H, dpr;

    function resize() {
        dpr = Math.min(window.devicePixelRatio || 1, 2);
        W = canvas.width = innerWidth * dpr;
        H = canvas.height = innerHeight * dpr;
        canvas.style.width = innerWidth + 'px';
        canvas.style.height = innerHeight + 'px';
    }
    addEventListener('resize', resize);
    resize();

    const N = 60;
    const parts = [];

    function spawn(p, anywhere) {
        p.baseX = Math.random() * W;
        p.y = anywhere ? Math.random() * H : H + 12 * dpr;
        p.r = (0.9 + Math.random() * 1.9) * dpr;
        p.speed = (0.25 + Math.random() * 0.7) * dpr;
        p.sway = Math.random() * Math.PI * 2;
        p.swaySpeed = 0.0015 + Math.random() * 0.0035;
        p.swayAmp = (12 + Math.random() * 26) * dpr;
        p.crimson = Math.random() < 0.22;
        p.alpha = 0.28 + Math.random() * 0.5;
        p.flicker = Math.random() * Math.PI * 2;
    }
    for (let i = 0; i < N; i++) {
        const p = {};
        spawn(p, true);
        parts.push(p);
    }

    let last = performance.now();
    function tick(now) {
        const dt = Math.min(now - last, 50);
        last = now;
        ctx.clearRect(0, 0, W, H);

        for (const p of parts) {
            p.y -= p.speed * (dt / 16.7);
            p.sway += p.swaySpeed * dt;
            p.flicker += 0.045;
            if (p.y < -20 * dpr) {
                spawn(p, false);
            }
            const x = p.baseX + Math.sin(p.sway) * p.swayAmp;
            const a = p.alpha * (0.7 + 0.3 * Math.sin(p.flicker));
            const g = ctx.createRadialGradient(x, p.y, 0, x, p.y, p.r * 3.2);
            if (p.crimson) {
                g.addColorStop(0, 'rgba(255,90,90,' + a.toFixed(3) + ')');
                g.addColorStop(1, 'rgba(200,40,40,0)');
            } else {
                g.addColorStop(0, 'rgba(255,200,90,' + a.toFixed(3) + ')');
                g.addColorStop(1, 'rgba(255,110,25,0)');
            }
            ctx.fillStyle = g;
            ctx.beginPath();
            ctx.arc(x, p.y, p.r * 3.2, 0, Math.PI * 2);
            ctx.fill();
        }
        requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
})();
