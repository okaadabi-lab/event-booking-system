<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FormInput;
use App\Support\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormInputTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function phones(): iterable
    {
        yield 'local with spaces' => ['0812 3456 7890', '+6281234567890'];
        yield 'local with dashes' => ['0812-3456-7890', '+6281234567890'];
        yield 'international' => ['+62 812 3456 7890', '+6281234567890'];
        yield 'country code without plus' => ['6281234567890', '+6281234567890'];
        yield 'too short' => ['0812', null];
        yield 'letters' => ['call me', null];
    }

    #[DataProvider('phones')]
    public function testNormalizesIndonesianPhoneNumbers(string $input, ?string $expected): void
    {
        self::assertSame($expected, FormInput::normalizePhone($input));
    }

    public function testCollectsAllErrorsBeforeThrowing(): void
    {
        $input = new FormInput(['email' => 'nope', 'guests' => '0', 'date' => '2026-02-30']);
        $input->string('name', 'Name');
        $input->email('email', 'Email');
        $input->int('guests', 'Guests', 1, 100);
        $input->date('date', 'Date');

        try {
            $input->throwIfInvalid();
            self::fail('Expected validation to fail.');
        } catch (ValidationFailed $e) {
            self::assertSame(['name', 'email', 'guests', 'date'], array_keys($e->errors));
        }
    }

    public function testIgnoresNonStringInput(): void
    {
        $input = new FormInput(['name' => ['injected']]);

        self::assertNull($input->string('name', 'Name'));
        self::assertTrue($input->hasError('name'));
    }
}
