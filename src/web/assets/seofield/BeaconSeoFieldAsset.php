<?php

namespace anvildev\beacon\web\assets\seofield;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * Drives the Beacon SEO field's CP UI: char-count meters, schema-mapping
 * repeater, and the "+ Add schema" button. Loads once per CP request, scoped
 * via DOM `data-` attributes so no globals leak onto `window`.
 */
final class BeaconSeoFieldAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [CpAsset::class];
    public $js = ['seo-field.js?v=10', 'geo-score-chip.js?v=1'];
    public $css = ['seo-field.css'];

    /**
     * Source strings used by seo-field.js / geo-score-chip.js via
     * Craft.t('beacon', …), so they are localized for the active CP language.
     */
    private const TRANSLATIONS = [
        'seoField.js.aspect.ratio.off.1.91',
        'seoField.js.below.1200.630.recommendation',
        'seoField.js.looks.good',
        'seoField.js.too.small.need.600.wide',
        'seoField.js.add.least.one.schema.bundle',
        'seoField.js.add.property',
        'seoField.add.schema.text',
        'seoField.js.all.required.recommended.properties.mapped',
        'seoField.js.below.120.characters.most.serps',
        'seoField.js.cancel',
        'seoField.js.canonical.must.absolute.url.beginning',
        'seoField.js.checking.image',
        'seoField.js.close',
        'seoField.js.could.not.load.image.verify',
        'seoField.js.could.not.load.suggested.mapping',
        'seoField.js.custom.fields',
        'seoField.js.desc',
        'seoField.js.description.empty.slack.discord.facebook',
        'seoField.edit.text',
        'schemas.edit.edit.schema.text',
        'seoField.js.entry.attributes',
        'seoField.js.entry.override',
        'seoField.js.entry.title.fallback',
        'seoField.js.expand.seo.description.toward.140',
        'seoField.js.fill.title.description.so.open',
        'seoField.js.global.default',
        'seoField.preview.hide.image.text',
        'seoField.js.increase.seo.title.length.toward',
        'seoField.js.json.ld.preview',
        'seoField.js.line.expected.key.value.format',
        'seoField.js.loading',
        'seoField.js.looks.good.next.validate.social',
        'seoField.js.missing.recommended',
        'seoField.js.missing.required',
        'seoField.js.no.social.image.open.graph',
        'seoField.js.open.type.s.spec.schema',
        'seoField.js.optional',
        'seoField.js.preview.gaps',
        'seoField.js.properties',
        'seoField.js.property',
        'seoField.js.re.check.canonical.after.url',
        'seoField.js.recommended',
        'redirectSources.remove.ariaLabel',
        'seoField.js.required',
        'seoField.js.review.robots.directives.avoid.combining',
        'seoField.js.seo.field',
        'seoField.js.save',
        'seoField.js.save.entry.once.before.requesting',
        'seoField.js.save.entry.first.needs.entry',
        'schemas.edit.schemaType.label',
        'seoField.js.section.default',
        'seoField.js.set.canonical.url.absolute.http',
        'seoField.js.short.titles.can.underperform.aim',
        'seoField.js.shorten.seo.title.stay.within',
        'seoField.js.show.image',
        'seoField.js.social.image.px',
        'aiCrawlers.source.text',
        'seoField.js.suggest.mapping',
        'seoField.js.suggest.mapping.requires.craft.cp',
        'seoField.js.tip.descriptions.over.165.characters',
        'seoField.js.tip.titles.over.60.characters',
        'seoField.js.title.empty.google.social.cards',
        'seoField.js.title.chars',
        'seoField.js.title.px.px',
        'seoField.js.trim.seo.description.avoid.truncation',
        'seoField.js.noindex.noarchive.redundant.noindex.already',
        'seoField.js.noindex.nosnippet.together.also.blocks',
        'seoField.js.propertyName',
        'seoField.js.schema.org.docs',
        'seoField.js.recommended.2',
        'seoField.js.required.2',
        'seoField.js.custom.property',
        'seoField.js.custom.template',
        'seoField.js.no.properties.mapped',
    ];

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerTranslations('beacon', self::TRANSLATIONS);
        }
    }
}
