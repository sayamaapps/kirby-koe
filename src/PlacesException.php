<?php

namespace Koe;

/**
 * A failed Places API step. The message is safe to print: it never contains the key.
 *
 * The class keeps the name it had before the namespace move (Koe\PlacesException),
 * so callers that catch it or compare class names see no change.
 *
 * @package   Koe
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-koe
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class PlacesException extends \RuntimeException
{
}
