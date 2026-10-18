<?php

namespace NineteenNinetyFour\Ingest\Tests\Feature;

use NineteenNinetyFour\Ingest\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;
use Statamic\Facades\Entry;
use Statamic\Statamic;

class TemplatesTest extends TestCase
{
    #[Test]
    public function the_feed_tag_says_when_a_feed_last_synced(): void
    {
        $this->booksFeed();
        $this->ingest('books');

        $html = (string) Antlers::parse('{{ ingest:feed handle="books" }}{{ title }}: {{ if last_synced }}synced{{ /if }}{{ /ingest:feed }}', [], true);
        $this->assertSame('Books: synced', trim($html));

        $this->assertSame('', trim((string) Antlers::parse('{{ ingest:feed handle="nope" }}x{{ /ingest:feed }}', [], true)));
    }

    #[Test]
    public function the_source_tag_says_where_an_entry_came_from(): void
    {
        $this->booksFeed();
        $this->ingest('books');
        $entry = Entry::query()->where('collection', 'books')->first();
        Entry::make()->collection('books')->id('mine')->slug('mine')->data(['title' => 'Mine'])->save();

        $this->assertSame('Books '.$entry->get('isbn'), trim((string) Antlers::parse('{{ ingest:source }}{{ title }} {{ key }}{{ /ingest:source }}', ['id' => $entry->id()], true)));
        $this->assertSame('', trim((string) Antlers::parse('{{ ingest:source }}x{{ /ingest:source }}', ['id' => 'mine'], true)));
    }

    #[Test]
    public function the_blade_examples_in_the_docs_work(): void
    {
        $this->booksFeed();
        $this->ingest('books');
        $entry = Entry::query()->where('collection', 'books')->first();

        $feed = Statamic::tag('ingest:feed')->params(['handle' => 'books'])->fetch();
        $this->assertNotNull($feed['last_synced']->diffForHumans());

        $source = Statamic::tag('ingest:source')->params(['id' => $entry->id()])->fetch();
        $this->assertSame('Books', $source['title']);
    }
}
