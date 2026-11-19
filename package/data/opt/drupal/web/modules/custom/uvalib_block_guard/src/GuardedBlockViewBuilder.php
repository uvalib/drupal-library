<?php

namespace Drupal\uvalib_block_guard;

use Drupal\block\BlockViewBuilder;

/**
 * Block view builder that isolates a failing block placed in a region.
 *
 * Covers pages that are not built with Layout Builder (see
 * GuardedBlockComponentRenderArray for those). Core's buildPreRenderableBlock()
 * registers static::class . '::preRender', so overriding it here is enough.
 */
class GuardedBlockViewBuilder extends BlockViewBuilder {

  /**
   * {@inheritdoc}
   */
  public static function preRender($build) {
    $plugin_id = isset($build['#block']) ? $build['#block']->getPluginId() : 'unknown';
    return BlockRenderGuard::run(
      static fn() => parent::preRender($build),
      static fn(array $fallback) => $fallback,
      $plugin_id,
      $build['#cache'] ?? [],
    );
  }

}
