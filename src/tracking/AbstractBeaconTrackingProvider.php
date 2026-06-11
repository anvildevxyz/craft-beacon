<?php

namespace anvildev\beacon\tracking;

/**
 * Convenience base for built-in Beacon tracking providers. Provides the
 * default `getFieldsTemplate()` that maps to `beacon/tracking/_provider-fields/<handle>`.
 * Third-party providers with templates in a different root should override it
 * rather than extending this class.
 */
abstract class AbstractBeaconTrackingProvider implements TrackingScriptProviderInterface
{
    public function getFieldsTemplate(): ?string
    {
        return 'beacon/tracking/_provider-fields/' . $this->getHandle();
    }
}
