<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\Http;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Shared POST+JSON toggle handler for inline CP switches (bots, rules, schemas).
 */
trait JsonToggleTrait
{
    use PostJsonTrait;

    /**
     * @param callable(int,bool):bool $apply Returns false when the target id doesn't exist.
     * @param callable():void|null $afterSuccess Optional side effect after a successful toggle.
     */
    private function toggleEnabled(
        string $idParam,
        callable $apply,
        string $missingMessage = '',
        ?callable $afterSuccess = null,
    ): Response {
        $this->requirePostJson();
        $request = Http::request();
        $enabled = (bool) $request->getRequiredBodyParam('enabled');
        if (!$apply((int) $request->getRequiredBodyParam($idParam), $enabled)) {
            throw new NotFoundHttpException($missingMessage);
        }
        if ($afterSuccess !== null) {
            $afterSuccess();
        }
        return $this->asJson(['success' => true, 'enabled' => $enabled]);
    }
}
