// Centralized site configuration.
//
// Every deploy-time / runtime value that used to be hardcoded across pages
// (site URL, contact details, socials, analytics IDs, reCAPTCHA keys, form
// endpoints, map embeds) now lives here. Values resolve from environment
// variables (import.meta.env, see /.env.example) and fall back to the current
// production defaults so the site builds and runs without a .env file.
//
// IMPORTANT: anything prefixed with PUBLIC_ is also exposed to client-side
// code (is:inline scripts). Secrets must NEVER be added here — keep them in
// the server-side CRM .env (public/crm/.env) only.

const env = import.meta.env;

const str = (key: string, fallback: string): string => {
    const value = env[key];
    return typeof value === "string" && value.trim() !== "" ? value.trim() : fallback;
};

export const site = {
    name: str("PUBLIC_SITE_NAME", "ChimpzLab"),
    url: str("PUBLIC_SITE_URL", "https://chimpzlab.com"),
    tagline: str("PUBLIC_SITE_TAGLINE", "Digital Marketing Agency in Thane"),

    // Contact details (used by Header, Footer, forms and landing pages).
    email: str("PUBLIC_SITE_EMAIL", "hello@chimpzlab.com"),
    phoneDisplay: str("PUBLIC_SITE_PHONE", "+91 74157 41562"),
    phoneHref: str("PUBLIC_SITE_PHONE_HREF", "+917415741562"),
    addressLines: str("PUBLIC_SITE_ADDRESS", "408, Fenkin 9 Phase 1, Wagle Estate, Behind Satkar Grande Hotel, Thane West 400604"),

    // Social profiles.
    socials: {
        youtube: str("PUBLIC_SITE_YOUTUBE", "https://youtube.com/@chimpzlab"),
        linkedin: str("PUBLIC_SITE_LINKEDIN", "https://linkedin.com/company/chimpzlab"),
        instagram: str("PUBLIC_SITE_INSTAGRAM", "https://instagram.com/chimpzlab"),
    },

    // Analytics.
    gtmId: str("PUBLIC_GTM_ID", "GTM-MVP2NQBM"),

    // Brand assets.
    logo: {
        header: str("PUBLIC_SITE_LOGO_HEADER", "/asset/chimpzlab-white.png"),
        footer: str("PUBLIC_SITE_LOGO_FOOTER", "/asset/chimpzlab-footer.png"),
        animated: str("PUBLIC_SITE_LOGO_ANIMATED", "/asset/chimpzlab-white-logo.gif"),
        ogImage: str("PUBLIC_SITE_OG_IMAGE", "/asset/favicon-512.png"),
    },

    // Maps embed (Google Maps embed URL shown in the Footer location modal).
    mapsEmbedUrl: str(
        "PUBLIC_SITE_MAPS_EMBED",
        "https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3768.175249159286!2d72.95386549999999!3d19.187546499999996!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3be7b9266a88bf85%3A0x71ba663ff0265751!2sChimpzLab%20-%20A%20Digital%20Experiences%20Company!5e0!3m2!1sen!2sin!4v1785755973300!5m2!1sen!2sin",
    ),

    // Lead-capture CRM.
    crm: {
        // Site API key (client-visible by design — the server validates it
        // against the CRM `sites` table and hardens submissions server-side).
        apiKey: str("PUBLIC_CRM_API_KEY", "3afc0840e1193e58397159d4af15cf4a15b5b35c"),
        contactEndpoint: str("PUBLIC_FORM_ENDPOINT", "/lead-capture.php"),
        realEstateEndpoint: str("PUBLIC_REAL_ESTATE_ENDPOINT", "/real-estate-lead.php"),
        redirect: str("PUBLIC_FORM_REDIRECT", "/thanks"),
    },

    // Google reCAPTCHA — public site keys only. The matching secret keys live
    // in the server-side CRM .env (RECAPTCHA_SECRET_KEY*), never here.
    recaptcha: {
        siteKey: str("PUBLIC_RECAPTCHA_SITE_KEY", "6Ld5s24tAAAAABcGfuai8DbOmn6JAS9kARQonH1h"),
        // Always-pass Google test key, swapped in automatically on localhost.
        testSiteKey: str("PUBLIC_RECAPTCHA_TEST_SITE_KEY", "6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI"),
    },
} as const;

export type SiteConfig = typeof site;
