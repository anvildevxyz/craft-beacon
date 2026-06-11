<?php

namespace anvildev\beacon\fields;

/**
 * Marker for the plugin's SEO field. Lets consumers detect and read the field
 * without depending on the concrete {@see BeaconSeoField}, which pulls in the
 * whole service layer. Read its value via {@see \anvildev\beacon\helpers\SeoFieldReader}.
 */
interface SeoFieldInterface
{
}
