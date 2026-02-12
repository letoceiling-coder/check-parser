<?php

namespace Tests\Unit;

use App\Exceptions\NoActiveRaffleException;
use App\Models\Raffle;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\ActiveRaffle\RaffleScope;
use App\Services\ActiveRaffleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveRaffleResolverTest extends TestCase
{
    use RefreshDatabase;

    private ?TelegramBot $bot = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\Schema::hasTable('raffles')) {
            return;
        }
        $user = User::factory()->create(['username' => 'testuser-' . uniqid()]);
        $this->bot = TelegramBot::create([
            'user_id' => $user->id,
            'token' => 'test-token-' . uniqid(),
            'webhook_url' => 'https://example.com/webhook',
            'is_active' => true,
        ]);
    }

    public function test_get_active_returns_null_when_no_raffles(): void
    {
        if (!$this->bot) {
            $this->markTestSkipped('Migrations not run');
        }
        $resolver = app(ActiveRaffleResolver::class);

        $raffle = $resolver->getActive(RaffleScope::forBot($this->bot->id));

        $this->assertNull($raffle);
    }

    public function test_require_active_throws_when_no_active_raffle(): void
    {
        if (!$this->bot) {
            $this->markTestSkipped('Migrations not run');
        }
        $resolver = app(ActiveRaffleResolver::class);

        $this->expectException(NoActiveRaffleException::class);

        $resolver->requireActive(RaffleScope::forBot($this->bot->id));
    }

    public function test_get_active_returns_only_active_raffle_for_bot(): void
    {
        if (!$this->bot) {
            $this->markTestSkipped('Migrations not run');
        }
        $active = Raffle::create([
            'telegram_bot_id' => $this->bot->id,
            'name' => 'Test',
            'status' => Raffle::STATUS_ACTIVE,
            'total_slots' => 100,
            'slot_price' => 1000,
            'slots_mode' => 'sequential',
        ]);
        $resolver = app(ActiveRaffleResolver::class);

        $raffle = $resolver->getActive(RaffleScope::forBot($this->bot->id));

        $this->assertNotNull($raffle);
        $this->assertSame((int) $active->id, (int) $raffle->id);
        $this->assertSame(Raffle::STATUS_ACTIVE, $raffle->status);
    }

    public function test_require_active_returns_raffle_when_one_active(): void
    {
        if (!$this->bot) {
            $this->markTestSkipped('Migrations not run');
        }
        $active = Raffle::create([
            'telegram_bot_id' => $this->bot->id,
            'name' => 'Test',
            'status' => Raffle::STATUS_ACTIVE,
            'total_slots' => 100,
            'slot_price' => 1000,
            'slots_mode' => 'sequential',
        ]);
        $resolver = app(ActiveRaffleResolver::class);

        $raffle = $resolver->requireActive(RaffleScope::forBot($this->bot->id));

        $this->assertSame((int) $active->id, (int) $raffle->id);
    }
}
