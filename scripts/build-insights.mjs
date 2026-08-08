import { readdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const articlesDir = join(root, 'public/insights/articles');

const files = (await readdir(articlesDir)).filter((f) =>
    f.endsWith('.json'),
);

const articles = [];
for (const file of files) {
    if (file === 'index.json') continue;
    const raw = await readFile(join(articlesDir, file), 'utf8');
    const article = JSON.parse(raw);
    if (!article.slug) {
        throw new Error(`Missing "slug" in ${file}`);
    }
    const { body, ...meta } = article;
    articles.push(meta);
}

articles.sort((a, b) => a.slug.localeCompare(b.slug));

await writeFile(
    join(articlesDir, 'index.json'),
    JSON.stringify({ articles }, null, 2),
);

console.log(
    `Regenerated index.json with ${articles.length} articles (${files.length - 1} sources)`,
);
