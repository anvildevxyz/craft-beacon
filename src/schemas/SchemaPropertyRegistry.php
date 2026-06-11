<?php

namespace anvildev\beacon\schemas;

/**
 * Catalogue of properties Beacon exposes per supported schema.org type.
 *
 * Drives the Beacon SEO field's property picker — authors get a list of
 * actually-meaningful properties for the chosen type instead of a free-text
 * input, with required (★) and recommended (◇) markers based on Google's
 * structured-data guidelines.
 *
 * Adding a new type: append an entry below; no other code change required —
 * the JS picks it up via the JSON config the field emits, and
 * {@see \anvildev\beacon\services\SchemaSuggestionService} reads the same map.
 *
 * Tier semantics:
 *  - 'required'    — Google rejects the structured data without this property.
 *  - 'recommended' — Google warns; the rich result is still eligible.
 *  - 'optional'    — Useful but rarely the difference between rich/no-rich.
 *
 * @phpstan-type PropertyDef array{
 *     name: string,
 *     tier: 'required'|'recommended'|'optional',
 *     help: string,
 *     suggest?: list<string>,
 * }
 */
final class SchemaPropertyRegistry
{
    /** @var array<string, list<PropertyDef>>|null memoised catalogue */
    private static ?array $cache = null;

    /**
     * @return array<string, list<PropertyDef>>
     */
    public static function all(): array
    {
        return self::$cache ??= [
            'Article' => [
                ['name' => 'headline', 'tier' => 'required', 'help' => 'Headline of the article. Keep under 110 chars.', 'suggest' => ['seo.title', 'entry.title']],
                ['name' => 'image', 'tier' => 'required', 'help' => 'URL of a representative image. 1200×630+ recommended.', 'suggest' => ['seo.openGraph.image']],
                ['name' => 'datePublished', 'tier' => 'required', 'help' => 'ISO 8601 publication time.', 'suggest' => ['entry.postDate']],
                ['name' => 'dateModified', 'tier' => 'recommended', 'help' => 'ISO 8601 last-modified time.', 'suggest' => ['entry.dateUpdated']],
                ['name' => 'author', 'tier' => 'recommended', 'help' => 'Author name or Person/Organization JSON-LD.', 'suggest' => []],
                ['name' => 'description', 'tier' => 'recommended', 'help' => 'Short summary of the article.', 'suggest' => ['seo.description']],
                ['name' => 'publisher', 'tier' => 'recommended', 'help' => 'Publishing Organization (defaults to site identity if blank).', 'suggest' => []],
                ['name' => 'mainEntityOfPage', 'tier' => 'optional', 'help' => 'Canonical URL of the article.', 'suggest' => ['seo.canonical']],
                ['name' => 'articleSection', 'tier' => 'optional', 'help' => 'Section/category name.'],
                ['name' => 'keywords', 'tier' => 'optional', 'help' => 'Comma-separated keywords.'],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag (auto-suggested from the entry site).', 'suggest' => ['entry.site.language']],
            ],
            'Product' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'Product name.', 'suggest' => ['entry.title']],
                ['name' => 'image', 'tier' => 'required', 'help' => 'Product image URL.', 'suggest' => ['seo.openGraph.image']],
                ['name' => 'description', 'tier' => 'recommended', 'help' => 'Product description.', 'suggest' => ['seo.description']],
                ['name' => 'sku', 'tier' => 'recommended', 'help' => 'Merchant SKU.'],
                ['name' => 'brand', 'tier' => 'recommended', 'help' => 'Brand name or Brand node.'],
                ['name' => 'offers', 'tier' => 'recommended', 'help' => 'Offer node (price, currency, availability).'],
                ['name' => 'aggregateRating', 'tier' => 'optional', 'help' => 'Aggregate review summary.'],
                ['name' => 'review', 'tier' => 'optional', 'help' => 'Individual reviews.'],
                ['name' => 'gtin', 'tier' => 'optional', 'help' => 'GTIN-8/12/13/14 if available.'],
                ['name' => 'mpn', 'tier' => 'optional', 'help' => 'Manufacturer part number.'],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag (auto-suggested from the entry site).', 'suggest' => ['entry.site.language']],
            ],
            'Recipe' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'Recipe name.', 'suggest' => ['entry.title']],
                ['name' => 'image', 'tier' => 'required', 'help' => 'Photo of the finished dish.', 'suggest' => ['seo.openGraph.image']],
                ['name' => 'recipeIngredient', 'tier' => 'required', 'help' => 'List of ingredient strings.'],
                ['name' => 'recipeInstructions', 'tier' => 'required', 'help' => 'Step-by-step instructions.'],
                ['name' => 'description', 'tier' => 'recommended', 'help' => 'Short summary.', 'suggest' => ['seo.description']],
                ['name' => 'prepTime', 'tier' => 'recommended', 'help' => 'ISO 8601 duration (PT15M).'],
                ['name' => 'cookTime', 'tier' => 'recommended', 'help' => 'ISO 8601 duration (PT30M).'],
                ['name' => 'totalTime', 'tier' => 'recommended', 'help' => 'ISO 8601 duration (PT45M).'],
                ['name' => 'recipeYield', 'tier' => 'recommended', 'help' => 'Servings, e.g. "4 servings".'],
                ['name' => 'nutrition', 'tier' => 'optional', 'help' => 'NutritionInformation node.'],
                ['name' => 'author', 'tier' => 'optional', 'help' => 'Author of the recipe.'],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag (auto-suggested from the entry site).', 'suggest' => ['entry.site.language']],
            ],
            'HowTo' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'Title of the how-to.', 'suggest' => ['entry.title']],
                ['name' => 'step', 'tier' => 'required', 'help' => 'Ordered list of HowToStep nodes.'],
                ['name' => 'description', 'tier' => 'recommended', 'help' => 'Short summary.', 'suggest' => ['seo.description']],
                ['name' => 'totalTime', 'tier' => 'recommended', 'help' => 'ISO 8601 duration.'],
                ['name' => 'estimatedCost', 'tier' => 'optional', 'help' => 'Total estimated cost (MonetaryAmount).'],
                ['name' => 'tool', 'tier' => 'optional', 'help' => 'Tools required (HowToTool list).'],
                ['name' => 'supply', 'tier' => 'optional', 'help' => 'Supplies required (HowToSupply list).'],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag (auto-suggested from the entry site).', 'suggest' => ['entry.site.language']],
            ],
            'FAQPage' => [
                ['name' => 'mainEntity', 'tier' => 'required', 'help' => 'Array of Question nodes with acceptedAnswer.'],
                ['name' => 'name', 'tier' => 'optional', 'help' => 'Page name.', 'suggest' => ['entry.title']],
                ['name' => 'description', 'tier' => 'optional', 'help' => 'FAQ summary.', 'suggest' => ['seo.description']],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag (auto-suggested from the entry site).', 'suggest' => ['entry.site.language']],
            ],
            'Review' => [
                ['name' => 'itemReviewed', 'tier' => 'required', 'help' => 'Thing being reviewed.'],
                ['name' => 'reviewRating', 'tier' => 'required', 'help' => 'Rating node ({@type:"Rating", ratingValue, bestRating}).'],
                ['name' => 'author', 'tier' => 'required', 'help' => 'Reviewer (Person or Organization).'],
                ['name' => 'name', 'tier' => 'recommended', 'help' => 'Review title.', 'suggest' => ['entry.title']],
                ['name' => 'reviewBody', 'tier' => 'recommended', 'help' => 'Review text.'],
                ['name' => 'datePublished', 'tier' => 'recommended', 'help' => 'When the review was published.', 'suggest' => ['entry.postDate']],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag (auto-suggested from the entry site).', 'suggest' => ['entry.site.language']],
            ],
            // Article variants — share most Article props; only the type-specific ones are listed below.
            'BlogPosting' => [
                ['name' => 'headline', 'tier' => 'required', 'help' => 'Post headline. Keep under 110 chars.', 'suggest' => ['seo.title', 'entry.title']],
                ['name' => 'image', 'tier' => 'required', 'help' => 'Featured image URL. 1200×630+ recommended.', 'suggest' => ['seo.openGraph.image']],
                ['name' => 'datePublished', 'tier' => 'required', 'help' => 'ISO 8601 publish time.', 'suggest' => ['entry.postDate']],
                ['name' => 'dateModified', 'tier' => 'recommended', 'help' => 'ISO 8601 last-modified time.', 'suggest' => ['entry.dateUpdated']],
                ['name' => 'author', 'tier' => 'recommended', 'help' => 'Author (auto-filled from Beacon SEO field if blank).'],
                ['name' => 'description', 'tier' => 'recommended', 'help' => 'Short summary.', 'suggest' => ['seo.description']],
                ['name' => 'mainEntityOfPage', 'tier' => 'optional', 'help' => 'Canonical URL.', 'suggest' => ['seo.canonical']],
                ['name' => 'wordCount', 'tier' => 'optional', 'help' => 'Word count of the post body.'],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag.', 'suggest' => ['entry.site.language']],
            ],
            'NewsArticle' => [
                ['name' => 'headline', 'tier' => 'required', 'help' => 'News headline. Under 110 chars.', 'suggest' => ['seo.title', 'entry.title']],
                ['name' => 'image', 'tier' => 'required', 'help' => 'Lead image URL.', 'suggest' => ['seo.openGraph.image']],
                ['name' => 'datePublished', 'tier' => 'required', 'help' => 'ISO 8601 publish time.', 'suggest' => ['entry.postDate']],
                ['name' => 'dateModified', 'tier' => 'recommended', 'help' => 'ISO 8601 last-modified time.', 'suggest' => ['entry.dateUpdated']],
                ['name' => 'author', 'tier' => 'recommended', 'help' => 'Reporter (auto-filled from Beacon SEO field).'],
                ['name' => 'publisher', 'tier' => 'recommended', 'help' => 'Publishing Organization (defaults to site identity).'],
                ['name' => 'dateline', 'tier' => 'optional', 'help' => 'Location of origin (e.g. "BERLIN, May 21").'],
                ['name' => 'printSection', 'tier' => 'optional', 'help' => 'Print edition section name.'],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag.', 'suggest' => ['entry.site.language']],
            ],
            'TechArticle' => [
                ['name' => 'headline', 'tier' => 'required', 'help' => 'Article headline.', 'suggest' => ['seo.title', 'entry.title']],
                ['name' => 'datePublished', 'tier' => 'required', 'help' => 'ISO 8601 publish time.', 'suggest' => ['entry.postDate']],
                ['name' => 'author', 'tier' => 'recommended', 'help' => 'Author (auto-filled from Beacon SEO field).'],
                ['name' => 'proficiencyLevel', 'tier' => 'optional', 'help' => 'Beginner / Expert / etc.'],
                ['name' => 'dependencies', 'tier' => 'optional', 'help' => 'Comma-separated prerequisites.'],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag.', 'suggest' => ['entry.site.language']],
            ],
            'ScholarlyArticle' => [
                ['name' => 'headline', 'tier' => 'required', 'help' => 'Paper title.', 'suggest' => ['seo.title', 'entry.title']],
                ['name' => 'datePublished', 'tier' => 'required', 'help' => 'ISO 8601 publish date.', 'suggest' => ['entry.postDate']],
                ['name' => 'author', 'tier' => 'required', 'help' => 'Author(s) (auto-filled from Beacon SEO field).'],
                ['name' => 'isPartOf', 'tier' => 'recommended', 'help' => 'Journal / issue / volume the article appears in.'],
                ['name' => 'citation', 'tier' => 'optional', 'help' => 'Works this paper cites.'],
                ['name' => 'about', 'tier' => 'optional', 'help' => 'Subject(s) of the paper.'],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag.', 'suggest' => ['entry.site.language']],
            ],
            'Course' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'Course name.', 'suggest' => ['entry.title']],
                ['name' => 'description', 'tier' => 'required', 'help' => 'What learners will get.', 'suggest' => ['seo.description']],
                ['name' => 'provider', 'tier' => 'required', 'help' => 'Organization or Person offering the course.'],
                ['name' => 'hasCourseInstance', 'tier' => 'recommended', 'help' => 'CourseInstance node(s) — dates, mode, location.'],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag.', 'suggest' => ['entry.site.language']],
            ],
            'QAPage' => [
                ['name' => 'mainEntity', 'tier' => 'required', 'help' => 'A single Question node with acceptedAnswer + optional suggestedAnswer.'],
                ['name' => 'name', 'tier' => 'optional', 'help' => 'Page name.', 'suggest' => ['entry.title']],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag.', 'suggest' => ['entry.site.language']],
            ],
            'ItemList' => [
                ['name' => 'itemListElement', 'tier' => 'required', 'help' => 'Array of ListItem (with position + url/name) or referenced things.'],
                ['name' => 'name', 'tier' => 'recommended', 'help' => 'List title.', 'suggest' => ['entry.title']],
                ['name' => 'numberOfItems', 'tier' => 'optional', 'help' => 'Total count (auto-derived if omitted).'],
                ['name' => 'itemListOrder', 'tier' => 'optional', 'help' => '"Ascending" / "Descending" / "Unordered".'],
            ],
            'AboutPage' => [
                ['name' => 'name', 'tier' => 'recommended', 'help' => 'Page title.', 'suggest' => ['entry.title']],
                ['name' => 'description', 'tier' => 'recommended', 'help' => 'Page summary.', 'suggest' => ['seo.description']],
                ['name' => 'about', 'tier' => 'optional', 'help' => 'The Organization or Person this page describes.'],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag.', 'suggest' => ['entry.site.language']],
            ],
            'ContactPage' => [
                ['name' => 'name', 'tier' => 'recommended', 'help' => 'Page title.', 'suggest' => ['entry.title']],
                ['name' => 'mainEntity', 'tier' => 'optional', 'help' => 'Organization with contactPoint(s).'],
                ['name' => 'inLanguage', 'tier' => 'recommended', 'help' => 'BCP-47 language tag.', 'suggest' => ['entry.site.language']],
            ],
            'Person' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'Full name.', 'suggest' => ['entry.title']],
                ['name' => 'jobTitle', 'tier' => 'recommended', 'help' => 'Role.'],
                ['name' => 'image', 'tier' => 'recommended', 'help' => 'Headshot URL.'],
                ['name' => 'description', 'tier' => 'recommended', 'help' => 'Short bio.', 'suggest' => ['seo.description']],
                ['name' => 'sameAs', 'tier' => 'recommended', 'help' => 'Verified profile URLs (LinkedIn, ORCID, etc.).'],
                ['name' => 'knowsAbout', 'tier' => 'optional', 'help' => 'Expertise topics.'],
                ['name' => 'hasCredential', 'tier' => 'optional', 'help' => 'EducationalOccupationalCredential node(s).'],
                ['name' => 'worksFor', 'tier' => 'optional', 'help' => 'Employer(s).'],
                ['name' => 'alumniOf', 'tier' => 'optional', 'help' => 'Alma mater(s).'],
            ],
            'Organization' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'Organization name.', 'suggest' => ['entry.title']],
                ['name' => 'url', 'tier' => 'required', 'help' => 'Canonical website URL.'],
                ['name' => 'logo', 'tier' => 'recommended', 'help' => 'Square-ish logo URL for Knowledge Graph.'],
                ['name' => 'sameAs', 'tier' => 'recommended', 'help' => 'Official profile / social URLs.'],
                ['name' => 'address', 'tier' => 'recommended', 'help' => 'PostalAddress node.'],
                ['name' => 'contactPoint', 'tier' => 'optional', 'help' => 'ContactPoint(s) for support / sales.'],
                ['name' => 'foundingDate', 'tier' => 'optional', 'help' => 'ISO 8601 founding date.'],
                ['name' => 'legalName', 'tier' => 'optional', 'help' => 'Registered legal name (if different).'],
            ],
            'LocalBusiness' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'Business name.', 'suggest' => ['entry.title']],
                ['name' => 'address', 'tier' => 'required', 'help' => 'PostalAddress with locality, region, country, postcode.'],
                ['name' => 'image', 'tier' => 'required', 'help' => 'Storefront / brand image.'],
                ['name' => 'telephone', 'tier' => 'recommended', 'help' => 'Public phone number in E.164 format.'],
                ['name' => 'openingHoursSpecification', 'tier' => 'recommended', 'help' => 'Array of OpeningHoursSpecification nodes.'],
                ['name' => 'priceRange', 'tier' => 'recommended', 'help' => 'Indicative price level (e.g. "$$").'],
                ['name' => 'geo', 'tier' => 'optional', 'help' => 'GeoCoordinates node (latitude/longitude).'],
                ['name' => 'aggregateRating', 'tier' => 'optional', 'help' => 'AggregateRating node.'],
                ['name' => 'url', 'tier' => 'recommended', 'help' => 'Canonical URL.', 'suggest' => ['seo.canonical']],
            ],
            'Restaurant' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'Restaurant name.', 'suggest' => ['entry.title']],
                ['name' => 'address', 'tier' => 'required', 'help' => 'PostalAddress.'],
                ['name' => 'image', 'tier' => 'required', 'help' => 'Restaurant photo URL.'],
                ['name' => 'servesCuisine', 'tier' => 'recommended', 'help' => 'Cuisine type(s) (e.g. "Italian").'],
                ['name' => 'menu', 'tier' => 'recommended', 'help' => 'Menu page URL or Menu node.'],
                ['name' => 'priceRange', 'tier' => 'recommended', 'help' => 'Price level (e.g. "$$").'],
                ['name' => 'acceptsReservations', 'tier' => 'optional', 'help' => 'true / false / Reservation URL.'],
                ['name' => 'telephone', 'tier' => 'recommended', 'help' => 'Phone number.'],
            ],
            'Store' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'Store name.', 'suggest' => ['entry.title']],
                ['name' => 'address', 'tier' => 'required', 'help' => 'PostalAddress.'],
                ['name' => 'image', 'tier' => 'required', 'help' => 'Storefront photo URL.'],
                ['name' => 'paymentAccepted', 'tier' => 'optional', 'help' => 'Accepted payment methods (cash, credit, etc.).'],
                ['name' => 'currenciesAccepted', 'tier' => 'optional', 'help' => 'ISO 4217 codes (e.g. "USD").'],
                ['name' => 'openingHoursSpecification', 'tier' => 'recommended', 'help' => 'Opening hours.'],
            ],
            'Event' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'Event title.', 'suggest' => ['entry.title']],
                ['name' => 'startDate', 'tier' => 'required', 'help' => 'ISO 8601 start (date or datetime).'],
                ['name' => 'location', 'tier' => 'required', 'help' => 'Place node (or VirtualLocation).'],
                ['name' => 'endDate', 'tier' => 'recommended', 'help' => 'ISO 8601 end.'],
                ['name' => 'description', 'tier' => 'recommended', 'help' => 'Event blurb.', 'suggest' => ['seo.description']],
                ['name' => 'image', 'tier' => 'recommended', 'help' => 'Event poster / hero image.', 'suggest' => ['seo.openGraph.image']],
                ['name' => 'organizer', 'tier' => 'recommended', 'help' => 'Organization or Person hosting.'],
                ['name' => 'offers', 'tier' => 'recommended', 'help' => 'Offer node (price + URL to buy tickets).'],
                ['name' => 'eventStatus', 'tier' => 'optional', 'help' => 'EventScheduled / EventCancelled / EventPostponed / EventRescheduled / EventMovedOnline.'],
                ['name' => 'eventAttendanceMode', 'tier' => 'optional', 'help' => 'OfflineEventAttendanceMode / OnlineEventAttendanceMode / MixedEventAttendanceMode.'],
            ],
            'JobPosting' => [
                ['name' => 'title', 'tier' => 'required', 'help' => 'Job title.', 'suggest' => ['entry.title']],
                ['name' => 'description', 'tier' => 'required', 'help' => 'Job description (HTML allowed).', 'suggest' => ['seo.description']],
                ['name' => 'datePosted', 'tier' => 'required', 'help' => 'ISO 8601 date the listing was posted.', 'suggest' => ['entry.postDate']],
                ['name' => 'hiringOrganization', 'tier' => 'required', 'help' => 'Organization node (name + url + optional logo).'],
                ['name' => 'jobLocation', 'tier' => 'required', 'help' => 'Place node with PostalAddress.'],
                ['name' => 'employmentType', 'tier' => 'recommended', 'help' => 'FULL_TIME / PART_TIME / CONTRACTOR / TEMPORARY / INTERN / VOLUNTEER / PER_DIEM / OTHER.'],
                ['name' => 'baseSalary', 'tier' => 'recommended', 'help' => 'MonetaryAmount (currency + value).'],
                ['name' => 'validThrough', 'tier' => 'recommended', 'help' => 'ISO 8601 expiry.'],
                ['name' => 'directApply', 'tier' => 'optional', 'help' => 'true / false — whether the listing accepts applications directly on this page.'],
            ],
            'VideoObject' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'Video title.', 'suggest' => ['entry.title']],
                ['name' => 'description', 'tier' => 'required', 'help' => 'What the video is about.', 'suggest' => ['seo.description']],
                ['name' => 'thumbnailUrl', 'tier' => 'required', 'help' => 'Thumbnail image URL (1280×720 recommended).'],
                ['name' => 'uploadDate', 'tier' => 'required', 'help' => 'ISO 8601 upload date.', 'suggest' => ['entry.postDate']],
                ['name' => 'duration', 'tier' => 'recommended', 'help' => 'ISO 8601 duration (e.g. PT5M30S).'],
                ['name' => 'contentUrl', 'tier' => 'recommended', 'help' => 'Direct URL to the video file.'],
                ['name' => 'embedUrl', 'tier' => 'recommended', 'help' => 'URL of the embeddable player.'],
                ['name' => 'inLanguage', 'tier' => 'optional', 'help' => 'BCP-47 language tag.', 'suggest' => ['entry.site.language']],
            ],
            'ImageObject' => [
                ['name' => 'contentUrl', 'tier' => 'required', 'help' => 'URL of the image file.'],
                ['name' => 'caption', 'tier' => 'recommended', 'help' => 'Image caption.'],
                ['name' => 'creditText', 'tier' => 'optional', 'help' => 'Attribution string.'],
                ['name' => 'creator', 'tier' => 'optional', 'help' => 'Photographer / illustrator (Person or Organization).'],
                ['name' => 'copyrightNotice', 'tier' => 'optional', 'help' => 'Free-text copyright string.'],
                ['name' => 'license', 'tier' => 'optional', 'help' => 'URL of the license terms.'],
                ['name' => 'width', 'tier' => 'optional', 'help' => 'Pixel width.'],
                ['name' => 'height', 'tier' => 'optional', 'help' => 'Pixel height.'],
            ],
            'PodcastEpisode' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'Episode title.', 'suggest' => ['entry.title']],
                ['name' => 'associatedMedia', 'tier' => 'required', 'help' => 'MediaObject with contentUrl pointing to the audio file.'],
                ['name' => 'datePublished', 'tier' => 'required', 'help' => 'ISO 8601 publish date.', 'suggest' => ['entry.postDate']],
                ['name' => 'duration', 'tier' => 'recommended', 'help' => 'ISO 8601 duration.'],
                ['name' => 'partOfSeries', 'tier' => 'recommended', 'help' => 'PodcastSeries this episode belongs to.'],
                ['name' => 'episodeNumber', 'tier' => 'optional', 'help' => 'Numeric episode number.'],
                ['name' => 'description', 'tier' => 'recommended', 'help' => 'Episode summary.', 'suggest' => ['seo.description']],
            ],
            'SoftwareApplication' => [
                ['name' => 'name', 'tier' => 'required', 'help' => 'App name.', 'suggest' => ['entry.title']],
                ['name' => 'applicationCategory', 'tier' => 'required', 'help' => 'e.g. GameApplication, BusinessApplication.'],
                ['name' => 'operatingSystem', 'tier' => 'required', 'help' => 'iOS / Android / Windows / macOS / web.'],
                ['name' => 'offers', 'tier' => 'recommended', 'help' => 'Offer node (price + currency or free).'],
                ['name' => 'aggregateRating', 'tier' => 'recommended', 'help' => 'AggregateRating node.'],
                ['name' => 'softwareVersion', 'tier' => 'optional', 'help' => 'Current release version.'],
                ['name' => 'downloadUrl', 'tier' => 'optional', 'help' => 'Direct download URL.'],
            ],
        ];
    }

    /**
     * @return list<PropertyDef>
     */
    public static function forType(string $type): array
    {
        $curated = self::all()[$type] ?? null;
        if ($curated !== null) {
            return $curated;
        }
        // Fallback: synthesise PropertyDefs from the generated schema.org catalogue.
        // No tier annotation — schema.org itself doesn't carry required/recommended
        // metadata (that's a Google overlay we only know for the curated set).
        $props = GeneratedSchemaCatalogue::propertiesFor($type);
        if ($props === []) {
            return [];
        }
        return array_map(
            static fn(string $name): array => ['name' => $name, 'tier' => 'optional', 'help' => ''],
            $props,
        );
    }

    /**
     * @return list<string>
     */
    public static function supportedTypes(): array
    {
        return array_keys(self::all());
    }
}
