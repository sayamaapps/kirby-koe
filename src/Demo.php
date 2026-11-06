<?php

namespace Sayamaapps\Koe;

use DateTimeImmutable;

/**
 * Sample reviews for the demo mode (`demo` option): a fixed, fictional set in
 * the form the cache keeps (Places::parse()), so the selection rules, the
 * approved list and the snippet run on it unchanged. No request, no key, no
 * cache: the data is built anew on every view, dated relative to today.
 *
 * The names and texts are invented. Nothing here comes from Google, so the
 * entries carry no link and no profile picture.
 *
 * @package   Sayamaapps\Koe
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-koe
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Demo
{
	/** Overall rating and count of the sample place. */
	public const COUNT = 47;
	public const RATING = 4.7;

	/** Days back, stars, name, text of each sample review (German, invented). */
	private const REVIEWS = [
		[6, 5, 'M. Beispiel', 'Sehr freundlicher Empfang, alles hat pünktlich geklappt und wir wurden ehrlich beraten. Man merkt, dass hier mit Freude gearbeitet wird. Gerne wieder!'],
		[23, 5, 'Anna Muster', 'Wir waren zum zweiten Mal hier und wieder rundum zufrieden. Die Atmosphäre ist angenehm, die Wartezeit kurz und auf Sonderwünsche wurde ohne Umstände eingegangen. Besonders gefallen hat uns, dass man sich Zeit für Fragen nimmt, auch wenn viel los ist.'],
		[41, 4, 'Thomas Exempel', 'Gute Qualität und nettes Team. Parkplätze sind in der Umgebung etwas knapp, sonst gibt es nichts auszusetzen.'],
		[67, 5, 'Familie Probst', 'Kompetent, herzlich und zuverlässig. Wir empfehlen den Betrieb im Freundeskreis schon seit Jahren weiter und wurden noch nie enttäuscht.'],
		[95, 5, 'J. Platzhalter', 'Top!'],
		[140, 3, 'S. Testmann', 'Grundsätzlich in Ordnung, an dem Tag war es allerdings sehr voll und wir mussten länger warten als gedacht.'],
	];

	/**
	 * The sample data in the form Store::read() returns, without `expires`.
	 *
	 * @return array{placeId: ?string, rating: float, count: int, reviews: list<array{id: string, name: string, date: string, rating: int, text: string, url: null, avatar: null}>}
	 */
	public static function data(DateTimeImmutable $today, string|null $placeId = null): array
	{
		$reviews = [];
		foreach (self::REVIEWS as $i => [$days, $stars, $name, $text]) {
			$reviews[] = [
				'id'     => 'demo-' . ($i + 1),
				'name'   => $name,
				'date'   => $today->modify('-' . $days . ' days')->format('Y-m-d'),
				'rating' => $stars,
				'text'   => $text,
				'url'    => null,
				'avatar' => null,
			];
		}
		return ['placeId' => $placeId, 'rating' => self::RATING, 'count' => self::COUNT, 'reviews' => $reviews];
	}
}
