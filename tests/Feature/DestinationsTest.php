<?php

namespace NineteenNinetyFour\Ingest\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use NineteenNinetyFour\Ingest\Runs\RunItem;
use NineteenNinetyFour\Ingest\Runs\RunStatus;
use NineteenNinetyFour\Ingest\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Term;
use Statamic\Facades\User;

class DestinationsTest extends TestCase
{
    #[Test]
    public function terms_are_created_and_updated(): void
    {
        $this->saveFeed('subjects', [
            'source' => ['type' => 'url', 'url' => 'https://feeds.test/books/index.json'],
            'format' => ['item_path' => 'data.*.subjects.*'],
            'destination' => ['type' => 'terms', 'taxonomy' => 'subjects'],
            'key' => ['value'],
            'mapping' => ['title' => ['path' => 'value'], 'description' => ['template' => 'Books about {value}']],
        ]);

        $run = $this->ingest('subjects');

        $subjects = collect($this->fixture('books/index.json')['data'])->flatMap(fn ($b) => $b['subjects'])->unique();
        $this->assertSame($subjects->count(), $run->counts['created'], (string) $run->message);
        $this->assertSame('Books about Crime', Term::find('subjects::crime')?->get('description'));
    }

    #[Test]
    public function a_single_object_fills_a_global_set(): void
    {
        GlobalSet::make('shop')->title('Shop')->save();
        GlobalSet::findByHandle('shop')->makeLocalization('default')->save();
        $this->blueprint('globals', 'shop', [
            'phone' => ['type' => 'text'],
            'notice' => ['type' => 'text'],
            'hours' => ['type' => 'grid', 'fields' => [
                ['handle' => 'day', 'field' => ['type' => 'text']],
                ['handle' => 'opens', 'field' => ['type' => 'time']],
            ]],
        ]);

        $this->saveFeed('hours', [
            'source' => ['type' => 'url', 'url' => 'https://feeds.test/settings/opening-hours.json'],
            'destination' => ['type' => 'globals', 'set' => 'shop'],
            'mapping' => [
                'phone' => ['path' => 'phone'],
                'notice' => ['path' => 'notice'],
                'hours' => ['path' => 'hours', 'fields' => ['day' => ['path' => 'day'], 'opens' => ['path' => 'opens']]],
            ],
        ]);

        $run = $this->ingest('hours');

        $this->assertSame(RunStatus::Completed, $run->status, (string) $run->message);
        $variables = GlobalSet::findByHandle('shop')->in('default');
        $this->assertSame('01273 000000', $variables->get('phone'));
        $this->assertSame('09:00', $variables->get('hours')[0]['opens']);
        $this->assertSame(1, $run->counts['updated']);
        $this->assertSame(1, $this->ingest('hours')->counts['unchanged']);
    }

    #[Test]
    public function users_are_synced_with_guardrails(): void
    {
        Notification::fake();
        Schema::create('password_reset_tokens', function ($table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Role::make('member')->save();
        Role::make('admin')->permissions(['super'])->save();
        config(['ingest.users.roles' => ['member']]);

        file_put_contents(self::TMP.'/members.json', json_encode(['members' => [
            ['email' => 'Ada@Example.com', 'name' => 'Ada Ashdown', 'roles' => ['member', 'admin'], 'super' => true, 'password' => 'hunter2'],
            ['email' => 'bea@example.com', 'name' => 'Bea Bramley', 'roles' => ['member']],
        ]]));
        $this->serve('members.json', self::TMP.'/members.json');

        $this->saveFeed('members', [
            'source' => ['type' => 'url', 'url' => 'https://feeds.test/members.json'],
            'format' => ['item_path' => 'members'],
            'destination' => ['type' => 'users', 'send_activation' => true],
            'key' => ['email'],
            'mapping' => ['email' => ['path' => 'email'], 'name' => ['path' => 'name'], 'roles' => ['path' => 'roles']],
        ]);

        $run = $this->ingest('members');

        $this->assertSame(2, $run->counts['created'], (string) $run->message);
        $ada = User::findByEmail('ada@example.com');
        $this->assertFalse($ada->isSuper());
        $this->assertSame(['member'], $ada->roles()->keys()->all());
        $this->assertNull($ada->password());
        $this->assertTrue($this->items($run, RunItem::CREATED)[0]->personal);
        Notification::assertCount(2);
    }

    #[Test]
    public function a_users_feed_cant_map_a_password_or_super(): void
    {
        $this->saveFeed('members', [
            'source' => ['type' => 'url', 'url' => 'https://feeds.test/x.json'],
            'destination' => ['type' => 'users'],
            'key' => ['email'],
            'mapping' => ['email' => ['path' => 'email'], 'super' => ['value' => true]],
        ]);

        $run = $this->ingest('members');

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('super', $run->message);
    }

    #[Test]
    public function missing_users_are_blocked_not_deleted(): void
    {
        Role::make('member')->save();
        config(['ingest.users.roles' => ['member']]);
        file_put_contents(self::TMP.'/members.json', json_encode(['members' => [['email' => 'ada@example.com', 'roles' => ['member']], ['email' => 'bea@example.com', 'roles' => ['member']]]]));
        $this->serve('members.json', self::TMP.'/members.json');
        $this->saveFeed('members', [
            'source' => ['type' => 'url', 'url' => 'https://feeds.test/members.json'],
            'format' => ['item_path' => 'members'],
            'destination' => ['type' => 'users'],
            'key' => ['email'],
            'sync' => ['missing' => ['action' => 'unpublish'], 'safety' => ['max_removal_percent' => 100]],
            'mapping' => ['email' => ['path' => 'email'], 'roles' => ['path' => 'roles']],
        ]);
        $this->ingest('members');

        file_put_contents(self::TMP.'/members.json', json_encode(['members' => [['email' => 'ada@example.com', 'roles' => ['member']]]]));
        $run = $this->ingest('members');

        $this->assertSame(1, $run->counts['unpublished']);
        $bea = User::findByEmail('bea@example.com');
        $this->assertNotNull($bea);
        $this->assertSame([], $bea->roles()->keys()->all());
        $this->assertTrue($bea->get('ingest_blocked'));
    }

    #[Test]
    public function a_second_site_feed_makes_localizations(): void
    {
        config(['statamic.system.multisite' => true]);
        Site::setSites([
            'default' => ['name' => 'English', 'url' => '/', 'locale' => 'en_GB'],
            'fr' => ['name' => 'French', 'url' => '/fr/', 'locale' => 'fr_FR'],
        ]);
        Collection::find('books')->sites(['default', 'fr'])->save();

        $this->booksFeed();
        $this->booksFeed([
            'destination' => ['site' => 'fr'],
            'localizes' => 'books',
            'mapping' => ['title' => ['template' => 'FR {title}']],
        ], handle: 'books_fr');

        $this->ingest('books');
        $run = $this->ingest('books_fr');

        $this->assertSame(20, $run->counts['created'], (string) $run->message);
        $fr = Entry::query()->where('collection', 'books')->where('site', 'fr')->first();
        $this->assertNotNull($fr->origin());
        $this->assertSame('FR '.$fr->origin()->get('title'), $fr->get('title'));
    }

    #[Test]
    public function structured_collections_get_parents_from_the_feed(): void
    {
        Collection::find('books')->structureContents(['max_depth' => 3])->save();
        file_put_contents(self::TMP.'/tree.json', json_encode(['data' => [
            ['id' => 'a', 'title' => 'Fiction', 'parent' => null],
            ['id' => 'b', 'title' => 'Crime', 'parent' => 'a'],
        ]]));
        $this->serve('tree.json', self::TMP.'/tree.json');

        $this->booksFeed(['key' => ['id'], 'mapping' => ['title' => ['path' => 'title'], 'parent' => ['path' => 'parent']]], 'tree.json');
        $this->ingest('books');

        $crime = Entry::query()->where('title', 'Crime')->first();
        $this->assertSame('Fiction', $crime->parent()?->get('title'));
    }

    #[Test]
    public function a_users_feed_never_adopts_or_changes_an_administrator(): void
    {
        Role::make('member')->save();
        config(['ingest.users.roles' => ['member']]);
        $admin = User::make()->email('boss@example.com')->makeSuper();
        $admin->save();

        file_put_contents(self::TMP.'/members.json', json_encode(['members' => [['email' => 'boss@example.com', 'name' => 'Not the boss', 'new_email' => 'attacker@example.com']]]));
        $this->serve('members.json', self::TMP.'/members.json');
        $this->saveFeed('members', [
            'source' => ['type' => 'url', 'url' => 'https://feeds.test/members.json'],
            'format' => ['item_path' => 'members'],
            'destination' => ['type' => 'users'],
            'key' => ['email'],
            'sync' => ['adopt' => ['field' => 'email', 'path' => 'email']],
            'mapping' => ['email' => ['path' => 'new_email'], 'name' => ['path' => 'name']],
        ]);

        $run = $this->ingest('members');

        $this->assertSame(1, $run->counts['failed']);
        $boss = User::find($admin->id());
        $this->assertSame('boss@example.com', $boss->email());
        $this->assertTrue($boss->isSuper());
        $this->assertNull(User::findByEmail('attacker@example.com'));
    }
}
