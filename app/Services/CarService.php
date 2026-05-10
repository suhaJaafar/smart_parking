<?php

namespace App\Services;

use App\Models\Car;
use App\Models\Park;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Domain operations for Car.
 */
class CarService
{
    /**
     * Create a car owned by the given user. Plate is unique per (prefix, number).
     */
    public function store(array $carData, User $owner): Car
    {
        return Car::create([
            ...$carData,
            'user_id' => $owner->id,
        ]);
    }

    public function patch(Car $car, array $carData): Car
    {
        $car->update($carData);
        return $car->fresh();
    }

    public function delete(Car $car): void
    {
        $car->delete();
    }

    /**
     * Find a car by its plate (prefix + number), or create it for the given owner
     * if it doesn't exist yet. Used by the WhatsApp bot to make the SPACE_OWNER's
     * job a one-step flow.
     */
    public function findOrCreateByPlate(
        string $platePrefix,
        string $carNumber,
        User $owner,
        ?string $model = null,
    ): Car {
        return Car::firstOrCreate(
            [
                'plate_prefix' => $platePrefix,
                'car_number'   => $carNumber,
            ],
            [
                'user_id' => $owner->id,
                'model'   => $model,
            ],
        );
    }

    /**
     * Park a car: link it to a park and decrement free_spaces, atomically.
     *
     * If the customer already had an ACTIVE reservation for this park, the
     * slot was debited at reservation time. Pass $alreadyHeld = true to skip
     * the second decrement (the reservation is fulfilled, not duplicated).
     *
     * Throws if the park is full or if the car is already inside another park.
     */
    public function enterPark(Car $car, Park $park, bool $alreadyHeld = false): Car
    {
        return DB::transaction(function () use ($car, $park, $alreadyHeld) {
            // Lock the park row to safely check & decrement capacity.
            $park = Park::whereKey($park->id)->lockForUpdate()->firstOrFail();

            if ($car->park_id !== null && $car->park_id !== $park->id) {
                throw new RuntimeException('Car is already inside another park.');
            }

            if ($car->park_id === $park->id) {
                return $car; // already inside, idempotent.
            }

            if (!$alreadyHeld) {
                if ($park->free_spaces <= 0) {
                    throw new RuntimeException('Park is full.');
                }

                $park->decrement('free_spaces');
            }

            $car->update(['park_id' => $park->id]);
            return $car->fresh();
        });
    }

    /**
     * Remove a car from its park and increment free_spaces.
     */
    public function exitPark(Car $car): Car
    {
        return DB::transaction(function () use ($car) {
            if ($car->park_id === null) {
                return $car; // nothing to do, idempotent.
            }

            $park = Park::whereKey($car->park_id)->lockForUpdate()->firstOrFail();

            if ($park->free_spaces < $park->capacity) {
                $park->increment('free_spaces');
            }

            $car->update(['park_id' => null]);
            return $car->fresh();
        });
    }
}
