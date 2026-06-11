<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\elements\AuthorElement;
use Craft;
use craft\elements\User;
use craft\test\TestCase;

/**
 * @group requires-craft
 */
class AuthorElementTest extends TestCase
{
    public function testCanCreateAndSaveAuthor(): void
    {
        $author = new AuthorElement();
        $author->title = 'Jane Researcher';
        $author->expertise = ['machine-learning', 'biology'];
        $author->credentials = ['PhD MIT', 'IEEE Senior Member'];

        /** @phpstan-ignore method.notFound */
        $saved = Craft::$app->getElements()->saveElement($author);
        $this->assertTrue($saved);
        $this->assertNotNull($author->id);

        $reloaded = AuthorElement::find()->id($author->id)->one();
        $this->assertSame('Jane Researcher', $reloaded->title);
        $this->assertSame(['machine-learning', 'biology'], $reloaded->expertise);
    }

    public function testPersonNodeCarriesStableId(): void
    {
        $author = new AuthorElement();
        $author->title = 'Ada Lovelace';

        /** @phpstan-ignore method.notFound */
        Craft::$app->getElements()->saveElement($author);

        $node = $author->toPersonNode();
        $this->assertNotNull($node);
        $this->assertSame('Person', $node['@type']);
        $this->assertArrayHasKey('@id', $node);
        $this->assertSame('urn:beacon:author:' . $author->uid, $node['@id']);
    }

    public function testIsEditableFromIndex(): void
    {
        $admin = new User(['admin' => true]);

        $author = new AuthorElement();
        $author->id = 123;

        $this->assertTrue($author->canView($admin));
        $this->assertTrue($author->canSave($admin));
        $this->assertStringContainsString('beacon/authors/123', (string) $author->getCpEditUrl());
    }
}
