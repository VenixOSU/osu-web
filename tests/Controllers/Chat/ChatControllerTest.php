<?php
/**
 *    Copyright 2015-2017 ppy Pty. Ltd.
 *
 *    This file is part of osu!web. osu!web is distributed with the hope of
 *    attracting more community contributions to the core ecosystem of osu!.
 *
 *    osu!web is free software: you can redistribute it and/or modify
 *    it under the terms of the Affero GNU General Public License version 3
 *    as published by the Free Software Foundation.
 *
 *    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
 *    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *    See the GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
 */
use App\Models\Chat;
use App\Models\User;
use App\Models\UserRelation;

class ChatControllerTest extends TestCase
{
    // Need to disable transactions for these tests otherwise the cross-database queries being used fail.
    protected $connectionsToTransact = [];

    protected static $faker;

    public static function setUpBeforeClass()
    {
        self::$faker = Faker\Factory::create();
    }

    public function setUp()
    {
        parent::setUp();

        $this->user = factory(User::class)->create();
        $this->anotherUser = factory(User::class)->create();
    }


    public function tearDown()
    {
        // Ideally this cleanup would be in `tearDownAfterClass` as to not run after *every* individual
        // test, but Laravel in its infinite wisdom nukes app() during `tearDown` (which runs before
        // `tearDownAfterClass`)... so here we are.

        DB::statement("SET foreign_key_checks=0");
        Chat\Message::truncate();
        Chat\UserChannel::truncate();
        Chat\Channel::truncate();
        UserRelation::truncate();
        User::truncate();
        DB::statement("SET foreign_key_checks=1");
    }

    //region POST /chat/new - Create New PM
    public function testCreatePMWhenGuest() // fail
    {
        $this->json(
            'POST',
            route('chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(401);
    }

    public function testCreatePMWhenBlocked() // fail
    {
        factory(UserRelation::class)->states('block')->create([
            'user_id' => $this->anotherUser->user_id,
            'zebra_id' => $this->user->user_id,
        ]);

        $this->actingAs($this->user)
            ->json(
                'POST',
                route('chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(403);
    }

    public function testCreatePMWhenRestricted() // fail
    {
        $restrictedUser = factory(User::class)->states('restricted')->create();

        $this->actingAs($restrictedUser)
            ->json(
                'POST',
                route('chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(403);
    }

    public function testCreatePMWhenTargetRestricted() // fail
    {
        $restrictedUser = factory(User::class)->states('restricted')->create();

        $this->actingAs($this->user)
            ->json(
                'POST',
                route('chat.new'),
                [
                    'target_id' => $restrictedUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(404);
    }

    public function testCreatePMWithSelf() // fail
    {
        $this->actingAs($this->user)
            ->json(
                'POST',
                route('chat.new'),
                [
                    'target_id' => $this->user->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(422);
    }

    public function testCreatePMWhenFriendsOnlyAndNotFriended() // fail
    {
        $privateUser = factory(User::class)->create(['pm_friends_only' => true]);

        $this->actingAs($this->user)
            ->json(
                'POST',
                route('chat.new'),
                [
                    'target_id' => $privateUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(403);
    }

    public function testCreatePMWhenFriendsOnlyAndFriended() // success
    {
        $privateUser = factory(User::class)->create(['pm_friends_only' => true]);
        factory(UserRelation::class)->states('friend')->create([
            'user_id' => $privateUser->user_id,
            'zebra_id' => $this->user->user_id,
        ]);

        $this->actingAs($this->user)
            ->json(
                'POST',
                route('chat.new'),
                [
                    'target_id' => $privateUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);
    }

    public function testCreatePM() // success
    {
        $this->actingAs($this->user)
            ->json(
                'POST',
                route('chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);
    }

    public function testCreatePMWhenAlreadyExists() // success
    {
        $this->actingAs($this->user)
            ->json(
                'POST',
                route('chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);

        // should return existing conversation and not error
        $this->actingAs($this->user)
            ->json(
                'POST',
                route('chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);
    }

    //endregion

    //region GET /chat/presence - Get Presence
    public function testChatPresenceWhenGuest() // fail
    {
        $this->json('GET', route('chat.presence'))
            ->assertStatus(401);
    }

    public function testChatPresence() // success
    {
        $publicChannel = factory(Chat\Channel::class)->states('public')->create();

        // join the channel
        $this->actingAs($this->user)
            ->json('PUT', route('chat.channels.join', [
                'channel_id' => $publicChannel->channel_id,
                'user_id' => $this->user->user_id,
            ]))
            ->assertStatus(204);

        $this->actingAs($this->user)
            ->json('GET', route('chat.presence'))
            ->assertStatus(200)
            ->assertJsonFragment(['channel_id' => $publicChannel->channel_id]);
    }

    public function testChatPresenceHidingBlocked() // success
    {
        // start conversation with $this->anotherUser
        $this->actingAs($this->user)
            ->json(
                'POST',
                route('chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);

        // block $this->anotherUser
        $block = factory(UserRelation::class)->states('block')->create([
            'user_id' => $this->user->user_id,
            'zebra_id' => $this->anotherUser->user_id,
        ]);

        // ensure conversation with $this->anotherUser isn't visible
        $this->actingAs($this->user)
            ->json('GET', route('chat.presence'))
            ->assertStatus(200)
            ->assertJsonMissing(['users' => [
                $this->user->user_id,
                $this->anotherUser->user_id,
            ]]);

        // unblock $this->anotherUser
        $block->delete();

        // ensure conversation with $this->anotherUser is visible again
        $this->actingAs($this->user)
            ->json('GET', route('chat.presence'))
            ->assertStatus(200)
            ->assertJsonFragment(['users' => [
                $this->user->user_id,
                $this->anotherUser->user_id,
            ]]);
    }

    public function testChatPresenceHidingRestricted() // success
    {
        // start conversation with $this->anotherUser
        $this->actingAs($this->user)
            ->json(
                'POST',
                route('chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);

        // restrict $this->anotherUser
        $this->anotherUser->update(['user_warnings' => 1]);

        // ensure conversation with $this->anotherUser isn't visible
        $this->actingAs($this->user)
            ->json('GET', route('chat.presence'))
            ->assertStatus(200)
            ->assertJsonMissing(['users' => [
                $this->user->user_id,
                $this->anotherUser->user_id,
            ]]);

        // unrestrict $this->anotherUser
        $this->anotherUser->update(['user_warnings' => 0]);

        // ensure conversation with $this->anotherUser is visible again
        $this->actingAs($this->user)
            ->json('GET', route('chat.presence'))
            ->assertStatus(200)
            ->assertJsonFragment(['users' => [
                $this->user->user_id,
                $this->anotherUser->user_id,
            ]]);
    }

    //endregion

    //region GET /chat/updates?since=[message_id] - Get Updates
    public function testChatUpdatesWhenGuest() // fail
    {
        $this->json('GET', route('chat.updates'))
            ->assertStatus(401);
    }

    public function testChatUpdatesWithNoNewMessages() // success
    {
        $publicChannel = factory(Chat\Channel::class)->states('public')->create();
        $publicMessage = factory(Chat\Message::class)->create(['channel_id' => $publicChannel->channel_id]);

        // join the channel
        $this->actingAs($this->user)
            ->json('PUT', route('chat.channels.join', [
                'channel_id' => $publicChannel->channel_id,
                'user_id' => $this->user->user_id,
            ]))
            ->assertStatus(204);

        $this->actingAs($this->user)
            ->json('GET', route('chat.updates'), ['since' => $publicMessage->message_id])
            ->assertStatus(204);
    }

    public function testChatUpdates() // success
    {
        $publicChannel = factory(Chat\Channel::class)->states('public')->create();
        $publicMessage = factory(Chat\Message::class)->create(['channel_id' => $publicChannel->channel_id]);

        // join channel
        $this->actingAs($this->user)
            ->json('PUT', route('chat.channels.join', [
                'channel_id' => $publicChannel->channel_id,
                'user_id' => $this->user->user_id,
            ]))
            ->assertStatus(204);

        $this->actingAs($this->user)
            ->json('GET', route('chat.updates'), ['since' => 0])
            ->assertStatus(200)
            ->assertJsonFragment(['content' => $publicMessage->content]);
    }

    //endregion
}
