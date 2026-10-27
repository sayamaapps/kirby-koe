<?php

namespace Sayamaapps\Koe;

use Kirby\Cms\App;

/**
 * What the site methods return: the reviews a page shows and the address of
 * the Google profile. config/site-methods.php only delegates here.
 *
 * @package   Sayamaapps\Koe
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-koe
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Site
{
	/** Address of the Google profile from `sayamaapps.koe.placeId`, or null. */
	public static function profileUrl(App $kirby): string|null
	{
		return Reviews::profileUrl(Options::placeId($kirby));
	}

	/**
	 * Reviews of the configured place, with the notice and the profile address,
	 * or null when nothing is to be shown.
	 *
	 * @return array{rating: float, count: int, placeId: string, reviews: array<int, array<string, mixed>>, limit: int, notice: string, profileUrl: string|null}|null
	 */
	public static function reviews(App $kirby, string|null $profileUrl): array|null
	{
		if (Options::get($kirby, 'active', false) !== true) {
			return null;
		}
		I18n::setLanguage(Options::language($kirby));
		$rules = Options::rules($kirby);
		$result = Plugin::reviews($kirby, $rules);
		if ($result === null) {
			return null;
		}
		$result['notice'] = Reviews::notice($rules);
		$result['profileUrl'] = $profileUrl;
		return $result;
	}
}
