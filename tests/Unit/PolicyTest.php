<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\AccessDenied;
use App\Auth\Ability;
use App\Auth\Policy;
use App\Auth\Role;
use App\Auth\User;
use App\Booking\BookingStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    private static function user(Role $role, bool $active = true): User
    {
        return new User(1, 'Tester', 't@example.com', $role, $active);
    }

    /**
     * @return iterable<string, array{Role, Ability, bool}>
     */
    public static function roleMatrix(): iterable
    {
        $sales = [Ability::ViewBookings, Ability::CreateBooking, Ability::EditQuote, Ability::AssignResources,
            Ability::ConfirmBooking, Ability::CompleteBooking, Ability::CancelBooking];
        $finance = [Ability::ViewBookings, Ability::RecordPayment, Ability::RecordRefund, Ability::ViewFinance];

        foreach (Ability::cases() as $ability) {
            yield "owner {$ability->value}" => [Role::Owner, $ability, true];
            yield "sales {$ability->value}" => [Role::Sales, $ability, in_array($ability, $sales, true)];
            yield "finance {$ability->value}" => [Role::Finance, $ability, in_array($ability, $finance, true)];
        }
    }

    #[DataProvider('roleMatrix')]
    public function testRoleAbilities(Role $role, Ability $ability, bool $expected): void
    {
        self::assertSame($expected, (new Policy())->allows(self::user($role), $ability));
    }

    public function testSalesCannotOverridePricesOrTouchMoney(): void
    {
        $policy = new Policy();
        $sales = self::user(Role::Sales);

        self::assertFalse($policy->allows($sales, Ability::OverridePrice));
        self::assertFalse($policy->allows($sales, Ability::RecordPayment));
        self::assertFalse($policy->allows($sales, Ability::RecordRefund));
    }

    public function testDeactivatedOwnerHasNoAbilities(): void
    {
        self::assertFalse((new Policy())->allows(self::user(Role::Owner, false), Ability::ViewBookings));
    }

    public function testBookingStateNarrowsRoleAbilities(): void
    {
        $policy = new Policy();
        $owner = self::user(Role::Owner);

        self::assertTrue($policy->allowsOnBooking($owner, Ability::EditQuote, BookingStatus::Quoted));
        self::assertFalse($policy->allowsOnBooking($owner, Ability::EditQuote, BookingStatus::Confirmed));
        self::assertFalse($policy->allowsOnBooking($owner, Ability::CancelBooking, BookingStatus::Completed));
        self::assertFalse($policy->allowsOnBooking($owner, Ability::RecordPayment, BookingStatus::Inquiry));
        self::assertFalse($policy->allowsOnBooking($owner, Ability::RecordPayment, BookingStatus::Cancelled));
        self::assertTrue($policy->allowsOnBooking($owner, Ability::RecordRefund, BookingStatus::Cancelled));
    }

    public function testAuthorizeThrowsForMissingAbility(): void
    {
        $this->expectException(AccessDenied::class);

        (new Policy())->authorize(self::user(Role::Finance), Ability::CreateBooking);
    }
}
