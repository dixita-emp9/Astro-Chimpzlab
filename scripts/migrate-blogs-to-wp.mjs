#!/usr/bin/env node
// One-time migration: copies the existing static JSON blogs
// (public/insights/articles/*.json) into WordPress as "insights" posts.
//
// Requirements:
//   1. The "ChimpzLab Insights CPT" plugin must be active in WordPress.
//   2. Create an Application Password (WP admin -> Users -> Profile ->
//      Application Passwords) with name "site-migration".
//   3. Run: WP_USER=admin WP_APP_PASS='xxxx xxxx xxxx xxxx xxxx xxxx' node scripts/migrate-blogs-to-wp.mjs
import { readdir, readFile } from 'node:fs/promises';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { existsSync } from 'node:fs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const articlesDir = join(root, 'public/insights/articles');

const WP_BASE =
    process.env.WP_BASE ||
    'https://chimpzlab.com/chimpzlab-old/wp-json/wp/v2';
const WP_USER = process.env.WP_USER;
const WP_APP_PASS = process.env.WP_APP_PASS;

if (!WP_USER || !WP_APP_PASS) {
    console.error(
        'Missing credentials. Set WP_USER and WP_APP_PASS env vars.',
    );
    process.exit(1);
}

const auth = 'Basic ' + Buffer.from(WP_USER + ':' + WP_APP_PASS).toString('base64');

async function api(path, options = {}) {
    const res = await fetch(WP_BASE + path, {
        ...options,
        headers: {
            Authorization: auth,
            'Content-Type': 'application/json',
            ...(options.headers || {}),
        },
    });
    if (!res.ok) {
        const body = await res.text();
        throw new Error(`WP ${res.status} on ${path}: ${body.slice(0, 300)}`);
    }
    return res.json();
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// Convert the structured JSON body blocks into Gutenberg-compatible HTML.
function blocksToGutenberg(article) {
    const html = [];
    if (article.takeaways && article.takeaways.length) {
        html.push('<!-- wp:heading -->');
        html.push('<h2>Key Takeaways</h2>');
        html.push('<!-- /wp:heading -->');
        html.push('<!-- wp:list -->');
        html.push('<ul>');
        article.takeaways.forEach((t) => html.push('<li>' + esc(t) + '</li>'));
        html.push('</ul>');
        html.push('<!-- /wp:list -->');
    }
    (article.body || []).forEach((block) => {
        if (block.type === 'p') {
            html.push('<!-- wp:paragraph -->');
            html.push('<p>' + esc(block.text) + '</p>');
            html.push('<!-- /wp:paragraph -->');
        } else if (block.type === 'h2') {
            html.push('<!-- wp:heading -->');
            html.push('<h2>' + esc(block.text) + '</h2>');
            html.push('<!-- /wp:heading -->');
        } else if (block.type === 'h3') {
            html.push('<!-- wp:heading {"level":3} -->');
            html.push('<h3>' + esc(block.text) + '</h3>');
            html.push('<!-- /wp:heading -->');
        } else if (block.type === 'blockquote') {
            html.push('<!-- wp:quote -->');
            html.push('<blockquote>' + esc(block.text) + '</blockquote>');
            html.push('<!-- /wp:quote -->');
        }
    });
    return html.join('\n');
}

async function ensureCategory(slug, name) {
    if (!slug) return null;
    try {
        const found = await api(
            '/categories?slug=' + encodeURIComponent(slug) + '&per_page=5',
        );
        if (Array.isArray(found) && found.length) return found[0].id;
    } catch (e) {}
    const created = await api('/categories', {
        method: 'POST',
        body: JSON.stringify({ name: name || slug, slug }),
    });
    return created.id;
}

async function ensureTag(name) {
    if (!name) return null;
    try {
        const found = await api(
            '/tags?search=' + encodeURIComponent(name) + '&per_page=5',
        );
        const exact = (Array.isArray(found) ? found : []).find(
            (t) => t.name.toLowerCase() === name.toLowerCase(),
        );
        if (exact) return exact.id;
    } catch (e) {}
    const created = await api('/tags', {
        method: 'POST',
        body: JSON.stringify({ name }),
    });
    return created.id;
}

// Uploads the article's cover image (public/<image>) to WP media library and
// returns the attachment id, or null if there is no local image / upload fails.
async function uploadImage(article) {
    const relPath = String(article.image || '');
    if (!relPath) return null;
    const absPath = resolve(join(root, 'public', relPath));
    if (!existsSync(absPath)) {
        console.warn(`  ! missing local image: ${relPath}`);
        return null;
    }
    const data = await readFile(absPath);
    const filename = relPath.split('/').pop();
    const mime =
        (/\.png$/i.test(filename) && 'image/png') ||
        (/\.webp$/i.test(filename) && 'image/webp') ||
        (/\.svg$/i.test(filename) && 'image/svg+xml') ||
        'image/jpeg';
    const res = await fetch(WP_BASE + '/media', {
        method: 'POST',
        headers: {
            Authorization: auth,
            'Content-Disposition': `attachment; filename="${filename}"`,
            'Content-Type': mime,
        },
        body: data,
    });
    if (!res.ok) {
        const body = await res.text();
        throw new Error(`media upload failed: ${body.slice(0, 300)}`);
    }
    const created = await res.json();
    return created.id;
}

async function main() {
    const files = (await readdir(articlesDir)).filter(
        (f) => f.endsWith('.json') && f !== 'index.json',
    );
    console.log(`Found ${files.length} articles to migrate.`);

    for (const file of files) {
        const raw = await readFile(join(articlesDir, file), 'utf8');
        const article = JSON.parse(raw);
        const content = blocksToGutenberg(article);

        const categorySlug = article.category;
        const categoryName = (categorySlug || '')
            .split('-')
            .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
            .join(' ');
        const categoryId = await ensureCategory(categorySlug, categoryName);

        const tagNames = (article.tag || '')
            .split(',')
            .map((t) => t.trim())
            .filter(Boolean);
        const tagIds = [];
        for (const name of tagNames.slice(0, 10)) {
            const id = await ensureTag(name);
            if (id) tagIds.push(id);
        }

        const payload = {
            title: article.titleFull,
            slug: article.slug,
            content,
            excerpt: article.intro || '',
            status: 'publish',
            categories: categoryId ? [categoryId] : [],
            tags: tagIds,
            meta: {
                date: article.date || '',
                readtime: article.readTime || '',
            },
        };

        try {
            let mediaId = null;
            if (article.image) {
                try {
                    mediaId = await uploadImage(article);
                    if (mediaId) payload.featured_media = mediaId;
                } catch (e) {
                    console.warn(
                        `  ! image skip for ${article.slug}: ${e.message}`,
                    );
                }
            }
            const created = await api('/insights', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            console.log(
                `OK  ${created.slug} (id ${created.id}${mediaId ? `, img ${mediaId}` : ''})`,
            );
        } catch (e) {
            console.error(`FAIL ${article.slug}: ${e.message}`);
        }
    }
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});
