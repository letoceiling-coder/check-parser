<?php

namespace Tests\Feature;

use App\Models\BotSettings;
use App\Models\Raffle;
use App\Models\Ticket;
use App\Models\TelegramBot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ActiveRaffleApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TelegramBot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['username' => 'admin-' . uniqid()]);
        $this->bot = TelegramBot::create([
            'user_id' => $this->user->id,
            'token' => 'test-' . uniqid(),
            'webhook_url' => 'https://example.com/wh',
            'is_active' => true,
        ]);
        BotSettings::getOrCreate($this->bot->id);
    }

    public function test_tickets_index_filters_by_active_raffle(): void
    {
        $active = Raffle::create([
            'telegram_bot_id' => $this->bot->id,
            'name' => 'Active',
            'status' => Raffle::STATUS_ACTIVE,
            'total_slots' => 10,
            'slot_price' => 1000,
            'slots_mode' => 'sequential',
        ]);
        $other = Raffle::create([
            'telegram_bot_id' => $this->bot->id,
            'name' => 'Other',
            'status' => Raffle::STATUS_COMPLETED,
            'total_slots' => 10,
            'slot_price' => 1000,
            'slots_mode' => 'sequential',
        ]);

        Ticket::create([
            'telegram_bot_id' => $this->bot->id,
            'raffle_id' => $active->id,
            'number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Ticket::create([
            'telegram_bot_id' => $this->bot->id,
            'raffle_id' => $other->id,
            'number' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/tickets?per_page=50');
        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame((int) $active->id, (int) $data[0]['raffle_id']);
    }

    public function test_activate_ensures_single_active_per_bot(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite does not have paused status in enum');
        }
        $r1 = Raffle::create([
            'telegram_bot_id' => $this->bot->id,
            'name' => 'First',
            'status' => Raffle::STATUS_ACTIVE,
            'total_slots' => 10,
            'slot_price' => 1000,
            'slots_mode' => 'sequential',
        ]);
        $r2 = Raffle::create([
            'telegram_bot_id' => $this->bot->id,
            'name' => 'Second',
            'status' => Raffle::STATUS_PAUSED,
            'total_slots' => 10,
            'slot_price' => 1000,
            'slots_mode' => 'sequential',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/bot/{$this->bot->id}/raffles/{$r2->id}/activate");
        $response->assertOk();

        $this->assertSame(Raffle::STATUS_PAUSED, $r1->fresh()->status);
        $this->assertSame(Raffle::STATUS_ACTIVE, $r2->fresh()->status);
        $activeCount = Raffle::where('telegram_bot_id', $this->bot->id)->where('status', Raffle::STATUS_ACTIVE)->count();
        $this->assertSame(1, $activeCount);
    }
}
