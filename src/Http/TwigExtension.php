<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Money;
use DateTimeInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class TwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('rupiah', static fn (int $amount): string => Money::format($amount)),
            new TwigFilter('plain_number', static fn (int $n): string => number_format($n, 0, ',', '.')),
            new TwigFilter('day', static fn (DateTimeInterface $d): string => $d->format('D j M Y')),
            new TwigFilter('short_day', static fn (DateTimeInterface $d): string => $d->format('j M')),
            new TwigFilter('stamp', static fn (DateTimeInterface $d): string => $d->format('j M Y, H:i')),
            new TwigFilter('time', static fn (DateTimeInterface $d): string => $d->format('H:i')),
            new TwigFilter('input_date', static fn (?DateTimeInterface $d): string => $d?->format('Y-m-d') ?? ''),
            new TwigFilter('input_datetime', static fn (?DateTimeInterface $d): string => $d?->format('Y-m-d\TH:i') ?? ''),
            new TwigFilter('humanize', static fn (string $s): string => ucfirst(str_replace('_', ' ', $s))),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('query', static fn (array $params): string => $params === [] ? '' : '?' . http_build_query($params)),
            new TwigFunction('day_fraction', self::dayFraction(...)),
        ];
    }

    /**
     * Position of a time within its day as a percentage, for the timeline bars.
     */
    public static function dayFraction(DateTimeInterface $time, DateTimeInterface $day): float
    {
        $start = (new \DateTimeImmutable($day->format('Y-m-d') . ' 00:00:00', $time->getTimezone()))->getTimestamp();
        $fraction = ($time->getTimestamp() - $start) / 864;

        return round(max(0, min(100, $fraction)), 2);
    }
}
