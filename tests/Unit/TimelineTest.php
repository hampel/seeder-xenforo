<?php namespace Tests\Unit;

use Hampel\Seeder\Clock;
use Hampel\Seeder\Timeline;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TimelineTest extends TestCase
{
	public static function spans(): array
	{
		return [['36h', 36 * 3600], ['90d', 90 * 86400], ['12w', 12 * 604800], ['6m', 6 * 2592000], ['2y', 2 * 31536000]];
	}

	#[DataProvider('spans')]
	public function test_a_span_is_a_number_and_a_unit($span, $seconds)
	{
		$timeline = Timeline::span($span, 2000000000);

		$this->assertSame(2000000000, $timeline->end());
		$this->assertSame(2000000000 - $seconds, $timeline->start());
	}

	public static function notSpans(): array
	{
		return [['90'], ['d'], ['0d'], ['-5d'], ['90 d'], ['1.5d'], ['ninety days'], ['']];
	}

	#[DataProvider('notSpans')]
	public function test_anything_else_is_refused($span)
	{
		$this->expectException(\InvalidArgumentException::class);

		Timeline::span($span);
	}

	public function test_every_moment_is_inside_the_window_and_most_are_recent()
	{
		mt_srand(7);
		$timeline = new Timeline(1000, 2000);

		$moments = [];
		for ($i = 0; $i < 2000; $i++)
		{
			$moments[] = $timeline->at();
		}

		$this->assertGreaterThanOrEqual(1000, min($moments));
		$this->assertLessThanOrEqual(2000, max($moments));
		$recent = count(array_filter($moments, function ($moment) { return $moment >= 1500; }));
		$this->assertGreaterThan(1300, $recent, 'the recent half should hold about 71% of the moments');
	}

	public function test_after_is_never_before_the_moment_given_and_never_past_the_end()
	{
		mt_srand(7);
		$timeline = new Timeline(1000, 2000);

		$gaps = [];
		for ($i = 0; $i < 2000; $i++)
		{
			$after = $timeline->after(1400);
			$this->assertGreaterThanOrEqual(1400, $after);
			$this->assertLessThanOrEqual(2000, $after);
			$gaps[] = $after - 1400;
		}

		$soon = count(array_filter($gaps, function ($gap) { return $gap <= 300; }));
		$this->assertGreaterThan(1300, $soon, 'most should follow soon after');
		$this->assertSame(2000, $timeline->after(5000), 'a moment past the end is brought back to it');
	}

	public function test_the_same_seed_gives_the_same_moments()
	{
		$timeline = new Timeline(1000, 2000);

		mt_srand(99);
		$first = [$timeline->at(), $timeline->at(), $timeline->after(1200)];
		mt_srand(99);
		$second = [$timeline->at(), $timeline->at(), $timeline->after(1200)];

		$this->assertSame($first, $second);
	}

	public function test_the_clock_is_moved_for_the_callback_and_put_back()
	{
		$real = \XF::$time;

		$seen = Clock::at(86400, function () { return \XF::$time; });

		$this->assertSame(86400, $seen);
		$this->assertSame($real, \XF::$time);
	}

	public function test_the_clock_is_put_back_when_the_callback_throws()
	{
		$real = \XF::$time;

		try
		{
			Clock::at(86400, function () { throw new \RuntimeException('no'); });
			$this->fail('the exception was swallowed');
		}
		catch (\RuntimeException $e)
		{
		}

		$this->assertSame($real, \XF::$time);
	}
}
