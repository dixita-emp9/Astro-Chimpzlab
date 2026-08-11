// WordPress REST API data source for ChimpzLab insights/blogs.
// Loaded before the inline loaders on blog-insite, insights, the homepage
// slider and service-page loaders so they all share one fetch+normalize path.
// WordPress is the single source of truth for blog content.
window.WPInsights = (function () {
    var API = "https://chimpzlab.com/chimpzlab-old/wp-json/wp/v2/insights";
    var PROXY = "/wp-proxy.php?endpoint=insights";
    var PROXY_IMG = "/wp-proxy.php?img=";
    var FALLBACK_IMG = "/asset/home-page.webp";
    var usingProxy = false;

    // Fetches through the same-origin PHP proxy first (works even when a strict
    // Content-Security-Policy blocks cross-origin calls to chimpzlab.com), and
    // falls back to the direct WordPress REST API when the proxy is not running
    // (e.g. `astro dev`, where public PHP files are served as plain text).
    async function fetchWp(query) {
        try {
            var res = await fetch(PROXY + query.replace(/^\?/, "&"));
            var text = await res.text();
            if (res.ok && /^[\s]*[{[]/.test(text)) {
                usingProxy = true;
                return JSON.parse(text);
            }
        } catch (e) {}
        usingProxy = false;
        var res2 = await fetch(API + query);
        if (!res2.ok) throw new Error("WP " + res2.status);
        return await res2.json();
    }

    // Rewrites chimpzlab.com media URLs so they load through the same-origin
    // proxy (only needed when the proxy is in use, i.e. under a strict CSP).
    function proxifyImage(url) {
        if (usingProxy && /^https:\/\/chimpzlab\.com\//i.test(url || "")) {
            return PROXY_IMG + encodeURIComponent(url);
        }
        return url;
    }

    function stripTags(html) {
        var d = document.createElement("div");
        d.innerHTML = html || "";
        return (d.textContent || "").replace(/\s+/g, " ").trim();
    }

    function fmtDate(iso) {
        if (!iso) return "";
        try {
            return new Date(iso).toLocaleDateString("en-US", {
                year: "numeric",
                month: "long",
            });
        } catch (e) {
            return iso;
        }
    }

    function computedReadTime(html) {
        var words = (stripTags(html).match(/\S+/g) || []).length;
        return Math.max(1, Math.ceil(words / 200)) + " Min Read";
    }

    function absImage(src) {
        if (!src) return FALLBACK_IMG;
        if (/^https?:/.test(src) || src.charAt(0) === "/") return src;
        return "/" + src;
    }

    function splitTitle(title) {
        var t = String(title || "").trim();
        if (!t) return [""];
        // Handle titles with question marks properly
        if (t.includes('?')) {
            var parts = t.split('?');
            if (parts.length > 1) {
                // Add question mark back to first part
                parts[0] = parts[0] + '?';
                // Remove any extra whitespace
                return parts.map(p => p.trim()).filter(Boolean);
            }
        }
        var parts = t
            .split(/\s*[?.:]\s+|\s+[-–—]\s+/)
            .map(function (s) {
                return s.trim();
            })
            .filter(Boolean);
        if (parts.length > 3) parts = parts.slice(0, 3);
        if (parts.length === 1 && t.length > 70) {
            var words = t.split(/\s+/);
            var mid = Math.ceil(words.length / 2);
            parts = [
                words.slice(0, mid).join(" "),
                words.slice(mid).join(" "),
            ];
        }
        return parts.length ? parts : [t];
    }

    function blocksToHtml(blocks) {
        return (blocks || [])
            .map(function (s) {
                if (!s || !s.type) return "";
                if (s.type === "p") return "<p>" + s.text + "</p>";
                if (s.type === "h2") return "<h2>" + s.text + "</h2>";
                if (s.type === "h3") return "<h3>" + s.text + "</h3>";
                if (s.type === "blockquote")
                    return "<blockquote>" + s.text + "</blockquote>";
                return "";
            })
            .join("");
    }

    // Cleans WP-rendered HTML so it matches the site's own article markup:
    //  - unwraps Gutenberg/theme wrapper divs so the top-level children are the
    //    actual blocks (p / h2 / blockquote)
    //  - removes empty paragraphs
    function cleanHtml(html) {
        if (!html) return "";
        var d = document.createElement("div");
        d.innerHTML = html;
        var wrappers = Array.prototype.slice.call(
            d.querySelectorAll("div.vgblk-rw-wrapper, div.entry-content"),
        );
        wrappers.forEach(function (w) {
            while (w.firstChild) {
                w.parentNode.insertBefore(w.firstChild, w);
            }
            w.parentNode.removeChild(w);
        });
        var empties = Array.prototype.slice.call(
            d.querySelectorAll("p"),
        ).filter(function (p) {
            return !(p.textContent || "").trim() && !p.querySelector("img");
        });
        empties.forEach(function (p) {
            if (p.parentNode) p.parentNode.removeChild(p);
        });
        return d.innerHTML;
    }

    // Pulls the "Key Takeaways" <ul> (the one following a heading that mentions
    // "Takeaway") out of the WP content. Falls back to the first <ul>.
    // Returns { body, takeaways }. Lets users author takeaways in Gutenberg.
    function extractTakeaways(html) {
        if (!html || html.indexOf("<ul") === -1) {
            return { body: html || "", takeaways: [] };
        }
        var d = document.createElement("div");
        d.innerHTML = html;
        var list = null;
        var lists = Array.prototype.slice.call(d.querySelectorAll("ul"));
        for (var i = 0; i < lists.length; i++) {
            var prev = lists[i].previousElementSibling;
            if (prev && /takeaway/i.test(prev.textContent || "")) {
                list = lists[i];
                break;
            }
        }
        if (!list) list = lists[0];
        var takeaways = [];
        if (list) {
            var listWrap = list.parentNode;
            // Capture takeaways text from <li> elements.
            list.querySelectorAll("li").forEach(function (li) {
                var t = (li.textContent || "").replace(/\s+/g, " ").trim();
                if (t) takeaways.push(t);
            });
            // Remove the heading that introduces the list (e.g. "Key Takeaways")
            // so it doesn't show up again as a section heading in the body.
            var prev = list.previousElementSibling;
            if (prev && prev.tagName === "H2" && /takeaway/i.test(prev.textContent || "")) {
                listWrap.removeChild(prev);
            }
            listWrap.removeChild(list);
            // If the wrapper is now empty, remove the blank comment nodes / empty
            // paragraphs Gutenberg leaves behind.
            if (listWrap && !listWrap.hasChildNodes()) {
                listWrap.parentNode && listWrap.parentNode.removeChild(listWrap);
            }
        }
        return { body: d.innerHTML, takeaways: takeaways };
    }

    // Pulls a "<!-- name:value -->" comment marker embedded in the post content
    // by the migration script (e.g. readtime, author title line-splits).
    function getMarker(html, name) {
        var re = new RegExp(
            "<!--\\s*" + name + ":\\s*([\\s\\S]*?)\\s*-->",
        );
        var m = html.match(re);
        return m ? m[1].trim() : "";
    }

    function normalize(post) {
        var title = stripTags(post.title && post.title.rendered);
        var contentHtml = cleanHtml(
            (post.content && post.content.rendered) || "",
        );
        // Use the authored read time embedded as an HTML comment by the
        // migration script ("<!-- readtime:5 Min Read -->"); fall back to the
        // word-count estimate if the marker is missing.
        var readTime = getMarker(contentHtml, "readtime");
        // Restore the author's exact title line-breaks (titleParts) instead of
        // re-splitting the flattened title, so the H1 animation wraps the same.
        var titleParts = [];
        try {
            var parsed = JSON.parse(getMarker(contentHtml, "titleparts"));
            if (Array.isArray(parsed) && parsed.length) {
                titleParts = parsed.map(function (p) {
                    return String(p);
                });
            }
        } catch (e) {}
        contentHtml = contentHtml.replace(
            /<!--\s*(readtime|titleparts):[\s\S]*?-->/g,
            "",
        );
        // Relay in-body chimpzlab.com media through the same-origin proxy so
        // images inside article bodies also work under a strict CSP.
        contentHtml = contentHtml.replace(
            /src="(https:\/\/chimpzlab\.com\/[^"]+)"/gi,
            function (m, u) {
                return 'src="' + proxifyImage(u) + '"';
            },
        );
        var excerpt = (post.excerpt && post.excerpt.rendered) || "";
        var terms = (post._embedded && post._embedded["wp:term"]) || [];
        var media =
            (post._embedded &&
                post._embedded["wp:featuredmedia"] &&
                post._embedded["wp:featuredmedia"][0]) ||
            null;
        var catGroup =
            terms.find(function (g) {
                return g && g[0] && g[0].taxonomy === "category";
            }) || [];
        var tagGroup =
            terms.find(function (g) {
                return g && g[0] && g[0].taxonomy === "post_tag";
            }) || [];
        var cats = catGroup.map(function (x) {
            return x.slug;
        });
        var tags = tagGroup.map(function (x) {
            return x.name;
        });
        var takeaways = [];
        var image = media && media.source_url ? media.source_url : "";
        image = proxifyImage(image);
        
        // Extract takeaways from content if not in meta
        var extracted = extractTakeaways(contentHtml);
        contentHtml = extracted.body;
        takeaways = extracted.takeaways;
        
        return {
            slug: post.slug,
            category: cats[0] || "strategy",
            tag: tags.join(", ") || "",
            readTime: readTime || computedReadTime(contentHtml),
            date: fmtDate(post.date) || "",
            titleParts: titleParts.length ? titleParts : splitTitle(title),
            titleFull: title,
            image: absImage(image),
            alt: (media && media.alt_text) || title,
            intro: stripTags(excerpt) || "",
            takeaways: takeaways,
            body: contentHtml,
        };
    }

    async function fetchIndex() {
        var data = [];
        try {
            data = await fetchWp("?per_page=100&_embed&orderby=date&order=desc");
            if (!Array.isArray(data)) throw new Error("bad payload");
        } catch (err) {
            console.warn("WPInsights fetchIndex failed:", err);
            return [];
        }
        data.sort(function (a, b) {
            var da = new Date(a.date).getTime() || 0;
            var db = new Date(b.date).getTime() || 0;
            if (da !== db) return db - da;
            return String(a.title && a.title.rendered).localeCompare(
                String(b.title && b.title.rendered),
            );
        });
        return data.map(function (p) {
            return normalize(p);
        });
    }

    async function fetchArticle(slug) {
        try {
            var data = await fetchWp(
                "?slug=" + encodeURIComponent(slug) + "&_embed",
            );
            if (!Array.isArray(data) || data.length === 0)
                throw new Error("not found");
            return normalize(data[0]);
        } catch (err) {
            console.warn("WPInsights fetchArticle failed:", err);
            return null;
        }
    }

    return {
        API: API,
        FALLBACK_IMG: FALLBACK_IMG,
        fetchIndex: fetchIndex,
        fetchArticle: fetchArticle,
        normalize: normalize,
    };
})();
