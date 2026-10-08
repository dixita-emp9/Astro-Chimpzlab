// Regenerates public/sitemap.xml at build time so it always mirrors the
// WordPress-published blogs: only `publish`-status posts appear, drafts /
// unpublished posts are automatically excluded.
//
// Mechanism: the WP REST API never exposes drafts, pending or private posts
// to unauthenticated requests, so whatever this script fetches is exactly
// the set of publicly visible articles. Static (non-blog) URLs are preserved
// from the existing sitemap.xml. On WP fetch failure the current file is
// kept untouched and the build continues (exit 0).
import { readFileSync, writeFileSync } from "node:fs";

const SITE_URL = (process.env.PUBLIC_SITE_URL || "https://chimpzlab.com").replace(/\/$/, "");
const OUT = new URL("../public/sitemap.xml", import.meta.url);
const API_REST = "https://chimpzlab.com/chimpzlab-old/?rest_route=/wp/v2/insights";
const API_PRETTY = "https://chimpzlab.com/chimpzlab-old/wp-json/wp/v2/insights";

async function fetchJson(url) {
    const res = await fetch(url);
    if (!res.ok) throw new Error(`WP ${res.status} for ${url}`);
    return { data: await res.json(), headers: res.headers };
}

async function fetchAllPosts() {
    // rest_route first (works even when pretty permalinks 404), then pretty.
    const errors = [];
    for (const base of [API_REST, API_PRETTY]) {
        try {
            const first = await fetchJson(
                `${base}${base === API_REST ? "&" : "?"}per_page=100&orderby=modified&order=desc&_fields=slug,modified`,
            );
            if (!Array.isArray(first.data)) throw new Error("bad payload");
            const totalPages = Number(first.headers.get("X-WP-TotalPages") || 1);
            let posts = first.data;
            for (let p = 2; p <= totalPages; p++) {
                const sep = base === API_REST ? "&" : "?";
                const page = await fetchJson(
                    `${base}${sep}per_page=100&orderby=modified&order=desc&_fields=slug,modified&page=${p}`,
                );
                if (Array.isArray(page.data)) posts = posts.concat(page.data);
            }
            return posts;
        } catch (err) {
            errors.push(err.message);
        }
    }
    throw new Error(errors.join(" | "));
}

function esc(s) {
    return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;");
}

function toLastmod(modified) {
    const d = new Date(modified);
    if (Number.isNaN(d.getTime())) return new Date().toISOString().slice(0, 10) + "T00:00:00+00:00";
    return d.toISOString().replace(/\.\d+Z$/, "+00:00");
}

const current = readFileSync(OUT, "utf8");
// Keep every curated static URL; only the /blog-insights/* section is rebuilt.
const kept = [];
for (const m of current.matchAll(/<url>\s*<loc>([^<]+)<\/loc>\s*<lastmod>([^<]*)<\/lastmod>\s*<changefreq>([^<]*)<\/changefreq>\s*<priority>([^<]*)<\/priority>\s*<\/url>/g)) {
    const [, loc, lastmod, changefreq, priority] = m;
    if (loc.includes("/blog-insights/")) continue;
    kept.push({ loc, lastmod, changefreq, priority });
}

let posts;
try {
    posts = await fetchAllPosts();
} catch (err) {
    console.warn(`generate-sitemap: WP fetch failed (${err.message}) — keeping existing sitemap.xml`);
    process.exit(0);
}

const seen = new Set();
const blogUrls = [];
for (const p of posts) {
    if (!p || !p.slug || seen.has(p.slug)) continue;
    seen.add(p.slug);
    blogUrls.push(
        `  <url>\n       <loc>${esc(`${SITE_URL}/blog-insights/${p.slug}`)}</loc>\n       <lastmod>${esc(toLastmod(p.modified))}</lastmod>\n       <changefreq>weekly</changefreq>\n       <priority>0.6400</priority>\n  </url>`,
    );
}

const staticUrls = kept.map(
    (u) => `  <url>\n       <loc>${esc(u.loc)}</loc>\n       <lastmod>${esc(u.lastmod)}</lastmod>\n       <changefreq>${esc(u.changefreq)}</changefreq>\n       <priority>${esc(u.priority)}</priority>\n  </url>`,
);

const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<?xml-stylesheet type="text/css" href="https://www.xml-sitemaps.com/css/sitemap.css"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">\n\n${staticUrls.join("\n")}\n${blogUrls.join("\n")}\n</urlset>\n`;
writeFileSync(OUT, xml);
console.log(`generate-sitemap: wrote ${staticUrls.length} static + ${blogUrls.length} published blog URLs`);
