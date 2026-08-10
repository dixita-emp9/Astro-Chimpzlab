// Dynamic robots.txt — generated from the centralized site config so the
// sitemap URL always matches the live SITE_URL instead of being hardcoded.
import type { APIRoute } from "astro";
import { site } from "../config/site";

export const GET: APIRoute = () => {
  const cleanUrl = site.url.replace(/\/$/, "");
  const sitemapUrl = `${cleanUrl}/sitemap-index.xml`;

  const body = `User-agent: *
Allow: /
Disallow: /thanks/
Disallow: /blog-insite?*

Sitemap: ${sitemapUrl}
`;

  return new Response(body, {
    headers: { "Content-Type": "text/plain; charset=utf-8" },
  });
};
