<?php

namespace anvildev\beacon\models;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\enums\TrackingProvider;
use anvildev\beacon\Plugin;
use Craft;
use yii\base\Model;

/**
 * @phpstan-import-type SiteOverrides from \anvildev\beacon\services\SiteOverrideResolver
 */
class TrackingScript extends Model
{
    public ?int $id = null;
    public ?string $uid = null;
    public string $name = '';
    public string $provider = TrackingProvider::Custom->value;
    /** @var array<string, mixed> */
    public array $config = [];
    public string $placement = 'head';
    public int $sortOrder = 0;
    /** @var SiteOverrides|null */
    public ?array $siteOverrides = null;

    public function rules(): array
    {
        return [
            [['name', 'provider', 'placement', 'config'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['provider'], 'string', 'max' => 64],
            [['placement'], 'string', 'max' => 16],
            // Accept built-in providers AND any registered via
            // EVENT_REGISTER_PROVIDERS, so custom providers shown in the CP
            // dropdown can actually be saved.
            ['provider', 'validateProvider'],
            ['placement', 'in', 'range' => array_column(TrackingPlacement::cases(), 'value')],
            ['sortOrder', 'integer', 'min' => 0],
            ['siteOverrides', 'validateSiteOverrides'],
        ];
    }

    /**
     * Valid provider = a built-in enum case OR a provider registered via
     * `TrackingProviderRegistry::EVENT_REGISTER_PROVIDERS`. Falls back to the
     * enum alone when the plugin isn't booted (e.g. pure unit tests).
     */
    public function validateProvider(string $attribute): void
    {
        $handles = array_column(TrackingProvider::cases(), 'value');
        if (Plugin::$plugin !== null) {
            $handles = array_values(array_unique([
                ...$handles,
                ...array_keys(Plugin::$plugin->trackingRegistry->all()),
            ]));
        }
        if (!in_array($this->{$attribute}, $handles, true)) {
            $this->addError($attribute, Craft::t('beacon', 'Unknown tracking provider "{handle}".', [
                'handle' => (string) $this->{$attribute},
            ]));
        }
    }

    public function validateSiteOverrides(string $attribute): void
    {
        $value = $this->{$attribute};
        if ($value === null) {
            return;
        }
        if (!is_array($value)) {
            $this->addError($attribute, 'siteOverrides must be an array.');
            return;
        }
        foreach ($value as $siteUid => $entry) {
            if (!is_string($siteUid) || $siteUid === '') {
                $this->addError($attribute, 'siteOverrides keys must be non-empty site UID strings.');
                return;
            }
            if (!is_array($entry)) {
                $this->addError($attribute, 'Each siteOverrides value must be an array.');
                return;
            }
            if (array_diff(array_keys($entry), ['enabled', 'config']) !== []) {
                $this->addError($attribute, 'Each siteOverrides value may only contain "enabled" and/or "config" keys.');
                return;
            }
            if (isset($entry['enabled']) && !is_bool($entry['enabled'])) {
                $this->addError($attribute, 'siteOverrides[].enabled must be a boolean.');
                return;
            }
            if (isset($entry['config']) && !is_array($entry['config'])) {
                $this->addError($attribute, 'siteOverrides[].config must be an array.');
                return;
            }
        }
    }
}
