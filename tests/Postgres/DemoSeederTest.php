<?php

namespace Tests\Postgres;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\BusinessTemplateSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * Données de démonstration de la base causeo-dev, sur les vraies migrations.
 */
class DemoSeederTest extends PostgresTestCase
{
    public function test_demo_data_is_created_and_replaced_when_seeded_again(): void
    {
        config(['services.gemini.api_key' => null]);
        $this->seed([PlanSeeder::class, BusinessTemplateSeeder::class, DemoSeeder::class]);
        $this->seed(DemoSeeder::class);

        $this->assertSame(3, User::where('email', 'like', '%@'.DemoSeeder::DOMAIN)->count());
        $this->assertTrue(Hash::check(DemoSeeder::PASSWORD, User::where('email', 'boutique@'.DemoSeeder::DOMAIN)->value('password')));

        $shop = Business::where('name', 'Awa Couture')->sole();
        $salon = Business::where('name', 'Salon Belle Tresse')->sole();
        $this->assertTrue($shop->hasModule('orders'));
        $this->assertTrue($salon->hasModule('appointments'));
        $this->assertSame('pro', $shop->billingState()->plan->slug);

        $this->assertSame(3, Conversation::where('business_id', $shop->id)->count());
        $this->assertSame(['delivered', 'new'], Order::where('business_id', $shop->id)->orderBy('status')->pluck('status')->all());
        $this->assertSame(['confirmed', 'requested'], Appointment::where('business_id', $salon->id)->orderBy('status')->pluck('status')->all());
        $this->assertSame(1, $shop->escalations()->where('status', 'pending')->count());
        $this->assertSame(3, $shop->documents()->sole()->chunk_count);
    }
}
