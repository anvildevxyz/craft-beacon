<?php

namespace anvildev\beacon\elements;

use anvildev\beacon\enums\RedirectStatusCode;
use anvildev\beacon\helpers\RedirectTargets;
use anvildev\beacon\helpers\Strings;
use Craft;

/**
 * Shared validation for redirect rules and short links (status code +
 * relative/http(s) target allowlist).
 */
trait ValidatesRedirectLinkTrait
{
    public function validateStatusCode(string $attribute): void
    {
        if (RedirectStatusCode::tryFrom((int) $this->$attribute) === null) {
            $this->addError($attribute, Craft::t('beacon', 'Invalid status code.'));
        }
    }

    protected function validateRedirectTargetUri(string $attribute, string $lineBreakMessage): void
    {
        $value = (string) $this->$attribute;
        if (Strings::containsLineBreaks($value)) {
            $this->addError($attribute, Craft::t('beacon', $lineBreakMessage));
            return;
        }
        if (($err = RedirectTargets::validateTargetUri($value)) !== null) {
            $this->addError($attribute, Craft::t('beacon', $err));
        }
    }
}
