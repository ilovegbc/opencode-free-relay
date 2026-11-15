(function () {
    'use strict';

    var root = document.documentElement;
    var $ = function (selector, scope) { return (scope || document).querySelector(selector); };
    var $$ = function (selector, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(selector)); };
    var fine = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // --- Theme -------------------------------------------------------------
    var themeSwitch = $('#themeSwitch');
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    function effectiveTheme() {
        if (root.dataset.theme === 'light' || root.dataset.theme === 'dark') {
            return root.dataset.theme;
        }
        return mq && mq.matches ? 'dark' : 'light';
    }
    function syncThemeUI() {
        if (themeSwitch) {
            themeSwitch.checked = effectiveTheme() === 'dark';
        }
    }
    if (themeSwitch) {
        themeSwitch.addEventListener('change', function () {
            var next = themeSwitch.checked ? 'dark' : 'light';
            root.dataset.theme = next;
            try { localStorage.setItem('ocrelay-theme', next); } catch (e) {}
        });
    }
    if (mq && mq.addEventListener) {
        mq.addEventListener('change', syncThemeUI);
    }
    syncThemeUI();

    // --- Mobile nav --------------------------------------------------------
    $$('[data-nav-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var nav = document.querySelector('[data-nav]');
            if (nav) {
                nav.classList.toggle('open');
            }
        });
    });

    // --- Toasts ------------------------------------------------------------
    var toastBox = $('#toasts');
    if (!toastBox) {
        toastBox = document.createElement('div');
        toastBox.className = 'toasts';
        toastBox.id = 'toasts';
        toastBox.setAttribute('aria-live', 'polite');
        document.body.appendChild(toastBox);
    }
    function toast(message, kind) {
        var el = document.createElement('div');
        el.className = 'toast' + (kind ? ' ' + kind : '');
        el.textContent = message;
        toastBox.appendChild(el);
        var kill = function () {
            if (!el.parentNode) { return; }
            el.classList.add('out');
            setTimeout(function () {
                if (el.parentNode) { el.parentNode.removeChild(el); }
            }, 300);
        };
        var timer = setTimeout(kill, 2600);
        el.addEventListener('click', function () {
            clearTimeout(timer);
            kill();
        });
    }

    // --- Copy buttons ------------------------------------------------------
    function legacyCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.cssText = 'position:fixed;top:-1000px;left:0;opacity:0';
        document.body.appendChild(area);
        area.select();
        area.setSelectionRange(0, area.value.length);
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(area);
        return ok;
    }
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () {
                return true;
            }).catch(function () {
                return legacyCopy(text);
            });
        }
        return Promise.resolve(legacyCopy(text));
    }
    function flashCopy(button) {
        if (!button) { return; }
        button.classList.add('done');
        setTimeout(function () { button.classList.remove('done'); }, 1400);
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-copy]') : null;
        if (!button) { return; }
        event.preventDefault();
        var spec = button.getAttribute('data-copy') || '';
        var text = spec;
        if (spec.charAt(0) === '#' || spec.charAt(0) === '.') {
            var target = document.querySelector(spec);
            if (target) {
                text = target.textContent || '';
            } else {
                text = '';
            }
        }
        if (!text) {
            toast('Nothing to copy', 'err');
            return;
        }
        copyText(text).then(function (ok) {
            if (ok) {
                toast('已复制 ' + text, 'ok');
                flashCopy(button);
            } else {
                toast('复制失败，请手动选择文本', 'err');
            }
        });
    });

    // --- Ripple ------------------------------------------------------------
    document.addEventListener('click', function (event) {
        if (!event.detail) { return; }
        var button = event.target.closest ? event.target.closest('.button, .btn, .iconbtn') : null;
        if (!button || calm) { return; }
        var rect = button.getBoundingClientRect();
        var ripple = document.createElement('span');
        ripple.className = 'rip';
        ripple.style.left = (event.clientX - rect.left) + 'px';
        ripple.style.top = (event.clientY - rect.top) + 'px';
        button.appendChild(ripple);
        setTimeout(function () {
            if (ripple.parentNode) { ripple.parentNode.removeChild(ripple); }
        }, 650);
    });
    $$('.button, .btn').forEach(function (button) {
        button.addEventListener('pointermove', function (event) {
            var rect = button.getBoundingClientRect();
            button.style.setProperty('--cx', (event.clientX - rect.left) + 'px');
            button.style.setProperty('--cy', (event.clientY - rect.top) + 'px');
        }, { passive: true });
    });

    // --- FAQ / accordion ---------------------------------------------------
    $$('.faq .it').forEach(function (item) {
        var button = $('button', item);
        if (!button) { return; }
        button.addEventListener('click', function () {
            var open = !item.classList.contains('open');
            $$('.faq .it').forEach(function (other) {
                if (other === item) { return; }
                other.classList.remove('open');
                var otherButton = $('button', other);
                if (otherButton) { otherButton.setAttribute('aria-expanded', 'false'); }
            });
            item.classList.toggle('open', open);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });

    // --- Background pointer light -----------------------------------------
    var bgLayer = $('.bg');
    if (bgLayer && fine && !calm) {
        var px = 0, py = 0, pending = false;
        var paintSpot = function () {
            pending = false;
            bgLayer.style.setProperty('--mx', px + 'px');
            bgLayer.style.setProperty('--my', py + 'px');
            if (!bgLayer.classList.contains('lit')) { bgLayer.classList.add('lit'); }
        };
        var queueSpot = function () {
            if (pending) { return; }
            pending = true;
            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(paintSpot);
            } else {
                paintSpot();
            }
        };
        document.addEventListener('pointermove', function (event) {
            px = event.clientX;
            py = event.clientY;
            queueSpot();
        }, { passive: true });
        document.addEventListener('pointerleave', function () { bgLayer.classList.remove('lit'); });
        window.addEventListener('blur', function () { bgLayer.classList.remove('lit'); });
    }

    // --- Card tilt / spotlight --------------------------------------------
    if (fine && !calm) {
        $$('.card').forEach(function (card) {
            var raf = 0, rx = 0, ry = 0;
            function paint() {
                raf = 0;
                card.style.setProperty('--fx-rx', rx.toFixed(2) + 'deg');
                card.style.setProperty('--fx-ry', ry.toFixed(2) + 'deg');
            }
            card.addEventListener('pointermove', function (event) {
                var rect = card.getBoundingClientRect();
                var x = (event.clientX - rect.left) / rect.width;
                var y = (event.clientY - rect.top) / rect.height;
                card.style.setProperty('--fx-x', (x * 100).toFixed(1) + '%');
                card.style.setProperty('--fx-y', (y * 100).toFixed(1) + '%');
                card.classList.add('fx-tilt');
                ry = (x - .5) * 4.5;
                rx = (.5 - y) * 4.5;
                if (!raf) { raf = requestAnimationFrame(paint); }
            }, { passive: true });
            function reset() {
                card.classList.remove('fx-tilt');
                card.style.setProperty('--fx-rx', '0deg');
                card.style.setProperty('--fx-ry', '0deg');
            }
            card.addEventListener('pointerleave', reset);
            card.addEventListener('blur', reset);
        });
    }

    // --- Reveal on scroll --------------------------------------------------
    if (!calm && 'IntersectionObserver' in window) {
        try {
            var reveal = $$('.section-title, .sec-h, .step, .faq .it, .card');
            reveal.forEach(function (element, index) {
                element.classList.add('fx-reveal');
                element.style.transitionDelay = ((index % 4) * 55) + 'ms';
            });
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('fx-in');
                        observer.unobserve(entry.target);
                    }
                });
            }, { rootMargin: '0px 0px -8% 0px', threshold: .06 });
            reveal.forEach(function (element) { observer.observe(element); });
        } catch (e) {}
    }

    // --- Cursor glow -------------------------------------------------------
    if (fine && !calm) {
        try {
            var cursor = document.createElement('div');
            cursor.className = 'fx-cursor';
            cursor.setAttribute('aria-hidden', 'true');
            document.body.appendChild(cursor);
            var cx = 0, cy = 0, mx = 0, my = 0, moved = false;
            document.addEventListener('pointermove', function (event) {
                mx = event.clientX;
                my = event.clientY;
                if (!moved) { cx = mx; cy = my; moved = true; }
                cursor.classList.add('on');
            }, { passive: true });
            document.addEventListener('pointerleave', function () { cursor.classList.remove('on'); });
            (function loop() {
                cx += (mx - cx) * .18;
                cy += (my - cy) * .18;
                cursor.style.transform = 'translate3d(' + cx.toFixed(1) + 'px,' + cy.toFixed(1) + 'px,0)';
                requestAnimationFrame(loop);
            })();
        } catch (e) {}
    }

    // --- Confirmation dialogs ---------------------------------------------
    $$('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm') || 'Are you sure?')) {
                event.preventDefault();
            }
        });
    });
})();
