// @ts-check
import { defineConfig } from 'astro/config';

import react from '@astrojs/react';

const siteUrl = (process.env.PUBLIC_SITE_URL || 'https://chimpzlab.com').replace(/\/$/, '');

// https://astro.build/config
export default defineConfig({
  site: siteUrl,
  integrations: [react()],
  redirects: {
    '/blog': '/insights',
    '/casestudy/ajmera-realty': '/case-studies/ajmera-realty',
    '/casestudy/blue-star': '/case-studies/blue-star',
    '/casestudy/carnelian-capital': '/case-studies/carnelian-capital',
    '/casestudy/cisco-thingqbator': '/case-studies/cisco-thingqbator',
    '/casestudy/evershine-amavi': '/case-studies/evershine-amavi',
    '/casestudy/evershine-builders': '/case-studies/evershine-builders',
    '/casestudy/evershine-house': '/case-studies/evershine-house',
    '/casestudy/forest-hills': '/case-studies/forest-hills',
    '/casestudy/ghci-2024': '/case-studies/ghci-2024',
    '/casestudy/imbesharam': '/case-studies/imbesharam',
    '/casestudy/mos-world': '/case-studies/mos-world',
    '/casestudy/munns-mars': '/case-studies/munns-mars',
    '/casestudy/nasscom-konnect': '/case-studies/nasscom-konnect',
    '/casestudy/nasscom-member-connect': '/case-studies/nasscom-member-connect',
    '/casestudy/psiog': '/case-studies/psiog',
    '/casestudy/quantumapps-ai': '/case-studies/quantumapps-ai',
    '/casestudy/robust-petcare': '/case-studies/robust-petcare',
    '/casestudy/soul-skin-clinic': '/case-studies/soul-skin-clinic',
    '/casestudy/soul-skin': '/case-studies/soul-skin',
    '/casestudy/stl-digital': '/case-studies/stl-digital',
    '/casestudy/tiger-analytics': '/case-studies/tiger-analytics',
    '/services/creative/branding': '/services/creative/branding-agency-in-thane',
    '/services/creative/video-production': '/services/creative/video-production-services-in-thane',
    '/services/demand-generation/email-marketing': '/services/demand-generation/email-marketing-services-in-thane',
    '/services/demand-generation/marketing-automation': '/services/demand-generation/marketing-automation-services-in-thane',
    '/services/demand-generation/performance-marketing': '/services/demand-generation/performance-marketing-agency-in-thane',
    '/services/demand-generation/vetted-lead-generation': '/services/demand-generation/vetted-lead-generation-services-in-thane',
    '/services/digital-experiences/chatbots': '/services/digital-experiences/chatbot-development-services-in-thane',
    '/services/digital-experiences/landing-pages': '/services/digital-experiences/landing-page-services-in-thane',
    '/services/digital-experiences/website-design-development': '/services/digital-experiences/website-design-development-services-in-thane',
    '/services/reputation-communications/corporate-communications': '/services/reputation-communications/corporate-communications-agency-in-thane',
    '/services/reputation-communications/employer-branding': '/services/reputation-communications/employer-branding-agency-in-thane',
    '/services/reputation-communications/public-relations': '/services/reputation-communications/public-relations-agency-in-thane',
    '/services/reputation-communications/thought-leadership': '/services/reputation-communications/thought-leadership-agency-in-thane',
    '/services/visibility-search/content-writing': '/services/visibility-search/content-writing-services-in-thane',
    '/services/visibility-search/influencer-marketing': '/services/visibility-search/influencer-marketing-agency-in-thane',
    '/services/visibility-search/seo-aeo': '/services/visibility-search/seo-aeo-agency-in-thane',
    '/services/visibility-search/social-media-marketing': '/services/visibility-search/social-media-marketing-agency-in-thane',
  },
});
