<?php

namespace anvildev\beacon\services;

use anvildev\beacon\elements\AuthorElement;
use anvildev\beacon\helpers\EntitySchema;
use anvildev\beacon\helpers\Ids;
use anvildev\beacon\models\SchemaBundle;
use anvildev\beacon\records\SchemaRecord;
use anvildev\beacon\schemas\SchemaTemplate;
use yii\base\Component;

/**
 * @phpstan-import-type SchemaConfig from \anvildev\beacon\models\SchemaBundle
 */
class SchemaService extends Component
{
    /** Types whose `author` slot gets auto-filled from the Beacon SEO field's authorIds. */
    private const AUTHOR_AUTOFILL_TYPES = ['Article', 'BlogPosting', 'NewsArticle', 'Recipe', 'Review'];

    /**
     * @param array<string, callable(): SchemaTemplate> $templateFactories
     */
    public function __construct(
        private array $templateFactories = [],
    ) {
        parent::__construct([]);
    }

    /**
     * @return list<SchemaRecord>
     */
    public function list(): array
    {
        /** @var list<SchemaRecord> $records */
        $records = SchemaRecord::find()
            ->orderBy(['entryTypeHandle' => SORT_ASC, 'sortOrder' => SORT_ASC])
            ->all();
        return $records;
    }

    public function findById(int $id): ?SchemaRecord
    {
        return SchemaRecord::findOne($id);
    }

    public function newRecord(): SchemaRecord
    {
        return new SchemaRecord();
    }

    /**
     * Schema types the renderer knows how to emit. Drives the SEO-field
     * modal's type dropdown so admins can extend the closed list of
     * built-ins (Article/Product/…) via `config/beacon.php`'s `schemaTypes`
     * without touching plugin code.
     *
     * @return list<string>
     */
    public function registeredTypes(): array
    {
        return array_keys($this->templateFactories);
    }

    /**
     * Returns the next `sortOrder` value to use within the given entry type
     * (one greater than the current max, or 0 if none exist yet).
     */
    public function nextSortOrderForEntryType(string $entryTypeHandle): int
    {
        $max = SchemaRecord::find()
            ->where(['entryTypeHandle' => $entryTypeHandle])
            ->max('[[sortOrder]]');
        return ((int) ($max ?? -1)) + 1;
    }

    /**
     * Persists a new global ordering: the i-th id in `$ids` gets `sortOrder = i`.
     *
     * @param list<int> $ids
     */
    public function applyOrder(array $ids): void
    {
        \Craft::$app->getDb()->transaction(function() use ($ids): void {
            foreach ($ids as $i => $id) {
                SchemaRecord::updateAll(['sortOrder' => $i], ['id' => $id]);
            }
        });
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        $record = SchemaRecord::findOne($id);
        if ($record === null) {
            return false;
        }
        $record->enabled = $enabled;
        $record->save(false);
        return true;
    }

    /**
     * Render the combined JSON-LD array for an entry.
     *
     * @param array<int, SchemaConfig> $perEntryAddons
     * @param array<string,mixed> $context
     * @return list<array<string,mixed>>
     */
    public function render(SchemaBundle $bundle, array $perEntryAddons, array $context): array
    {
        $output = [];

        foreach ([...$bundle->schemas, ...$perEntryAddons] as $schemaConfig) {
            $rendered = $this->renderOne($schemaConfig, $context);
            if ($rendered !== null) {
                $output[] = $rendered;
            }
        }

        // Bind the page's linked entities (Wikidata `about`/`mentions`) onto the
        // primary node so AI engines and knowledge graphs can disambiguate the
        // subject. Only the first node carries them — addon/secondary nodes
        // describe their own things. A mapping that already declared about/
        // mentions wins (don't clobber editor intent).
        $entityNodes = EntitySchema::nodesFor($context['seo']['entities'] ?? null);
        if ($entityNodes !== []) {
            // No schema bundle is mapped for this entry type, so there is no
            // primary node to host the entities. Emit a minimal WebPage host
            // instead of silently dropping them.
            if ($output === []) {
                $output[] = $this->webPageHostNode($context);
            }
            foreach ($entityNodes as $key => $nodes) {
                if (!isset($output[0][$key])) {
                    $output[0][$key] = $nodes;
                }
            }
        }

        return $output;
    }

    /**
     * Minimal standalone `WebPage` node used to carry linked entities when the
     * entry has no mapped schema bundle. Uses the entry URL + title already
     * resolved into the render context.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function webPageHostNode(array $context): array
    {
        $node = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
        ];

        $url = $context['entry']['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $node['@id'] = $url . '#webpage';
            $node['url'] = $url;
        }

        $title = $context['title'] ?? '';
        if (is_string($title) && $title !== '') {
            $node['name'] = $title;
        }

        return $node;
    }

    /**
     * @param SchemaConfig $schemaConfig
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function renderOne(array $schemaConfig, array $context): ?array
    {
        $type = $schemaConfig['type'];
        if (!isset($this->templateFactories[$type])) {
            return null;
        }

        $template = ($this->templateFactories[$type])();
        $output = $template->render($context, $schemaConfig['mapping'] ?? []);

        // Auto-fill the schema's `author` when the bundle mapping didn't supply one and the
        // entry has Beacon authors attached — keeps the author linkage from needing to be
        // declared twice (once on the Beacon SEO field, once again in the schema mapping).
        if (
            !isset($output['author'])
            && in_array($type, self::AUTHOR_AUTOFILL_TYPES, true)
            && is_array($context['seo']['authorIds'] ?? null)
            && $context['seo']['authorIds'] !== []
        ) {
            $persons = $this->resolveAuthorPersonNodes(
                $context['seo']['authorIds'],
                isset($context['entry']['siteId']) ? (int) $context['entry']['siteId'] : null,
            );
            if ($persons !== []) {
                $output['author'] = $persons;
            }
        }

        return $output;
    }

    /**
     * @param list<int|string> $authorIds
     * @return list<array<string,mixed>>
     */
    private function resolveAuthorPersonNodes(array $authorIds, ?int $siteId): array
    {
        $ids = Ids::positiveInts($authorIds);
        if ($ids === []) {
            return [];
        }
        $query = AuthorElement::find()->id($ids)->status(null);
        if ($siteId !== null) {
            $query->siteId($siteId);
        }
        $nodes = [];
        /** @var AuthorElement $author */
        foreach ($query->all() as $author) {
            if (($node = $author->toPersonNode()) !== null) {
                $nodes[] = $node;
            }
        }
        return $nodes;
    }
}
