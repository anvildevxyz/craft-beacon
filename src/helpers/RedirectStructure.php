<?php

namespace anvildev\beacon\helpers;

use anvildev\beacon\elements\RedirectElement;
use Craft;
use craft\models\Structure;
use yii\db\Query;

/**
 * Reads and bootstraps the redirect precedence structure without going through
 * {@see \anvildev\beacon\services\RedirectService}, so element queries and
 * elements can bind to the structure without importing {@see \anvildev\beacon\Plugin}.
 */
final class RedirectStructure
{
    public static function structureId(): ?int
    {
        $id = (new Query())
            ->select(['redirectStructureId'])
            ->from('{{%beacon_settings}}')
            ->where(['id' => 1])
            ->scalar();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Ensures the redirect Structure exists, recording its id on the settings
     * row and placing any existing redirects into it (in current order). Safe
     * to call repeatedly; returns the structure id.
     */
    public static function ensureExists(): int
    {
        $existing = self::structureId();
        if ($existing !== null && Craft::$app->getStructures()->getStructureById($existing) !== null) {
            return $existing;
        }
        $structure = new Structure(['maxLevels' => 1]);
        Craft::$app->getStructures()->saveStructure($structure);
        Craft::$app->getDb()->createCommand()
            ->update('{{%beacon_settings}}', ['redirectStructureId' => $structure->id], ['id' => 1])
            ->execute();

        $ids = (new Query())
            ->select(['id'])
            ->from('{{%beacon_redirects}}')
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->column();
        // Batch-load every redirect element in one query, then append in the
        // original sortOrder. Avoids an N+1 element query per redirect when
        // seeding the structure on installs with a large legacy redirect set.
        $elements = array_column(
            RedirectElement::find()->id(array_map(intval(...), $ids))->siteId('*')->status(null)->all(),
            null,
            'id',
        );
        $structures = Craft::$app->getStructures();
        foreach ($ids as $id) {
            $el = $elements[(int) $id] ?? null;
            if ($el !== null) {
                $structures->appendToRoot($structure->id, $el);
            }
        }

        return $structure->id;
    }
}
