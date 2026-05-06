(function(window) {
    'use strict';

    /**
     * Platform providers with deterministic client-side embed URL construction.
     * Each entry: { name, pattern (RegExp), embed (fn(match) -> URL string) }
     */
    var PLATFORM_PROVIDERS = [
        {
            name: 'youtube',
            pattern: /(?:youtube\.com\/watch\?(?:[^&]*&)*v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/,
            embed: function(m) { return 'https://www.youtube.com/embed/' + m[1] + '?rel=0'; }
        },
        {
            name: 'odysee',
            pattern: /odysee\.com\/((@[^/?#]+)\/([^/?#]+))/,
            embed: function(m) { return 'https://odysee.com/$/embed/' + m[1]; }
        },
        {
            name: 'bitchute',
            pattern: /bitchute\.com\/video\/([a-zA-Z0-9]+)/,
            embed: function(m) { return 'https://www.bitchute.com/embed/' + m[1] + '/'; }
        },
        {
            name: 'brighteon',
            pattern: /brighteon\.com\/(?!embed\/)([a-zA-Z0-9_-]{8,})/,
            embed: function(m) { return 'https://www.brighteon.com/embed/' + m[1]; }
        }
    ];

    /**
     * Providers that need server-side resolution before embedding.
     * Each entry: { name, pattern (RegExp) }
     */
    var PROXY_PROVIDERS = [
        {
            name: 'bastyon',
            pattern: /bastyon\.com\/index\?[^#]*\bvideo=1\b/i
        }
    ];

    /**
     * oEmbed providers resolved client-side. The browser fetches the oEmbed endpoint
     * directly; /api/media/embed is used as a silent CORS fallback when the direct
     * fetch fails (e.g. providers that do not set Access-Control-Allow-Origin).
     * Each entry: { name, pattern (RegExp), endpoint (fn(url) -> endpoint URL string) }
     */
    var OEMBED_PROVIDERS = [
        {
            name: 'rumble',
            pattern: /rumble\.com\/v[a-z0-9]+-/i,
            endpoint: function(url) { return 'https://rumble.com/api/Media/oembed.json?url=' + encodeURIComponent(url); }
        },
        {
            name: 'soundcloud',
            pattern: /soundcloud\.com\//i,
            endpoint: function(url) { return 'https://soundcloud.com/oembed?format=json&url=' + encodeURIComponent(url); }
        },
        {
            name: 'twitter',
            pattern: /(?:twitter|x)\.com\//i,
            endpoint: function(url) { return 'https://publish.twitter.com/oembed?url=' + encodeURIComponent(url); }
        },
        {
            name: 'tiktok',
            pattern: /tiktok\.com\/@[^\/]+\/video\//i,
            endpoint: function(url) { return 'https://www.tiktok.com/oembed?url=' + encodeURIComponent(url); }
        },
        {
            name: 'reverbnation',
            pattern: /reverbnation\.com\//i,
            endpoint: function(url) { return 'https://www.reverbnation.com/oembed?format=json&url=' + encodeURIComponent(url); }
        }
    ];

    /** In-memory cache: source URL -> resolved embed HTML (empty string = no embed) */
    var oembedCache = Object.create(null);

    var VIDEO_EXTS = /\.(mp4|webm|ogv|mov)$/i;
    var AUDIO_EXTS = /\.(mp3|flac|ogg|opus|wav|m4a|aac)$/i;
    var IMAGE_EXTS = /\.(png|webp|gif|jpe?g|svg)$/i;

    function isEnabled() {
        return (window.siteConfig || {}).mediaPlayerEnabled !== false;
    }

    function isAutoMode() {
        return ((window.userSettings || {}).media_render_mode || 'auto') !== 'click';
    }

    function buildIframe(src, providerName) {
        var iframe = document.createElement('iframe');
        iframe.src = src;
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('loading', 'lazy');
        iframe.className = 'bink-media-iframe';
        iframe.title = providerName + ' embed';
        return iframe;
    }

    function buildVideo(url) {
        var video = document.createElement('video');
        video.controls = true;
        video.preload = 'metadata';
        video.className = 'bink-media-player bink-media-video';
        var source = document.createElement('source');
        source.src = url;
        video.appendChild(source);
        return video;
    }

    function buildAudio(url) {
        var audio = document.createElement('audio');
        audio.controls = true;
        audio.preload = 'metadata';
        audio.className = 'bink-media-player bink-media-audio';
        var source = document.createElement('source');
        source.src = url;
        audio.appendChild(source);
        return audio;
    }

    function initPlyr(el) {
        if (window.BinkPlayer) {
            window.BinkPlayer.init(el);
        }
    }

    /**
     * Walk up the DOM to find the nearest scrollable container (the modal body).
     * Used to get the true available pixel width for image clamping.
     */
    function findScrollContainer(el) {
        var node = el.parentNode;
        while (node && node !== document.body) {
            var style = window.getComputedStyle(node);
            if (style.overflow === 'auto' || style.overflow === 'scroll' ||
                    style.overflowX === 'auto' || style.overflowX === 'scroll' ||
                    style.overflowY === 'auto' || style.overflowY === 'scroll') {
                return node;
            }
            node = node.parentNode;
        }
        return document.body;
    }

    /**
     * SVG is intentionally loaded as <img> (not <object> or inline SVG) so the
     * browser does not execute SVG scripts and the image cannot access the parent DOM.
     *
     * After loading, clamp the image to the modal's actual pixel width so that
     * percentage-based max-width cannot resolve wider than the modal when the image
     * is injected inside an inline <span>.
     */
    function buildImage(url) {
        var img = document.createElement('img');
        img.src = url;
        img.referrerPolicy = 'no-referrer';
        img.loading = 'lazy';
        img.className = 'bink-media-player bink-media-image';
        img.alt = '';
        img.addEventListener('load', function() {
            var container = findScrollContainer(img);
            var available = container ? Math.floor(container.clientWidth * 0.95) : 0;
            if (available > 0 && img.naturalWidth > available) {
                img.style.maxWidth = available + 'px';
            }
        });
        return img;
    }

    function wrapEmbed(el) {
        var wrap = document.createElement('div');
        wrap.className = 'bink-media-embed mt-2 mb-1';
        wrap.appendChild(el);
        return wrap;
    }

    function buildLoadButton(mediaEl, isMedia) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'bink-media-load-btn btn btn-sm btn-outline-secondary ms-2';
        btn.textContent = '▶ Load player';
        btn.addEventListener('click', function() {
            btn.parentNode.insertBefore(wrapEmbed(mediaEl), btn);
            if (isMedia) initPlyr(mediaEl);
            btn.remove();
        });
        return btn;
    }

    function injectAfter(anchor, el) {
        var isMedia = el.tagName === 'VIDEO' || el.tagName === 'AUDIO';
        if (isAutoMode()) {
            anchor.parentNode.insertBefore(wrapEmbed(el), anchor.nextSibling);
            if (isMedia) initPlyr(el);
        } else {
            anchor.parentNode.insertBefore(buildLoadButton(el, isMedia), anchor.nextSibling);
        }
    }

    /**
     * Re-execute <script> tags inside a freshly-injected element.
     * Browsers do not run scripts set via innerHTML.
     */
    function activateScripts(el) {
        var scripts = el.querySelectorAll('script');
        for (var i = 0; i < scripts.length; i++) {
            var old = scripts[i];
            var fresh = document.createElement('script');
            for (var j = 0; j < old.attributes.length; j++) {
                fresh.setAttribute(old.attributes[j].name, old.attributes[j].value);
            }
            fresh.textContent = old.textContent;
            old.parentNode.replaceChild(fresh, old);
        }
    }

    function injectOembed(anchor, html) {
        var wrap = document.createElement('div');
        wrap.className = 'bink-media-embed bink-oembed mt-2 mb-1';
        wrap.innerHTML = html;
        if (isAutoMode()) {
            anchor.parentNode.insertBefore(wrap, anchor.nextSibling);
            activateScripts(wrap);
        } else {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'bink-media-load-btn btn btn-sm btn-outline-secondary ms-2';
            btn.textContent = '▶ Load player';
            btn.addEventListener('click', function() {
                btn.parentNode.insertBefore(wrap, btn);
                activateScripts(wrap);
                btn.remove();
            });
            anchor.parentNode.insertBefore(btn, anchor.nextSibling);
        }
    }

    function resolveOembed(anchor, href, provider) {
        if (href in oembedCache) {
            if (oembedCache[href]) injectOembed(anchor, oembedCache[href]);
            return;
        }

        fetch(provider.endpoint(href))
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function(data) {
                var html = (data && data.html) || '';
                oembedCache[href] = html;
                if (html) injectOembed(anchor, html);
            })
            .catch(function() {
                fetch('/api/media/embed?url=' + encodeURIComponent(href))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var html = (data && data.type !== 'unknown' && data.embed_html) || '';
                        oembedCache[href] = html;
                        if (html) injectOembed(anchor, html);
                    })
                    .catch(function() { oembedCache[href] = ''; });
            });
    }

    function resolveViaProxy(anchor, href) {
        if (href in oembedCache) {
            if (oembedCache[href]) injectOembed(anchor, oembedCache[href]);
            return;
        }

        fetch('/api/media/embed?url=' + encodeURIComponent(href))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var html = (data && data.type !== 'unknown' && data.embed_html) || '';
                oembedCache[href] = html;
                if (html) injectOembed(anchor, html);
            })
            .catch(function() { oembedCache[href] = ''; });
    }

    function matchProxyProvider(href) {
        for (var i = 0; i < PROXY_PROVIDERS.length; i++) {
            if (PROXY_PROVIDERS[i].pattern.test(href)) return PROXY_PROVIDERS[i];
        }
        return null;
    }

    function matchOembedProvider(href) {
        for (var i = 0; i < OEMBED_PROVIDERS.length; i++) {
            if (OEMBED_PROVIDERS[i].pattern.test(href)) return OEMBED_PROVIDERS[i];
        }
        return null;
    }

    function stripQuery(url) {
        return url.split('?')[0].split('#')[0];
    }

    function resolveAnchor(anchor) {
        var href = anchor.href;
        if (!href || !/^https?:\/\//i.test(href)) return;

        for (var i = 0; i < PLATFORM_PROVIDERS.length; i++) {
            var provider = PLATFORM_PROVIDERS[i];
            var m = href.match(provider.pattern);
            if (m) {
                injectAfter(anchor, buildIframe(provider.embed(m), provider.name));
                return;
            }
        }

        var path = stripQuery(href);

        if (VIDEO_EXTS.test(path)) {
            injectAfter(anchor, buildVideo(href));
        } else if (AUDIO_EXTS.test(path)) {
            injectAfter(anchor, buildAudio(href));
        } else if (IMAGE_EXTS.test(path)) {
            injectAfter(anchor, buildImage(href));
        } else {
            var proxyProvider = matchProxyProvider(href);
            if (proxyProvider) {
                resolveViaProxy(anchor, href);
                return;
            }

            var oembedProvider = matchOembedProvider(href);
            if (oembedProvider) resolveOembed(anchor, href, oembedProvider);
        }
    }

    function getContainer(containerOrSelector) {
        if (!containerOrSelector) return null;
        if (typeof containerOrSelector === 'string') {
            return document.querySelector(containerOrSelector);
        }
        return containerOrSelector;
    }

    /**
     * Scan all anchor tags inside container and inject embeds for recognized media URLs.
     * Safe to call multiple times — already-processed anchors are skipped.
     * @param {Element|string} containerOrSelector
     */
    function scan(containerOrSelector) {
        var container = getContainer(containerOrSelector);
        if (!container) return;
        if (!isEnabled()) return;

        var anchors = container.querySelectorAll('a[href]');
        for (var i = 0; i < anchors.length; i++) {
            var anchor = anchors[i];
            if (anchor.dataset.binkMediaProcessed) continue;
            anchor.dataset.binkMediaProcessed = '1';
            resolveAnchor(anchor);
        }
    }

    /**
     * Remove all injected embeds and load buttons inside container and reset processed markers.
     * Call before re-rendering a container so embeds don't duplicate.
     * @param {Element|string} containerOrSelector
     */
    function cleanup(containerOrSelector) {
        var container = getContainer(containerOrSelector);
        if (!container) return;

        var embeds = container.querySelectorAll('.bink-media-embed, .bink-media-load-btn');
        for (var i = 0; i < embeds.length; i++) {
            embeds[i].remove();
        }

        var processed = container.querySelectorAll('a[data-bink-media-processed]');
        for (var i = 0; i < processed.length; i++) {
            delete processed[i].dataset.binkMediaProcessed;
        }
    }

    window.BinkMediaPlayer = { scan: scan, cleanup: cleanup };

})(window);
