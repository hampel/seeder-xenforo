<?php namespace Hampel\Seeder\Factory\Concerns;

use XF\Util\File;

trait MakesImages
{
	/**
	 * @return string the path of a small PNG, different each time
	 */
	protected static function image(): string
	{
		$path = File::getTempFile();
		$image = imagecreatetruecolor(480, 270);
		imagefill($image, 0, 0, imagecolorallocate($image, mt_rand(40, 215), mt_rand(40, 215), mt_rand(40, 215)));
		imagefilledellipse($image, mt_rand(80, 400), mt_rand(60, 210), mt_rand(60, 160), mt_rand(60, 160),
			imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
		imagepng($image, $path);
		imagedestroy($image);

		return $path;
	}
}
