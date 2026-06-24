<?php

use App\Models\{User, Park, Payment};
use App\Bots\Engine\ConversationEngine;
use App\Bots\Flows\ParkPriceFlow;
use App\Data\ParkData;
use App\Data\LocationData;
use App\Enums\{CountryTypes, StateTypes, RoleTypes};
use App\Services\ParkService;
use App\Services\ReservationService;
use App\Services\Payments\PaymentService;
use App\Models\Role;
use Illuminate\Support\Facades\{DB, Hash};

DB::beginTransaction();
try {
    // container wiring still resolves with the new ParkPriceFlow dependency
    app(ConversationEngine::class);
    app(ParkPriceFlow::class);
    echo 'engine + price flow resolve OK' . PHP_EOL;

    $owner = User::create(['name' => 'owner', 'email' => uniqid('o') . '@t.local', 'password' => Hash::make('x')]);
    $role  = Role::firstOrCreate(['role' => RoleTypes::SPACE_OWNER->value]);
    $owner->roles()->syncWithoutDetaching([$role->id]);

    // create a park via the real service with an owner-defined price
    $park = app(ParkService::class)->createWithLocation(
        location: new LocationData(
            country: CountryTypes::IRAQ, state: StateTypes::BAGHDAD, city: 'Baghdad',
            postalCode: null, latitude: 33.31, longitude: 44.36, extraDetails: 'test',
        ),
        park: new ParkData(name: 'Priced Lot', capacity: 5, price: 3500),
        owner: $owner,
    );
    echo 'created park price = ' . $park->price . ' (expect 3500)' . PHP_EOL;

    // a reservation's provisioned payment must charge the park price, once
    $cust = User::create(['name' => 'c', 'email' => uniqid('c') . '@t.local', 'password' => Hash::make('x')]);
    $svc  = app(ReservationService::class);
    $r = $svc->reserve($cust, $park);
    $svc->markActive($cust, $park); // provisions the Payment row
    $pay = Payment::where('reserve_id', $r->id)->first();
    echo 'payment amount = ' . ($pay?->amount ?? 'NONE') . ' (expect 3500.000)' . PHP_EOL;

    // editing the price updates it for the next charge
    $park->update(['price' => 5000]);
    echo 'after edit park price = ' . $park->fresh()->price . ' (expect 5000)' . PHP_EOL;

    // legacy park with default price still charges the migration default (3000)
    $legacy = Park::create(['name' => 'Legacy', 'capacity' => 3, 'free_spaces' => 3, 'user_id' => $owner->id]);
    echo 'legacy default price = ' . $legacy->price . ' (expect 3000.000)' . PHP_EOL;
} finally {
    DB::rollBack();
    echo 'rolled back' . PHP_EOL;
}
