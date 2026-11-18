<?php

namespace Drupal\uvalib_block_guard;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;
use Drupal\layout_builder\EventSubscriber\BlockComponentRenderArray;

/**
 * Layout Builder block renderer that isolates a failing block.
 *
 * Replaces core's layout_builder.render_block_component_subscriber (see
 * UvalibBlockGuardServiceProvider). The failure comes from $block->build(),
 * which Views blocks run eagerly, so it must be caught here rather than in a
 * #pre_render callback.
 */
class GuardedBlockComponentRenderArray extends BlockComponentRenderArray {

  /**
   * {@inheritdoc}
   */
  public function onBuildRender(SectionComponentBuildRenderArrayEvent $event) {
    BlockRenderGuard::run(
      fn() => parent::onBuildRender($event),
      function (array $fallback) use ($event) {
        // SectionComponent::toRenderArray() finishes with
        // $event->getCacheableMetadata()->applyTo($build), which would
        // overwrite a #cache set on the build array itself, so the short
        // max-age and tags must go on the event.
        $event->addCacheableDependency(CacheableMetadata::createFromRenderArray($fallback));
        unset($fallback['#cache']);
        $event->setBuild($fallback);
      },
      $event->getPlugin()->getPluginId(),
    );
  }

}
