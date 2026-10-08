<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Resource\TimeWindow;
use App\Support\DomainRuleViolation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TimeWindowTest extends TestCase
{
    private static function window(string $start, string $end): TimeWindow
    {
        return new TimeWindow(new DateTimeImmutable($start), new DateTimeImmutable($end));
    }

    public function testTouchingWindowsDoNotOverlapWithoutBuffer(): void
    {
        $morning = self::window('2026-10-20 06:00', '2026-10-20 12:00');
        $afternoon = self::window('2026-10-20 12:00', '2026-10-20 18:00');

        self::assertFalse($morning->overlaps($afternoon));
        self::assertFalse($afternoon->overlaps($morning));
    }

    public function testBufferTurnsCloseWindowsIntoAnOverlap(): void
    {
        $morning = self::window('2026-10-20 06:00', '2026-10-20 12:00');
        $afternoon = self::window('2026-10-20 13:00', '2026-10-20 18:00');

        self::assertFalse($morning->overlaps($afternoon, 60));
        self::assertTrue($morning->overlaps($afternoon, 61));
    }

    public function testContainedWindowOverlaps(): void
    {
        $day = self::window('2026-10-20 06:00', '2026-10-20 23:00');

        self::assertTrue($day->overlaps(self::window('2026-10-20 10:00', '2026-10-20 11:00')));
    }

    public function testCoversEveryDayItTouches(): void
    {
        $overnight = self::window('2026-10-19 20:00', '2026-10-20 23:00');

        self::assertTrue($overnight->covers(new DateTimeImmutable('2026-10-19')));
        self::assertTrue($overnight->covers(new DateTimeImmutable('2026-10-20')));
        self::assertFalse($overnight->covers(new DateTimeImmutable('2026-10-21')));
    }

    public function testEndMustBeAfterStart(): void
    {
        $this->expectException(DomainRuleViolation::class);

        self::window('2026-10-20 12:00', '2026-10-20 12:00');
    }

    public function testRejectsImplausiblyLongWindows(): void
    {
        $this->expectException(DomainRuleViolation::class);

        self::window('2026-10-20 00:00', '2026-10-25 00:01');
    }
}
