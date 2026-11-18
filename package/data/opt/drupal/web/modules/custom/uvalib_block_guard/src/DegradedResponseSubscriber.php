<?php

namespace Drupal\uvalib_block_guard;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Caps the page cache lifetime of a response that contains a failed block.
 *
 * The placeholder returned for a failed block has max-age 60, which Drupal's
 * render cache and dynamic page cache honor. The internal page cache (which
 * serves most anonymous traffic) does not: it takes its expiry from the
 * Expires header, and core sets that to a past date, which stores the page
 * permanently until a cache tag is invalidated. Without this, a degraded
 * homepage caused by a transient error would stay degraded until the next
 * content change. Only responses carrying DEGRADED_TAG are touched.
 */
class DegradedResponseSubscriber implements EventSubscriberInterface {

  public function __construct(protected TimeInterface $time) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After FinishResponseSubscriber::onRespond() (priority 0), which sets the
    // default Expires and Cache-Control headers that we then tighten.
    return [KernelEvents::RESPONSE => ['onRespond', -10]];
  }

  /**
   * Shortens Expires and a public Cache-Control max-age for degraded pages.
   */
  public function onRespond(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $response = $event->getResponse();
    if (!$response instanceof CacheableResponseInterface
      || !in_array(BlockRenderGuard::DEGRADED_TAG, $response->getCacheableMetadata()->getCacheTags(), TRUE)) {
      return;
    }
    $ttl = BlockRenderGuard::RETRY_SECONDS;
    $response->setExpires(new \DateTimeImmutable('@' . ($this->time->getRequestTime() + $ttl)));
    if ($response->headers->hasCacheControlDirective('public')) {
      $response->setMaxAge($ttl);
    }
  }

}
