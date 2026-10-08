<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Reads and validates fields from a parsed form body. Each reader returns a typed
 * value (or null) and records a friendly message on failure; call
 * throwIfInvalid() once all fields have been read.
 */
final class FormInput
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function raw(string $field): string
    {
        $value = $this->data[$field] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    public function string(string $field, string $label, bool $required = true, int $max = 255, int $min = 0): ?string
    {
        $value = $this->raw($field);
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }

            return null;
        }
        $length = mb_strlen($value);
        if ($length > $max) {
            $this->errors[$field] = "$label must be $max characters or fewer.";
        } elseif ($length < $min) {
            $this->errors[$field] = "$label must be at least $min characters.";
        }

        return $value;
    }

    public function email(string $field, string $label, bool $required = true): ?string
    {
        $value = $this->string($field, $label, $required, 190);
        if ($value === null) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = "$label must be a valid email address, like name@example.com.";

            return null;
        }

        return mb_strtolower($value);
    }

    public function phone(string $field, string $label): ?string
    {
        $value = $this->string($field, $label, true, 30);
        if ($value === null) {
            return null;
        }
        $normalized = self::normalizePhone($value);
        if ($normalized === null) {
            $this->errors[$field] = "$label must be a phone number like 0812 3456 7890 or +62 812 3456 7890.";
        }

        return $normalized;
    }

    public function int(string $field, string $label, int $min, int $max, bool $required = true): ?int
    {
        $value = $this->raw($field);
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }

            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($int === false) {
            $this->errors[$field] = "$label must be a whole number between $min and $max.";

            return null;
        }

        return $int;
    }

    public function money(string $field, string $label, bool $required = true): ?int
    {
        $value = $this->raw($field);
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }

            return null;
        }
        $amount = Money::parse($value);
        if ($amount === null) {
            $this->errors[$field] = "$label must be a whole rupiah amount, like 1500000 or 1.500.000.";
        }

        return $amount;
    }

    public function date(string $field, string $label, bool $required = true): ?DateTimeImmutable
    {
        return $this->parseDate($field, $label, 'Y-m-d', 'a date', $required);
    }

    public function dateTime(string $field, string $label, bool $required = true): ?DateTimeImmutable
    {
        return $this->parseDate($field, $label, 'Y-m-d\TH:i', 'a date and time', $required);
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    public function enum(string $field, string $label, string $enum): ?\BackedEnum
    {
        $value = $this->raw($field);
        $case = $value === '' ? null : $enum::tryFrom($value);
        if ($case === null) {
            $this->errors[$field] = "Choose a valid $label.";
        }

        return $case;
    }

    public function checkbox(string $field): bool
    {
        return in_array($this->data[$field] ?? null, ['1', 'on', 'yes'], true);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $field): array
    {
        $value = $this->data[$field] ?? [];

        return is_array($value) ? $value : [];
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function throwIfInvalid(): void
    {
        if ($this->errors !== []) {
            throw new ValidationFailed($this->errors);
        }
    }

    /**
     * Indonesian numbers are stored in one canonical form (+62...) so the same
     * customer typing "0812-..." or "+62 812..." is recognised as one person.
     */
    public static function normalizePhone(string $value): ?string
    {
        $digits = (string) preg_replace('/[\s\-.()]/', '', $value);
        if (str_starts_with($digits, '0')) {
            $digits = '+62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '62')) {
            $digits = '+' . $digits;
        }

        return preg_match('/^\+\d{9,15}$/', $digits) === 1 ? $digits : null;
    }

    private function parseDate(
        string $field,
        string $label,
        string $format,
        string $description,
        bool $required,
    ): ?DateTimeImmutable {
        $value = $this->raw($field);
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }

            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        $warnings = DateTimeImmutable::getLastErrors();
        if ($date === false || ($warnings !== false && $warnings['warning_count'] + $warnings['error_count'] > 0)) {
            $this->errors[$field] = "$label must be $description.";

            return null;
        }

        return $date;
    }
}
