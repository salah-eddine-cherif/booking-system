<?php

namespace Database\Seeders;

use App\Enums\DepositType;
use App\Enums\UserRole;
use App\Models\AvailabilityException;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\PendingBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * A small clinic, deliberately spread across three timezones so the seeded data
 * exercises the parts of the system that are actually interesting.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seeding is not a good reason to email anyone.
        Notification::fake();

        $admin = User::factory()->admin()->notBookable()->create([
            'name' => 'Dana Owner',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'timezone' => 'Europe/Lisbon',
            'title' => 'Practice Manager',
        ]);

        $ana = User::factory()->workingWeekdays('09:00:00', '17:00:00')->create([
            'name' => 'Ana Ferreira',
            'email' => 'ana@example.com',
            'password' => Hash::make('password'),
            'role' => UserRole::Staff,
            'timezone' => 'Europe/Lisbon',
            'title' => 'Senior Physiotherapist',
        ]);

        $marcus = User::factory()->workingWeekdays('08:00:00', '16:00:00')->create([
            'name' => 'Marcus Chen',
            'email' => 'marcus@example.com',
            'password' => Hash::make('password'),
            'role' => UserRole::Staff,
            'timezone' => 'America/New_York',
            'title' => 'Physiotherapist',
        ]);

        // A Saturday morning clinic on top of Marcus's weekday pattern.
        $marcus->availabilityRules()->create([
            'day_of_week' => 6,
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
        ]);

        $priya = User::factory()->workingWeekdays('10:00:00', '18:00:00')->create([
            'name' => 'Priya Nair',
            'email' => 'priya@example.com',
            'password' => Hash::make('password'),
            'role' => UserRole::Staff,
            'timezone' => 'Asia/Kolkata',
            'title' => 'Rehabilitation Coach',
        ]);

        // Ana takes a long lunch a week on Wednesday.
        AvailabilityException::factory()
            ->blocking(
                CarbonImmutable::now('Europe/Lisbon')->next(CarbonImmutable::WEDNESDAY)->format('Y-m-d'),
                '12:00:00',
                '14:00:00',
            )
            ->for($ana)
            ->create(['reason' => 'Team meeting']);

        // Priya is away for a day.
        AvailabilityException::factory()
            ->dayOff(CarbonImmutable::now('Asia/Kolkata')->addWeek()->next(CarbonImmutable::FRIDAY)->format('Y-m-d'))
            ->for($priya)
            ->create(['reason' => 'Annual leave']);

        $assessment = Service::create([
            'name' => 'Initial Assessment',
            'slug' => 'initial-assessment',
            'description' => 'A full first appointment, including history and a movement screen.',
            'duration_minutes' => 60,
            'buffer_after_minutes' => 15,
            'slot_increment_minutes' => 30,
            'min_notice_minutes' => 240,
            'price_amount' => 12000,
            'currency' => 'eur',
            'deposit_type' => DepositType::Percentage,
            'deposit_value' => 25,
            'reminder_hours_before' => 24,
            'sort_order' => 1,
        ]);

        $followUp = Service::create([
            'name' => 'Follow-up Session',
            'slug' => 'follow-up-session',
            'description' => 'A shorter treatment session for existing patients.',
            'duration_minutes' => 30,
            'buffer_after_minutes' => 10,
            'slot_increment_minutes' => 15,
            'price_amount' => 7000,
            'currency' => 'eur',
            'deposit_type' => DepositType::None,
            'reminder_hours_before' => 12,
            'sort_order' => 2,
        ]);

        $rehabClass = Service::create([
            'name' => 'Group Rehab Class',
            'slug' => 'group-rehab-class',
            'description' => 'A small-group class — up to eight people share each slot.',
            'duration_minutes' => 45,
            'slot_increment_minutes' => 60,
            'capacity' => 8,
            'price_amount' => 2500,
            'currency' => 'eur',
            'deposit_type' => DepositType::Fixed,
            'deposit_value' => 1000,
            'sort_order' => 3,
        ]);

        $assessment->staff()->sync([$ana->id, $marcus->id]);
        $followUp->staff()->sync([$ana->id, $marcus->id, $priya->id]);
        $rehabClass->staff()->sync([$priya->id]);

        // Ana is the senior clinician, so her follow-ups cost more.
        $followUp->staff()->updateExistingPivot($ana->id, ['price_amount' => 8500]);

        $this->seedExampleBookings($followUp, $ana);

        $this->command?->info('Seeded. Sign in as admin@example.com / password.');
        $this->command?->info("Admin id {$admin->id}; staff: {$ana->name}, {$marcus->name}, {$priya->name}.");
    }

    /**
     * Book a couple of real appointments through the booking service, so the
     * seeded calendar obeys the same rules as production traffic.
     */
    private function seedExampleBookings(Service $service, User $staff): void
    {
        $bookings = app(BookingService::class);

        $customers = Customer::factory()->count(2)->sequence(
            ['name' => 'Joana Silva', 'email' => 'joana@example.com', 'timezone' => 'Europe/Lisbon'],
            ['name' => 'Tom Baker', 'email' => 'tom@example.com', 'timezone' => 'Europe/London'],
        )->create();

        // Next Tuesday, in the staff member's own zone.
        $tuesday = CarbonImmutable::now($staff->timezone)
            ->next(CarbonImmutable::TUESDAY)
            ->setTime(10, 0);

        foreach ([0, 60] as $index => $offset) {
            $bookings->book(new PendingBooking(
                service: $service,
                staff: $staff,
                customer: $customers[$index],
                startsAt: $tuesday->addMinutes($offset)->utc(),
                customerTimezone: $customers[$index]->timezone,
                notes: $index === 0 ? 'Prefers a ground-floor room.' : null,
            ));
        }
    }
}
