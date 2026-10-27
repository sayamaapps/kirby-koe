<?php

// setRules() replaces the Kirby set, so the set is repeated next to the one
// rule that differs: the @package line names the namespace of each class.
return Kirby\PhpCs\Config::create()
	->setRules(['@Kirby/style:risky' => true, 'phpdoc_no_package' => false])
	->setFinder(
		PhpCsFixer\Finder::create()
			->exclude('vendor')
			->in(__DIR__)
	);
