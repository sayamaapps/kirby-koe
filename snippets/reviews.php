<?php
/**
 * Google reviews, the plain default shipped with Koe. For your own markup
 * create site/snippets/koe/reviews.php (Kirby takes that one first) or any
 * snippet that calls $site->koeReviews().
 *
 *   <?php snippet('koe/reviews') ?>
 *   <?php snippet('koe/reviews', ['title' => 'What our guests say', 'logo' => 'assets/GoogleMaps_Logo_Gray.svg']) ?>
 *
 * Google Places policies: name the author with the profile picture, link
 * both to the review on Google Maps (googleMapsUri; without a safe link they
 * stay unlinked), show the "Google Maps" attribution. The picture comes from
 * the site's own server (route koe/avatar/…), never from Google. Without
 * $logo (or `koe.logo`) the attribution is text; with it, the official,
 * unaltered logo served from your own site.
 *
 * Rating and count are always shown; empty slots are filled by a "read all
 * on Google" tile. Below the single reviews a notice says how they were
 * selected and sorted (Google Places policies); it is worded from the options
 * in force. With the module off or nothing in the cache yet, the section is
 * omitted entirely. Deliberately no structured data: Google ignores review
 * markup a business publishes about itself. No CSS: the grid belongs to the
 * site (classes koe-*).
 *
 * @var string|null $title
 * @var string|null $logo  path under public/, e.g. assets/GoogleMaps_Logo_Gray.svg
 */
$r = site()->koeReviews();
if ($r === null) {
    return;
}
$t = fn (string $key, array $values = []) => \Koe\I18n::t($key, $values);
$profile = $r['profileUrl'];
$title ??= koe_option('title') ?? $t('title');
$logo ??= koe_option('logo');
$tab = '<span class="sr-only"> ' . esc($t('opensNewTab')) . '</span>';
?>
<section class="koe" id="koe" aria-labelledby="koe-title">
  <h2 id="koe-title"><?= esc($title) ?></h2>
  <p class="koe-rating">
    <strong><?= esc($t('ratingOutOf', ['rating' => \Koe\I18n::number($r['rating'])])) ?></strong>
    <span><?= esc($t('countOn', ['count' => $r['count']])) ?> <?php if ($logo) : ?><img class="koe-logo" src="<?= esc(url($logo), 'attr') ?>" width="98" height="18" alt="Google Maps"><?php else : ?>Google Maps<?php endif ?><?php if ($profile) : ?> · <a href="<?= esc($profile, 'attr') ?>" target="_blank" rel="noopener"><?= esc($t('viewProfile')) ?><?= $tab ?></a><?php endif ?></span>
  </p>
  <?php if ($r['reviews'] !== []) : ?>
  <ul class="koe-list">
    <?php foreach ($r['reviews'] as $review) : ?>
    <?php $avatar = $review['avatar'] ? '<img class="koe-avatar" src="' . esc($review['avatar'], 'attr') . '" width="32" height="32" alt="" loading="lazy">' : '' ?>
    <li class="koe-review">
      <p class="koe-stars" role="img" aria-label="<?= esc($t('starsOutOf', ['stars' => $review['rating']]), 'attr') ?>"><span aria-hidden="true"><?= str_repeat('★', $review['rating']) ?></span></p>
      <blockquote><?= nl2br(esc($review['text'])) ?></blockquote>
      <p class="koe-author"><?php if ($review['url']) : ?><a href="<?= esc($review['url'], 'attr') ?>" target="_blank" rel="noopener"><?= $avatar ?><?= esc($review['name']) ?><?= $tab ?></a><?php else : ?><?= $avatar ?><?= esc($review['name']) ?><?php endif ?> · <time datetime="<?= esc($review['date'], 'attr') ?>"><?= esc($review['month']) ?></time></p>
    </li>
    <?php endforeach ?>
    <?php if ($profile && count($r['reviews']) < $r['limit']) : ?>
    <li class="koe-review koe-more">
      <p><?= esc($t('reviewsCount', ['count' => $r['count']])) ?></p>
      <a href="<?= esc($profile, 'attr') ?>" target="_blank" rel="noopener"><?= esc($t('readAll')) ?> <span aria-hidden="true">→</span><?= $tab ?></a>
    </li>
    <?php endif ?>
  </ul>
  <p class="koe-notice"><?= esc($r['notice']) ?></p>
  <?php endif ?>
</section>
