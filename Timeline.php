<?php namespace Hampel\Seeder;

/**
 * The window seeded content is spread across, ending now. Dates are weighted towards the recent
 * end, as a real forum's are, so "what's new" and a statistics graph both have something to show.
 *
 * Draws from mt_rand(), which Faker's seed() seeds - so a seeded run places its content at the
 * same offsets every time.
 */
class Timeline
{
	public const DEFAULT_SPAN = '90d';

	private const UNITS = ['h' => 3600, 'd' => 86400, 'w' => 604800, 'm' => 2592000, 'y' => 31536000];

	/** @var int */
	protected $start;

	/** @var int */
	protected $end;

	public function __construct(int $start, int $end)
	{
		if ($start > $end)
		{
			throw new \InvalidArgumentException('A timeline cannot start after it ends');
		}

		$this->start = $start;
		$this->end = $end;
	}

	/**
	 * @param string $span a number and a unit: 36h, 90d, 12w, 6m, 2y
	 */
	public static function span(string $span, ?int $end = null): self
	{
		if (!preg_match('/^([1-9]\d*)([hdwmy])$/', $span, $match))
		{
			throw new \InvalidArgumentException("'{$span}' is not a span: use a number and h, d, w, m or y, such as 90d");
		}

		$end = $end ?? time();

		return new self($end - $match[1] * self::UNITS[$match[2]], $end);
	}

	public function start(): int
	{
		return $this->start;
	}

	public function end(): int
	{
		return $this->end;
	}

	/**
	 * A moment in the window, more likely to be recent than old.
	 */
	public function at(): int
	{
		$u = $this->random();

		return $this->start + (int) round(($this->end - $this->start) * (1 - $u * $u));
	}

	/**
	 * A moment after the one given and inside the window, more likely to be soon after than long
	 * after - a reply follows its thread, activity follows registration.
	 */
	public function after(int $time): int
	{
		$time = max($this->start, min($time, $this->end));
		$u = $this->random();

		return $time + (int) round(($this->end - $time) * $u * $u);
	}

	protected function random(): float
	{
		return mt_rand() / mt_getrandmax();
	}
}
