<?php

namespace NineteenNinetyFour\Ingest\Destinations;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ingest\Contracts\Destination;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Facades\User;
use Statamic\Fields\Field;

/**
 * Users, with guardrails:
 *
 * - a feed can never make anyone a super admin, or set a password;
 * - roles and groups come only from ingest.users.roles / ingest.users.groups;
 * - new users can't sign in until they reset their password (an activation
 *   email can be sent: destination "send_activation");
 * - "missing" users are blocked from signing in (roles and groups removed),
 *   never deleted, unless the feed explicitly deletes;
 * - run history marks their values as personal data, kept for
 *   ingest.history.keep_personal_days.
 */
class UsersDestination implements Destination
{
    private const FORBIDDEN = ['super', 'password', 'password_hash', 'password_confirmation', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public static function handle(): string
    {
        return 'users';
    }

    public static function label(): string
    {
        return __('Users');
    }

    public function contentType(): string
    {
        return 'user';
    }

    public function personal(): bool
    {
        return true;
    }

    public function validate(Feed $feed): void
    {
        foreach (array_keys($feed->mapping()) as $handle) {
            if (in_array($handle, self::FORBIDDEN, true)) {
                throw new IngestException("A feed can't set a user's {$handle}.");
            }
        }
    }

    public function fields(Feed $feed): Collection
    {
        $fields = User::blueprint()->fields()->all()->except(self::FORBIDDEN);

        $fields->put('email', $fields->get('email') ?? new Field('email', ['type' => 'text', 'display' => __('Email')]));
        $fields->put('roles', new Field('roles', ['type' => 'list', 'display' => __('Roles')]));
        $fields->put('groups', new Field('groups', ['type' => 'list', 'display' => __('Groups')]));

        return $fields;
    }

    public function find(Feed $feed, string $id): mixed
    {
        return User::find($id);
    }

    public function findBy(Feed $feed, string $field, mixed $value): Collection
    {
        // Administrators are found too, so the item fails (in fill) rather
        // than quietly creating someone else.
        return $field === 'email'
            ? collect([User::findByEmail(mb_strtolower(trim(Value::text($value))))])->filter()
            : User::query()->where($field, $value)->limit(2)->get();
    }

    /**
     * Super admins, and anyone with a role or group outside the allow-lists,
     * are out of a feed's reach.
     */
    public function protectedUser(mixed $user): bool
    {
        if ($user->isSuper()) {
            return true;
        }

        $roles = array_map('strval', (array) config('ingest.users.roles', []));
        $groups = array_map('strval', (array) config('ingest.users.groups', []));

        return $user->explicitRoles()->keys()->diff($roles)->isNotEmpty()
            || $user->groups()->keys()->diff($groups)->isNotEmpty();
    }

    public function values(Feed $feed, mixed $content, array $handles): array
    {
        $out = [];
        foreach ($handles as $handle) {
            $out[$handle] = match ($handle) {
                'email' => $content->email(),
                'roles' => $content->explicitRoles()->keys()->sort()->values()->all(),
                'groups' => $content->groups()->keys()->sort()->values()->all(),
                default => $content->get($handle),
            };
        }

        return $out;
    }

    public function make(Feed $feed, array $values, Context $context): mixed
    {
        $email = mb_strtolower(trim(Value::text($values['email'] ?? '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new FieldError('A new user needs a valid email address.');
        }

        if (User::findByEmail($email)) {
            throw new FieldError("A user with the email {$email} already exists. Set the feed to adopt existing users by email.");
        }

        return User::make()->email($email);
    }

    public function fill(Feed $feed, mixed $content, array $values, Context $context): void
    {
        if ($content->id() && User::find($content->id()) && $this->protectedUser($content)) {
            throw new FieldError(__('Ingest doesn\'t change administrators, or users with roles it isn\'t allowed to give.'));
        }

        foreach ($values as $handle => $value) {
            if (in_array($handle, self::FORBIDDEN, true)) {
                continue;
            }

            match ($handle) {
                'email' => $value ? $content->email(mb_strtolower(trim((string) $value))) : null,
                'roles' => $content->explicitRoles($this->allowed((array) $value, 'roles', $context)),
                'groups' => $content->groups($this->allowed((array) $value, 'groups', $context)),
                default => $value === null ? $content->remove($handle) : $content->set($handle, $value),
            };
        }
    }

    /** @return string[] */
    private function allowed(array $values, string $type, Context $context): array
    {
        $allowed = array_map('strval', (array) config("ingest.users.{$type}", []));
        $keep = [];

        foreach ($values as $value) {
            $value = (string) $value;
            if (in_array($value, $allowed, true)) {
                $keep[] = $value;
            } else {
                $context->warn("The {$type} \"{$value}\" isn't in ingest.users.{$type}, so it wasn't given.");
            }
        }

        return $keep;
    }

    public function save(mixed $content): void
    {
        config('ingest.quiet_saves', true) ? $content->saveQuietly() : $content->save();
    }

    public function id(mixed $content): string
    {
        return (string) $content->id();
    }

    public function title(mixed $content): ?string
    {
        return $content->name() ?: $content->email();
    }

    public function isPublished(mixed $content): bool
    {
        return ! $content->get('ingest_blocked');
    }

    /**
     * "Unpublishing" a user takes away their roles and groups, so they
     * can't use the Control Panel, and marks them as blocked.
     */
    public function setPublished(mixed $content, bool $published): void
    {
        if ($published) {
            $content->remove('ingest_blocked');

            return;
        }

        if ($this->protectedUser($content)) {
            return;
        }

        $content->explicitRoles([]);
        $content->groups([]);
        $content->set('ingest_blocked', true);
    }

    /**
     * Undoing a block: the roles and groups the user had, as far as the
     * allow-lists still permit.
     */
    public function restoreAccess(mixed $content, array $snapshot): void
    {
        $roles = array_intersect((array) ($snapshot['roles'] ?? []), array_map('strval', (array) config('ingest.users.roles', [])));
        $groups = array_intersect((array) ($snapshot['groups'] ?? []), array_map('strval', (array) config('ingest.users.groups', [])));

        $content->explicitRoles(array_values($roles));
        $content->groups(array_values($groups));
    }

    /**
     * New users have no password: send them an activation email when the
     * feed asks for it.
     */
    public function afterSave(Feed $feed, mixed $content, Context $context): void
    {
        if ($context->isNew && ! $context->dryRun && ! empty($feed->destination()['send_activation'])) {
            $content->generateTokenAndSendPasswordResetNotification();
        }
    }

    public function delete(mixed $content): void
    {
        if ($this->protectedUser($content)) {
            throw new IngestException(__('Ingest doesn\'t delete administrators.'));
        }

        config('ingest.quiet_saves', true) ? $content->deleteQuietly() : $content->delete();
    }

    public function snapshot(mixed $content): array
    {
        return [
            'id' => $content->id(),
            'email' => $content->email(),
            'roles' => $content->explicitRoles()->keys()->all(),
            'groups' => $content->groups()->keys()->all(),
            'data' => collect($content->data()->all())->except(self::FORBIDDEN)->all(),
        ];
    }

    public function restore(Feed $feed, array $snapshot): mixed
    {
        $user = User::make()->id($snapshot['id'])->email($snapshot['email'])->data((array) $snapshot['data']);
        $user->explicitRoles((array) $snapshot['roles']);
        $user->groups((array) $snapshot['groups']);
        $this->save($user);

        return $user;
    }
}
