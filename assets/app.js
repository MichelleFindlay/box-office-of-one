(function () {
    "use strict";

    var config = window.APP_CONFIG || { pollIntervalMs: 15000 };
    var lastImage = null;
    var activeLayer = "a";
    var lastCheckTime = Date.now();
    var currentItem = window.INITIAL_ITEM || null;

    var els = {
        artTile: document.querySelector("[data-art-tile]"),
        artImg: document.querySelector("[data-art-img]"),
        artFallback: document.querySelector("[data-art-fallback]"),
        name: document.querySelector("[data-track-name]"),
        subtitle: document.querySelector("[data-track-artist]"),
        meta: document.querySelector("[data-track-album]"),
        heroRatings: document.querySelector("[data-hero-ratings]"),
        badge: document.querySelector("[data-status-badge]"),
        updated: document.querySelector("[data-updated]"),
        bgA: document.querySelector("[data-bg-a]"),
        bgB: document.querySelector("[data-bg-b]"),
        traktLink: document.querySelector("[data-trakt-link]"),
        progress: document.querySelector("[data-watch-progress]"),
        progressFill: document.querySelector("[data-watch-progress-fill]"),
        progressLabel: document.querySelector("[data-watch-progress-label]"),
        prevWrap: document.querySelector("[data-prev-track]"),
        prevThumb: document.querySelector("[data-prev-thumb]"),
        prevArtImg: document.querySelector("[data-prev-art-img]"),
        prevArtFallback: document.querySelector("[data-prev-art-fallback]"),
        prevName: document.querySelector("[data-prev-track-name]"),
        prevSubtitle: document.querySelector("[data-prev-track-artist]"),
        prevRatings: document.querySelector("[data-prev-ratings]"),
    };

    function setText(el, value) {
        if (el) {
            el.textContent = value || "";
        }
    }

    // A poster URL can still 404 (a since-replaced image on Trakt's or
    // TMDB's CDN) — fall back to the letter placeholder whenever a load
    // actually fails rather than showing a broken image.
    function fallBackToLetterOnError(imgEl, fallbackEl) {
        if (!imgEl || !fallbackEl) {
            return;
        }
        imgEl.addEventListener("error", function () {
            imgEl.style.display = "none";
            fallbackEl.style.display = "";
        });
    }

    fallBackToLetterOnError(els.artImg, els.artFallback);
    fallBackToLetterOnError(els.prevArtImg, els.prevArtFallback);

    function setArt(imgEl, fallbackEl, url) {
        if (!imgEl) {
            return;
        }
        if (url) {
            imgEl.src = url;
            imgEl.style.display = "";
            if (fallbackEl) {
                fallbackEl.style.display = "none";
            }
        } else {
            imgEl.style.display = "none";
            if (fallbackEl) {
                fallbackEl.style.display = "";
            }
        }
    }

    /**
     * Crossfade the blurred background to a new poster or backdrop image.
     */
    function updateBackground(imageUrl) {
        if (!imageUrl || imageUrl === lastImage) {
            return;
        }
        lastImage = imageUrl;

        var incoming = activeLayer === "a" ? els.bgB : els.bgA;
        var outgoing = activeLayer === "a" ? els.bgA : els.bgB;

        incoming.style.backgroundImage = "url('" + imageUrl + "')";
        incoming.classList.add("visible");
        outgoing.classList.remove("visible");

        activeLayer = activeLayer === "a" ? "b" : "a";

        extractPalette(imageUrl);
    }

    // Once extractPalette() sets --accent via JS, it overrides the CSS
    // default indefinitely — there was previously no path back, so an item
    // with no art at all would keep showing whatever the last item with
    // art left behind. Reset everything to its CSS default when that
    // happens: fading out the background layer reveals the plain dark page
    // background, and removing the --accent overrides (rather than
    // hardcoding the default here too) lets them fall back to :root's
    // declared values, still through the same 5s --color-transition every
    // other accent change already uses.
    function resetTheme() {
        if (lastImage === null) {
            return; // already at the default; nothing to reset
        }
        lastImage = null;

        if (els.bgA) els.bgA.classList.remove("visible");
        if (els.bgB) els.bgB.classList.remove("visible");

        var root = document.documentElement.style;
        root.removeProperty("--accent");
        root.removeProperty("--accent-soft");
    }

    /**
     * Sample the poster on a canvas to derive an accent colour for the
     * theme. Falls back to the default accent if the image can't be read
     * (e.g. blocked by CORS), since the blurred background still updates
     * regardless.
     */
    function extractPalette(imageUrl) {
        var img = new Image();
        img.crossOrigin = "anonymous";

        img.onload = function () {
            try {
                var size = 24;
                var canvas = document.createElement("canvas");
                canvas.width = size;
                canvas.height = size;
                var ctx = canvas.getContext("2d");
                ctx.drawImage(img, 0, 0, size, size);
                var data = ctx.getImageData(0, 0, size, size).data;

                var r = 0, g = 0, b = 0, weight = 0;

                for (var i = 0; i < data.length; i += 4) {
                    var pr = data[i], pg = data[i + 1], pb = data[i + 2];
                    var max = Math.max(pr, pg, pb);
                    var min = Math.min(pr, pg, pb);
                    var sat = max === 0 ? 0 : (max - min) / max;
                    // Weight saturated, mid-brightness pixels more heavily so the
                    // accent isn't dragged towards a muddy grey average.
                    var w = 0.15 + sat;

                    r += pr * w;
                    g += pg * w;
                    b += pb * w;
                    weight += w;
                }

                r = Math.round(r / weight);
                g = Math.round(g / weight);
                b = Math.round(b / weight);

                // Keep the accent readable no matter what the poster
                // looks like: it's used both as white-text-on-accent
                // (active period buttons, art fallback tiles) and as
                // accent-text-on-the-page's-own-dark-background (status
                // badge, footer links). Both need the accent's own
                // brightness to sit in a safe middle band — and since
                // perceived brightness depends on hue (yellow reads much
                // brighter than blue at the same HSL lightness), the only
                // reliable way to do that is to target real WCAG relative
                // luminance directly, not raw HSL lightness. Hue and
                // saturation are left untouched, so the theme still
                // visibly follows the art's actual colour.
                var hsl = rgbToHsl(r, g, b);
                var safeRgb = ensureSafeLuminance(hsl[0], hsl[1], hsl[2]);

                applyAccent(safeRgb[0], safeRgb[1], safeRgb[2]);
            } catch (err) {
                // Canvas was tainted (no CORS headers) — keep the default accent.
            }
        };

        img.onerror = function () {};
        img.src = imageUrl;
    }

    function rgbToHsl(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b);
        var h = 0, s = 0, l = (max + min) / 2;

        if (max !== min) {
            var d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            switch (max) {
                case r: h = (g - b) / d + (g < b ? 6 : 0); break;
                case g: h = (b - r) / d + 2; break;
                default: h = (r - g) / d + 4;
            }
            h /= 6;
        }

        return [h * 360, s * 100, l * 100];
    }

    function hslToRgb(h, s, l) {
        h /= 360; s /= 100; l /= 100;
        var r, g, b;

        if (s === 0) {
            r = g = b = l;
        } else {
            var hue2rgb = function (p, q, t) {
                if (t < 0) t += 1;
                if (t > 1) t -= 1;
                if (t < 1 / 6) return p + (q - p) * 6 * t;
                if (t < 1 / 2) return q;
                if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
                return p;
            };
            var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
            var p = 2 * l - q;
            r = hue2rgb(p, q, h + 1 / 3);
            g = hue2rgb(p, q, h);
            b = hue2rgb(p, q, h - 1 / 3);
        }

        return [Math.round(r * 255), Math.round(g * 255), Math.round(b * 255)];
    }

    function relativeLuminance(r, g, b) {
        function linearize(c) {
            c /= 255;
            return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * linearize(r) + 0.7152 * linearize(g) + 0.0722 * linearize(b);
    }

    // Binary-searches lightness (at a fixed hue/saturation) until the
    // resulting colour's WCAG relative luminance lands in a band that
    // reads clearly both as white-text-on-accent and as accent-text on the
    // page's near-black background — see the call site for why luminance
    // rather than raw HSL lightness is the right thing to target.
    function ensureSafeLuminance(h, s, l) {
        var MIN_LUM = 0.12;
        var MAX_LUM = 0.16;

        var rgb = hslToRgb(h, s, l);
        var lum = relativeLuminance(rgb[0], rgb[1], rgb[2]);
        if (lum >= MIN_LUM && lum <= MAX_LUM) {
            return rgb;
        }

        var lo = 0, hi = 100, result = rgb;
        for (var i = 0; i < 18; i++) {
            var mid = (lo + hi) / 2;
            var testRgb = hslToRgb(h, s, mid);
            var testLum = relativeLuminance(testRgb[0], testRgb[1], testRgb[2]);
            result = testRgb;

            if (testLum < MIN_LUM) {
                lo = mid;
            } else if (testLum > MAX_LUM) {
                hi = mid;
            } else {
                break;
            }
        }

        return result;
    }

    function applyAccent(r, g, b) {
        var root = document.documentElement.style;
        root.setProperty("--accent", "rgb(" + r + ", " + g + ", " + b + ")");
        root.setProperty("--accent-soft", "rgba(" + r + ", " + g + ", " + b + ", 0.35)");
    }

    function setInfoKey(node, key) {
        if (!node) return;
        if (key) {
            node.setAttribute("data-info-key", key);
        } else {
            node.removeAttribute("data-info-key");
        }
    }

    function timeAgo(unix) {
        var secs = Math.max(0, Math.round(Date.now() / 1000 - unix));
        if (secs < 3600) return Math.max(1, Math.round(secs / 60)) + " min ago";
        if (secs < 86400) return Math.round(secs / 3600) + " h ago";
        var days = Math.round(secs / 86400);
        return days === 1 ? "yesterday" : days + " days ago";
    }

    // Live items carry when they started and when they're expected to end
    // (Trakt reports these directly; for a Plex session they're derived from
    // the real playback position), so the bar is wall-clock interpolation
    // between the two, ticked every second between polls. A paused Plex
    // session stays frozen at its reported position instead.
    function updateProgress() {
        if (!els.progress) {
            return;
        }
        var item = currentItem;
        if (!item || !item.live || !item.started_at || !item.expires_at || item.expires_at <= item.started_at) {
            els.progress.style.display = "none";
            return;
        }
        var duration = item.expires_at - item.started_at;
        var frac = item.paused && item.progress !== null && item.progress !== undefined
            ? item.progress
            : (Date.now() / 1000 - item.started_at) / duration;
        frac = Math.min(1, Math.max(0, frac));
        var remaining = Math.max(0, Math.round(((1 - frac) * duration) / 60));
        els.progress.style.display = "";
        els.progressFill.style.width = (frac * 100).toFixed(1) + "%";
        setText(els.progressLabel, Math.round(frac * 100) + "% · " + remaining + " min left" + (item.paused ? " · paused" : ""));
    }

    function renderBadge(item) {
        if (!els.badge) {
            return;
        }
        if (item.live && item.paused) {
            els.badge.classList.add("live");
            els.badge.textContent = "Paused";
        } else if (item.live) {
            els.badge.classList.add("live");
            els.badge.innerHTML = '<span class="eq"><span></span><span></span><span></span></span> ';
            els.badge.appendChild(document.createTextNode(
                item.action === "checkin" ? "Checked in" : "Now watching"));
        } else {
            els.badge.classList.remove("live");
            els.badge.textContent = "Last watched" + (item.watched_at ? " · " + timeAgo(item.watched_at) : "");
        }
    }

    function renderCurrent(item) {
        if (!item || !item.title) {
            return;
        }
        currentItem = item;

        setText(els.name, item.title);
        setInfoKey(els.artTile, item.info_key);
        setText(els.subtitle, item.subtitle);
        setText(els.meta, item.meta);
        if (els.heroRatings) {
            els.heroRatings.innerHTML = "";
            var chips = ratingChips(item.ratings);
            if (chips) els.heroRatings.appendChild(chips);
        }

        if (els.artFallback) els.artFallback.textContent = (item.title || "?").charAt(0).toUpperCase();
        setArt(els.artImg, els.artFallback, item.image);

        renderBadge(item);
        updateProgress();

        if (els.traktLink) {
            if (item.url) {
                els.traktLink.href = item.url;
                els.traktLink.style.display = "";
            } else {
                els.traktLink.style.display = "none";
            }
        }

        if (item.backdrop) {
            updateBackground(item.backdrop);
        } else {
            resetTheme();
        }
    }

    function renderPrevious(prev) {
        if (!els.prevWrap) {
            return;
        }
        if (!prev || !prev.title) {
            els.prevWrap.style.display = "none";
            return;
        }

        els.prevWrap.style.display = "";
        setText(els.prevName, prev.title);
        setInfoKey(els.prevThumb, prev.info_key);
        setText(els.prevSubtitle, prev.subtitle);
        if (els.prevRatings) {
            els.prevRatings.innerHTML = "";
            var chips = ratingChips(prev.ratings);
            if (chips) els.prevRatings.appendChild(chips);
        }
        if (els.prevArtFallback) els.prevArtFallback.textContent = (prev.title || "?").charAt(0).toUpperCase();
        setArt(els.prevArtImg, els.prevArtFallback, prev.image);
    }

    function renderStats(stats) {
        if (!stats) {
            return;
        }
        Object.keys(stats).forEach(function (key) {
            var node = document.querySelector('[data-stat="' + key + '"]');
            if (node) {
                node.textContent = stats[key];
            }
        });
    }

    function poll() {
        fetch("api.php", { cache: "no-store" })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    renderCurrent(data.current);
                    renderPrevious(data.previous);
                    renderStats(data.stats);
                    lastCheckTime = Date.now();
                    updateElapsedLabel();
                }
            })
            .catch(function () {
                /* silently retry on next interval */
            });
    }

    function updateElapsedLabel() {
        if (!els.updated) {
            return;
        }
        var secs = Math.max(0, Math.round((Date.now() - lastCheckTime) / 1000));
        els.updated.textContent = secs < 1 ? "Updated just now" : "Updated " + secs + "s ago";
    }

    // Kick off theming from the server-rendered initial item, then keep
    // polling for changes.
    if (currentItem) {
        renderBadge(currentItem);
        if (currentItem.backdrop) {
            updateBackground(currentItem.backdrop);
        }
    }

    updateElapsedLabel();
    updateProgress();
    setInterval(function () {
        updateElapsedLabel();
        updateProgress();
    }, 1000);
    setInterval(poll, config.pollIntervalMs);

    // --- Insight widget popups ---

    var modalOverlay = document.querySelector("[data-modal-overlay]");
    var modalBody = document.querySelector("[data-modal-body]");
    var modalClose = document.querySelector("[data-modal-close]");
    var widgetCache = {};
    var SVG_NS = "http://www.w3.org/2000/svg";

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function fmt(n) {
        return Number(n).toLocaleString();
    }

    function openModal() {
        if (!modalOverlay) return;
        modalOverlay.hidden = false;
    }

    function closeModal() {
        if (!modalOverlay) return;
        modalOverlay.hidden = true;
    }

    function loadWidget(id) {
        if (!modalBody) return;
        openModal();
        modalBody.innerHTML = "";
        modalBody.appendChild(el("div", "widget-loading", "Loading…"));

        if (widgetCache[id]) {
            renderWidget(id, widgetCache[id]);
            return;
        }

        fetch("widgets.php?id=" + encodeURIComponent(id), { cache: "no-store" })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (!payload || !payload.ok) {
                    modalBody.innerHTML = "";
                    modalBody.appendChild(el("div", "widget-empty", "Couldn't load this widget."));
                    return;
                }
                widgetCache[id] = payload.data;
                renderWidget(id, payload.data);
            })
            .catch(function () {
                modalBody.innerHTML = "";
                modalBody.appendChild(el("div", "widget-empty", "Couldn't load this widget."));
            });
    }

    var WIDGET_TITLES = {
        watch_clock: "Watch Clock",
        week_rhythm: "Weekly Rhythm",
        time_watched: "Time Watched",
        binge: "Binge Report",
        decades: "Movie Decades",
        streaks: "Streaks",
        hot_takes: "Hot Takes",
        watchlist: "Watchlist Debt",
    };

    var WIDGET_RENDERERS = {
        watch_clock: renderWatchClock,
        week_rhythm: renderWeekRhythm,
        time_watched: renderTimeWatched,
        binge: renderBinge,
        decades: renderDecades,
        streaks: renderStreaks,
        hot_takes: renderHotTakes,
        watchlist: renderWatchlist,
    };

    var WIDGET_EMPTY = {
        hot_takes: "No ratings yet — rate a few things on Trakt and check back.",
        watchlist: "Your watchlist is empty (or private without sign-in).",
        binge: "No binges yet — that's 3+ episodes of one show back to back.",
        decades: "No movies in your history yet.",
    };

    function renderWidget(id, data) {
        modalBody.innerHTML = "";
        modalBody.appendChild(el("h3", "widget-title", WIDGET_TITLES[id] || ""));

        if (!data || data.available === false) {
            modalBody.appendChild(el("div", "widget-empty", WIDGET_EMPTY[id] || "Not enough watch history yet — check back once more has synced."));
            return;
        }

        var renderer = WIDGET_RENDERERS[id];
        if (renderer) {
            renderer(data);
        }
    }

    function renderWatchClock(data) {
        var max = Math.max.apply(null, data.hours);
        // Labels sit at rMax + 18 from center, so the viewBox leaves enough
        // margin beyond that for "0:00"-style text not to get clipped.
        var cx = 130, cy = 130, rMax = 90, rMin = 22;
        var svg = document.createElementNS(SVG_NS, "svg");
        svg.setAttribute("viewBox", "0 0 260 260");
        svg.setAttribute("class", "clock-svg");

        for (var h = 0; h < 24; h++) {
            var angle = (h / 24) * Math.PI * 2 - Math.PI / 2;
            var frac = max > 0 ? data.hours[h] / max : 0;
            var r = rMin + frac * (rMax - rMin);
            var line = document.createElementNS(SVG_NS, "line");
            line.setAttribute("x1", cx + Math.cos(angle) * rMin);
            line.setAttribute("y1", cy + Math.sin(angle) * rMin);
            line.setAttribute("x2", cx + Math.cos(angle) * r);
            line.setAttribute("y2", cy + Math.sin(angle) * r);
            line.setAttribute("stroke", "var(--accent)");
            line.setAttribute("stroke-width", "6");
            line.setAttribute("stroke-linecap", "round");
            var title = document.createElementNS(SVG_NS, "title");
            title.textContent = h + ":00 — " + fmt(data.hours[h]) + " plays";
            line.appendChild(title);
            svg.appendChild(line);
        }

        [0, 6, 12, 18].forEach(function (h) {
            var angle = (h / 24) * Math.PI * 2 - Math.PI / 2;
            var text = document.createElementNS(SVG_NS, "text");
            text.setAttribute("x", cx + Math.cos(angle) * (rMax + 18));
            text.setAttribute("y", cy + Math.sin(angle) * (rMax + 18));
            text.setAttribute("text-anchor", "middle");
            text.setAttribute("dominant-baseline", "middle");
            text.textContent = h + ":00";
            svg.appendChild(text);
        });

        modalBody.appendChild(el("div", "widget-headline", data.label));
        modalBody.appendChild(svg);
        modalBody.appendChild(el("div", "widget-subtext", data.sample_note || ""));
    }

    function renderWeekRhythm(data) {
        var max = Math.max.apply(null, data.days);
        var bars = el("div", "energy-bars");

        data.days.forEach(function (hours, i) {
            var col = el("div", "energy-bar-col" + (data.labels[i] === data.peak_day ? " peak" : ""));
            col.title = data.labels[i] + ": " + fmt(hours) + " hours";
            var bar = el("div", "energy-bar");
            bar.style.height = (max > 0 ? Math.max(4, Math.round((hours / max) * 100)) : 4) + "%";
            col.appendChild(bar);
            col.appendChild(el("div", "energy-bar-label", data.labels[i]));
            bars.appendChild(col);
        });

        modalBody.appendChild(el("div", "widget-headline", "Peak day: " + data.peak_day));
        modalBody.appendChild(bars);
        modalBody.appendChild(el("div", "widget-subtext", "Total hours watched on each day of the week. " + (data.sample_note || "")));
    }

    function renderTimeWatched(data) {
        modalBody.appendChild(el("div", "widget-headline", fmt(data.total_hours) + " hours watched"));
        modalBody.appendChild(el("div", "widget-subtext", "That's about " + fmt(data.total_days) + " full days"));

        var list = el("ul", "distance-list");
        data.comparisons.forEach(function (c) {
            var li = el("li", "distance-row");
            var top = el("div", "distance-row-top");
            var left = document.createElement("span");
            left.appendChild(el("span", "distance-count", "~" + fmt(c.count)));
            left.appendChild(document.createTextNode(" " + c.label));
            top.appendChild(left);
            top.appendChild(el("span", null, c.pct + "%"));
            li.appendChild(top);

            var bar = el("div", "distance-bar");
            var fill = el("div", "distance-bar-fill");
            fill.style.width = c.pct + "%";
            bar.appendChild(fill);
            li.appendChild(bar);
            list.appendChild(li);
        });
        modalBody.appendChild(list);
        modalBody.appendChild(el("div", "widget-subtext", "Bars show how far into the next one you are. " + (data.source_note || "")));
    }

    // Same markup as renderRatingChips() in index.php.
    function ratingChips(chips) {
        if (!chips || !chips.length) {
            return null;
        }
        var wrap = el("span", "rating-chips");
        chips.forEach(function (c) {
            var chip = el("span", "rating-chip rating-" + c.kind);
            chip.title = c.title;
            chip.appendChild(el("span", "rating-label", c.label));
            chip.appendChild(document.createTextNode(" " + c.value));
            wrap.appendChild(chip);
        });
        return wrap;
    }

    // key: 'm<trakt id>' / 's<trakt id>' — hovering the poster then shows
    // that film's or show's info card.
    function posterThumb(url, name, key) {
        var thumb = el("span", "thumb thumb-poster");
        if (/^[ms]\d+$/.test(key || "")) thumb.setAttribute("data-info-key", key);
        var initial = (name || "?").charAt(0).toUpperCase();
        if (url) {
            var img = document.createElement("img");
            img.src = url;
            img.alt = "";
            img.loading = "lazy";
            img.addEventListener("error", function () {
                img.style.display = "none";
                thumb.textContent = initial;
            });
            thumb.appendChild(img);
        } else {
            thumb.textContent = initial;
        }
        return thumb;
    }

    function renderBinge(data) {
        var top = data.sessions[0];
        modalBody.appendChild(el("div", "widget-headline", top.episodes + " episodes of " + top.show + " in one go"));
        modalBody.appendChild(el("div", "widget-subtext", fmt(data.session_count) + " binges in total. Your biggest:"));

        var ol = el("ol", "track-list");
        data.sessions.forEach(function (s, i) {
            var li = el("li", "track-row");
            li.appendChild(el("span", "rank", String(i + 1)));
            li.appendChild(posterThumb(s.poster, s.show, s.key));
            var meta = el("span", "meta");
            meta.appendChild(el("div", "name", s.show));
            meta.appendChild(el("div", "artist", s.range + " · " + s.date));
            var chips = ratingChips(s.ratings);
            if (chips) meta.appendChild(chips);
            li.appendChild(meta);
            li.appendChild(el("span", "count", s.episodes + " eps · " + s.hours + "h"));
            ol.appendChild(li);
        });
        modalBody.appendChild(ol);
        modalBody.appendChild(el("div", "widget-subtext", data.sample_note || ""));
    }

    function renderDecades(data) {
        modalBody.appendChild(el("div", "widget-headline", "Your era: the " + data.peak_decade));
        modalBody.appendChild(el("div", "widget-subtext",
            fmt(data.movie_count) + " movies · median release year " + data.median_year + " · oldest: " + data.oldest));

        var max = Math.max.apply(null, data.decades.map(function (d) { return d.count; }));
        var bars = el("div", "energy-bars decade-bars");
        data.decades.forEach(function (d) {
            var col = el("div", "energy-bar-col" + (d.label === data.peak_decade ? " peak" : ""));
            col.title = d.label + ": " + fmt(d.count) + " movies";
            var bar = el("div", "energy-bar");
            bar.style.height = Math.max(4, Math.round((d.count / max) * 100)) + "%";
            col.appendChild(bar);
            col.appendChild(el("div", "energy-bar-label", "'" + d.label.slice(2)));
            bars.appendChild(col);
        });
        modalBody.appendChild(bars);
    }

    function renderStreaks(data) {
        modalBody.appendChild(el("div", "widget-headline", "Longest streak: " + fmt(data.longest) + " days"));
        modalBody.appendChild(el("div", "widget-subtext",
            (data.longest_range ? data.longest_range + " · " : "") + "current streak: " + fmt(data.current) + " day" + (data.current === 1 ? "" : "s")));

        // GitHub-style calendar: one column per week, Monday at the top.
        var max = Math.max.apply(null, data.calendar.map(function (d) { return d.minutes; }));
        var grid = el("div", "streak-grid");
        data.calendar.forEach(function (d) {
            var cell = el("span", "streak-cell");
            if (d.minutes > 0) {
                var level = Math.min(4, Math.ceil((d.minutes / max) * 4));
                cell.classList.add("streak-l" + level);
            }
            cell.title = d.date + ": " + (d.minutes ? Math.round(d.minutes) + " min" : "nothing");
            grid.appendChild(cell);
        });
        var wrap = el("div", "streak-grid-wrap");
        wrap.appendChild(grid);
        modalBody.appendChild(wrap);
        modalBody.appendChild(el("div", "widget-subtext",
            "Something watched on " + fmt(data.active_days) + " of the last " + fmt(data.calendar.length) + " days. " + (data.sample_note || "")));
    }

    function renderHotTakes(data) {
        modalBody.appendChild(el("div", "widget-headline", "Average rating: " + data.average + " / 10"));
        modalBody.appendChild(el("div", "widget-subtext", "Across " + fmt(data.count) + " ratings"));

        var max = Math.max.apply(null, data.distribution);
        var bars = el("div", "energy-bars rating-bars");
        data.distribution.forEach(function (count, i) {
            var col = el("div", "energy-bar-col");
            col.title = (i + 1) + "/10: " + fmt(count);
            var bar = el("div", "energy-bar");
            bar.style.height = (max > 0 ? Math.max(4, Math.round((count / max) * 100)) : 4) + "%";
            col.appendChild(bar);
            col.appendChild(el("div", "energy-bar-label", String(i + 1)));
            bars.appendChild(col);
        });
        modalBody.appendChild(bars);

        if (data.takes && data.takes.length) {
            modalBody.appendChild(el("div", "widget-section-label", "Your hottest takes"));
            var list = el("ul", "take-list");
            data.takes.forEach(function (t) {
                var li = el("li", "take-row");
                li.appendChild(el("span", "take-title", t.title));
                var verdict = t.diff > 0 ? "you loved it" : "you didn't";
                li.appendChild(el("span", "take-scores", "you " + t.mine + " · them " + t.community + " — " + verdict));
                list.appendChild(li);
            });
            modalBody.appendChild(list);
            modalBody.appendChild(el("div", "widget-subtext", "Movies and shows with 100+ community votes, furthest from the Trakt average."));
        }
    }

    function renderWatchlist(data) {
        modalBody.appendChild(el("div", "widget-headline", fmt(data.hours) + " hours of watchlist"));
        var sub = fmt(data.total_items) + " items (" + fmt(data.movies) + " movies, " + fmt(data.shows) + " shows)";
        if (data.days_to_clear) {
            sub += " — about " + fmt(data.days_to_clear) + " days to clear at your recent pace of " + fmt(data.per_day_min) + " min/day";
        } else if (data.per_day_min === null) {
            sub += " — your recent pace is still syncing.";
        } else {
            sub += " — at your recent pace, never. Better get started.";
        }
        modalBody.appendChild(el("div", "widget-subtext", sub));

        if (data.pick) {
            var p = data.pick;
            modalBody.appendChild(el("div", "widget-section-label", "Tonight's pick"));
            var card = el("div", "pick-card");
            card.appendChild(posterThumb(p.poster, p.title, p.key));
            var info = el("div", "pick-info");
            var name = p.url ? el("a", "pick-title", p.title) : el("div", "pick-title", p.title);
            if (p.url) {
                name.href = p.url;
                name.target = "_blank";
                name.rel = "noopener";
            }
            info.appendChild(name);
            info.appendChild(el("div", "pick-meta", [p.year, p.runtime ? p.runtime + " min" : ""].filter(Boolean).join(" · ")));
            var pickChips = ratingChips(p.ratings);
            if (pickChips) info.appendChild(pickChips);
            if (p.overview) {
                info.appendChild(el("div", "pick-overview", p.overview));
            }
            card.appendChild(info);
            modalBody.appendChild(card);
        }
    }

    // --- Period pickers (Top Shows, Top Movies, Genre Breakdown) ---

    function genreColor(i) {
        return "hsl(" + ((i * 137.508) % 360) + ", 65%, 55%)";
    }

    function renderGenreContent(container, data) {
        container.innerHTML = "";
        var genres = data.genres;

        if (data.syncing) {
            container.appendChild(el("p", "empty-state", config.syncingMessage));
            return;
        }
        if (!genres || !genres.length) {
            container.appendChild(el("p", "empty-state", "Nothing watched in this period."));
            return;
        }

        var bar = el("div", "genre-bar");
        genres.forEach(function (g, i) {
            var seg = el("div", "genre-segment");
            seg.style.width = g.pct + "%";
            seg.style.background = genreColor(i);
            seg.title = g.name + " — " + g.pct + "%";
            bar.appendChild(seg);
        });
        container.appendChild(bar);

        var legend = el("ul", "genre-legend");
        genres.forEach(function (g, i) {
            var li = el("li", "genre-legend-item");
            li.setAttribute("data-pct", g.pct);
            var swatch = el("span", "genre-swatch");
            swatch.style.background = genreColor(i);
            li.appendChild(swatch);
            li.appendChild(el("span", "genre-name", g.name));
            li.appendChild(el("span", "genre-pct", g.pct + "%"));
            legend.appendChild(li);
        });
        container.appendChild(legend);

        applyGenreThreshold();
    }

    // Hides legend rows below the selected percentage threshold so a long
    // tail of small genres doesn't make the page too tall — the bar stays
    // untouched, only the list is filtered. Re-applied after every
    // re-render, so the choice persists across period switches.
    var genreThresholdSelect = document.querySelector("[data-genre-threshold]");

    function applyGenreThreshold() {
        if (!genreThresholdSelect) {
            return;
        }
        var min = parseFloat(genreThresholdSelect.value) || 0;
        document.querySelectorAll(".genre-legend-item").forEach(function (li) {
            var pct = parseFloat(li.getAttribute("data-pct")) || 0;
            li.classList.toggle("genre-hidden", pct < min);
        });
    }

    if (genreThresholdSelect) {
        genreThresholdSelect.addEventListener("change", applyGenreThreshold);
        applyGenreThreshold();
    }

    // Same markup as renderTitleListMarkup() in index.php — keep the two in step.
    function renderTitleListContent(container, data, emptyMessage) {
        container.innerHTML = "";

        if (data.syncing) {
            container.appendChild(el("p", "empty-state", config.syncingMessage));
            return;
        }
        if (!data.titles || !data.titles.length) {
            container.appendChild(el("p", "empty-state", emptyMessage));
            return;
        }

        var ol = el("ol", "track-list");
        data.titles.forEach(function (t) {
            var li = el("li", "track-row");
            li.appendChild(el("span", "rank", String(t.rank)));
            li.appendChild(posterThumb(t.art, t.name, t.key));

            var meta = el("span", "meta");
            var name = el("div", "name");
            if (t.url) {
                var a = el("a", null, t.name);
                a.href = t.url;
                a.target = "_blank";
                a.rel = "noopener";
                name.appendChild(a);
            } else {
                name.textContent = t.name;
            }
            meta.appendChild(name);
            meta.appendChild(el("div", "artist", t.sub));
            var chips = ratingChips(t.ratings);
            if (chips) meta.appendChild(chips);
            li.appendChild(meta);

            var count = el("span", "count", t.count);
            if (t.pct !== null && t.pct !== undefined) {
                var bar = el("div", "bar");
                var fill = el("div", "bar-fill");
                fill.style.width = t.pct + "%";
                bar.appendChild(fill);
                count.appendChild(bar);
            }
            li.appendChild(count);

            ol.appendChild(li);
        });

        container.appendChild(ol);
    }

    var PERIOD_PICKERS = {
        genre: {
            url: function (period) { return "widgets.php?id=genre&period=" + encodeURIComponent(period); },
            render: function (container, data) { renderGenreContent(container, data); },
        },
        shows: {
            url: function (period) { return "widgets.php?id=titles&panel=shows&period=" + encodeURIComponent(period); },
            render: function (container, data) { renderTitleListContent(container, data, "No episodes watched in this period."); },
        },
        movies: {
            url: function (period) { return "widgets.php?id=titles&panel=movies&period=" + encodeURIComponent(period); },
            render: function (container, data) { renderTitleListContent(container, data, "No movies watched in this period."); },
        },
    };

    Object.keys(PERIOD_PICKERS).forEach(function (group) {
        var pickerConfig = PERIOD_PICKERS[group];
        var buttons = document.querySelectorAll('[data-period-group="' + group + '"]');
        var container = document.querySelector('[data-period-content="' + group + '"]');
        if (!buttons.length || !container) return;

        buttons.forEach(function (btn) {
            btn.addEventListener("click", function () {
                var period = btn.getAttribute("data-period");
                if (btn.classList.contains("active")) return;

                buttons.forEach(function (b) { b.classList.remove("active"); });
                btn.classList.add("active");

                container.innerHTML = "";
                container.appendChild(el("div", "widget-loading", "Loading…"));

                fetch(pickerConfig.url(period), { cache: "no-store" })
                    .then(function (res) { return res.json(); })
                    .then(function (payload) {
                        if (payload && payload.ok) {
                            pickerConfig.render(container, payload.data);
                        } else {
                            container.innerHTML = "";
                            container.appendChild(el("p", "empty-state", "Couldn't load this period."));
                        }
                    })
                    .catch(function () {
                        container.innerHTML = "";
                        container.appendChild(el("p", "empty-state", "Couldn't load this period."));
                    });
            });
        });
    });

    document.querySelectorAll("[data-widget-id]").forEach(function (card) {
        card.addEventListener("click", function () {
            loadWidget(card.getAttribute("data-widget-id"));
        });
    });

    if (modalClose) {
        modalClose.addEventListener("click", closeModal);
    }
    if (modalOverlay) {
        modalOverlay.addEventListener("click", function (evt) {
            if (evt.target === modalOverlay) {
                closeModal();
            }
        });
    }
    document.addEventListener("keydown", function (evt) {
        if (evt.key === "Escape") {
            closeModal();
        }
    });

    // --- Poster hover card ---
    // Any poster carrying data-info-key ('s<trakt id>' for a show,
    // 'm<trakt id>' for a film) — in Top Shows / Top Movies, the Now
    // Watching and Previously watched cards, Binge Report rows and the
    // watchlist pick — shows an info card after a short hover. Fetched once
    // per title per visit from details.php; mouse-only, since touch has no hover.

    var infoCache = {};
    var infoCard = null;
    var infoTimer = null;
    var infoTarget = null;

    function buildInfoCard(info) {
        var card = el("div", "info-card");
        card.appendChild(el("div", "info-card-title", info.title + (info.year ? " (" + info.year + ")" : "")));

        if (info.tagline) {
            card.appendChild(el("div", "info-card-tagline", info.tagline));
        }
        if (info.facts && info.facts.length) {
            card.appendChild(el("div", "info-card-facts", info.facts.join(" · ")));
        }
        var chips = ratingChips(info.chips);
        if (chips) card.appendChild(chips);
        if (info.genres && info.genres.length) {
            card.appendChild(el("div", "info-card-genres", info.genres.join(", ")));
        }
        if (info.overview) {
            card.appendChild(el("p", "info-card-overview", info.overview));
        }

        if (info.rows && info.rows.length) {
            var rows = el("dl", "info-card-rows");
            info.rows.forEach(function (r) {
                rows.appendChild(el("dt", null, r.label));
                rows.appendChild(el("dd", null, r.value));
            });
            card.appendChild(rows);
        }

        return card;
    }

    function positionInfoCard(target) {
        var rect = target.getBoundingClientRect();
        var cardRect = infoCard.getBoundingClientRect();
        var margin = 12;
        var left = Math.min(window.innerWidth - cardRect.width - margin, Math.max(margin, rect.left));
        var top = rect.bottom + 8;
        if (top + cardRect.height > window.innerHeight - margin) {
            top = Math.max(margin, rect.top - cardRect.height - 8); // flip above if no room below
        }
        infoCard.style.left = (left + window.scrollX) + "px";
        infoCard.style.top = (top + window.scrollY) + "px";
    }

    function showInfoCard(target, info) {
        hideInfoCard();
        if (infoTarget !== target) return; // mouse moved on while loading
        infoCard = buildInfoCard(info);
        document.body.appendChild(infoCard);
        positionInfoCard(target);
        infoCard.classList.add("visible");
    }

    function hideInfoCard() {
        if (infoCard) {
            infoCard.remove();
            infoCard = null;
        }
    }

    function loadInfo(target) {
        var key = target.getAttribute("data-info-key");
        if (infoCache[key]) {
            showInfoCard(target, infoCache[key]);
            return;
        }
        fetch("details.php?key=" + encodeURIComponent(key), { cache: "no-store" })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (payload && payload.ok) {
                    infoCache[key] = payload.info;
                    showInfoCard(target, payload.info);
                }
            })
            .catch(function () { /* no card this time */ });
    }

    document.addEventListener("mouseover", function (evt) {
        var target = evt.target.closest && evt.target.closest("[data-info-key]");
        if (target === infoTarget) return;
        infoTarget = target;
        clearTimeout(infoTimer);
        hideInfoCard();
        if (target) {
            infoTimer = setTimeout(function () { loadInfo(target); }, 350);
        }
    });

    document.addEventListener("scroll", function () {
        clearTimeout(infoTimer);
        infoTarget = null;
        hideInfoCard();
    }, { passive: true });
})();
