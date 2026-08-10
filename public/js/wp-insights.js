// WordPress REST API data source for ChimpzLab insights/blogs.
// Loaded before the inline loaders on blog-insite, insights, the homepage
// slider and service-page loaders so they all share one fetch+normalize path.
// Falls back to the static /insights/articles/*.json files if WP is unreachable.
window.WPInsights = (function () {
    var API = "https://chimpzlab.com/chimpzlab-old/wp-json/wp/v2/insights";
    var FALLBACK_IMG = "/asset/home-page.webp";

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

    function normalize(post) {
        var title = stripTags(post.title && post.title.rendered);
        var contentHtml = cleanHtml(
            (post.content && post.content.rendered) || "",
        );
        var excerpt = (post.excerpt && post.excerpt.rendered) || "";
        var terms = (post._embedded && post._embedded["wp:term"]) || [];
        var media =
            (post._embedded &&
                post._embedded["wp:featuredmedia"] &&
                post._embedded["wp:featuredmedia"][0]) ||
            null;
        var meta = post.meta || {};
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
        var takeaways = meta.takeaways
            ? String(meta.takeaways)
                  .split(/\r?\n/)
                  .map(function (s) {
                      return s.trim();
                  })
                  .filter(Boolean)
            : [];
        var image = media && media.source_url ? media.source_url : "";
        if (!takeaways.length) {
            var extracted = extractTakeaways(contentHtml);
            contentHtml = extracted.body;
            takeaways = extracted.takeaways;
        }
        return {
            slug: post.slug,
            category: cats[0] || "strategy",
            tag: meta.tag || tags.join(", ") || "",
            readTime: meta.readtime || computedReadTime(contentHtml),
            date: meta.date || fmtDate(post.date) || "",
            titleParts: splitTitle(title),
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
            var url = API + "?per_page=100&_embed&orderby=date&order=desc";
            var res = await fetch(url);
            if (!res.ok) throw new Error("WP " + res.status);
            data = await res.json();
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
            var res = await fetch(
                API + "?slug=" + encodeURIComponent(slug) + "&_embed",
            );
            if (!res.ok) throw new Error("WP " + res.status);
            var data = await res.json();
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
