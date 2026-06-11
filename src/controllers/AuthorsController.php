<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\elements\AuthorElement;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\Strings;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class AuthorsController extends Controller
{
    use AssetSelectorTrait;
    use BeaconCpPermissionTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_AUTHORS;

    /**
     * Lists the configured author elements.
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('beacon/authors/index');
    }

    /**
     * Renders the author edit form (or a blank one for a new author).
     *
     * @throws \yii\web\NotFoundHttpException when $authorId is given but no matching author exists
     */
    public function actionEdit(?int $authorId = null): Response
    {
        $author = $authorId !== null ? AuthorElement::find()->id($authorId)->one() : new AuthorElement();
        if (!$author) {
            throw new NotFoundHttpException();
        }
        return $this->renderTemplate('beacon/authors/_edit', ['author' => $author]);
    }

    /**
     * Creates or updates an author element from the posted form and redirects
     * to the posted return URL on success.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $authorId = $request->getBodyParam('authorId');

        $author = ($authorId ? AuthorElement::find()->id($authorId)->one() : null) ?? new AuthorElement();

        $author->title = $request->getBodyParam('title');
        foreach (['expertise', 'credentials', 'sameAs', 'alumniOf', 'affiliation', 'worksFor'] as $field) {
            $author->{$field} = $this->splitList($request->getBodyParam($field));
        }
        $author->jobTitle = $request->getBodyParam('jobTitle');
        $raw = $request->getBodyParam('description');
        $author->description = is_string($raw) && trim($raw) !== '' ? $raw : null;
        $author->imageAssetId = $this->assetIdFromSelector($request->getBodyParam('imageAssetId'));

        $session = Craft::$app->getSession();
        if (!Craft::$app->getElements()->saveElement($author)) {
            $session->setError(Craft::t('beacon', 'Couldn\'t save author.'));
            return null;
        }

        $session->setNotice(Craft::t('beacon', 'Author saved.'));
        return $this->redirectToPostedUrl($author);
    }

    /**
     * Splits a one-entry-per-line textarea value into a trimmed list, capping
     * at 50 non-empty entries as a controller-level guard before
     * {@see AuthorElement::defineRules()} runs the authoritative validation.
     *
     * @return list<string>
     */
    private function splitList(string|int|float|bool|null $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        return array_slice(Strings::splitLines($value), 0, 50);
    }
}
